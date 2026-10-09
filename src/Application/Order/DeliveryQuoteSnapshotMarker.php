<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Woo hides null metadata in meta_exists; a persisted marker must still require the packet. */
final class DeliveryQuoteSnapshotMarker {
	private const MAX_META = 512;
	public static function exists( object $object ): ?bool {
		if ( true === $object->meta_exists( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) ) { return true; }
		// Legacy unit doubles have no WC_Data carrier. Native Woo always follows
		// the raw, bounded path below after meta_exists has loaded its metadata.
		if ( ! class_exists( '\WC_Data', false ) || ! $object instanceof \WC_Data ) { return false; }
		try {
			$entries = self::raw( '\WC_Data', 'meta_data', $object );
			if ( ! is_array( $entries ) || count( $entries ) > self::MAX_META ) { return null; }
			foreach ( $entries as $entry ) {
				if ( ! is_object( $entry ) || '\\' . get_class( $entry ) !== '\WC_Meta_Data' ) { return null; }
				foreach ( [ 'data', 'current_data' ] as $property ) {
					$facts = self::raw( '\WC_Meta_Data', $property, $entry );
					if ( ! is_array( $facts ) || ! array_key_exists( 'key', $facts ) || ! array_key_exists( 'value', $facts ) || count( $facts ) > 3 || [] !== array_diff( array_keys( $facts ), [ 'id', 'key', 'value' ] ) || ! is_string( $facts['key'] ) || strlen( $facts['key'] ) > 255 ) { return null; }
					$id = $facts['id'] ?? null;
					if ( null === $id || 0 === $id || '' === $id ) { continue; }
					if ( is_int( $id ) ) { if ( $id < 1 ) { return null; } }
					elseif ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) || strlen( $id ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $id ) === strlen( (string) PHP_INT_MAX ) && strcmp( $id, (string) PHP_INT_MAX ) > 0 ) ) { return null; }
					if ( DeliveryQuoteSnapshotEnvelope::META_FORMAT === $facts['key'] ) { return true; }
				}
			}
			return false;
		} catch ( \Throwable ) { return null; }
	}
	/** New mandatory readers require one unchanged exact marker; duplicate Woo rows cannot collapse. */
	public static function matches( object $object, string $expected ): bool {
		try {
			$value = $object->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT, true );
			if ( $value !== $expected && ( ! ctype_digit( $expected ) || $value !== (int) $expected ) ) { return false; }
			if ( ! class_exists( '\WC_Data', false ) || ! $object instanceof \WC_Data ) { return true === $object->meta_exists( DeliveryQuoteSnapshotEnvelope::META_FORMAT ); }
			$entries = self::raw( '\WC_Data', 'meta_data', $object ); if ( ! is_array( $entries ) || count( $entries ) > self::MAX_META ) { return false; } $count = 0;
			foreach ( $entries as $entry ) {
				if ( ! is_object( $entry ) || '\\' . get_class( $entry ) !== '\WC_Meta_Data' ) { return false; } $matched_sides = 0;
				foreach ( [ 'data', 'current_data' ] as $property ) {
					$facts = self::raw( '\WC_Meta_Data', $property, $entry );
					if ( ! is_array( $facts ) || ! array_key_exists( 'key', $facts ) || ! array_key_exists( 'value', $facts ) || count( $facts ) > 3 || [] !== array_diff( array_keys( $facts ), [ 'id', 'key', 'value' ] ) || ! is_string( $facts['key'] ) || strlen( $facts['key'] ) > 255 ) { return false; }
					if ( DeliveryQuoteSnapshotEnvelope::META_FORMAT === $facts['key'] ) { if ( $facts['value'] !== $expected && $facts['value'] !== (int) $expected ) { return false; } ++$matched_sides; }
				}
				if ( 1 === $matched_sides || ( 2 === $matched_sides && ++$count > 1 ) ) { return false; }
			}
			return 1 === $count;
		} catch ( \Throwable ) { return false; }
	}
	private static function raw( string $class, string $property, object $object ): mixed {
		$reflection = new \ReflectionProperty( $class, $property );
		return method_exists( $reflection, 'getRawValue' ) ? $reflection->getRawValue( $object ) : $reflection->getValue( $object );
	}
}
