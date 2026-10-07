<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\EmergencyControl;

use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/** Always-live Woo boundaries, deliberately independent of storefront feature flags. */
final class EmergencyCheckoutHooks {
	public function __construct( private EmergencyControlRuntime $runtime, private ?EmergencyControlResponse $response = null ) { $this->response ??= new EmergencyControlResponse(); }
	public function register(): void {
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'latch_restored_line' ], -100, 3 );
		add_action( 'woocommerce_add_to_cart', [ $this, 'latch_added_line' ], PHP_INT_MAX, 6 );
		add_action( 'woocommerce_cart_item_removed', [ $this->runtime, 'forget_removed_line' ], PHP_INT_MAX, 2 );
		add_action( 'woocommerce_cart_item_restored', [ $this, 'latch_restored_cart_item' ], PHP_INT_MAX, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'bind_order_line' ], PHP_INT_MAX, 4 );
		add_action( 'woocommerce_checkout_order_created', [ $this->runtime, 'freeze_saved_order' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], -100, 6 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'guard_item_data' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'validate_store_add_to_cart' ], -100, 2 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'early_classic' ], -100, 2 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'append_store_cart_errors' ], -100, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'early_store_api' ], -100, 2 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'final_classic' ], PHP_INT_MAX, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'final_store_api' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_before_pay_action', [ $this, 'before_pay' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_cart_shipping_packages', [ $this->runtime, 'decorate_packages' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_package_rates', [ $this, 'package_rates' ], PHP_INT_MAX, 2 );
	}
	public function latch_restored_line( array $line, array $values, string $key ): array { $this->runtime->latch_line( $key, array_replace( $line, $values ) ); return $line; }
	public function latch_added_line( string $key, int $product_id, int $quantity, int $variation_id = 0, array $variation = [], array $data = [] ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$line = is_object( $cart ) && method_exists( $cart, 'get_cart_item' ) ? $cart->get_cart_item( $key ) : null;
		if ( is_array( $line ) ) { $this->runtime->latch_line( $key, $line ); }
	}
	public function bind_order_line( mixed $item, string $key, array $values, mixed $order ): void {
		$this->runtime->latch_line( $key, $values );
		if ( $item instanceof \WC_Order_Item_Product ) { $this->runtime->bind_order_line( $key, $item ); }
	}
	public function latch_restored_cart_item( string $key, mixed $cart ): void {
		$line = is_object( $cart ) && method_exists( $cart, 'get_cart_item' ) ? $cart->get_cart_item( $key ) : null;
		if ( is_array( $line ) ) { $this->runtime->latch_line( $key, $line ); }
	}
	public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = [], array $data = [] ): bool {
		if ( ! $passed ) { return false; }
		$decision = $this->runtime->product_decision( $product_id, $variation_id > 0 ? $variation_id : null );
		if ( $decision->allowed ) { return true; }
		if ( function_exists( 'wc_add_notice' ) ) { wc_add_notice( EmergencyControlResponse::shopper_message( $decision ), 'error' ); } return false;
	}
	public function validate_store_add_to_cart( mixed $product, mixed $request ): void {
		if ( ! $product instanceof \WC_Product ) { EmergencyControlResponse::reject_store_api( EmergencyControlRuntime::unavailable() ); }
		$variation = $product->is_type( 'variation' ) ? $product->get_id() : null;
		$id = null !== $variation ? $product->get_parent_id() : $product->get_id();
		$decision = $this->runtime->product_decision( $id, $variation );
		if ( ! $decision->allowed ) { EmergencyControlResponse::reject_store_api( $decision ); }
	}
	public function guard_item_data( array $data, int $product_id, int $variation_id ): array {
		$decision = $this->runtime->product_decision( $product_id, $variation_id > 0 ? $variation_id : null );
		if ( ! $decision->allowed ) { EmergencyControlResponse::reject_classic( $decision ); }
		return $data;
	}
	public function early_classic( array $data, \WP_Error $errors ): void { $decision = $this->runtime->cart_decision(); if ( ! $decision->allowed ) { $view = EmergencyControlResponse::shopper_projection( $decision ); $errors->add( 'cetech_de_checkout_control', $view['message'] . ' Reference: ' . $view['correlation_id'], $view ); } }
	/** Woo validates cart errors before draft-order hooks; reads/editing must not throw. */
	public function append_store_cart_errors( mixed $errors, mixed $cart ): void {
		unset( $cart );
		if ( ! $errors instanceof \WP_Error ) { return; }
		$decision = $this->runtime->cart_decision();
		if ( ! $decision->allowed ) {
			$view = EmergencyControlResponse::shopper_projection( $decision );
			$errors->add( 'cetech_de_checkout_control', $view['message'] . ' Reference: ' . $view['correlation_id'], $view );
		}
	}
	public function early_store_api( mixed $order, mixed $request ): void { $decision = $this->runtime->cart_decision(); if ( ! $decision->allowed ) { EmergencyControlResponse::reject_store_api( $decision ); } }
	public function final_classic( int $order_id, array $posted, mixed $order ): void { $decision = $order instanceof \WC_Order ? $this->runtime->final_order_decision( $order, 'classic' ) : EmergencyControlRuntime::unavailable(); if ( ! $decision->allowed ) { EmergencyControlResponse::reject_classic( $decision ); } }
	public function final_store_api( mixed $order ): void { $decision = $order instanceof \WC_Order ? $this->runtime->final_order_decision( $order, 'store_api' ) : EmergencyControlRuntime::unavailable(); if ( ! $decision->allowed ) { EmergencyControlResponse::reject_store_api( $decision ); } }
	public function before_pay( mixed $order ): void { $decision = $order instanceof \WC_Order ? $this->runtime->final_order_decision( $order, 'order_pay' ) : EmergencyControlRuntime::unavailable(); if ( ! $decision->allowed ) { $this->response->refuse_order_pay( $decision ); } }
	public function package_rates( array $rates, array $package ): array {
		if ( ! $this->runtime->package_owned( $package ) ) { return $rates; }
		if ( ! $this->runtime->package_allowed( $package ) ) { return []; }
		return array_filter( $rates, static function ( mixed $rate, mixed $key ): bool {
			$method = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : ( is_string( $key ) && str_starts_with( $key, SelectedOfferShippingMethod::METHOD_ID . ':' ) ? SelectedOfferShippingMethod::METHOD_ID : null );
			return SelectedOfferShippingMethod::METHOD_ID === $method;
		}, ARRAY_FILTER_USE_BOTH );
	}
}
