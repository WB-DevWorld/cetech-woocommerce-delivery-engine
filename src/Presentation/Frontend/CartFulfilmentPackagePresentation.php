<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;

/**
 * Customer-facing cart/checkout package heading and destination copy.
 *
 * Pickup groups must not look like a delivery shipment to the customer's
 * shipping address. Does not change grouping, quoting, or shipment persistence.
 */
final class CartFulfilmentPackagePresentation {

	private static ?array $current_package = null;

	public function register(): void {
		add_filter( 'woocommerce_shipping_package_name', [ $this, 'filter_package_name' ], 20, 4 );
		add_filter( 'woocommerce_shipping_formatted_destination', [ $this, 'filter_formatted_destination' ], 20, 2 );
		add_filter( 'gettext', [ $this, 'filter_shipping_to_copy' ], 20, 3 );
		add_action( 'woocommerce_after_template_part', [ $this, 'clear_current_package' ], 20, 1 );
	}

	/**
	 * @param mixed $name
	 * @param mixed $package_index
	 * @param mixed $package
	 * @param mixed $total_packages
	 */
	public function filter_package_name( $name, $package_index, $package, $total_packages = 1 ): string {
		unset( $package_index, $total_packages );

		$package = is_array( $package ) ? $package : [];
		self::$current_package = $package;

		return self::heading( (string) $name, $package );
	}

	/**
	 * WooCommerce's second argument is the raw destination address, not the package.
	 * Pickup copy uses the package stashed by filter_package_name.
	 *
	 * @param mixed $destination
	 * @param mixed $raw_address
	 */
	public function filter_formatted_destination( $destination, $raw_address = null ): string {
		unset( $raw_address );

		$package = is_array( self::$current_package ) ? self::$current_package : [];

		return self::destination( (string) $destination, $package );
	}

	/**
	 * @param mixed $translated
	 * @param mixed $text
	 * @param mixed $domain
	 */
	public function filter_shipping_to_copy( $translated, $text, $domain ): string {
		$translated = (string) $translated;
		$text       = (string) $text;
		$domain     = (string) $domain;

		if ( 'woocommerce' !== $domain || ! self::is_pickup( self::$current_package ?? [] ) ) {
			return $translated;
		}

		if ( 'Shipping to %s.' === $text ) {
			return __( 'Pickup address: %s.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( 'Shipping options will be updated during checkout.' === $text ) {
			$ready = self::readiness( self::$current_package ?? [] );

			return '' !== $ready ? $ready : '';
		}

		return $translated;
	}

	/**
	 * @param mixed $template_name
	 */
	public function clear_current_package( $template_name ): void {
		if ( is_string( $template_name ) && str_contains( $template_name, 'cart-shipping' ) ) {
			self::$current_package = null;
		}
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function heading( string $default, array $package ): string {
		if ( ! self::is_pickup( $package ) ) {
			return $default;
		}

		$location = self::location_name( $package );

		if ( '' !== $location ) {
			return sprintf(
				/* translators: %s: pickup location name */
				__( 'Pickup at %s', 'cetech-woocommerce-delivery-engine' ),
				$location
			);
		}

		return __( 'Store Pickup', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function destination( string $customer_shipping_destination, array $package ): string {
		if ( ! self::is_pickup( $package ) ) {
			return $customer_shipping_destination;
		}

		$address = self::pickup_address( $package );

		if ( '' !== $address ) {
			return $address;
		}

		return self::location_name( $package );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function uses_customer_shipping_destination( array $package ): bool {
		return ! self::is_pickup( $package );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function is_pickup( array $package ): bool {
		$meta = DeliveryGroupIdentity::package_meta( $package );

		if ( is_array( $meta ) && ! empty( $meta['is_pickup'] ) ) {
			return true;
		}

		$group_id = is_array( $meta ) ? (string) ( $meta['group_id'] ?? '' ) : '';

		return '' !== $group_id && DeliveryGroupIdentity::is_pickup_group( $group_id );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	private static function location_name( array $package ): string {
		$meta = DeliveryGroupIdentity::package_meta( $package );

		return is_array( $meta ) ? trim( (string) ( $meta['pickup_location_label'] ?? '' ) ) : '';
	}

	/**
	 * @param array<string, mixed> $package
	 */
	private static function pickup_address( array $package ): string {
		$meta = DeliveryGroupIdentity::package_meta( $package );
		$raw  = is_array( $meta ) ? (string) ( $meta['pickup_address'] ?? '' ) : '';

		return PickupLocationAddressFormatter::format( $raw );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	private static function readiness( array $package ): string {
		$meta = DeliveryGroupIdentity::package_meta( $package );

		return is_array( $meta ) ? trim( (string) ( $meta['estimate_text'] ?? '' ) ) : '';
	}
}
