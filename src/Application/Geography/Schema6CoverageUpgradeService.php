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
 */
final class Schema6CoverageUpgradeService {

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
	 * @return array{countries:list<string>,bootstrapped:int,migration:array<string,mixed>}
	 */
	public function run(): array {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return [
				'countries'    => [],
				'bootstrapped' => 0,
				'migration'    => [ 'skipped' => true, 'reason' => 'coverage_tables_missing' ],
			];
		}

		$codes        = $this->referenced_country_codes();
		$bootstrapped = 0;
		foreach ( $codes as $code ) {
			$result = $this->woo_bootstrap->bootstrap_country( $code );
			$bootstrapped += (int) ( $result['created'] ?? 0 );
		}

		return [
			'countries'    => $codes,
			'bootstrapped' => $bootstrapped,
			'migration'    => $this->migrator->migrate(),
		];
	}
}
