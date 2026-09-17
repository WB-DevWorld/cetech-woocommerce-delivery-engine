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
 * Durable state prevents scanning every storefront request after the
 * initial conversion completes. Administrators may still request
 * explicit reconciliation later.
 */
final class Schema6CoverageUpgradeService {

	public const OPTION_KEY = 'cetech_de_schema6_coverage_upgrade';

	public const STATUS_PENDING = 'pending';

	public const STATUS_RUNNING = 'running';

	public const STATUS_COMPLETED = 'completed';

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
		foreach ( $this->zones->list( [ 'limit' => 5000 ] ) as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );
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

		return array_values( $codes );
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

		if ( self::STATUS_RUNNING === (string) ( $state['status'] ?? '' ) && ! $this->lock_expired( $state ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'running' ],
				'state'        => $state,
			];
		}

		return $this->run( false );
	}

	/**
	 * Explicit/on-demand reconciliation. Completing the first upgrade pass
	 * does not prevent later administrator-triggered review.
	 *
	 * @return array<string, mixed>
	 */
	public function reconcile( bool $force = true ): array {
		return $this->run( $force );
	}

	/**
	 * @return array{countries:list<string>,bootstrapped:int,migration:array<string,mixed>,state?:array<string,mixed>}
	 */
	public function run( bool $force = false ): array {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'coverage_tables_missing' ],
			];
		}

		$this->store_state(
			[
				'status'     => self::STATUS_RUNNING,
				'started_at' => time(),
				'force'      => $force,
			]
		);

		$codes        = $this->referenced_country_codes();
		$bootstrapped = 0;
		foreach ( $codes as $code ) {
			$result = $this->woo_bootstrap->bootstrap_country( $code );
			$bootstrapped += (int) ( $result['created'] ?? 0 );
		}

		$migration = $this->migrator->migrate( $force );
		$state     = [
			'status'           => self::STATUS_COMPLETED,
			'completed_at'     => time(),
			'countries'        => $codes,
			'bootstrapped'     => $bootstrapped,
			'review_required'  => (int) ( $migration['review_required'] ?? 0 ),
			'converted'        => (int) ( $migration['converted'] ?? 0 ),
		];
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
	 * @param array<string, mixed> $state
	 */
	private function lock_expired( array $state ): bool {
		$started = (int) ( $state['started_at'] ?? 0 );

		return $started <= 0 || ( time() - $started ) > 300;
	}
}
