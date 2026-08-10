<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Configuration inheritance scopes for Stage 2 storage.
 *
 * Category is intentionally excluded: legacy category rules remain quarantined
 * in product_delivery_rules until a later design decision.
 */
enum ConfigurationScopeType: string {

	case Global = 'global';
	case Product = 'product';
	case Variation = 'variation';
}
