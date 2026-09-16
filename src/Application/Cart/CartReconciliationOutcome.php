<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

/**
 * Result of reconciling one managed cart line against live configuration.
 */
final class CartReconciliationOutcome {

	public const ACTION_UNCHANGED = 'unchanged';

	public const ACTION_REFRESHED = 'refreshed';

	public const ACTION_NEEDS_RESELECTION = 'needs_reselection';

	public const ACTION_UNMANAGED = 'unmanaged';

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function __construct(
		public readonly string $cart_item_key,
		public readonly string $action,
		public readonly array $cart_item,
		public readonly bool $changed,
		public readonly string $status,
		public readonly string $message,
		public readonly string $product_name = ''
	) {
	}

	public function needsCustomerReselection(): bool {
		return self::ACTION_NEEDS_RESELECTION === $this->action;
	}
}
