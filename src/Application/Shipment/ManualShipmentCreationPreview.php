<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Read-only staff preview before manual shipment creation from a historical order.
 */
final class ManualShipmentCreationPreview {

	public const CODE_READY             = 'ready';
	public const CODE_ALREADY_EXISTS    = 'already_exists';
	public const CODE_PICKUP_ONLY       = 'pickup_only';
	public const CODE_ORDER_NOT_FOUND   = 'order_not_found';
	public const CODE_NO_SNAPSHOT       = 'no_snapshot';
	public const CODE_INELIGIBLE        = 'ineligible';
	public const CODE_FEATURE_DISABLED  = 'feature_disabled';
	public const CODE_INVALID_SNAPSHOT  = 'invalid_snapshot';

	/**
	 * @param list<ManualShipmentPreviewGroup> $groups
	 * @param list<ManualShipmentPreviewItem>  $items
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly ?int $order_id = null,
		public readonly string $order_number = '',
		public readonly string $customer_name = '',
		public readonly string $order_date = '',
		public readonly string $payment_method_label = '',
		public readonly string $destination_summary = '',
		public readonly string $order_url = '',
		public readonly array $groups = [],
		public readonly array $items = []
	) {
	}

	public function can_create(): bool {
		return self::CODE_READY === $this->code;
	}

	public function is_already_created(): bool {
		return self::CODE_ALREADY_EXISTS === $this->code;
	}
}
