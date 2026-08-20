<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Country-scoped WooCommerce state code → label map for region matching.
 */
interface WooCommerceStateCatalogInterface {

	/**
	 * @return array<string, string> State code => human-readable label for the country.
	 */
	public function states_for_country( string $country_code ): array;
}
