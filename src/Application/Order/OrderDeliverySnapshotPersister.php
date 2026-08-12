<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Support\Logger;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Persists delivery snapshots onto WooCommerce orders using HPOS-compatible CRUD APIs.
 *
 * Does not create shipments, alter totals, or auto-complete orders.
 */
final class OrderDeliverySnapshotPersister {

	public function __construct(
		private OrderDeliverySnapshotGate $gate,
		private OrderDeliverySnapshotBuilder $builder,
		private Logger $logger
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'handle_create_order_line_item' ], 10, 4 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'handle_order_created' ], 10, 1 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_protected_order_item_meta' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'strip_protected_formatted_meta' ], 10, 2 );
	}

	/**
	 * @param list<string> $hidden
	 *
	 * @return list<string>
	 */
	public function hide_protected_order_item_meta( array $hidden ): array {
		foreach ( [
			OrderDeliverySnapshot::META_LINE_SNAPSHOT,
			OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,
		] as $key ) {
			if ( ! in_array( $key, $hidden, true ) ) {
				$hidden[] = $key;
			}
		}

		return $hidden;
	}

	/**
	 * Remove protected meta from formatted order-item projections (customer/email/REST-safe).
	 *
	 * @param array<int|string, mixed> $formatted_meta
	 * @param mixed                    $item
	 *
	 * @return array<int|string, mixed>
	 */
	public function strip_protected_formatted_meta( array $formatted_meta, $item ): array {
		unset( $item );

		$protected = [
			OrderDeliverySnapshot::META_LINE_SNAPSHOT,
			OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,
		];

		foreach ( $formatted_meta as $meta_id => $meta ) {
			$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';

			if ( in_array( $key, $protected, true ) || str_starts_with( $key, '_cetech_de_' ) ) {
				unset( $formatted_meta[ $meta_id ] );
			}
		}

		return $formatted_meta;
	}

	public function is_runtime_active(): bool {
		return $this->gate->is_runtime_active();
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @param array<string, mixed>  $values
	 */
	public function handle_create_order_line_item(
		$item,
		string $cart_item_key,
		array $values,
		WC_Order $order
	): void {
		if ( ! $this->is_runtime_active() || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$snapshot = $this->builder->build_line_snapshot( $cart_item_key, $values, $order );

		if ( null === $snapshot ) {
			return;
		}

		$encoded = wp_json_encode( $snapshot->toArray() );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			$this->logger->warning( 'Order delivery line snapshot encoding failed.' );

			return;
		}

		$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $encoded, true );
		$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, OrderDeliverySnapshot::VERSION, true );
	}

	public function handle_order_created( WC_Order $order ): void {
		if ( ! $this->is_runtime_active() ) {
			return;
		}

		if ( ! $this->order_has_line_snapshots( $order ) && ! $this->order_uses_selected_offer_shipping( $order ) ) {
			return;
		}

		$package_snapshot = $this->builder->build_package_snapshot( $order );

		if ( null === $package_snapshot ) {
			return;
		}

		$encoded = wp_json_encode( $package_snapshot->toArray() );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			$this->logger->warning( 'Order delivery package snapshot encoding failed.' );

			return;
		}

		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, $encoded );
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, OrderDeliverySnapshot::VERSION );
		$order->save();
	}

	private function order_has_line_snapshots( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );

			if ( is_string( $raw ) && '' !== $raw ) {
				return true;
			}
		}

		return false;
	}

	private function order_uses_selected_offer_shipping( WC_Order $order ): bool {
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! is_object( $shipping_item ) || ! method_exists( $shipping_item, 'get_method_id' ) ) {
				continue;
			}

			if ( SelectedOfferShippingMethod::METHOD_ID === (string) $shipping_item->get_method_id() ) {
				return true;
			}
		}

		return false;
	}
}
