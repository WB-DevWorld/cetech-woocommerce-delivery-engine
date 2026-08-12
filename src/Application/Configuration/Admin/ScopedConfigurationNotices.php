<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Immutable notices for delivery-settings admin screens.
 */
final class ScopedConfigurationNotices {

	public const TRANSITIONAL_TITLE = 'These settings are stored, but customers still use the previous rules';

	public const TRANSITIONAL_MESSAGE = 'You can edit and preview the new inherited delivery settings here. Shoppers continue to use the Legacy Delivery Rules until the New Delivery Settings System is turned on in Settings → Advanced.';

	public const PREVIEW_LIMITATION_TITLE = 'This preview shows delivery settings, not a shipping price';

	public const PREVIEW_LIMITATION_MESSAGE = 'This page shows which delivery settings would apply for the selected product, variation, and delivery setup. It does not check the customer’s address, calculate a fee, or complete checkout.';

	public const HARD_CONSTRAINT_NOTE = 'Some fulfilment limits are applied later, when a shopper actually chooses a delivery option.';

	public const CATEGORY_WARNING_TITLE = 'This product still uses a legacy category rule';

	public const CATEGORY_WARNING_MESSAGE = 'This product still gets its delivery settings from a legacy category rule. The new delivery settings system will not take control of this product until that legacy dependency is resolved.';

	public const LEGACY_RUNTIME_LABEL = 'Currently used for shoppers: Legacy Delivery Rules';

	public const SCOPED_RUNTIME_LABEL = 'Stored here: new inherited delivery settings (not yet used for shoppers)';
}
