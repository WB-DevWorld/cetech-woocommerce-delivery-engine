<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Imports a portable configuration package by stable internal_code.
 * Never writes orders, shipments, audit, jobs, secrets, or runtime feature flags.
 */
final class ConfigurationImporter {

	/** @var list<string> */
	public const SECTION_ORDER = [
		'delivery_options',
		'delivery_areas',
		'logistics_profiles',
		'pickup_locations',
		'suppliers',
		'origins',
		'delivery_area_rules',
		'rate_cards',
		'site_wide_defaults',
		'profile_defaults',
	];

	public function __construct(
		private readonly DeliveryOfferRepositoryInterface $offers,
		private readonly DestinationZoneRepositoryInterface $zones,
		private readonly DestinationRuleRepositoryInterface $rules,
		private readonly RateCardRepositoryInterface $rates,
		private readonly LogisticsProfileRepositoryInterface $logistics,
		private readonly PickupLocationRepositoryInterface $pickups,
		private readonly SupplierRepositoryInterface $suppliers,
		private readonly OriginRepositoryInterface $origins,
		private readonly ?ScopedConfigurationRepositoryInterface $scopes = null,
		private readonly ?SiteWideDefaultsSettings $settings = null
	) {
	}

	/**
	 * @return list<array{section: string, code: string, row: array<string, mixed>}>
	 */
	public function flatten( ConfigurationPackage $package ): array {
		$items = [];
		foreach ( self::SECTION_ORDER as $section ) {
			$rows = $package->sections[ $section ] ?? null;
			if ( ! is_array( $rows ) ) {
				continue;
			}
			if ( 'site_wide_defaults' === $section ) {
				$items[] = [
					'section' => $section,
					'code'    => 'site_wide_defaults',
					'row'     => is_array( $rows ) ? $rows : [],
				];
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$code = (string) ( $row['internal_code'] ?? $row['profile_key'] ?? $row['zone_code'] ?? '' );
				if ( '' === $code && 'delivery_area_rules' === $section ) {
					$code = (string) ( $row['zone_code'] ?? '' ) . ':' . (string) ( $row['rule_type'] ?? '' ) . ':' . (string) ( $row['rule_value'] ?? '' );
				}
				$items[] = [
					'section' => $section,
					'code'    => $code,
					'row'     => $row,
				];
			}
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	public function apply_item( string $section, array $row, ConfigImportConflictMode $mode, bool $dry_run, bool $allow_private ): array {
		if ( in_array( $section, [ 'suppliers', 'origins' ], true ) && ! $allow_private ) {
			return [
				'outcome'       => 'skipped',
				'error_code'    => 'private_source_omitted',
				'error_summary' => 'Private supplier/origin rows were omitted.',
			];
		}

		if ( in_array( $section, [ 'orders', 'shipments', 'audit_log', 'bulk_jobs', 'feature_flags' ], true ) ) {
			return [
				'outcome'       => 'skipped',
				'error_code'    => 'non_portable_section',
				'error_summary' => 'Transactional history and runtime flags are not imported.',
			];
		}

		try {
			return match ( $section ) {
				'delivery_options' => $this->upsert_coded( $this->offers, $row, $mode, $dry_run ),
				'delivery_areas' => $this->upsert_coded( $this->zones, $row, $mode, $dry_run ),
				'logistics_profiles' => $this->upsert_coded( $this->logistics, $row, $mode, $dry_run ),
				'pickup_locations' => $this->upsert_coded( $this->pickups, $row, $mode, $dry_run ),
				'suppliers' => $this->upsert_coded( $this->suppliers, $row, $mode, $dry_run ),
				'origins' => $this->upsert_coded( $this->origins, $row, $mode, $dry_run ),
				'rate_cards' => $this->upsert_rate_card( $row, $mode, $dry_run ),
				'delivery_area_rules' => $this->upsert_rule( $row, $mode, $dry_run ),
				'site_wide_defaults' => $this->upsert_site_wide( $row, $mode, $dry_run ),
				'profile_defaults' => $this->upsert_profile_default( $row, $mode, $dry_run ),
				default => [
					'outcome'       => 'skipped',
					'error_code'    => 'unknown_section',
					'error_summary' => 'Unknown configuration section.',
				],
			};
		} catch ( \Throwable $exception ) {
			return [
				'outcome'       => 'failed',
				'error_code'    => 'import_failed',
				'error_summary' => $exception->getMessage(),
			];
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function upsert_coded( object $repository, array $row, ConfigImportConflictMode $mode, bool $dry_run ): array {
		$code = trim( (string) ( $row['internal_code'] ?? '' ) );
		if ( '' === $code ) {
			return $this->fail( 'missing_internal_code', 'A stable internal code is required.' );
		}
		if ( ! method_exists( $repository, 'findByCode' ) || ! method_exists( $repository, 'save' ) ) {
			return $this->fail( 'unsupported_repository', 'This section cannot be imported.' );
		}

		$payload = $row;
		unset( $payload['id'] );
		$existing = $repository->findByCode( $code );

		if ( ! is_array( $existing ) ) {
			if ( ConfigImportConflictMode::SkipConflicts === $mode ) {
				// Missing rows are added even in skip-conflicts; skip only applies to matches.
			}
			if ( $dry_run ) {
				return $this->changed();
			}
			$id = (int) $repository->save( $payload );

			return $id > 0 ? $this->changed() : $this->fail( 'save_failed', 'Unable to add configuration row.' );
		}

		if ( ConfigImportConflictMode::SkipConflicts === $mode || ConfigImportConflictMode::AddMissing === $mode ) {
			return $this->skipped( 'conflict_skipped', 'An entity with this code already exists.' );
		}

		$payload['id'] = (int) ( $existing['id'] ?? 0 );
		if ( $dry_run ) {
			return $this->changed();
		}
		$id = (int) $repository->save( $payload );

		return $id > 0 ? $this->changed() : $this->fail( 'save_failed', 'Unable to update configuration row.' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function upsert_rate_card( array $row, ConfigImportConflictMode $mode, bool $dry_run ): array {
		$offer = $this->offers->findByCode( (string) ( $row['delivery_offer_code'] ?? '' ) );
		$zone  = $this->zones->findByCode( (string) ( $row['destination_zone_code'] ?? '' ) );
		if ( ! is_array( $offer ) || ! is_array( $zone ) ) {
			return $this->fail( 'missing_reference', 'Rate Card references an unknown Delivery Option or Delivery Area code.' );
		}
		$row['delivery_offer_id']   = (int) ( $offer['id'] ?? 0 );
		$row['destination_zone_id'] = (int) ( $zone['id'] ?? 0 );
		if ( ! empty( $row['logistics_profile_code'] ) ) {
			$profile = $this->logistics->findByCode( (string) $row['logistics_profile_code'] );
			$row['logistics_profile_id'] = is_array( $profile ) ? (int) ( $profile['id'] ?? 0 ) : null;
		}
		if ( ! empty( $row['supplier_code'] ) ) {
			$supplier = $this->suppliers->findByCode( (string) $row['supplier_code'] );
			$row['supplier_id'] = is_array( $supplier ) ? (int) ( $supplier['id'] ?? 0 ) : null;
		}
		if ( ! empty( $row['origin_code'] ) ) {
			$origin = $this->origins->findByCode( (string) $row['origin_code'] );
			$row['origin_id'] = is_array( $origin ) ? (int) ( $origin['id'] ?? 0 ) : null;
		}
		unset( $row['delivery_offer_code'], $row['destination_zone_code'], $row['logistics_profile_code'], $row['supplier_code'], $row['origin_code'] );

		return $this->upsert_coded( $this->rates, $row, $mode, $dry_run );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function upsert_rule( array $row, ConfigImportConflictMode $mode, bool $dry_run ): array {
		$zone = $this->zones->findByCode( (string) ( $row['zone_code'] ?? '' ) );
		if ( ! is_array( $zone ) ) {
			return $this->fail( 'missing_reference', 'Delivery Area rule references an unknown area code.' );
		}
		$zone_id = (int) ( $zone['id'] ?? 0 );
		$existing = $this->rules->listByZoneId( $zone_id );
		$incoming = [
			'rule_type'  => (string) ( $row['rule_type'] ?? '' ),
			'rule_value' => (string) ( $row['rule_value'] ?? '' ),
			'match_mode' => (string) ( $row['match_mode'] ?? 'exact' ),
			'priority'   => (int) ( $row['priority'] ?? 100 ),
		];
		$already = false;
		foreach ( $existing as $rule ) {
			if ( (string) ( $rule['rule_type'] ?? '' ) === $incoming['rule_type'] && (string) ( $rule['rule_value'] ?? '' ) === $incoming['rule_value'] ) {
				$already = true;
				break;
			}
		}
		if ( $already && ( ConfigImportConflictMode::SkipConflicts === $mode || ConfigImportConflictMode::AddMissing === $mode ) ) {
			return $this->skipped( 'conflict_skipped', 'This Delivery Area already has that rule.' );
		}
		if ( $dry_run ) {
			return $this->changed();
		}
		$next = $existing;
		if ( ConfigImportConflictMode::Replace === $mode ) {
			$next = [ $incoming ];
		} elseif ( ! $already ) {
			$next[] = $incoming;
		} else {
			foreach ( $next as $i => $rule ) {
				if ( (string) ( $rule['rule_type'] ?? '' ) === $incoming['rule_type'] && (string) ( $rule['rule_value'] ?? '' ) === $incoming['rule_value'] ) {
					$next[ $i ] = $incoming;
				}
			}
		}
		$ok = $this->rules->replaceForZone( $zone_id, $next );

		return $ok ? $this->changed() : $this->fail( 'save_failed', 'Unable to save Delivery Area rules.' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function upsert_site_wide( array $row, ConfigImportConflictMode $mode, bool $dry_run ): array {
		if ( ! $this->settings instanceof SiteWideDefaultsSettings ) {
			return $this->skipped( 'site_wide_unavailable', 'Site-wide defaults are not available on this install.' );
		}
		$current = $this->settings->read();
		if ( $current['setup_completed'] && ConfigImportConflictMode::SkipConflicts === $mode ) {
			return $this->skipped( 'conflict_skipped', 'Site-wide defaults already exist.' );
		}
		if ( $dry_run ) {
			return $this->changed();
		}
		$active  = is_array( $row['active_profiles'] ?? null ) ? $row['active_profiles'] : [];
		$primary = (string) ( $row['primary_profile'] ?? '' );
		$this->settings->save(
			[
				'active_profiles' => $active,
				'primary_profile' => $primary,
				'setup_completed' => false,
			]
		);

		return $this->changed();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function upsert_profile_default( array $row, ConfigImportConflictMode $mode, bool $dry_run ): array {
		if ( ! $this->scopes instanceof ScopedConfigurationRepositoryInterface ) {
			return $this->skipped( 'profile_defaults_unavailable', 'Profile defaults cannot be imported here.' );
		}
		$key = sanitize_key( (string) ( $row['profile_key'] ?? '' ) );
		if ( '' === $key || ! FulfilmentProfileRegistry::has( $key ) ) {
			return $this->fail( 'unknown_profile', 'Unknown fulfilment profile key.' );
		}
		$existing = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$key
		);
		if ( $existing instanceof ScopedConfiguration && ( ConfigImportConflictMode::SkipConflicts === $mode || ConfigImportConflictMode::AddMissing === $mode ) ) {
			return $this->skipped( 'conflict_skipped', 'Profile defaults already exist for this fulfilment profile.' );
		}

		$scalars     = [];
		$collections = [];
		foreach ( (array) ( $row['scalars'] ?? [] ) as $field_key => $instruction ) {
			if ( ! is_array( $instruction ) ) {
				continue;
			}
			$field_key = (string) $field_key;
			$mode_key  = (string) ( $instruction['mode'] ?? ScalarConfigurationMode::Override->value );
			$value     = $instruction['value'] ?? null;
			if ( in_array( $field_key, [ ConfigurationFieldKey::LOGISTICS_PROFILE_ID, ConfigurationFieldKey::SUPPLIER_ID, ConfigurationFieldKey::ORIGIN_ID ], true ) && is_string( $value ) && ! ctype_digit( $value ) ) {
				$resolved = ( new EntityCodeResolver( $this->offers, $this->logistics, $this->suppliers, $this->origins, $this->pickups ) )->resolve_scalar_value( $field_key, $value );
				$value    = $resolved;
			}
			$scalars[ $field_key ] = ScalarFieldInstruction::fromStorage( $field_key, $mode_key, $value, (string) ( $instruction['value_type'] ?? 'string' ) );
		}
		foreach ( (array) ( $row['collections'] ?? [] ) as $field_key => $instruction ) {
			if ( ! is_array( $instruction ) ) {
				continue;
			}
			$field_key = (string) $field_key;
			$codes     = is_array( $instruction['member_codes'] ?? null ) ? $instruction['member_codes'] : ( $instruction['members'] ?? [] );
			$ids       = ( new EntityCodeResolver( $this->offers ) )->resolve_offer_ids( is_array( $codes ) ? $codes : [] );
			$collections[ $field_key ] = CollectionFieldInstruction::fromStorage(
				$field_key,
				(string) ( $instruction['mode'] ?? CollectionConfigurationMode::Replace->value ),
				$ids
			);
		}

		if ( $dry_run ) {
			return $this->changed();
		}

		$scope = $existing instanceof ScopedConfiguration
			? $existing->scope
			: ConfigurationScope::profileDefault( $key );
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration( $scope, $scalars, $collections ) );

		return $this->changed();
	}

	/**
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function changed(): array {
		return [
			'outcome'       => 'changed',
			'error_code'    => null,
			'error_summary' => null,
		];
	}

	/**
	 * Post-import inventory used for sister-site health checks.
	 * Does not activate runtime flags or mark setup complete.
	 *
	 * @return array<string, mixed>
	 */
	public function health_report(): array {
		$counts = [];
		$map    = [
			'delivery_options'    => $this->offers,
			'delivery_areas'      => $this->zones,
			'delivery_area_rules' => $this->rules,
			'logistics_profiles'  => $this->logistics,
			'pickup_locations'    => $this->pickups,
			'suppliers'           => $this->suppliers,
			'origins'             => $this->origins,
			'rate_cards'          => $this->rates,
		];
		foreach ( $map as $section => $repository ) {
			$counts[ $section ] = method_exists( $repository, 'count_all' ) ? (int) $repository->count_all() : 0;
		}
		$settings = $this->settings instanceof SiteWideDefaultsSettings ? $this->settings->read() : [];

		return [
			'counts'          => $counts,
			'setup_completed' => (bool) ( $settings['setup_completed'] ?? false ),
			'runtime_flags'   => [
				'enable_product_delivery_selector'             => function_exists( 'get_option' ) ? (int) get_option( 'cetech_de_enable_product_delivery_selector', 0 ) : 0,
				'enable_woocommerce_shipping_rate_calculation' => function_exists( 'get_option' ) ? (int) get_option( 'cetech_de_enable_woocommerce_shipping_rate_calculation', 0 ) : 0,
				'enable_shipment_records'                      => function_exists( 'get_option' ) ? (int) get_option( 'cetech_de_enable_shipment_records', 0 ) : 0,
			],
		];
	}

	/**
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function skipped( string $code, string $summary ): array {
		return [
			'outcome'       => 'skipped',
			'error_code'    => $code,
			'error_summary' => $summary,
		];
	}

	/**
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	private function fail( string $code, string $summary ): array {
		return [
			'outcome'       => 'failed',
			'error_code'    => $code,
			'error_summary' => $summary,
		];
	}
}
