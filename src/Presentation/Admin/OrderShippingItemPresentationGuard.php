<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingMethodLabel;
use WC_Order_Item_Shipping;

/**
 * Hides Delivery Engine technical shipping-line meta from ordinary WooCommerce UI.
 *
 * Stored values remain intact for snapshots and grouping. Normal staff see the
 * Delivery information panel instead of raw group keys or implementation labels.
 */
final class OrderShippingItemPresentationGuard {

	public const GROUP_ID_META_KEY = 'cetech_de_group_id';

	public const SHIPPING_METHOD_ID = 'delivery_engine_selected_offer';

	/**
	 * Meta keys that are technical package accounting, not staff-facing copy.
	 *
	 * @var list<string>
	 */
	private const TECHNICAL_SHIPPING_META_KEYS = [
		self::GROUP_ID_META_KEY,
		'package_qty',
		'Package Qty',
	];

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_technical_order_item_meta' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'strip_technical_formatted_meta' ], 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', [ $this, 'normalize_display_meta_key' ], 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', [ $this, 'normalize_display_meta_value' ], 10, 3 );
		add_filter( 'woocommerce_order_item_shipping_get_method_title', [ $this, 'operational_shipping_method_title' ], 10, 2 );
		add_filter( 'woocommerce_order_item_shipping_get_name', [ $this, 'operational_shipping_method_title' ], 10, 2 );
	}

	/**
	 * @param list<string> $hidden
	 *
	 * @return list<string>
	 */
	public function hide_technical_order_item_meta( array $hidden ): array {
		foreach ( array_merge(
			self::TECHNICAL_SHIPPING_META_KEYS,
			[
				OrderDeliverySnapshot::META_LINE_SNAPSHOT,
				OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,
			]
		) as $key ) {
			if ( ! in_array( $key, $hidden, true ) ) {
				$hidden[] = $key;
			}
		}

		return $hidden;
	}

	/**
	 * @param array<int|string, mixed> $formatted_meta
	 * @param mixed                    $item
	 *
	 * @return array<int|string, mixed>
	 */
	public function strip_technical_formatted_meta( array $formatted_meta, $item ): array {
		$is_our_shipping = $this->is_delivery_engine_shipping_item( $item );

		foreach ( $formatted_meta as $meta_id => $meta ) {
			$key         = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
			$display_key = is_object( $meta ) && isset( $meta->display_key ) ? (string) $meta->display_key : $key;
			$value       = is_object( $meta ) && isset( $meta->value ) ? $meta->value : null;

			if ( $this->is_technical_meta_key( $key ) || $this->is_technical_meta_key( $display_key ) ) {
				unset( $formatted_meta[ $meta_id ] );
				continue;
			}

			if ( $is_our_shipping && $this->is_redundant_package_accounting_key( $key, $display_key ) ) {
				unset( $formatted_meta[ $meta_id ] );
				continue;
			}

			$display_value = is_object( $meta ) && isset( $meta->display_value ) ? $meta->display_value : $value;

			if (
				$this->is_implementation_shipping_label( $value )
				|| $this->is_implementation_shipping_label( $display_value )
			) {
				unset( $formatted_meta[ $meta_id ] );
			}
		}

		return $formatted_meta;
	}

	/**
	 * @param mixed $display_key
	 * @param mixed $meta
	 * @param mixed $item
	 *
	 * @return mixed
	 */
	public function normalize_display_meta_key( $display_key, $meta, $item ) {
		unset( $meta, $item );

		$key = is_string( $display_key ) ? $display_key : '';

		if ( $this->is_technical_meta_key( $key ) ) {
			return '';
		}

		return $display_key;
	}

	/**
	 * @param mixed $display_value
	 * @param mixed $meta
	 * @param mixed $item
	 *
	 * @return mixed
	 */
	public function normalize_display_meta_value( $display_value, $meta, $item ) {
		$key = '';
		if ( is_object( $meta ) && isset( $meta->key ) ) {
			$key = (string) $meta->key;
		}

		if ( $this->is_technical_meta_key( $key ) ) {
			return '';
		}

		if ( $this->is_delivery_engine_shipping_item( $item ) && $this->is_implementation_shipping_label( $display_value ) ) {
			return SelectedOfferShippingMethodLabel::default_delivery_label();
		}

		return $display_value;
	}

	/**
	 * @param mixed $title
	 * @param mixed $item
	 */
	public function operational_shipping_method_title( $title, $item = null ): string {
		$resolved = is_string( $title ) ? trim( $title ) : '';

		if ( ! $this->is_delivery_engine_shipping_item( $item ) && ! $this->is_implementation_shipping_label( $resolved ) ) {
			return is_string( $title ) ? $title : SelectedOfferShippingMethodLabel::default_delivery_label();
		}

		if ( '' === $resolved || $this->is_implementation_shipping_label( $resolved ) ) {
			return SelectedOfferShippingMethodLabel::default_delivery_label();
		}

		return $resolved;
	}

	/**
	 * @param mixed $item
	 */
	public function is_delivery_engine_shipping_item( $item ): bool {
		if ( ! $item instanceof WC_Order_Item_Shipping ) {
			return false;
		}

		return self::SHIPPING_METHOD_ID === (string) $item->get_method_id();
	}

	public function is_technical_meta_key( string $key ): bool {
		$normalized = strtolower( trim( $key ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( in_array( $normalized, array_map( 'strtolower', self::TECHNICAL_SHIPPING_META_KEYS ), true ) ) {
			return true;
		}

		if ( str_starts_with( $normalized, 'cetech_de_' ) || str_starts_with( $normalized, '_cetech_de_' ) ) {
			return true;
		}

		// Humanized forms WooCommerce may show for raw keys.
		$compact = str_replace( [ ' ', '-', '_' ], '', $normalized );

		return in_array(
			$compact,
			[
				'cetechdegroupid',
				'packageqty',
			],
			true
		);
	}

	/**
	 * @return list<string>
	 */
	public static function technical_shipping_meta_keys(): array {
		return self::TECHNICAL_SHIPPING_META_KEYS;
	}

	private function is_redundant_package_accounting_key( string $key, string $display_key ): bool {
		$candidates = [ strtolower( $key ), strtolower( $display_key ) ];

		foreach ( $candidates as $candidate ) {
			$compact = str_replace( [ ' ', '-', '_' ], '', $candidate );
			if ( in_array( $compact, [ 'packageqty', 'items' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $value
	 */
	private function is_implementation_shipping_label( $value ): bool {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}

		$normalized = strtolower( trim( (string) $value ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( self::SHIPPING_METHOD_ID === $normalized ) {
			return true;
		}

		$compact = str_replace( [ ' ', '-', '_', '—' ], '', $normalized );

		return str_contains( $compact, 'deliveryengineselectedoffer' );
	}
}
