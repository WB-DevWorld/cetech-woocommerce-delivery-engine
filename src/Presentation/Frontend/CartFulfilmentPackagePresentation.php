<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

/**
 * Customer-facing cart/checkout package heading and destination copy.
 *
 * Pickup groups must not look like a delivery shipment to the customer's
 * shipping address. Does not change grouping, quoting, or shipment persistence.
 */
final class CartFulfilmentPackagePresentation {

	private static ?array $current_package = null;

	private static bool $buffering_pickup_shipping = false;

	public function register(): void {
		add_filter( 'woocommerce_shipping_package_name', [ $this, 'filter_package_name' ], 20, 4 );
		add_filter( 'woocommerce_shipping_formatted_destination', [ $this, 'filter_formatted_destination' ], 20, 2 );
		add_filter( 'woocommerce_formatted_address', [ $this, 'filter_formatted_address' ], 20, 2 );
		add_filter( 'woocommerce_shipping_show_shipping_calculator', [ $this, 'filter_show_shipping_calculator' ], 20, 3 );
		add_filter( 'woocommerce_shipping_package_details_array', [ $this, 'filter_package_details' ], 20, 2 );
		add_filter( 'gettext', [ $this, 'filter_shipping_to_copy' ], 20, 3 );
		add_action( 'woocommerce_before_template_part', [ $this, 'before_shipping_template' ], 1, 4 );
		add_action( 'woocommerce_after_template_part', [ $this, 'after_shipping_template' ], 1, 4 );
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
	 * Pickup copy uses the package stashed before cart-shipping renders.
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
	 * cart-shipping.php formats the destination before the package-name filter.
	 *
	 * @param mixed $formatted
	 * @param mixed $raw_address
	 */
	public function filter_formatted_address( $formatted, $raw_address = null ): string {
		unset( $raw_address );

		$package = is_array( self::$current_package ) ? self::$current_package : [];

		return self::destination( (string) $formatted, $package );
	}

	/**
	 * @param mixed $show
	 * @param mixed $index
	 * @param mixed $package
	 */
	public function filter_show_shipping_calculator( $show, $index = 0, $package = array() ): bool {
		unset( $index );

		$package = is_array( $package ) ? $package : [];

		if ( self::is_pickup( $package ) ) {
			return false;
		}

		return (bool) $show;
	}

	/**
	 * @param mixed $details
	 * @param mixed $package
	 *
	 * @return array<string, string>
	 */
	public function filter_package_details( $details, $package = array() ): array {
		$package = is_array( $package ) ? $package : [];

		if ( self::is_managed( $package ) ) {
			return [];
		}

		return is_array( $details ) ? $details : [];
	}

	/**
	 * @param mixed $translated
	 * @param mixed $text
	 * @param mixed $domain
	 */
	public function filter_shipping_to_copy( $translated, $text, $domain ): string {
		$translated = (string) $translated;
		$text       = (string) $text;
		unset( $domain );

		if ( ! self::is_pickup( self::$current_package ?? [] ) ) {
			$package = self::$current_package ?? [];
			if ( self::is_managed( $package ) && '' !== trim( (string) ( ( DeliveryGroupIdentity::package_meta( $package )['locality_label'] ?? '' ) ) ) ) {
				if ( self::is_shipping_to_string( $text ) || self::is_shipping_to_string( $translated ) ) {
					return '';
				}
			}

			return $translated;
		}

		if ( self::is_shipping_to_string( $text ) || self::is_shipping_to_string( $translated ) ) {
			return '%s';
		}

		if ( self::is_change_address_string( $text ) || self::is_change_address_string( $translated ) ) {
			return '';
		}

		if ( 'Shipping options will be updated during checkout.' === $text ) {
			$ready = self::readiness( self::$current_package ?? [] );

			return '' !== $ready ? $ready : '';
		}

		return $translated;
	}

	/**
	 * @param mixed $template_name
	 * @param mixed $template_path
	 * @param mixed $located
	 * @param mixed $args
	 */
	public function before_shipping_template( $template_name, $template_path = '', $located = '', $args = array() ): void {
		unset( $template_path, $located );

		if ( ! self::is_cart_shipping_template( $template_name ) ) {
			return;
		}

		$args    = is_array( $args ) ? $args : [];
		$package = is_array( $args['package'] ?? null ) ? $args['package'] : [];
		self::$current_package = $package;

		if ( self::is_managed( $package ) && ! self::$buffering_pickup_shipping ) {
			ob_start();
			self::$buffering_pickup_shipping = true;
		}
	}

	/**
	 * @param mixed $template_name
	 * @param mixed $template_path
	 * @param mixed $located
	 * @param mixed $args
	 */
	public function after_shipping_template( $template_name, $template_path = '', $located = '', $args = array() ): void {
		unset( $template_path, $located, $args );

		if ( ! self::is_cart_shipping_template( $template_name ) ) {
			return;
		}

		if ( self::$buffering_pickup_shipping ) {
			$html = (string) ob_get_clean();
			self::$buffering_pickup_shipping = false;
			$package = is_array( self::$current_package ) ? self::$current_package : [];
			echo self::rewrite_managed_package_html( $html, $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rewriting WooCommerce template HTML.
		}

		self::$current_package = null;
	}

	/**
	 * @param mixed $template_name
	 */
	public function clear_current_package( $template_name ): void {
		$this->after_shipping_template( $template_name );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function heading( string $default, array $package ): string {
		if ( self::is_pickup( $package ) ) {
			$location = self::location_name( $package );

			return '' !== $location ? $location : CustomerStorefrontCopy::store_pickup();
		}

		$meta = DeliveryGroupIdentity::package_meta( $package );
		if ( is_array( $meta ) && ! empty( $meta['managed'] ) ) {
			$locality = trim( (string) ( $meta['locality_label'] ?? '' ) );
			if ( '' !== $locality ) {
				return CustomerStorefrontCopy::delivery_to( $locality );
			}
		}

		return $default;
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function destination( string $customer_shipping_destination, array $package ): string {
		if ( self::is_pickup( $package ) ) {
			$address = self::pickup_address( $package );

			if ( '' !== $address ) {
				return $address;
			}

			return self::location_name( $package );
		}

		$meta = DeliveryGroupIdentity::package_meta( $package );
		if ( is_array( $meta ) && ! empty( $meta['managed'] ) && '' !== trim( (string) ( $meta['locality_label'] ?? '' ) ) ) {
			return '';
		}

		return $customer_shipping_destination;
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
	public static function shows_shipping_to_copy( array $package ): bool {
		if ( self::is_pickup( $package ) ) {
			return false;
		}

		$meta = DeliveryGroupIdentity::package_meta( $package );

		return ! ( is_array( $meta ) && ! empty( $meta['managed'] ) && '' !== trim( (string) ( $meta['locality_label'] ?? '' ) ) );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function shows_change_address( array $package ): bool {
		return ! self::is_pickup( $package );
	}

	/**
	 * Compact managed shipping rows: keep the rate, drop repeated destination/contents.
	 *
	 * @param array<string, mixed> $package
	 */
	public static function rewrite_managed_package_html( string $html, array $package ): string {
		if ( ! self::is_managed( $package ) ) {
			return $html;
		}

		if ( self::is_pickup( $package ) ) {
			$html = self::rewrite_pickup_package_html( $html, $package );
			$html = (string) preg_replace( '/<p[^>]*class="[^"]*woocommerce-shipping-contents[^"]*"[^>]*>.*?<\/p>/s', '', $html );

			return $html;
		}

		$meta     = DeliveryGroupIdentity::package_meta( $package );
		$locality = is_array( $meta ) ? trim( (string) ( $meta['locality_label'] ?? '' ) ) : '';
		if ( '' === $locality ) {
			return $html;
		}

		$html = (string) preg_replace( '/<p[^>]*class="[^"]*woocommerce-shipping-contents[^"]*"[^>]*>.*?<\/p>/s', '', $html );
		$html = (string) preg_replace( '/<p[^>]*class="[^"]*woocommerce-shipping-destination[^"]*"[^>]*>.*?<\/p>/s', '', $html );
		$html = (string) preg_replace( '/Shipping to\s+(?:<[^>]+>)?[^<]*(?:<\/[^>]+>)?\.?\s*/i', '', $html );
		$html = (string) preg_replace( '/<a\b[^>]*>\s*Change address\s*<\/a>/i', '', $html );
		$delivery_to = CustomerStorefrontCopy::delivery_to( $locality );
		$html        = (string) preg_replace( '/' . preg_quote( $delivery_to, '/' ) . '\s*:\s*/', '', $html );

		return $html;
	}

	/**
	 * Replace WooCommerce "Shipping to" / "Change address" markup on pickup packages.
	 *
	 * @param array<string, mixed> $package
	 */
	public static function rewrite_pickup_package_html( string $html, array $package ): string {
		if ( ! self::is_pickup( $package ) ) {
			return $html;
		}

		$address     = self::pickup_address( $package );
		$pickup_copy = $address;
		$destination_html = '' !== $pickup_copy
			? '<p class="woocommerce-shipping-destination">' . esc_html( $pickup_copy ) . '</p>'
			: '';

		$destination_pattern = '/<p[^>]*class="[^"]*woocommerce-shipping-destination[^"]*"[^>]*>.*?<\/p>/s';

		if ( 1 === preg_match( $destination_pattern, $html ) ) {
			$html = (string) preg_replace( $destination_pattern, $destination_html, $html, 1 );
		} elseif ( '' !== $destination_html ) {
			if ( str_contains( $html, '</ul>' ) ) {
				$html = (string) preg_replace( '/<\/ul>/', '</ul>' . $destination_html, $html, 1 );
			} else {
				$html .= $destination_html;
			}
		}

		$html = (string) preg_replace( '/<form\b[^>]*woocommerce-shipping-calculator[^>]*>.*?<\/form>/s', '', $html );
		$html = (string) preg_replace( '/<p\b[^>]*woocommerce-shipping-calculator[^>]*>.*?<\/p>/s', '', $html );
		$html = (string) preg_replace( '/Shipping to\s+(?:<[^>]+>)?[^<]*(?:<\/[^>]+>)?\.?\s*/i', '', $html );
		$html = (string) preg_replace( '/<a\b[^>]*>\s*Change address\s*<\/a>/i', '', $html );
		$html = (string) preg_replace( '/<button\b[^>]*>\s*Change address\s*<\/button>/i', '', $html );

		return $html;
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function is_managed( array $package ): bool {
		$meta = DeliveryGroupIdentity::package_meta( $package );

		return is_array( $meta ) && ! empty( $meta['managed'] );
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
	 * @param mixed $template_name
	 */
	private static function is_cart_shipping_template( $template_name ): bool {
		if ( ! is_string( $template_name ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $template_name );

		return str_ends_with( $normalized, 'cart/cart-shipping.php' )
			|| 'cart-shipping.php' === $normalized;
	}

	private static function is_shipping_to_string( string $value ): bool {
		return 'Shipping to %s.' === $value
			|| 'Shipping to %s' === $value;
	}

	private static function is_change_address_string( string $value ): bool {
		return 'Change address' === $value;
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
