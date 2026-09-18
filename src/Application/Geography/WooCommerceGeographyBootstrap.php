<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

/**
 * Maps WooCommerce country/state catalog into canonical locations.
 */
final class WooCommerceGeographyBootstrap {

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private LocationAliasRepositoryInterface $aliases,
		private ProviderMappingRepositoryInterface $mappings
	) {
	}

	/**
	 * @return array{country:?CanonicalLocation,states:int,created:int}
	 */
	public function bootstrap_country( string $country_code ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$created      = 0;
		$country      = $this->locations->find_country( $country_code, true );
		if ( ! $country instanceof CanonicalLocation ) {
			$label   = $this->country_label( $country_code ) ?: $country_code;
			$country = $this->locations->save(
				new CanonicalLocation(
					0,
					GeographyNameNormalizer::new_location_key(),
					$country_code,
					null,
					GeographyLocationType::Country,
					null,
					$label,
					GeographyNameNormalizer::normalize( $label ),
					GeographyNameNormalizer::fold_ascii( $label ),
					null,
					null,
					RecordStatus::Active,
					''
				)
			);
			$this->locations->update_ancestry_path( $country->id, LocationAncestry::append_path( '', $country->id ) );
			$country = $this->locations->find_by_id( $country->id ) ?? $country;
			$this->mappings->upsert( $country->id, GeographyProvider::WooCommerce, $country_code, null, 'woocommerce', '', 'A', 'PCLI' );
			++$created;
		}

		$states = $this->states( $country_code );
		$state_count = 0;
		foreach ( $states as $code => $label ) {
			$code  = strtoupper( (string) $code );
			$label = (string) $label;
			$existing = $this->locations->find_exact_child(
				$country_code,
				$country->id,
				GeographyNameNormalizer::normalize( $label ),
				GeographyLocationType::Administrative
			);
			if ( ! $existing instanceof CanonicalLocation ) {
				$existing = $this->locations->find_exact_child(
					$country_code,
					$country->id,
					GeographyNameNormalizer::normalize( $code ),
					GeographyLocationType::Administrative
				);
			}
			if ( ! $existing instanceof CanonicalLocation ) {
				$existing = $this->locations->save(
					new CanonicalLocation(
						0,
						GeographyNameNormalizer::new_location_key(),
						$country_code,
						$country->id,
						GeographyLocationType::Administrative,
						1,
						$label,
						GeographyNameNormalizer::normalize( $label ),
						GeographyNameNormalizer::fold_ascii( $label ),
						null,
						null,
						RecordStatus::Active,
						LocationAncestry::append_path( $country->ancestry_path, 0 )
					)
				);
				$this->locations->update_ancestry_path(
					$existing->id,
					LocationAncestry::append_path( $country->ancestry_path, $existing->id )
				);
				$existing = $this->locations->find_by_id( $existing->id ) ?? $existing;
				++$created;
			}
			$this->mappings->upsert(
				$existing->id,
				GeographyProvider::WooCommerce,
				$country_code . ':' . $code,
				null,
				'woocommerce',
				$country_code,
				'A',
				'ADM1'
			);
			if ( GeographyNameNormalizer::normalize( $code ) !== $existing->normalized_name ) {
				$this->aliases->add_alias( $existing->id, $code, GeographyNameNormalizer::normalize( $code ), '', 'code', false );
			}
			++$state_count;
		}

		return [
			'country' => $country,
			'states'  => $state_count,
			'created' => $created,
		];
	}

	private function country_label( string $country_code ): string {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) ) {
			return $country_code;
		}

		$countries = WC()->countries->get_countries();
		if ( is_array( $countries ) && isset( $countries[ $country_code ] ) ) {
			return (string) $countries[ $country_code ];
		}

		return $country_code;
	}

	/**
	 * @return array<string, string>
	 */
	private function states( string $country_code ): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) || ! method_exists( WC()->countries, 'get_states' ) ) {
			return [];
		}

		$states = WC()->countries->get_states( $country_code );

		return is_array( $states ) ? $states : [];
	}
}
