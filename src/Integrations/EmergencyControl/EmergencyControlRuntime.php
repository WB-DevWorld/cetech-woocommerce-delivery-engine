<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionControlInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionResult;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnership;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;

/** Shared, flag-independent guard. Every observation uses the current reader. */
final class EmergencyControlRuntime {
	private ?\Closure $site;
	private bool $latch_failed = false;
	public function __construct( private EmergencyAdmissionControlInterface $control, private EmergencyOwnershipClassifier $classifier, private EmergencyOwnershipLatch $latch, private EmergencyCheckoutAdmissionService $admission, ?callable $site_resolver = null ) {
		$this->site = null === $site_resolver ? null : \Closure::fromCallable( $site_resolver );
	}
	public function product_allowed( int $product_id, ?int $variation_id = null ): bool {
		return $this->product_decision( $product_id, $variation_id )->allowed;
	}
	public function product_decision( int $product_id, ?int $variation_id = null ): EmergencyAdmissionResult {
		try { return $this->admission->early_product( $product_id, $variation_id ); } catch ( \Throwable ) { return self::unavailable(); }
	}
	public function latch_line( string $key, array $line ): void {
		try { $ownership = $this->classifier->line( $key, $line ); } catch ( \Throwable ) { $ownership = EmergencyOwnership::Unresolved; }
		try { $this->latch->capture_line( $key, $line, $ownership ); } catch ( \Throwable ) { $this->latch_failed = true; }
	}
	public function bind_order_line( string $key, \WC_Order_Item_Product $item ): void { try { $this->latch->bind_order_line( $key, $item ); } catch ( \Throwable ) { $this->latch_failed = true; } }
	public function forget_removed_line( string $key, mixed $cart ): void {
		try { if ( is_object( $cart ) && method_exists( $cart, 'get_cart_item' ) && ! is_array( $cart->get_cart_item( $key ) ) ) { $this->latch->forget_line( $key ); } } catch ( \Throwable ) { $this->latch_failed = true; }
	}
	public function line_allowed( string $key, array $line ): bool { return $this->line_decision( $key, $line )->allowed; }
	public function line_decision( string $key, array $line ): EmergencyAdmissionResult { return $this->early_cart_decision( [ $key => $line ] ); }
	public function lines_allowed( array $lines, array $packages = [] ): bool {
		return $this->early_cart_decision( $lines, $packages )->allowed;
	}
	public function early_cart_decision( array $lines, array $packages = [] ): EmergencyAdmissionResult {
		if ( $this->latch_failed ) { return self::unavailable(); }
		try { return $this->admission->early_cart( $lines, $packages ); } catch ( \Throwable ) { return self::unavailable(); }
	}
	public function cart_allowed(): bool {
		return $this->cart_decision()->allowed;
	}
	public function cart_decision(): EmergencyAdmissionResult {
		try { $cart = function_exists( 'WC' ) ? WC()->cart : null; return $this->early_cart_decision( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ? $cart->get_cart() : [] ); } catch ( \Throwable ) { return self::unavailable(); }
	}
	public function package_owned( array $package ): bool {
		try { return EmergencyOwnership::Unmanaged !== $this->classifier->cart( is_array( $package['contents'] ?? null ) ? $package['contents'] : [], [ $package ] ); } catch ( \Throwable ) { return true; }
	}
	public function package_allowed( array $package ): bool {
		return $this->package_decision( $package )->allowed;
	}
	public function package_decision( array $package ): EmergencyAdmissionResult {
		if ( true === ( DeliveryGroupIdentity::package_meta( $package )['checkout_control_limit'] ?? false ) ) { return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', EmergencyOwnership::Unresolved ); }
		return $this->early_cart_decision( is_array( $package['contents'] ?? null ) ? $package['contents'] : [], [ $package ] );
	}
	/** Private package metadata is hashed by Woo, never exposed as shopper facts. */
	public function package_epoch( array $package ): array {
		if ( ! $this->package_owned( $package ) ) { return []; }
		try {
			$site = null !== $this->site ? ( $this->site )() : ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
			$result = $this->control->read( $site );
			return $result->available && null !== $result->state ? $result->state->epoch() : [ 'state' => 'unavailable', 'revision' => 0 ];
		} catch ( \Throwable ) { return [ 'state' => 'unavailable', 'revision' => 0 ]; }
	}
	public function decorate_packages( array $packages ): array {
		if ( count( $packages ) > 200 ) {
			return [ [ 'contents' => [], DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'checkout_control_limit' => true, 'checkout_control' => [ 'state' => 'unavailable', 'revision' => 0 ] ] ] ];
		}
		foreach ( $packages as &$package ) {
			if ( ! is_array( $package ) || ! $this->package_owned( $package ) ) { continue; }
			$meta = DeliveryGroupIdentity::package_meta( $package ) ?? [];
			$meta['managed'] = true;
			$meta['checkout_control'] = $this->package_epoch( $package );
			$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = $meta;
		}
		unset( $package ); return $packages;
	}
	public function final_order( \WC_Order $order, string $route ): bool {
		return $this->final_order_decision( $order, $route )->allowed;
	}
	public function final_order_decision( \WC_Order $order, string $route ): EmergencyAdmissionResult {
		if ( $this->latch_failed ) { return self::unavailable(); }
		try { return $this->admission->final_order( $order, $route ); } catch ( \Throwable ) { return self::unavailable(); }
	}
	public static function unavailable(): EmergencyAdmissionResult { return new EmergencyAdmissionResult( false, 'control_unavailable', EmergencyOwnership::Unresolved ); }
}
