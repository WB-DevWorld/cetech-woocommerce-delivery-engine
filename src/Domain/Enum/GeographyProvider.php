<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum GeographyProvider: string {

	case WooCommerce = 'woocommerce';
	case GeoNames = 'geonames';
}
