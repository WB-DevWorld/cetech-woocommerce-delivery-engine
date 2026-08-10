<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Immutable transitional / quarantine notices for Stage 4 admin screens.
 */
final class ScopedConfigurationNotices {

	public const TRANSITIONAL_TITLE = 'Pre-cutover scoped configuration';

	public const TRANSITIONAL_MESSAGE = 'Scoped configuration is currently in pre-cutover mode. Changes made here are stored and can be previewed, but the current customer-facing Delivery Engine runtime continues to use the legacy RC configuration until the runtime migration is completed.';

	public const PREVIEW_LIMITATION_TITLE = 'Effective configuration preview only';

	public const PREVIEW_LIMITATION_MESSAGE = 'Configuration inheritance has been resolved for this product, variation, and slice. Destination eligibility, rate calculation, checkout selection, and full fulfilment hard-constraint evaluation are not included. This is not a final shipping quote.';

	public const HARD_CONSTRAINT_NOTE = 'Stage 3 hard fulfilment constraints currently use a pass-through service; downstream constraints are not evaluated here.';

	public const CATEGORY_WARNING_TITLE = 'Legacy category configuration present';

	public const CATEGORY_WARNING_MESSAGE = 'One or more legacy category product rules may affect this product under the current RC runtime. Category rules are not represented in the new Global → Product → Variation scoped resolver. Stage 5 must explicitly address parity before runtime cutover.';

	public const LEGACY_RUNTIME_LABEL = 'Legacy Product Rules (current RC runtime)';

	public const SCOPED_RUNTIME_LABEL = 'Scoped Configuration (v3 storage — pre-cutover)';
}
