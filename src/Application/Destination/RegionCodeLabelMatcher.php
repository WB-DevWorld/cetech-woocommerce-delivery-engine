<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Matches a Delivery Area region rule to a WooCommerce address region.
 *
 * WooCommerce packages supply canonical state codes. Administrators may save
 * either that code or the human-readable state label. Equivalence is scoped
 * to the supplied country so labels/codes from another country cannot match.
 */
final class RegionCodeLabelMatcher {

	public function __construct(
		private ?WooCommerceStateCatalogInterface $catalog = null
	) {
	}

	public function matches( string $country_code, string $address_region, string $rule_region ): bool {
		$address_region = strtolower( trim( $address_region ) );
		$rule_region    = strtolower( trim( $rule_region ) );

		if ( '' === $address_region || '' === $rule_region ) {
			return false;
		}

		if ( $address_region === $rule_region ) {
			return true;
		}

		return in_array( $rule_region, $this->equivalents( $country_code, $address_region ), true );
	}

	/**
	 * @return list<string>
	 */
	private function equivalents( string $country_code, string $normalized_region ): array {
		$set = [ $normalized_region ];

		if ( null === $this->catalog ) {
			return $set;
		}

		foreach ( $this->catalog->states_for_country( $country_code ) as $code => $label ) {
			$code_key  = strtolower( trim( (string) $code ) );
			$label_key = strtolower( trim( (string) $label ) );

			if ( $normalized_region !== $code_key && ( '' === $label_key || $normalized_region !== $label_key ) ) {
				continue;
			}

			$set[] = $code_key;

			if ( '' !== $label_key ) {
				$set[] = $label_key;
			}
		}

		return array_values( array_unique( $set ) );
	}
}
