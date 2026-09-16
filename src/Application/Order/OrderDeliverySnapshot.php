<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;

/**
 * Snapshot contract version and protected meta keys.
 */
final class OrderDeliverySnapshot {

	public const VERSION = '1';

	public const VERSION_V2 = '2';

	public const META_LINE_SNAPSHOT = '_cetech_de_delivery_snapshot';

	public const META_LINE_SNAPSHOT_VERSION = '_cetech_de_delivery_snapshot_version';

	/**
	 * Transient Store API mapping key written only when the Classic line-item
	 * hook cannot yet build a snapshot (order address not copied). Removed after
	 * the immutable line snapshot is persisted. Not a historical contract field.
	 */
	public const META_CART_ITEM_KEY = '_cetech_de_cart_item_key';

	public const META_ORDER_QUOTE_SNAPSHOT = '_cetech_de_delivery_quote_snapshot';

	public const META_ORDER_SNAPSHOT_VERSION = '_cetech_de_order_delivery_snapshot_version';

	public const QUOTE_STATUS_QUOTED = 'quoted';

	public const QUOTE_STATUS_SELECTION_ONLY = 'selection_only';

	public const QUOTE_STATUS_SKIPPED = 'skipped';

	public const PACKAGE_STATUS_SUCCESS = 'success';

	public const PACKAGE_STATUS_NOT_APPLICABLE = 'not_applicable';

	public const PACKAGE_STATUS_FAILURE = 'failure';
}

/**
 * Per order line delivery snapshot (protected meta JSON).
 */
final class OrderDeliveryLineSnapshot {

	public function __construct(
		public readonly string $contract_version,
		public readonly string $snapshot_version,
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_choice,
		public readonly ?int $delivery_offer_id,
		public readonly ?string $delivery_offer_public_label,
		public readonly ?string $delivery_offer_public_description,
		public readonly ?string $estimate_text,
		public readonly ?int $rule_id,
		public readonly ?int $destination_zone_id,
		public readonly int $quantity,
		public readonly string $currency_code,
		public readonly ?string $quoted_amount,
		public readonly string $quote_status,
		public readonly ?int $rate_card_id,
		public readonly ?string $rate_card_code,
		public readonly string $snapshotted_at,
		public readonly ?string $delivery_group_id = null,
		public readonly ?string $pickup_location_label = null,
		public readonly ?string $pickup_address = null,
		public readonly ?string $pickup_instructions = null,
		public readonly ?int $customer_context_version = null,
		public readonly ?array $matching_location = null,
		public readonly ?array $delivery_address = null,
		public readonly ?string $matching_identity = null,
		public readonly ?string $delivery_location_identity = null,
		public readonly ?int $pickup_location_id = null
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'contract_version'                  => $this->contract_version,
			'snapshot_version'                  => $this->snapshot_version,
			'product_id'                        => $this->product_id,
			'variation_id'                      => $this->variation_id,
			'fulfilment_availability'           => $this->fulfilment_availability,
			'fulfilment_choice'                 => $this->fulfilment_choice,
			'delivery_offer_id'                 => $this->delivery_offer_id,
			'delivery_offer_public_label'       => $this->delivery_offer_public_label,
			'delivery_offer_public_description' => $this->delivery_offer_public_description,
			'estimate_text'                     => $this->estimate_text,
			'rule_id'                           => $this->rule_id,
			'destination_zone_id'               => $this->destination_zone_id,
			'quantity'                          => $this->quantity,
			'currency_code'                     => $this->currency_code,
			'quoted_amount'                     => $this->quoted_amount,
			'quote_status'                      => $this->quote_status,
			'rate_card_id'                      => $this->rate_card_id,
			'rate_card_code'                    => $this->rate_card_code,
			'snapshotted_at'                    => $this->snapshotted_at,
		];

		if ( null !== $this->delivery_group_id && '' !== $this->delivery_group_id ) {
			$data['delivery_group_id'] = $this->delivery_group_id;
		}

		if ( null !== $this->pickup_location_label && '' !== $this->pickup_location_label ) {
			$data['pickup_location_label'] = $this->pickup_location_label;
		}

		if ( null !== $this->pickup_address && '' !== $this->pickup_address ) {
			$data['pickup_address'] = $this->pickup_address;
		}

		if ( null !== $this->pickup_instructions && '' !== $this->pickup_instructions ) {
			$data['pickup_instructions'] = $this->pickup_instructions;
		}

		if ( OrderDeliverySnapshot::VERSION_V2 === $this->snapshot_version ) {
			$data['customer_context_version'] = $this->customer_context_version;
			$data['matching_location'] = $this->matching_location;
			$data['delivery_address'] = $this->delivery_address;
			$data['matching_identity'] = $this->matching_identity;
			$data['delivery_location_identity'] = $this->delivery_location_identity;
			$data['pickup_location_id'] = $this->pickup_location_id;
		}

		return $data;
	}
}

/**
 * One fulfilment/shipping group captured on the order (protected meta JSON).
 */
final class OrderDeliveryGroupSnapshot {

	public function __construct(
		public readonly string $group_id,
		public readonly ?string $shipping_method_id,
		public readonly ?string $shipping_method_label,
		public readonly ?string $package_total_delivery_amount,
		public readonly string $fulfilment_choice,
		public readonly bool $is_pickup,
		public readonly int $display_index
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'group_id'                      => $this->group_id,
			'shipping_method_id'            => $this->shipping_method_id,
			'shipping_method_label'         => $this->shipping_method_label,
			'package_total_delivery_amount' => $this->package_total_delivery_amount,
			'fulfilment_choice'             => $this->fulfilment_choice,
			'is_pickup'                     => $this->is_pickup,
			'display_index'                 => $this->display_index,
		];
	}
}

/**
 * Order-level delivery/shipping quote snapshot (protected meta JSON).
 */
final class OrderDeliveryPackageSnapshot {

	/**
	 * @param list<OrderDeliveryGroupSnapshot> $groups
	 */
	public function __construct(
		public readonly string $snapshot_version,
		public readonly ?string $shipping_method_id,
		public readonly ?string $shipping_method_label,
		public readonly ?string $package_total_delivery_amount,
		public readonly string $currency_code,
		public readonly ?int $destination_zone_id,
		public readonly string $quote_status,
		public readonly string $snapshotted_at,
		public readonly array $groups = []
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'snapshot_version'              => $this->snapshot_version,
			'shipping_method_id'            => $this->shipping_method_id,
			'shipping_method_label'         => $this->shipping_method_label,
			'package_total_delivery_amount' => $this->package_total_delivery_amount,
			'currency_code'                 => $this->currency_code,
			'destination_zone_id'           => $this->destination_zone_id,
			'quote_status'                  => $this->quote_status,
			'snapshotted_at'                => $this->snapshotted_at,
		];

		if ( [] !== $this->groups ) {
			$data['groups'] = array_map(
				static fn ( OrderDeliveryGroupSnapshot $group ): array => $group->toArray(),
				$this->groups
			);
		}

		return $data;
	}
}
