<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Distinguishes PDP locality selection from WooCommerce destination resolution.
 *
 * ShopperSelector: a usable locality pack makes free-text City/Town search input,
 * not authoritative delivery identity. A canonical key is required for locality quotes.
 *
 * WooCommerceDestination: Classic/Blocks/API destinations may resolve by exact
 * canonical name or exact active alias. Never fuzzy.
 */
enum CanonicalResolutionContext: string {

	case ShopperSelector = 'shopper_selector';
	case WooCommerceDestination = 'woocommerce_destination';
}
