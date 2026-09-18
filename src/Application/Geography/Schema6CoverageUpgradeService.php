<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;

/**
 * Schema-5 → schema-6 coverage conversion after tables exist.
 *
 * Woo canonical country/state rows are bootstrapped for every country
 * referenced by legacy destination_rules before conversion runs.
 *
 * Durable leased state prevents two requests from converting the same
 * store concurrently. Administrators may still request explicit
 * reconciliation later.
 */
final class Schema6CoverageUpgradeService {

	public const OPTION_KEY = 'cetech_de_schema6_coverage_upgrade';

	public const STATUS_PENDING = 'pending';

	public const STATUS_RUNNING = 'running';

	public const STATUS_COMPLETED = 'completed';

	public const LEASE_TTL = 300;

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
		$lease = $this->lease_payload( $owner, $now, self::STATUS_RUNNING, 0 );

		if ( function_exists( 'add_option' ) && add_option( self::OPTION_KEY, $lease, '', false ) ) {
			return true;
		}

		$current = $this->current_state();
		$existing_owner = (string) ( $current['owner'] ?? $current['lease_owner'] ?? '' );
		if ( $existing_owner === $owner ) {
			return true;
		}
		if ( ! $this->lock_expired( $current ) ) {
			return false;
		}

		return $this->compare_and_swap_state( $current, $lease );
	}

	/**
	 * Boot-safe: run the conversion at most once unless it is still pending
	 * or a stale running lock expired.
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_run(): array {
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

		$owner = $this->new_owner_token();
		if ( ! $this->try_claim_owner( $owner ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
				'state'        => $this->current_state(),
			];
		}

		return $this->run( false, $owner );
	}

	/**
	 * Explicit/on-demand reconciliation. Completing the first upgrade pass
	 * does not prevent later administrator-triggered review.
	 *
	 * @return array<string, mixed>
	 */
	public function reconcile( bool $force = true ): array {
		$owner = $this->new_owner_token();
		if ( ! $this->try_claim_owner( $owner ) && ! $force ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
				'state'        => $this->current_state(),
			];
		}
		if ( $force ) {
			$state = $this->current_state();
			if ( ! $this->try_claim_owner( $owner ) ) {
				$this->compare_and_swap_state(
					$state,
					$this->lease_payload( $owner, time(), self::STATUS_RUNNING, (int) ( $state['last_zone_id'] ?? 0 ) )
				);
			}
		}

		return $this->run( $force, $owner );
	}

	/**
	 * @return array{countries:list<string>,bootstrapped:int,migration:array<string,mixed>,state?:array<string,mixed>}
	 */
	public function run( bool $force = false, string $owner = '' ): array {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'coverage_tables_missing' ],
			];
		}

		$owner = '' !== $owner ? $owner : $this->new_owner_token();
		$state = $this->current_state();
		$after = (int) ( $state['last_zone_id'] ?? 0 );
		$this->store_state(
			array_merge(
				$state,
				$this->lease_payload( $owner, time(), self::STATUS_RUNNING, $after )
			)
		);

		$codes        = $this->referenced_country_codes();
		$bootstrapped = 0;
		foreach ( $codes as $code ) {
			$result = $this->woo_bootstrap->bootstrap_country( $code );
			$bootstrapped += (int) ( $result['created'] ?? 0 );
		}

		$migration = $this->migrator->migrate( $force, $after );
		$last_id   = (int) ( $migration['last_zone_id'] ?? $after );
		$complete  = ! empty( $migration['complete'] );
		$state     = array_merge(
			$this->lease_payload( $owner, time(), $complete ? self::STATUS_COMPLETED : self::STATUS_RUNNING, $last_id ),
			[
				'completed_at'    => $complete ? time() : 0,
				'countries'       => $codes,
				'bootstrapped'    => $bootstrapped,
				'review_required' => (int) ( $migration['review_required'] ?? 0 ),
				'converted'       => (int) ( $migration['converted'] ?? 0 ),
				'warnings'        => $migration['warnings'] ?? [],
			]
		);
		$this->store_state( $state );

		return [
			'countries'    => $codes,
			'bootstrapped' => $bootstrapped,
			'migration'    => $migration,
			'state'        => $state,
		];
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
	 * @param array<string, mixed> $state
	 */
	private function store_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $state, false );
		}
	}

	/**
	 * @param array<string, mixed> $expected
	 * @param array<string, mixed> $replacement
	 */
	private function compare_and_swap_state( array $expected, array $replacement ): bool {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'update' ) ) {
			$updated = $wpdb->update(
				(string) $wpdb->options,
				[ 'option_value' => serialize( $replacement ) ],
				[
					'option_name'  => self::OPTION_KEY,
					'option_value' => serialize( $expected ),
				]
			);
			if ( is_int( $updated ) && $updated > 0 ) {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( self::OPTION_KEY, 'options' );
				}
				$GLOBALS['cetech_de_test_options'][ self::OPTION_KEY ] = $replacement;

				return true;
			}
			if ( false !== $updated && 0 !== $updated ) {
				return false;
			}
		}

		$current = $this->current_state();
		if ( serialize( $current ) !== serialize( $expected ) && [] !== $expected && $current !== $expected ) {
			return false;
		}
		$this->store_state( $replacement );

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function lease_payload( string $owner, int $now, string $status, int $last_zone_id ): array {
		return [
			'status'            => $status,
			'owner'             => $owner,
			'lease_owner'       => $owner,
			'lease_acquired_at' => $now,
			'lease_expires_at'  => $now + self::LEASE_TTL,
			'started_at'        => $now,
			'last_zone_id'      => max( 0, $last_zone_id ),
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

	private function new_owner_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return uniqid( 'schema6-', true );
		}
	}
}
