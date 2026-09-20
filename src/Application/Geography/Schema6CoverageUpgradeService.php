<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;

/**
 * Schema-5 → schema-6 coverage conversion after tables exist.
 *
 * Woo canonical country/state rows are bootstrapped for every country
 * referenced by legacy destination_rules before conversion runs.
 *
 * Durable leased pass + worker fencing keeps conversion bounded per tick.
 * Administrators may still request explicit reconciliation later.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013.
 */
final class Schema6CoverageUpgradeService {

	public const OPTION_KEY = 'cetech_de_schema6_coverage_upgrade';

	public const HOOK = 'cetech_de_schema6_coverage_upgrade_tick';

	public const GROUP = 'cetech-delivery-engine-schema6';

	public const STATUS_PENDING = 'pending';

	public const STATUS_RUNNING = 'running';

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_DEFERRED_WOO = 'deferred_woo';

	public const PASS_INITIAL = 'initial';

	public const PASS_RECONCILE = 'reconciliation';

	public const LEASE_TTL = 300;

	public const WORKER_TTL = 60;

	public const PAGES_PER_TICK = 1;

	public function __construct(
		private DestinationZoneRepositoryInterface $zones,
		private DestinationRuleRepositoryInterface $rules,
		private WooCommerceGeographyBootstrap $woo_bootstrap,
		private LegacyDestinationCoverageMigrator $migrator
	) {
	}

	/**
	 * @return list<string>
	 */
	public function referenced_country_codes(): array {
		$codes = [];
		$after = 0;
		do {
			$page = $this->zones->page_after( $after, 100 );
			foreach ( $page as $zone ) {
				$zone_id = (int) ( $zone['id'] ?? 0 );
				$after   = max( $after, $zone_id );
				if ( $zone_id <= 0 ) {
					continue;
				}
				foreach ( $this->rules->listByZoneId( $zone_id ) as $rule ) {
					if ( DestinationRuleType::Country->value !== (string) ( $rule['rule_type'] ?? '' ) ) {
						continue;
					}
					$code = strtoupper( trim( (string) ( $rule['rule_value'] ?? '' ) ) );
					if ( 2 === strlen( $code ) ) {
						$codes[ $code ] = $code;
					}
				}
			}
		} while ( [] !== $page );

		return array_values( $codes );
	}

	/**
	 * Atomic lease claim. Two concurrent starters cannot both own the pass.
	 */
	public function try_claim_owner( string $owner ): bool {
		$owner = trim( $owner );
		if ( '' === $owner ) {
			return false;
		}
		$now   = time();
		$lease = $this->lease_payload( $owner, $now, self::STATUS_RUNNING, 0, $this->new_pass_id(), self::PASS_INITIAL );

		if ( function_exists( 'add_option' ) && add_option( self::OPTION_KEY, $lease, '', false ) ) {
			return true;
		}

		$current        = $this->current_state();
		$existing_owner = (string) ( $current['owner'] ?? $current['lease_owner'] ?? '' );
		if ( $existing_owner === $owner ) {
			return true;
		}
		if ( self::STATUS_COMPLETED === (string) ( $current['status'] ?? '' ) ) {
			return false;
		}
		if ( ! $this->lock_expired( $current ) ) {
			return false;
		}

		$lease = $this->lease_payload(
			$owner,
			$now,
			self::STATUS_RUNNING,
			(int) ( $current['last_zone_id'] ?? 0 ),
			(string) ( $current['pass_id'] ?? $this->new_pass_id() ),
			(string) ( $current['pass_kind'] ?? self::PASS_INITIAL )
		);

		return $this->compare_and_swap_state( $current, $lease );
	}

	/**
	 * Boot-safe: one bounded tick unless conversion is complete or another worker is live.
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_run(): array {
		return $this->tick_pass( false );
	}

	/**
	 * Action Scheduler / subsequent-request continuation of the current pass.
	 *
	 * @return array<string, mixed>
	 */
	public function continue_pass(): array {
		return $this->tick_pass( false );
	}

	/**
	 * Explicit/on-demand reconciliation. Completing the first upgrade pass
	 * does not prevent later administrator-triggered review.
	 *
	 * A force reconciliation must not steal a currently live non-expired pass.
	 * Failed CAS does not call run() anyway.
	 *
	 * @return array<string, mixed>
	 */
	public function reconcile( bool $force = true ): array {
		$state = $this->current_state();
		if ( $this->pass_is_live( $state ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
				'state'        => $state,
			];
		}

		$owner   = $this->new_owner_token();
		$pass_id = $this->new_pass_id();
		$now     = time();
		$next    = $this->lease_payload( $owner, $now, self::STATUS_RUNNING, 0, $pass_id, self::PASS_RECONCILE );
		if ( [] === $state || self::STATUS_PENDING === (string) ( $state['status'] ?? '' ) ) {
			if ( function_exists( 'add_option' ) && add_option( self::OPTION_KEY, $next, '', false ) ) {
				return $this->execute_tick( $force, $owner, $pass_id );
			}
			$state = $this->current_state();
			if ( $this->pass_is_live( $state ) ) {
				return [
					'countries'    => [],
					'bootstrapped' => 0,
					'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
					'state'        => $state,
				];
			}
		}

		if ( ! $this->compare_and_swap_state( $state, $next ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'cas_failed' ],
				'state'        => $this->current_state(),
			];
		}

