<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Infrastructure\WooCommerce\Destination\WooCommerceStateCatalog;
use PHPUnit\Framework\TestCase;

final class WooCommerceStateCatalogTest extends TestCase {

	public function test_returns_empty_map_when_woocommerce_is_unavailable(): void {
		$catalog = new WooCommerceStateCatalog();

		self::assertSame( [], $catalog->states_for_country( 'GH' ) );
		self::assertSame( [], $catalog->states_for_country( '' ) );
	}
}
