<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};

/** Bounded private placement facts. Digests exclude themselves and payment outcomes. */
final class QuoteNativeOrderFacts {
	public const META_DRAFT = '_cetech_de_quote_native_draft';
	public const META_LINE_KEY = '_cetech_de_quote_line_key';
	public const META_REFERENCE = '_cetech_de_quote_reference';
	public const META_TAX_SOURCE = '_cetech_de_quote_native_tax_source';
	public static function encode( array $facts ): string {
		$nodes = 0; $normalized = self::normalize( $facts, 0, $nodes );
		$json = json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > 4194304 ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
		return $json;
	}
	public static function digest( string $purpose, array $facts ): string { return hash( 'sha256', 'cetech-quote-order-' . $purpose . '-v1:' . self::encode( $facts ) ); }
	public static function mapping( \WC_Order $order, QuoteStoredRow $quote ): array {
		$context = $quote->context()?->private_facts(); $items = $order->get_items( 'line_item' );
		if ( null === $context || count( $items ) > 200 || count( $items ) !== count( $context['lines'] ) || $order->get_id() < 1 ) { self::fail(); }
		$mapped = []; $claimed = [];
		foreach ( $context['lines'] as $line ) {
			$pool = []; $keyed = [];
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || $item->get_id() < 1 || $item->get_order_id( 'edit' ) !== $order->get_id() ) { self::fail(); }
				if ( isset( $claimed[$item->get_id()] ) ) { continue; }
				$key = $item->get_meta( QuoteNativeOrderFacts::META_LINE_KEY, true );
				if ( '' === $key ) { $key = $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ); }
				if ( $item->get_product_id() === $line['product_id'] && ( $item->get_variation_id() ?: null ) === $line['variation_id'] && self::quantity( $item->get_quantity() ) === $line['quantity'] ) {
					$pool[] = $item; if ( $key === $line['line_key'] ) { $keyed[] = $item; }
				} elseif ( $key === $line['line_key'] ) { self::fail(); }
			}
			$pool = [] !== $keyed ? $keyed : $pool;
			if ( 1 !== count( $pool ) ) { self::fail(); }
			$item = $pool[0]; $claimed[$item->get_id()] = true;
			$mapped[$line['component_key']][] = [ 'line_key' => $line['line_key'], 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => $line['parent_id'], 'quantity' => $line['quantity'], 'item_id' => $item->get_id() ];
		}
		$groups = []; foreach ( $mapped as $component => $lines ) { $groups[] = [ 'component_key' => $component, 'lines' => $lines ]; }
		return QuoteBinding::canonical_mapping( [ 'format_version' => 1, 'groups' => $groups ] );
	}
	public static function native( \WC_Order $order ): array {
		if ( [] !== $order->get_items( 'fee' ) || [] !== $order->get_items( 'coupon' ) ) { self::fail(); }
		$key = $order->get_order_key( 'edit' ); if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 128 ) { self::fail(); }
		$facts = [ 'currency' => $order->get_currency( 'edit' ), 'customer_id' => $order->get_customer_id( 'edit' ), 'order_key' => $key, 'total' => self::decimal( $order->get_total( 'edit' ) ), 'total_tax' => self::decimal( $order->get_total_tax( 'edit' ) ), 'cart_tax' => self::decimal( $order->get_cart_tax( 'edit' ) ), 'shipping_total' => self::decimal( $order->get_shipping_total( 'edit' ) ), 'shipping_tax' => self::decimal( $order->get_shipping_tax( 'edit' ) ), 'address' => [], 'lines' => [], 'shipping' => [], 'tax' => [] ];
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $field ) { $facts['address'][$field] = $order->{'get_shipping_' . $field}( 'edit' ); }
		foreach ( $order->get_items( 'line_item' ) as $item ) { $facts['lines'][] = [ 'id' => $item->get_id(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'quantity' => self::quantity( $item->get_quantity() ), 'total' => self::decimal( $item->get_total( 'edit' ) ), 'tax' => self::decimal( $item->get_total_tax( 'edit' ) ), 'subtotal' => self::decimal( $item->get_subtotal( 'edit' ) ), 'subtotal_tax' => self::decimal( $item->get_subtotal_tax( 'edit' ) ), 'taxes' => $item->get_taxes( 'edit' ) ]; }
		foreach ( $order->get_items( 'shipping' ) as $item ) { $instance = $item->get_instance_id( 'edit' ); if ( is_string( $instance ) && ctype_digit( $instance ) ) { $instance = (int) $instance; } if ( ! is_int( $instance ) || $instance < 0 ) { self::fail(); } $facts['shipping'][] = [ 'id' => $item->get_id(), 'method_id' => $item->get_method_id( 'edit' ), 'instance_id' => $instance, 'group_id' => $item->get_meta( 'cetech_de_group_id', true ), 'label' => $item->get_name( 'edit' ), 'total' => self::decimal( $item->get_total( 'edit' ) ), 'tax' => self::decimal( $item->get_total_tax( 'edit' ) ), 'taxes' => $item->get_taxes( 'edit' ) ]; }
		foreach ( $order->get_items( 'tax' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Tax || $item->get_id() < 1 || $item->get_order_id( 'edit' ) !== $order->get_id() ) { self::fail(); }
			$rate = $item->get_rate_id( 'edit' ); $label = $item->get_label( 'edit' ); $code = $item->get_name( 'edit' ); $compound = $item->get_compound( 'edit' ); $percent = $item->get_rate_percent( 'edit' );
			if ( ! is_int( $rate ) || $rate < 1 || ! is_string( $label ) || strlen( $label ) > 512 || ! is_string( $code ) || strlen( $code ) > 512 || ! is_bool( $compound ) || null !== $percent && ! is_float( $percent ) && ! is_int( $percent ) && ! is_string( $percent ) ) { self::fail(); }
			$facts['tax'][] = [ 'id' => $item->get_id(), 'rate_id' => $rate, 'label' => $label, 'code' => $code, 'compound' => $compound, 'rate_percent' => null === $percent ? null : self::decimal( (string) $percent ), 'tax' => self::decimal( (string) $item->get_tax_total( 'edit' ) ), 'shipping_tax' => self::decimal( (string) $item->get_shipping_tax_total( 'edit' ) ) ];
		}
		foreach ( [ 'lines', 'shipping', 'tax' ] as $type ) { usort( $facts[$type], static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] ); } QuoteNativeOrderFacts::encode( $facts ); return $facts;
	}
	/** Compare saved native commerce bytes without loading Woo or invoking its tax engine. */
	public static function assert_physical( array $physical, array $native, bool $hpos ): void {
		$meta = []; foreach ( $physical['order_meta'] as $row ) { $meta[$row['meta_key']][] = $row['meta_value']; }
		if ( $hpos ) {
			$row = $physical['order'][0]; $money = $physical['money'][0];
			if ( $row['currency'] !== $native['currency'] || $money['order_key'] !== $native['order_key'] || (int) $row['customer_id'] !== $native['customer_id'] || self::decimal( $row['total_amount'] ) !== $native['total'] || self::decimal( $row['tax_amount'] ) !== $native['cart_tax'] || self::decimal( $money['shipping_total_amount'] ) !== $native['shipping_total'] || self::decimal( $money['shipping_tax_amount'] ) !== $native['shipping_tax'] ) { self::fail(); }
			$address = array_values( array_filter( $physical['address'], static fn( array $row ): bool => 'shipping' === $row['address_type'] ) ); if ( 1 !== count( $address ) ) { self::fail(); }
			foreach ( $native['address'] as $key => $value ) { if ( ( $address[0][$key] ?? '' ) !== $value ) { self::fail(); } }
		} else {
			if ( 'shop_order' !== $physical['order'][0]['post_type'] ) { self::fail(); }
			foreach ( [ '_order_key' => $native['order_key'], '_order_currency' => $native['currency'], '_customer_user' => (string) $native['customer_id'] ] as $key => $value ) { if ( ( $meta[$key] ?? [] ) !== [ $value ] ) { self::fail(); } }
			foreach ( [ '_order_total' => 'total', '_order_tax' => 'cart_tax', '_order_shipping' => 'shipping_total', '_order_shipping_tax' => 'shipping_tax' ] as $key => $field ) { if ( 1 !== count( $meta[$key] ?? [] ) || self::decimal( $meta[$key][0] ) !== $native[$field] ) { self::fail(); } }
			foreach ( $native['address'] as $key => $value ) { $saved = $meta['_shipping_' . $key] ?? []; if ( $saved !== [ $value ] && ! ( '' === $value && [] === $saved ) ) { self::fail(); } }
		}
		$items = []; foreach ( $physical['item_meta'] as $row ) { $items[(int) $row['order_item_id']][$row['meta_key']][] = $row['meta_value']; }
		$expected_items = []; foreach ( [ 'lines' => 'line_item', 'shipping' => 'shipping', 'tax' => 'tax' ] as $source => $type ) { foreach ( $native[$source] as $item ) { $expected_items[$item['id']] = $type; } } $actual_items = [];
		foreach ( $physical['items'] as $row ) { $id = (int) $row['order_item_id']; if ( isset( $actual_items[$id] ) || (int) $row['order_id'] < 1 ) { self::fail(); } $actual_items[$id] = $row['order_item_type']; } ksort( $actual_items, SORT_NUMERIC ); ksort( $expected_items, SORT_NUMERIC ); if ( $actual_items !== $expected_items ) { self::fail(); }
		foreach ( $native['lines'] as $line ) {
			$raw = $items[$line['id']] ?? [];
			foreach ( [ '_product_id' => (string) $line['product_id'], '_variation_id' => (string) $line['variation_id'], '_qty' => $line['quantity'] ] as $key => $value ) { if ( 1 !== count( $raw[$key] ?? [] ) || ( '_qty' === $key ? self::quantity( $raw[$key][0] ) : $raw[$key][0] ) !== $value ) { self::fail(); } }
			foreach ( [ '_line_total' => 'total', '_line_tax' => 'tax', '_line_subtotal' => 'subtotal', '_line_subtotal_tax' => 'subtotal_tax' ] as $key => $field ) { if ( 1 !== count( $raw[$key] ?? [] ) || self::decimal( $raw[$key][0] ) !== $line[$field] ) { self::fail(); } }
			if ( ( $raw['_line_tax_data'] ?? [] ) !== [ serialize( $line['taxes'] ) ] ) { self::fail(); }
		}
		foreach ( $native['shipping'] as $line ) { $raw = $items[$line['id']] ?? []; foreach ( [ 'method_id' => $line['method_id'], 'instance_id' => (string) $line['instance_id'], 'cost' => $line['total'], 'total_tax' => $line['tax'] ] as $key => $value ) { if ( 1 !== count( $raw[$key] ?? [] ) || ( in_array( $key, [ 'method_id', 'instance_id' ], true ) ? $raw[$key][0] : self::decimal( $raw[$key][0] ) ) !== $value ) { self::fail(); } } if ( ( $raw['taxes'] ?? [] ) !== [ serialize( $line['taxes'] ) ] ) { self::fail(); } }
		foreach ( $native['tax'] as $line ) {
			$raw = $items[$line['id']] ?? [];
			foreach ( [ 'rate_id' => (string) $line['rate_id'], 'label' => $line['label'], 'compound' => $line['compound'] ? '1' : '' ] as $key => $value ) { if ( ( $raw[$key] ?? [] ) !== [ $value ] ) { self::fail(); } }
			foreach ( [ 'tax_amount' => 'tax', 'shipping_tax_amount' => 'shipping_tax' ] as $key => $field ) { if ( 1 !== count( $raw[$key] ?? [] ) || self::decimal( $raw[$key][0] ) !== $line[$field] ) { self::fail(); } }
			if ( null !== $line['rate_percent'] && ( 1 !== count( $raw['rate_percent'] ?? [] ) || self::decimal( $raw['rate_percent'][0] ) !== $line['rate_percent'] ) ) { self::fail(); }
			$physical_item = array_values( array_filter( $physical['items'], static fn( array $row ): bool => (int) $row['order_item_id'] === $line['id'] ) ); if ( 1 !== count( $physical_item ) || $physical_item[0]['order_item_name'] !== $line['code'] ) { self::fail(); }
		}
	}
	private static function decimal( mixed $value ): string { if ( ! is_string( $value ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,12}))?\z/D', $value, $m ) ) { self::fail(); } $fraction = rtrim( $m[2] ?? '', '0' ); return $m[1] . ( '' === $fraction ? '' : '.' . $fraction ); }
	private static function quantity( mixed $quantity ): string { if ( is_float( $quantity ) && is_finite( $quantity ) && floor( $quantity ) === $quantity ) { $quantity = (int) $quantity; } if ( is_int( $quantity ) ) { $quantity = (string) $quantity; } $value = self::decimal( $quantity ); if ( '0' === $value ) { self::fail(); } return $value; }
	private static function fail(): never { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
	private static function normalize( mixed $value, int $depth, int &$nodes ): mixed {
		if ( $depth > 24 || ++$nodes > 120000 ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) { ksort( $value, SORT_STRING ); }
			$out = []; foreach ( $value as $key => $child ) { $out[$key] = self::normalize( $child, $depth + 1, $nodes ); } return $out;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) { return $value; }
		throw new \UnexpectedValueException( 'Saved quote facts unavailable.' );
	}
}
