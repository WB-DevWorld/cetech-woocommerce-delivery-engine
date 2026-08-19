<?php

declare(strict_types=1);

/**
 * Minimal WooCommerce order stubs for Stage 14C unit tests.
 */

if ( ! class_exists( 'WC_Order', false ) ) {
	class WC_Order {

		/** @param array<string, mixed> $data */
		public function __construct( private array $data = [] ) {
			$this->data['meta']            = is_array( $this->data['meta'] ?? null ) ? $this->data['meta'] : [];
			$this->data['items']           = is_array( $this->data['items'] ?? null ) ? $this->data['items'] : [];
			$this->data['shipping_items']  = is_array( $this->data['shipping_items'] ?? null ) ? $this->data['shipping_items'] : [];
		}

		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}

		public function get_order_number(): string {
			return (string) ( $this->data['order_number'] ?? $this->get_id() );
		}

		public function get_status(): string {
			return (string) ( $this->data['status'] ?? 'pending' );
		}

		public function is_paid(): bool {
			return (bool) ( $this->data['paid'] ?? false );
		}

		public function get_date_paid( $context = 'view' ): mixed {
			unset( $context );

			return $this->data['date_paid'] ?? null;
		}

		public function get_payment_method(): string {
			return (string) ( $this->data['payment_method'] ?? '' );
		}

		public function get_payment_method_title(): string {
			if ( isset( $this->data['payment_method_title'] ) ) {
				return (string) $this->data['payment_method_title'];
			}

			return 'cod' === $this->get_payment_method() ? 'Cash on delivery' : $this->get_payment_method();
		}

		public function get_date_created( $context = 'view' ): mixed {
			unset( $context );

			return $this->data['date_created'] ?? '2026-08-19 10:00:00';
		}

		public function get_formatted_shipping_address(): string {
			return (string) ( $this->data['shipping_address'] ?? '' );
		}

		public function get_formatted_billing_address(): string {
			return (string) ( $this->data['billing_address'] ?? '' );
		}

		/**
		 * @return array<int|string, object>
		 */
		public function get_items( string $type = '' ): array {
			if ( 'shipping' === $type ) {
				return $this->data['shipping_items'];
			}

			return $this->data['items'];
		}

		public function get_meta( string $key, bool $single = true ): mixed {
			unset( $single );

			return $this->data['meta'][ $key ] ?? '';
		}

		public function update_meta_data( string $key, mixed $value ): void {
			$this->data['meta'][ $key ] = $value;
		}

		public function delete_meta_data( string $key ): void {
			unset( $this->data['meta'][ $key ] );
		}

		public function get_qty_refunded_for_item( int $item_id ): float {
			$map = is_array( $this->data['refunded_qty'] ?? null ) ? $this->data['refunded_qty'] : [];

			return (float) ( $map[ $item_id ] ?? 0 );
		}

		public function set_refunded_qty( int $item_id, float $qty ): void {
			if ( ! isset( $this->data['refunded_qty'] ) || ! is_array( $this->data['refunded_qty'] ) ) {
				$this->data['refunded_qty'] = [];
			}

			$this->data['refunded_qty'][ $item_id ] = $qty;
		}

		public function set_status( string $status ): void {
			$this->data['status'] = $status;
		}

		public function save(): void {
			$GLOBALS['cetech_de_test_wc_orders'][ $this->get_id() ] = $this;
		}

		public function get_edit_order_url(): string {
			return 'https://example.test/wp-admin/post.php?post=' . $this->get_id() . '&action=edit';
		}

		public function get_formatted_billing_full_name(): string {
			if ( isset( $this->data['billing_full_name'] ) ) {
				return (string) $this->data['billing_full_name'];
			}

			return trim( $this->get_billing_first_name() . ' ' . $this->get_billing_last_name() );
		}

		public function get_billing_first_name(): string {
			return (string) ( $this->data['billing_first_name'] ?? '' );
		}

		public function get_billing_last_name(): string {
			return (string) ( $this->data['billing_last_name'] ?? '' );
		}

		public function get_billing_company(): string {
			return (string) ( $this->data['billing_company'] ?? '' );
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	class WC_Order_Item_Product {

		/** @param array<string, mixed> $data */
		public function __construct( private array $data = [] ) {
			$this->data['meta'] = is_array( $this->data['meta'] ?? null ) ? $this->data['meta'] : [];
		}

		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}

		public function get_name(): string {
			return (string) ( $this->data['name'] ?? '' );
		}

		public function get_product(): mixed {
			return $this->data['product'] ?? null;
		}

		public function get_sku(): string {
			return (string) ( $this->data['sku'] ?? '' );
		}

		public function get_variation_id(): int {
			return (int) ( $this->data['variation_id'] ?? 0 );
		}

		public function get_quantity(): int {
			return (int) ( $this->data['quantity'] ?? 1 );
		}

		public function get_meta( string $key, bool $single = true ): mixed {
			unset( $single );

			return $this->data['meta'][ $key ] ?? '';
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Shipping', false ) ) {
	class WC_Order_Item_Shipping {

		/** @param array<string, mixed> $data */
		public function __construct( private array $data = [] ) {
			$this->data['meta'] = is_array( $this->data['meta'] ?? null ) ? $this->data['meta'] : [];
		}

		public function get_meta( string $key, bool $single = true ): mixed {
			unset( $single );

			return $this->data['meta'][ $key ] ?? '';
		}

		public function get_total(): string {
			return (string) ( $this->data['total'] ?? '0' );
		}

		public function get_method_id(): string {
			return (string) ( $this->data['method_id'] ?? '' );
		}
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return list<WC_Order>
	 */
	function wc_get_orders( $args = [] ) {
		$GLOBALS['cetech_de_test_wc_get_orders_calls'][] = $args;
		$map = $GLOBALS['cetech_de_test_wc_orders'] ?? [];
		$include = [];

		foreach ( (array) ( $args['include'] ?? [] ) as $id ) {
			$include[] = (int) $id;
		}

		$found = [];

		foreach ( $include as $id ) {
			if ( isset( $map[ $id ] ) && $map[ $id ] instanceof WC_Order ) {
				$found[] = $map[ $id ];
			}
		}

		return $found;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @return WC_Order|false
	 */
	function wc_get_order( $order_id ) {
		$map = $GLOBALS['cetech_de_test_wc_orders'] ?? [];
		$id  = (int) $order_id;

		return isset( $map[ $id ] ) && $map[ $id ] instanceof WC_Order ? $map[ $id ] : false;
	}
}
