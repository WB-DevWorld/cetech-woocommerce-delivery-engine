<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Builds customer-safe delivery summary rows from protected order snapshots (read-only).
 */
final class CustomerOrderDeliverySummaryBuilder {

	public const SUMMARY_FLAG = 'enable_customer_order_delivery_summary';

	public function __construct(
		private OrderDeliverySnapshotReader $reader,
		private OrderDeliverySnapshotIntegrity $integrity
	) {
	}

	public function build( WC_Order $order ): ?CustomerOrderDeliverySummary {
		$line_summaries = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$line_summary = $this->build_line_from_item( $item, $item->get_name() );

			if ( null !== $line_summary ) {
				$line_summaries[] = $line_summary;
			}
		}

		$package_summary = $this->build_package_from_order( $order );

		if ( [] === $line_summaries && null === $package_summary ) {
			return null;
		}

		return new CustomerOrderDeliverySummary( $line_summaries, $package_summary );
	}

	private function build_line_from_item( WC_Order_Item_Product $item, string $product_name ): ?CustomerOrderDeliveryLineSummary {
		$read   = $this->reader->read_line( $item );
		$status = $this->integrity->classify_line( $read );

		if (
			OrderDeliverySnapshotIntegrity::STATUS_PRESENT_VALID !== $status
			&& OrderDeliverySnapshotIntegrity::STATUS_SELECTION_ONLY !== $status
		) {
			return null;
		}

		if ( null === $read->snapshot ) {
			return null;
		}

		return $this->map_line_snapshot( $read->snapshot, $product_name, $status, (int) $item->get_id() );
	}

	private function build_package_from_order( WC_Order $order ): ?CustomerOrderDeliveryPackageSummary {
		$read   = $this->reader->read_package( $order );
		$status = $this->integrity->classify_package( $read );

		if ( OrderDeliverySnapshotIntegrity::STATUS_PRESENT_VALID !== $status || null === $read->snapshot ) {
			return null;
		}

		return $this->map_package_snapshot( $read->snapshot );
	}

	private function map_line_snapshot(
		OrderDeliveryLineSnapshot $snapshot,
		string $product_name,
		string $integrity_status,
		int $order_item_id
	): CustomerOrderDeliveryLineSummary {
		$is_quoted = OrderDeliverySnapshotIntegrity::STATUS_PRESENT_VALID === $integrity_status;

		$delivery_label = $snapshot->delivery_offer_public_label;

		if ( null === $delivery_label || '' === trim( $delivery_label ) ) {
			$delivery_label = $this->format_fulfilment_choice( $snapshot->fulfilment_choice );
		}

		$quoted_amount = null;

		if ( $is_quoted && null !== $snapshot->quoted_amount && '' !== trim( $snapshot->quoted_amount ) ) {
			$quoted_amount = $this->format_amount( $snapshot->quoted_amount, $snapshot->currency_code );
		}

		return new CustomerOrderDeliveryLineSummary(
			$product_name,
			$delivery_label,
			$this->format_fulfilment_availability( $snapshot->fulfilment_availability ),
			$this->format_fulfilment_choice( $snapshot->fulfilment_choice ),
			$snapshot->delivery_offer_public_description,
			$snapshot->estimate_text,
			$this->customer_quote_status_label( $snapshot->quote_status, $is_quoted ),
			$quoted_amount,
			$this->format_snapshotted_at( $snapshot->snapshotted_at ),
			// Pickup location/address/instructions are not part of the V1 protected
			// line snapshot contract. Compact presentation supports them when present.
			null,
			null,
			null,
			$order_item_id
		);
	}

	private function map_package_snapshot( OrderDeliveryPackageSnapshot $snapshot ): CustomerOrderDeliveryPackageSummary {
		$method_label = $snapshot->shipping_method_label;

		if ( null === $method_label || '' === trim( $method_label ) ) {
			$method_label = __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
		}

		$amount = null;

		if ( null !== $snapshot->package_total_delivery_amount && '' !== trim( $snapshot->package_total_delivery_amount ) ) {
			$amount = $this->format_amount( $snapshot->package_total_delivery_amount, $snapshot->currency_code );
		}

		return new CustomerOrderDeliveryPackageSummary(
			$method_label,
			$amount,
			$this->format_snapshotted_at( $snapshot->snapshotted_at )
		);
	}

	private function customer_quote_status_label( string $quote_status, bool $is_quoted ): ?string {
		if ( $is_quoted && OrderDeliverySnapshot::QUOTE_STATUS_QUOTED === $quote_status ) {
			return __( 'Delivery price confirmed', 'cetech-woocommerce-delivery-engine' );
		}

		if ( OrderDeliverySnapshot::QUOTE_STATUS_SELECTION_ONLY === $quote_status || OrderDeliverySnapshot::QUOTE_STATUS_SKIPPED === $quote_status ) {
			return __( 'Fulfilment choice recorded', 'cetech-woocommerce-delivery-engine' );
		}

		return null;
	}

	private function format_fulfilment_availability( string $value ): string {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $value ) {
				return match ( $case ) {
					FulfilmentAvailability::InternationalFulfilment => __( 'International fulfilment', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InStore => __( 'In store', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InWarehouse => __( 'In warehouse', 'cetech-woocommerce-delivery-engine' ),
				};
			}
		}

		return ucwords( str_replace( '_', ' ', $value ) );
	}

	private function format_fulfilment_choice( string $value ): string {
		return match ( $value ) {
			'delivery' => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
			'store_pickup' => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			default => ucwords( str_replace( '_', ' ', $value ) ),
		};
	}

	private function format_amount( string $amount, string $currency_code ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( (string) wc_price( $amount, [ 'currency' => $currency_code ] ) );
		}

		return trim( $amount . ' ' . $currency_code );
	}

	private function format_snapshotted_at( string $iso_timestamp ): ?string {
		$timestamp = strtotime( $iso_timestamp );

		if ( false === $timestamp ) {
			return null;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}

/**
 * Customer-safe per-line delivery summary (no internal IDs).
 *
 * Stage 13F customer surfaces render only the compact public contract.
 * Availability/method/charge fields remain available for internal mapping
 * but must not be shown on customer thank-you / email surfaces.
 */
final class CustomerOrderDeliveryLineSummary {

	public function __construct(
		public readonly string $product_name,
		public readonly string $delivery_option_label,
		public readonly string $fulfilment_availability_label,
		public readonly string $fulfilment_choice_label,
		public readonly ?string $delivery_option_description,
		public readonly ?string $estimate_text,
		public readonly ?string $quote_status_label,
		public readonly ?string $quoted_amount_display,
		public readonly ?string $snapshotted_at_display,
		public readonly ?string $pickup_location_label = null,
		public readonly ?string $pickup_address = null,
		public readonly ?string $pickup_instructions = null,
		public readonly int $order_item_id = 0
	) {
	}
}

/**
 * Customer-safe package/shipping summary (no internal IDs).
 */
final class CustomerOrderDeliveryPackageSummary {

	public function __construct(
		public readonly string $shipping_method_label,
		public readonly ?string $package_amount_display,
		public readonly ?string $snapshotted_at_display
	) {
	}
}

/**
 * Combined customer-safe order delivery summary.
 */
final class CustomerOrderDeliverySummary {

	/**
	 * @param list<CustomerOrderDeliveryLineSummary> $lines
	 */
	public function __construct(
		public readonly array $lines,
		public readonly ?CustomerOrderDeliveryPackageSummary $package
	) {
	}
}
