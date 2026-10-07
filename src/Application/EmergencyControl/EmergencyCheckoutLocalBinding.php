<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;

/**
 * Prewarmed native Woo data only. The control-lock comparison invokes no Woo
 * getter, metadata loader, WordPress function, filter or object serializer.
 */
final class EmergencyCheckoutLocalBinding implements \JsonSerializable {
	private const NATIVE_PROPERTIES = [ 'id', 'data', 'changes', 'meta_data', 'items' ];
	private const PROPERTY_OWNERS = [ 'WC_Data', 'WC_Abstract_Order', 'WC_Order', 'WC_Order_Item', 'WC_Order_Item_Product', 'WC_Order_Item_Shipping', 'WC_Meta_Data' ];
	private const META_KEYS = [ OrderDeliverySnapshot::META_LINE_SNAPSHOT, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, OrderDeliverySnapshot::META_CART_ITEM_KEY, 'cetech_de_group_id', 'is_vat_exempt' ];

	private function __construct( private readonly \WC_Order $order, private readonly string $digest, private readonly mixed $raw_site ) {}

	public static function capture( \WC_Order $order ): ?self {
		try {
			$digest = self::digest( $order );
			return null === $digest ? null : new self( $order, $digest, $GLOBALS['blog_id'] ?? null );
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function unchanged(): bool {
		try {
			return ( $GLOBALS['blog_id'] ?? null ) === $this->raw_site && self::digest( $this->order ) === $this->digest;
		} catch ( \Throwable ) {
			return false;
		}
	}

	private static function digest( \WC_Order $order ): ?string {
		$nodes = 0;
		$objects = [];
		$document = self::value( $order, 0, $nodes, $objects );
		return EmergencyCheckoutFacts::bounded_hash( $document );
	}

	private static function value( mixed $value, int $depth, int &$nodes, array &$objects ): mixed {
		if ( ++$nodes > 12000 || $depth > 16 || is_resource( $value ) ) {
			throw new \UnexpectedValueException( 'Checkout local binding is unavailable.' );
		}
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $key => $child ) {
				$result[ $key ] = self::value( $child, $depth + 1, $nodes, $objects );
			}
			return $result;
		}
		if ( ! is_object( $value ) ) {
			return $value;
		}
		$class = get_class( $value );
		if ( in_array( $class, [ 'DateTime', 'DateTimeImmutable', 'WC_DateTime' ], true ) ) {
			// Native date property casting does not invoke overridable format/serialization methods.
			return [ 'date_type' => $class, 'date' => self::value( (array) $value, $depth + 1, $nodes, $objects ) ];
		}
		if ( ! $value instanceof \WC_Order && ! $value instanceof \WC_Order_Item_Product && ! $value instanceof \WC_Order_Item_Shipping ) {
			throw new \UnexpectedValueException( 'Unsupported checkout local object.' );
		}
		$id = spl_object_id( $value );
		if ( isset( $objects[ $id ] ) ) {
			return [ 'reference' => $id ];
		}
		$objects[ $id ] = true;
		$reflection = new \ReflectionObject( $value );
		$result = [ 'class' => $class, 'object' => $id ];
		foreach ( self::NATIVE_PROPERTIES as $name ) {
			if ( ! $reflection->hasProperty( $name ) ) {
				continue;
			}
			$property = $reflection->getProperty( $name );
			if ( ! in_array( $property->getDeclaringClass()->getName(), self::PROPERTY_OWNERS, true ) || $property->isStatic() || ! $property->isInitialized( $value ) ) {
				throw new \UnexpectedValueException( 'Unsupported checkout local property.' );
			}
			$raw = self::raw( $property, $value );
			if ( 'meta_data' === $name ) {
				if ( ! is_array( $raw ) ) {
					throw new \UnexpectedValueException( 'Checkout metadata was not prewarmed.' );
				}
				$result[ $name ] = self::metadata( $raw, $depth, $nodes, $objects );
			} elseif ( 'items' === $name ) {
				if ( ! is_array( $raw ) ) {
					throw new \UnexpectedValueException( 'Checkout items were not prewarmed.' );
				}
				$selected = [];
				foreach ( [ 'line_items', 'shipping_lines' ] as $key ) {
					if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
						throw new \UnexpectedValueException( 'Checkout items were not prewarmed.' );
					}
					$selected[ $key ] = $raw[ $key ];
				}
				$result[ $name ] = self::value( $selected, $depth + 1, $nodes, $objects );
			} else {
				$result[ $name ] = self::value( $raw, $depth + 1, $nodes, $objects );
			}
		}
		if ( ! array_key_exists( 'data', $result ) ) {
			throw new \UnexpectedValueException( 'Checkout native data is unavailable.' );
		}
		return $result;
	}

	private static function metadata( array $metadata, int $depth, int &$nodes, array &$objects ): array {
		$result = [];
		foreach ( $metadata as $index => $meta ) {
			if ( ++$nodes > 12000 ) {
				throw new \UnexpectedValueException( 'Checkout local metadata exceeds its budget.' );
			}
			if ( ! $meta instanceof \WC_Meta_Data ) {
				throw new \UnexpectedValueException( 'Unsupported checkout metadata.' );
			}
			$reflection = new \ReflectionObject( $meta );
			$entry = [];
			$protected = false;
			foreach ( [ 'data', 'current_data' ] as $name ) {
				if ( ! $reflection->hasProperty( $name ) ) {
					continue;
				}
				$property = $reflection->getProperty( $name );
				if ( 'WC_Meta_Data' !== $property->getDeclaringClass()->getName() || ! $property->isInitialized( $meta ) ) {
					throw new \UnexpectedValueException( 'Unsupported checkout metadata property.' );
				}
				$raw = self::raw( $property, $meta );
				if ( ! is_array( $raw ) ) {
					throw new \UnexpectedValueException( 'Unavailable checkout metadata.' );
				}
				$protected = $protected || in_array( $raw['key'] ?? null, self::META_KEYS, true );
				$entry[ $name ] = $raw;
			}
			if ( $protected ) {
				$result[ $index ] = [ 'object' => spl_object_id( $meta ), 'facts' => self::value( $entry, $depth + 1, $nodes, $objects ) ];
			}
		}
		return $result;
	}

	private static function raw( \ReflectionProperty $property, object $object ): mixed {
		// PHP 8.4+ raw access also bypasses property hooks. Pinned Woo properties on 8.3 have none.
		return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object );
	}

	public function jsonSerialize(): never {
		throw new \LogicException( 'Checkout local binding is private.' );
	}
}