		return $this->execute_tick( $force, $owner, $pass_id );
	}

	/**
	 * One bounded conversion tick. Never calls migrate() with unlimited max_pages.
	 *
	 * @return array{countries:list<string>,bootstrapped:int,migration:array<string,mixed>,state?:array<string,mixed>}
	 */
	public function run( bool $force = false, string $owner = '' ): array {
		if ( '' !== $owner ) {
			if ( ! $this->try_claim_owner( $owner ) && ! $this->owns_pass( $owner ) ) {
				return [
					'countries'    => [],
					'bootstrapped' => 0,
					'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
					'state'        => $this->current_state(),
				];
			}

			$state = $this->current_state();

			return $this->execute_tick( $force, $owner, (string) ( $state['pass_id'] ?? '' ) );
		}

		return $this->tick_pass( $force );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function current_state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [ 'status' => self::STATUS_PENDING ];
		}
		$raw = get_option( self::OPTION_KEY, [] );

		return is_array( $raw ) ? $raw : [ 'status' => self::STATUS_PENDING ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tick_pass( bool $force ): array {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'coverage_tables_missing' ],
			];
		}

		$state = $this->current_state();
		if ( self::STATUS_COMPLETED === (string) ( $state['status'] ?? '' ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'already_completed' ],
				'state'        => $state,
			];
		}

		if ( ! $this->woo_catalog_available() ) {
			$deferred = array_merge(
				$state,
				[
					'status'            => self::STATUS_DEFERRED_WOO,
					'worker'            => '',
					'worker_expires_at' => 0,
				]
			);
			if ( [] !== $state && self::STATUS_COMPLETED !== (string) ( $state['status'] ?? '' ) ) {
				$this->compare_and_swap_state( $state, $deferred );
			} elseif ( function_exists( 'add_option' ) ) {
				add_option( self::OPTION_KEY, $deferred + [ 'status' => self::STATUS_DEFERRED_WOO ], '', false );
			}
			$this->enqueue_continuation();

			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'woocommerce_unavailable' ],
				'state'        => $this->current_state(),
			];
		}

		$claimed = $this->acquire_worker();
		if ( null === $claimed ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
				'state'        => $this->current_state(),
			];
		}

		return $this->execute_tick( $force, $claimed['owner'], $claimed['pass_id'] );
	}

	/**
	 * @return array{owner:string,pass_id:string}|null
	 */
	private function acquire_worker(): ?array {
		$owner = $this->new_owner_token();
		$now   = time();
		if ( $this->try_claim_owner( $owner ) ) {
			$state = $this->current_state();
			$work  = array_merge(
				$state,
				[
					'worker'            => $owner,
					'worker_expires_at' => $now + self::WORKER_TTL,
					'lease_expires_at'  => $now + self::LEASE_TTL,
				]
			);
			$this->compare_and_swap_state( $state, $work );

			return [
				'owner'   => $owner,
				'pass_id' => (string) ( $work['pass_id'] ?? $state['pass_id'] ?? '' ),
			];
		}

		$state = $this->current_state();
		if ( self::STATUS_COMPLETED === (string) ( $state['status'] ?? '' ) ) {
			return null;
		}
		if ( $this->worker_is_live( $state ) ) {
			return null;
		}
		if ( $this->pass_is_live( $state ) || self::STATUS_DEFERRED_WOO === (string) ( $state['status'] ?? '' ) || self::STATUS_PENDING === (string) ( $state['status'] ?? '' ) || $this->lock_expired( $state ) ) {
			$pass_owner = (string) ( $state['owner'] ?? $state['lease_owner'] ?? $owner );
			$next       = array_merge(
				$state,
				[
					'status'            => self::STATUS_RUNNING,
					'worker'            => $owner,
					'worker_expires_at' => $now + self::WORKER_TTL,
					'lease_expires_at'  => $now + self::LEASE_TTL,
					'owner'             => '' !== $pass_owner ? $pass_owner : $owner,
					'lease_owner'       => '' !== $pass_owner ? $pass_owner : $owner,
				]
			);
			if ( ! $this->compare_and_swap_state( $state, $next ) ) {
				return null;
			}

			return [
				'owner'   => $owner,
				'pass_id' => (string) ( $next['pass_id'] ?? '' ),
			];
		}

		return null;
	}

	/**
	 * @return array{countries:list<string>,bootstrapped:int,migration:array<string,mixed>,state?:array<string,mixed>}
	 */
	private function execute_tick( bool $force, string $owner, string $pass_id ): array {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'coverage_tables_missing' ],
			];
		}

		if ( ! $this->woo_catalog_available() ) {
			$state = $this->current_state();
			$this->owner_store(
				$owner,
				$pass_id,
				array_merge( $state, [ 'status' => self::STATUS_DEFERRED_WOO, 'worker' => '', 'worker_expires_at' => 0 ] )
			);

			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'woocommerce_unavailable' ],
				'state'        => $this->current_state(),
			];
		}

		if ( ! $this->renew_owner_lease( $owner, $pass_id ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'stale_owner' ],
				'state'        => $this->current_state(),
			];
		}

		$state = $this->current_state();
		$after = (int) ( $state['last_zone_id'] ?? 0 );

		$codes        = $this->referenced_country_codes();
		$bootstrapped = 0;
		foreach ( $codes as $code ) {
			$result        = $this->woo_bootstrap->bootstrap_country( $code );
			$bootstrapped += (int) ( $result['created'] ?? 0 );
			if ( ! $this->renew_owner_lease( $owner, $pass_id ) ) {
				return [
					'countries'    => $codes,
					'bootstrapped' => $bootstrapped,
					'migration'    => [ 'skipped' => true, 'reason' => 'stale_owner' ],
					'state'        => $this->current_state(),
				];
			}
		}

		$migration = $this->migrator->migrate( $force, $after, self::PAGES_PER_TICK );
		$failed_id = (int) ( $migration['failed_zone_id'] ?? 0 );
		$last_id   = (int) ( $migration['last_zone_id'] ?? $after );
		$complete  = ! empty( $migration['complete'] ) && $failed_id <= 0;
		$now       = time();
		$next      = array_merge(
			$state,
			[
				'status'            => $complete ? self::STATUS_COMPLETED : self::STATUS_RUNNING,
				'owner'             => (string) ( $state['owner'] ?? $owner ),
				'lease_owner'       => (string) ( $state['owner'] ?? $owner ),
				'pass_id'           => '' !== $pass_id ? $pass_id : (string) ( $state['pass_id'] ?? '' ),
				'last_zone_id'      => $last_id,
				'failed_zone_id'    => $failed_id,
				'completed_at'      => $complete ? $now : 0,
				'countries'         => $codes,
				'bootstrapped'      => $bootstrapped,
				'review_required'   => (int) ( $migration['review_required'] ?? 0 ),
				'converted'         => (int) ( $migration['converted'] ?? 0 ),
				'warnings'          => $migration['warnings'] ?? [],
				'worker'            => '',
				'worker_expires_at' => 0,
				'lease_expires_at'  => $now + self::LEASE_TTL,
			]
		);

		if ( ! $this->owner_store( $owner, $pass_id, $next ) ) {
			return [
				'countries'    => $codes,
				'bootstrapped' => $bootstrapped,
				'migration'    => $migration + [ 'fenced' => false ],
				'state'        => $this->current_state(),
			];
		}

		if ( ! $complete ) {
			$this->enqueue_continuation();
		}

		return [
			'countries'    => $codes,
			'bootstrapped' => $bootstrapped,
			'migration'    => $migration,
			'state'        => $this->current_state(),
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function owner_store( string $owner, string $pass_id, array $state ): bool {
		$current = $this->current_state();
		if ( ! $this->worker_may_commit( $owner, $pass_id, $current ) ) {
			return false;
		}

		return $this->compare_and_swap_state( $current, $state );
	}

	private function renew_owner_lease( string $owner, string $pass_id ): bool {
		$current = $this->current_state();
		if ( ! $this->worker_may_commit( $owner, $pass_id, $current ) ) {
			return false;
		}
		$now  = time();
		$next = array_merge(
			$current,
			[
				'lease_expires_at'  => $now + self::LEASE_TTL,
				'worker'            => $owner,
				'worker_expires_at' => $now + self::WORKER_TTL,
			]
		);

		return $this->compare_and_swap_state( $current, $next );
	}

	/**
	 * Tick mutation is fenced to the exact current worker token plus pass identity.
	 * Historical pass-owner identity is not sufficient after another worker is installed.
	 *
	 * @param array<string, mixed> $current
	 */
	private function worker_may_commit( string $worker, string $pass_id, array $current ): bool {
		$worker = trim( $worker );
		if ( '' === $worker ) {
			return false;
		}
		$current_pass   = (string) ( $current['pass_id'] ?? '' );
		$current_worker = (string) ( $current['worker'] ?? '' );
		if ( '' !== $pass_id && '' !== $current_pass && $current_pass !== $pass_id ) {
			return false;
		}

		return $current_worker === $worker;
	}

	private function owns_pass( string $owner ): bool {
		$state = $this->current_state();

		return $owner === (string) ( $state['owner'] ?? '' ) || $owner === (string) ( $state['worker'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function pass_is_live( array $state ): bool {
		$status = (string) ( $state['status'] ?? '' );
		if ( self::STATUS_RUNNING !== $status ) {
			return false;
		}

		return ! $this->lock_expired( $state );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function worker_is_live( array $state ): bool {
		$worker = (string) ( $state['worker'] ?? '' );
		if ( '' === $worker ) {
			return false;
		}

		return (int) ( $state['worker_expires_at'] ?? 0 ) > time();
	}

	/**
	 * @param array<string, mixed> $expected
	 * @param array<string, mixed> $replacement
	 */
	private function compare_and_swap_state( array $expected, array $replacement ): bool {
		global $wpdb;
		if ( $this->real_wpdb_cas_available() ) {
			$updated = $wpdb->update(
				(string) $wpdb->options,
				[ 'option_value' => serialize( $replacement ) ],
				[
					'option_name'  => self::OPTION_KEY,
					'option_value' => serialize( $expected ),
				]
			);
			if ( false === $updated ) {
				return false;
			}
			if ( is_int( $updated ) && $updated > 0 ) {
				return $this->accept_cas_replacement( $replacement );
			}
			if ( 0 === (int) $updated && $this->persisted_option_equals( $replacement ) ) {
				return $this->accept_cas_replacement( $replacement );
			}

			return false;
		}

		$current = $this->current_state();
		if ( serialize( $current ) !== serialize( $expected ) && [] !== $expected && $current !== $expected ) {
			return false;
		}
		$this->store_state( $replacement );

		return true;
	}

	/**
	 * @param array<string, mixed> $replacement
	 */
	private function accept_cas_replacement( array $replacement ): bool {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_KEY, 'options' );
		}
		$GLOBALS['cetech_de_test_options'][ self::OPTION_KEY ] = $replacement;

		return true;
	}

	/**
	 * Direct options-table read. Zero-row CAS may succeed only when this equals replacement.
	 *
	 * @param array<string, mixed> $replacement
	 */
	private function persisted_option_equals( array $replacement ): bool {
		global $wpdb;
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->options );
		if ( ! is_string( $table ) || '' === $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a sanitized wpdb->options identifier.
		$sql = $wpdb->prepare(
			"SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1",
			self::OPTION_KEY
		);
		if ( ! is_string( $sql ) ) {
			return false;
		}
		$stored = $wpdb->get_var( $sql );

		return is_string( $stored ) && $stored === serialize( $replacement );
	}

	private function real_wpdb_cas_available(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'update' ) ) {
			return false;
		}

		return ! str_contains( $wpdb::class, 'FakeWpdb' );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function store_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $state, false );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function lease_payload( string $owner, int $now, string $status, int $last_zone_id, string $pass_id, string $pass_kind ): array {
		return [
			'status'            => $status,
			'owner'             => $owner,
			'lease_owner'       => $owner,
			'pass_id'           => $pass_id,
			'pass_kind'         => $pass_kind,
			'lease_acquired_at' => $now,
			'lease_expires_at'  => $now + self::LEASE_TTL,
			'started_at'        => $now,
			'last_zone_id'      => max( 0, $last_zone_id ),
			'failed_zone_id'    => 0,
			'worker'            => $owner,
			'worker_expires_at' => $now + self::WORKER_TTL,
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function lock_expired( array $state ): bool {
		$status = (string) ( $state['status'] ?? '' );
		if ( self::STATUS_COMPLETED === $status ) {
			return false;
		}
		$expires = (int) ( $state['lease_expires_at'] ?? 0 );
		if ( $expires > 0 ) {
			return $expires <= time();
		}
		$started = (int) ( $state['started_at'] ?? 0 );

		return $started <= 0 || ( time() - $started ) > self::LEASE_TTL;
	}

	private function enqueue_continuation(): void {
		ActionSchedulerReadiness::enqueue_unique_async( self::HOOK, [], self::GROUP );
	}

	public function woo_catalog_available(): bool {
		if ( isset( $GLOBALS['cetech_de_test_wc'] ) && is_object( $GLOBALS['cetech_de_test_wc'] ) ) {
			return true;
		}
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) && method_exists( $wc->countries, 'get_countries' ) ) {
				return true;
			}
		}

		return false;
	}

	private function new_owner_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return uniqid( 'schema6-', true );
		}
	}

	private function new_pass_id(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable ) {
			return uniqid( 'pass-', true );
		}
	}
}
