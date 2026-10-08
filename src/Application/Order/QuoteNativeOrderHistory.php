<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Quote ownership survives malformed or missing mandatory history; legacy cannot reclaim it. */
final class QuoteNativeOrderHistory {
	/** Loaded native metadata preserves null values and distinguishes absence from corruption. */
	public static function meta_present( object $object, string $key ): ?bool {
		try {
			$object->get_meta( $key, true );
			$reported = $object->meta_exists( $key );
			if ( ! class_exists( '\WC_Data', false ) || ! $object instanceof \WC_Data ) { return (bool) $reported; }
			$property = new \ReflectionProperty( '\WC_Data', 'meta_data' );
			$entries = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object );
			if ( ! is_array( $entries ) || count( $entries ) > 512 ) { return null; }
			$count = 0;
			foreach ( $entries as $entry ) {
				if ( ! is_object( $entry ) || 'WC_Meta_Data' !== get_class( $entry ) ) { return null; }
				$matches = false;
				foreach ( [ 'data', 'current_data' ] as $name ) {
					$property = new \ReflectionProperty( '\WC_Meta_Data', $name );
					$facts = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $entry ) : $property->getValue( $entry );
					if ( ! is_array( $facts ) || ! array_key_exists( 'key', $facts ) || ! array_key_exists( 'value', $facts ) || count( $facts ) > 3 || [] !== array_diff( array_keys( $facts ), [ 'id', 'key', 'value' ] ) ) { return null; }
					if ( $facts['key'] === $key ) { $matches = true; }
				}
				if ( $matches && ++$count > 1 ) { return null; }
			}
			return $count > 0;
		} catch ( \Throwable ) { return null; }
	}
	public static function owned( \WC_Order $order ): bool {
		foreach ( [ QuoteNativeOrderFacts::META_DRAFT, QuoteNativeOrderFacts::META_REFERENCE, QuoteNativeOrderFacts::META_TAX_SOURCE ] as $key ) { if ( false !== self::meta_present( $order, $key ) ) { return true; } }
		if ( self::object_owned( $order, OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) ) { return true; }
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && self::object_owned( $item, OrderDeliverySnapshot::META_LINE_SNAPSHOT ) ) { return true; }
		}
		return false;
	}
	public static function object_owned( object $object, string $key ): bool {
		if ( false !== DeliveryQuoteSnapshotMarker::exists( $object ) ) { return true; }
		$raw = $object->get_meta( $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) { return false; }
		if ( strlen( $raw ) > 4 * 1024 * 1024 ) { return true; }
		try { $decoded = json_decode( $raw, true, OrderDeliverySnapshotJson::MAX_DEPTH, JSON_THROW_ON_ERROR ); if ( is_array( $decoded ) && array_key_exists( DeliveryQuoteSnapshotEnvelope::MEMBER, $decoded ) ) { return true; } } catch ( \Throwable ) {}
		// A corrupted JSON packet may retain its mandatory name using JSON's
		// ASCII unicode escape spelling. Ownership still cannot fall to legacy.
		$names = preg_replace_callback( '/\\\\u00([0-7][0-9a-fA-F])/', static fn( array $match ): string => chr( hexdec( $match[1] ) ), $raw );
		return null === $names || str_contains( $names, DeliveryQuoteSnapshotEnvelope::MEMBER );
	}
	/** Verification only. No current offer/rate/tax/currency configuration is consulted. */
	public static function verify( \WC_Order $order ): bool {
		try {
			$reader = new OrderDeliverySnapshotReader(); $package = $reader->read_package( $order );
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package->error || 'recorded' !== $package->delivery_quote?->status || null === $package->delivery_quote->envelope ) { return false; }
			$original = $package->delivery_quote->envelope;
			$found = false;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || ! self::object_owned( $item, OrderDeliverySnapshot::META_LINE_SNAPSHOT ) ) { return false; }
				$line = $reader->read_line( $item );
				if ( OrderDeliveryLineReadResult::ERROR_NONE !== $line->error || null === $line->delivery_quote?->envelope || ! $original->matches( $line->delivery_quote->envelope ) ) { return false; }
				$found = true;
			}
			return $found;
		} catch ( \Throwable ) { return false; }
	}
}
