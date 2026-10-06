<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/**
 * Immutable fingerprint of a validated command, independent of retry metadata.
 *
 * Lists preserve order. String-keyed arrays and exact stdClass values represent
 * objects. Empty [] is a list; empty stdClass is an object. Operation-specific
 * equivalences belong to the validating caller, never this generic encoder.
 */
final readonly class CanonicalIntent {

	public const MAX_DEPTH = 32;
	public const MAX_NODES = 4096;
	public const MAX_BYTES = 262144;

	private function __construct( private string $digest ) {
	}

	/**
	 * Include the originally opened row identity and revision/precondition here.
	 *
	 * The caller supplies semantic data only. Tokens, attempt/correlation IDs and
	 * attempt timestamps are not command parameters and are not inferred from it.
	 * This fingerprint alone does not authorize, persist, or deduplicate an effect.
	 */
	public static function from_command(
		OperationIdentity $identity,
		mixed $target_identity,
		mixed $preconditions,
		mixed $semantic_payload
	): self {
		$command = [
			'operation'         => $identity->operation,
			'operation_version' => $identity->operation_version,
			'target_key'        => $identity->target_key,
			'target_identity'   => $target_identity,
			'preconditions'     => $preconditions,
			'payload'           => $semantic_payload,
		];
		$encoded = '';
		$nodes = 0;
		$objects = new \SplObjectStorage();
		self::encode_value( $command, 0, $nodes, $encoded, $objects );

		return new self( hash( 'sha256', 'cetech-intent-v1:' . $encoded ) );
	}

	public function fingerprint(): string {
		return $this->digest;
	}

	public function equals( self $other ): bool {
		return hash_equals( $this->digest, $other->digest );
	}

	private static function encode_value(
		mixed $value,
		int $depth,
		int &$nodes,
		string &$encoded,
		\SplObjectStorage $objects
	): void {
		if ( $depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES ) {
			throw new \InvalidArgumentException( 'Canonical intent exceeds its structural limit.' );
		}

		if ( null === $value ) {
			self::append( $encoded, '["null"]' );
			return;
		}
		if ( is_bool( $value ) ) {
			self::append( $encoded, $value ? '["boolean",true]' : '["boolean",false]' );
			return;
		}
		if ( is_int( $value ) ) {
			self::append( $encoded, '["integer","' . $value . '"]' );
			return;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new \InvalidArgumentException( 'Invalid canonical numeric value.' );
			}
			// Network-endian IEEE 754 bytes avoid serialize_precision/locale drift.
			self::append( $encoded, '["float64","' . bin2hex( pack( 'E', $value ) ) . '"]' );
			return;
		}
		if ( is_string( $value ) ) {
			self::append( $encoded, '["string",' . self::encode_string( $value ) . ']' );
			return;
		}
		if ( is_array( $value ) && array_is_list( $value ) ) {
			if ( count( $value ) > self::MAX_NODES - $nodes ) {
				throw new \InvalidArgumentException( 'Canonical intent exceeds its structural limit.' );
			}
			self::append( $encoded, '["list",[' );
			$first = true;
			foreach ( $value as $item ) {
				if ( ! $first ) {
					self::append( $encoded, ',' );
				}
				$first = false;
				self::encode_value( $item, $depth + 1, $nodes, $encoded, $objects );
			}
			self::append( $encoded, ']]' );
			return;
		}

		$is_object = is_object( $value ) && \stdClass::class === get_class( $value );
		if ( ! is_array( $value ) && ! $is_object ) {
			throw new \InvalidArgumentException( 'Unsupported canonical value type.' );
		}
		if ( $is_object ) {
			if ( $objects->offsetExists( $value ) ) {
				throw new \InvalidArgumentException( 'Cyclic canonical object.' );
			}
			$objects->offsetSet( $value );
			$source = $value;
		} else {
			if ( count( $value ) > self::MAX_NODES - $nodes ) {
				throw new \InvalidArgumentException( 'Canonical intent exceeds its structural limit.' );
			}
			$source = $value;
		}

		$members = [];
		$key_bytes = 0;
		foreach ( $source as $key => $member ) {
			// Sparse/integer-keyed PHP arrays have no declared object equivalence.
			if ( ! $is_object && ! is_string( $key ) ) {
				throw new \InvalidArgumentException( 'Invalid canonical object key.' );
			}
			// stdClass property names are strings, including numeric JSON keys.
			$key = (string) $key;
			$key_bytes += strlen( $key );
			if ( $key_bytes > self::MAX_BYTES ) {
				throw new \InvalidArgumentException( 'Canonical intent exceeds its byte limit.' );
			}
			self::encode_string( $key );
			$members[] = [ $key, $member ];
			if ( count( $members ) > self::MAX_NODES - $nodes ) {
				throw new \InvalidArgumentException( 'Canonical intent exceeds its structural limit.' );
			}
		}
		usort( $members, static fn( array $left, array $right ): int => strcmp( $left[0], $right[0] ) );
		self::append( $encoded, '["object",[' );
		$first = true;
		foreach ( $members as [ $key, $member ] ) {
			if ( ! $first ) {
				self::append( $encoded, ',' );
			}
			$first = false;
			self::append( $encoded, '[' . self::encode_string( $key ) . ',' );
			self::encode_value( $member, $depth + 1, $nodes, $encoded, $objects );
			self::append( $encoded, ']' );
		}
		self::append( $encoded, ']]' );
		if ( $is_object ) {
			$objects->offsetUnset( $value );
		}
	}

	private static function encode_string( string $value ): string {
		if ( strlen( $value ) > self::MAX_BYTES || 1 !== preg_match( '//u', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid canonical string.' );
		}
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function append( string &$encoded, string $part ): void {
		if ( strlen( $encoded ) + strlen( $part ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Canonical intent exceeds its byte limit.' );
		}
		$encoded .= $part;
	}
}
