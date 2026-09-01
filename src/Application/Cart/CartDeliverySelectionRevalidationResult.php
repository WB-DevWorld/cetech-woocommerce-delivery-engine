<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

/**
 * Outcome of revalidating a cart line delivery selection (no private data).
 */
final class CartDeliverySelectionRevalidationResult {

	public const STATUS_VALID = 'valid';

	public const STATUS_STALE = 'stale';

	public const STATUS_UNAVAILABLE = 'unavailable';

	public const STATUS_INVALID = 'invalid';

	public const STATUS_MISSING = 'missing';

	public const STATUS_NEEDS_RESELECTION = 'needs_reselection';

	/**
	 * @param array<string, mixed>|null $stored_intent
	 * @param array<string, mixed>|null $current_intent Safe intent array when revalidation succeeded
	 */
	public function __construct(
		public readonly string $cart_item_key,
		public readonly string $status,
		public readonly string $message,
		public readonly ?array $stored_intent,
		public readonly ?array $current_intent
	) {
	}

	public function isActionable(): bool {
		return self::STATUS_VALID !== $this->status && self::STATUS_MISSING !== $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'cart_item_key'  => $this->cart_item_key,
			'status'         => $this->status,
			'message'        => $this->message,
			'stored_intent'  => $this->stored_intent,
			'current_intent' => $this->current_intent,
		];
	}
}
