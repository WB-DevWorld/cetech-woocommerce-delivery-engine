<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Exports reusable Delivery Engine configuration by stable internal_code.
 * Does not export orders, shipments, audit, jobs, secrets, or runtime flags.
 */
final class ConfigurationExporter {

	public function __construct(
		private readonly DeliveryOfferRepositoryInterface $offers,
		private readonly DestinationZoneRepositoryInterface $zones,
		private readonly DestinationRuleRepositoryInterface $rules,
		private readonly RateCardRepositoryInterface $rates,
		private readonly LogisticsProfileRepositoryInterface $logistics,
		private readonly PickupLocationRepositoryInterface $pickups,
		private readonly SupplierRepositoryInterface $suppliers,
		private readonly OriginRepositoryInterface $origins,
		private readonly ?SiteWideDefaultsSettings $settings = null,
		private readonly ?ScopedConfigurationRepositoryInterface $scopes = null
	) {
	}

	/**
	 * @param list<string> $sections
	 */
	public function export( array $sections, bool $include_private_sources, bool $can_export_private ): ConfigurationPackage {
		$payload = [];
		$wanted  = [] === $sections ? $this->default_sections( $include_private_sources && $can_export_private ) : $sections;

		foreach ( $wanted as $section ) {
			if ( in_array( $section, [ 'suppliers', 'origins' ], true ) ) {
				if ( ! $include_private_sources || ! $can_export_private ) {
					continue;
				}
			}
			if ( in_array( $section, [ 'orders', 'shipments', 'audit_log', 'bulk_jobs', 'feature_flags' ], true ) ) {
				continue;
			}
			$payload[ $section ] = $this->export_section( $section );
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';

		return ConfigurationPackage::create( $version, SchemaVersion::target(), $payload, $include_private_sources && $can_export_private );
	}

	/**
	 * @return list<string>
	 */
	public function default_sections( bool $private ): array {
		$sections = [
			'site_wide_defaults',
			'profile_defaults',
			'delivery_options',
			'delivery_areas',
			'delivery_area_rules',
			'rate_cards',
			'logistics_profiles',
			'pickup_locations',
		];
		if ( $private ) {
			$sections[] = 'suppliers';
			$sections[] = 'origins';
		}

		return $sections;
	}

	/**
	 * @return array<string, mixed>|list<array<string, mixed>>
	 */
	private function export_section( string $section ): array {
		if ( 'site_wide_defaults' === $section ) {
			return $this->settings instanceof SiteWideDefaultsSettings
				? [
					'active_profiles' => $this->settings->active_profile_keys(),
					'primary_profile' => $this->settings->primary_profile_key(),
				]
				: [];
		}
		if ( 'profile_defaults' === $section ) {
			return $this->export_profile_defaults();
		}

		$repository = match ( $section ) {
			'delivery_options'    => $this->offers,
			'delivery_areas'      => $this->zones,
			'delivery_area_rules' => $this->rules,
			'rate_cards'          => $this->rates,
			'logistics_profiles'  => $this->logistics,
			'pickup_locations'    => $this->pickups,
			'suppliers'           => $this->suppliers,
			'origins'             => $this->origins,
			default               => null,
		};

		if ( null === $repository ) {
			return [];
		}

		$exported = [];
		foreach ( EntityKeysetPager::iterate( $repository ) as $row ) {
			$exported[] = $this->portable_row( $section, $row );
		}

		return $exported;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function export_profile_defaults(): array {
		if ( ! $this->scopes instanceof ScopedConfigurationRepositoryInterface ) {
			return [];
		}
		$profiles = $this->scopes->findByScope( ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID );
		$out      = [];
		foreach ( $profiles as $config ) {
			if ( ! $config instanceof ScopedConfiguration ) {
				continue;
			}
			if ( ConfigurationScope::DEFAULT_SLICE_KEY === $config->scope->slice_key ) {
				continue;
			}
			$scalars = [];
			foreach ( $config->scalars as $key => $instruction ) {
				$stored = $instruction->toStorageArray();
				if ( in_array( $key, [ ConfigurationFieldKey::LOGISTICS_PROFILE_ID, ConfigurationFieldKey::SUPPLIER_ID, ConfigurationFieldKey::ORIGIN_ID ], true ) ) {
					$id = (int) ( $stored['value'] ?? 0 );
					$kind = match ( $key ) {
						ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'logistics',
						ConfigurationFieldKey::SUPPLIER_ID => 'supplier',
						default => 'origin',
					};
					$stored['value'] = $this->code_for( $kind, $id );
				}
				$scalars[ $key ] = $stored;
			}
			$collections = [];
			foreach ( $config->collections as $key => $instruction ) {
				$stored = $instruction->toStorageArray();
				$codes  = [];
				foreach ( (array) ( $stored['members'] ?? [] ) as $member ) {
					$codes[] = $this->code_for( 'offer', (int) $member );
				}
				unset( $stored['members'] );
				$stored['member_codes'] = array_values( array_filter( $codes ) );
				$collections[ $key ]    = $stored;
			}
			$out[] = [
				'profile_key'  => $config->scope->slice_key,
				'scalars'      => $scalars,
				'collections'  => $collections,
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function portable_row( string $section, array $row ): array {
		unset( $row['id'] );
		foreach ( [ 'password', 'api_key', 'secret', 'token', 'credentials' ] as $secret ) {
			unset( $row[ $secret ] );
		}

		if ( 'rate_cards' === $section ) {
			$row['delivery_offer_code']    = $this->code_for( 'offer', (int) ( $row['delivery_offer_id'] ?? 0 ) );
			$row['destination_zone_code']  = $this->code_for( 'zone', (int) ( $row['destination_zone_id'] ?? 0 ) );
			$row['logistics_profile_code'] = $this->code_for( 'logistics', (int) ( $row['logistics_profile_id'] ?? 0 ) );
			$row['supplier_code']          = $this->code_for( 'supplier', (int) ( $row['supplier_id'] ?? 0 ) );
			$row['origin_code']            = $this->code_for( 'origin', (int) ( $row['origin_id'] ?? 0 ) );
			unset( $row['delivery_offer_id'], $row['destination_zone_id'], $row['logistics_profile_id'], $row['supplier_id'], $row['origin_id'] );
		}

		if ( 'delivery_area_rules' === $section ) {
			$row['zone_code'] = $this->code_for( 'zone', (int) ( $row['zone_id'] ?? 0 ) );
			unset( $row['zone_id'] );
		}

		return $row;
	}

	private function code_for( string $kind, int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$row = match ( $kind ) {
			'offer' => $this->offers->findById( $id ),
			'zone' => $this->zones->findById( $id ),
			'logistics' => $this->logistics->findById( $id ),
			'supplier' => $this->suppliers->findById( $id ),
			'origin' => $this->origins->findById( $id ),
			'pickup' => $this->pickups->findById( $id ),
			default => null,
		};

		return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
	}
}
