<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Immutable notices for delivery-settings admin screens.
 */
final class ScopedConfigurationNotices {

	public const TRANSITIONAL_TITLE = 'Existing delivery configuration is still serving customers';

	public const TRANSITIONAL_MESSAGE = 'You can edit these delivery settings here. Existing delivery configuration is still serving customers while you finish Delivery Engine setup.';

	public const PREVIEW_LIMITATION_TITLE = 'This preview shows delivery settings, not a shipping price';

	public const PREVIEW_LIMITATION_MESSAGE = 'This page shows which delivery settings would apply for the selected product, variation, and delivery setup. It does not check the customer’s address, calculate a fee, or complete checkout.';

	public const HARD_CONSTRAINT_NOTE = 'Some fulfilment limits are applied later, when a shopper actually chooses a delivery option.';

	public const CATEGORY_WARNING_TITLE = 'This product still uses a category delivery rule';

	public const CATEGORY_WARNING_MESSAGE = 'This product still gets its delivery settings from a category rule. Review it before relying on Site-wide Defaults for this product.';

	public const LEGACY_RUNTIME_LABEL = 'Currently used for shoppers: existing delivery configuration';

	public const SCOPED_RUNTIME_LABEL = 'Stored here: Site-wide Defaults and Product Exceptions';

	public const PRODUCT_LEGACY_TITLE = 'This product still uses older delivery settings';

	public const PRODUCT_LEGACY_MESSAGE = 'This product still uses older delivery settings that need review. Customize it from the product Delivery panel if it should follow Site-wide Defaults or a Product Exception.';
}
