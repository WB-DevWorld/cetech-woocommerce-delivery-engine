<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Support\Logger;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Persists delivery snapshots onto WooCommerce orders using HPOS-compatible CRUD APIs.
 *
 * Does not create shipments, alter totals, or auto-complete orders.
 */
final class OrderDeliverySnapshotPersister {

	private const SELECTED_OFFER_METHOD_ID = 'delivery_engine_selected_offer';

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
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'handle_order_created' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'handle_store_api_order_update' ], 20, 2 );
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
			OrderDeliverySnapshot::META_CART_ITEM_KEY,
			'cetech_de_group_id',
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
			OrderDeliverySnapshot::META_CART_ITEM_KEY,
			'cetech_de_group_id',
		];

		foreach ( $formatted_meta as $meta_id => $meta ) {
			$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';

			if (
				in_array( $key, $protected, true )
				|| str_starts_with( $key, '_cetech_de_' )
				|| str_starts_with( $key, 'cetech_de_' )
			) {
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

		if ( $this->item_has_line_snapshot( $item ) ) {
			$this->forget_cart_item_key( $item );

			return;
		}

		$snapshot = $this->builder->build_line_snapshot( $cart_item_key, $values, $order );

		if ( null === $snapshot ) {
			$this->remember_cart_item_key( $item, $cart_item_key );

			return;
		}

		$this->write_line_snapshot( $item, $snapshot );
	}

	public function handle_order_created( WC_Order $order ): void {
		if ( ! $this->is_runtime_active() ) {
			return;
		}

		$this->persist_missing_line_snapshots_from_cart( $order );

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
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, $package_snapshot->snapshot_version );
		$order->save();
	}

	/**
	 * Store API checkout may not fire woocommerce_checkout_order_created.
	 *
	 * @param mixed $order
	 * @param mixed $request
	 */
	public function handle_store_api_order_update( $order, $request ): void {
		unset( $request );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->handle_order_created( $order );
	}

	/**
	 * Store API creates line items before copying the customer address onto the order.
	 * Classic already has addresses at woocommerce_checkout_create_order_line_item, so this is a no-op there.
	 * After Store API address sync, rebuild any missing line snapshots from WC()->cart.
	 */
	private function persist_missing_line_snapshots_from_cart( WC_Order $order ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'get_cart' ) ) {
			return;
		}

		$cart = $wc->cart->get_cart();
		if ( ! is_array( $cart ) || [] === $cart ) {
			return;
		}

		$claimed = [];

		foreach ( $cart as $cart_item_key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$snapshot = $this->builder->build_line_snapshot( (string) $cart_item_key, $values, $order );
			if ( null === $snapshot ) {
				continue;
			}

			$item = $this->match_order_item_for_cart_values( $order, (string) $cart_item_key, $values, $claimed );
			if ( null === $item ) {
				continue;
			}

			$claimed[ $item->get_id() ] = true;

			if ( $this->item_has_line_snapshot( $item ) ) {
				$this->forget_cart_item_key( $item );

				continue;
			}

			$this->write_line_snapshot( $item, $snapshot );
		}
	}

	/**
	 * Prefer the Store API cart-item key recorded on the line item; fall back to
	 * product_id + variation_id + quantity with claimed-item uniqueness.
	 *
	 * @param array<string, mixed> $values
	 * @param array<int, true>     $claimed
	 */
	private function match_order_item_for_cart_values( WC_Order $order, string $cart_item_key, array $values, array $claimed ): ?WC_Order_Item_Product {
		if ( '' !== $cart_item_key ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$item_id = $item->get_id();
				if ( isset( $claimed[ $item_id ] ) ) {
					continue;
				}

				$stored = $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true );
				if ( is_string( $stored ) && $stored === $cart_item_key ) {
					return $item;
				}
			}
		}

		$product_id   = (int) ( $values['product_id'] ?? 0 );
		$variation_id = (int) ( $values['variation_id'] ?? 0 );
		$quantity     = (int) ( $values['quantity'] ?? 0 );

		$exact = [];
		$loose = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$item_id = $item->get_id();
			if ( isset( $claimed[ $item_id ] ) ) {
				continue;
			}

			if ( (int) $item->get_product_id() !== $product_id || (int) $item->get_variation_id() !== $variation_id ) {
				continue;
			}

			$loose[] = $item;
			if ( $quantity <= 0 || (int) $item->get_quantity() === $quantity ) {
				$exact[] = $item;
			}
		}

		$pool = [] !== $exact ? $exact : $loose;

		if ( count( $pool ) !== 1 ) {
			if ( count( $pool ) > 1 ) {
				$this->logger->warning(
					'Ambiguous Store API order-item mapping; refusing to guess.',
					[
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'quantity'     => $quantity,
						'candidates'   => count( $pool ),
					]
				);
			}

			return null;
		}

		return $pool[0];
	}

	private function remember_cart_item_key( WC_Order_Item_Product $item, string $cart_item_key ): void {
		if ( '' === $cart_item_key || $this->item_has_line_snapshot( $item ) ) {
			return;
		}

		$existing = $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		if ( method_exists( $item, 'update_meta_data' ) ) {
			$item->update_meta_data( OrderDeliverySnapshot::META_CART_ITEM_KEY, $cart_item_key );
		} else {
			$item->add_meta_data( OrderDeliverySnapshot::META_CART_ITEM_KEY, $cart_item_key, true );
		}
	}

	private function forget_cart_item_key( WC_Order_Item_Product $item ): void {
		if ( ! method_exists( $item, 'delete_meta_data' ) ) {
			return;
		}

		$existing = $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true );
		if ( ! is_string( $existing ) || '' === $existing ) {
			return;
		}

		$item->delete_meta_data( OrderDeliverySnapshot::META_CART_ITEM_KEY );

		if ( $item->get_id() > 0 && method_exists( $item, 'save' ) ) {
			$item->save();
		}
	}

	private function write_line_snapshot( WC_Order_Item_Product $item, OrderDeliveryLineSnapshot $snapshot ): void {
		$this->forget_cart_item_key( $item );

		if ( $this->item_has_line_snapshot( $item ) ) {
			return;
		}

		$encoded = wp_json_encode( $snapshot->toArray() );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			$this->logger->warning( 'Order delivery line snapshot encoding failed.' );

			return;
		}

		if ( method_exists( $item, 'update_meta_data' ) ) {
			$item->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $encoded );
			$item->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $snapshot->snapshot_version );
		} else {
			$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $encoded, true );
			$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $snapshot->snapshot_version, true );
		}

		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		}
	}

	private function item_has_line_snapshot( WC_Order_Item_Product $item ): bool {
		$raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );

		return is_string( $raw ) && '' !== $raw;
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

			if ( self::SELECTED_OFFER_METHOD_ID === (string) $shipping_item->get_method_id() ) {
				return true;
			}
		}

		return false;
	}
}
