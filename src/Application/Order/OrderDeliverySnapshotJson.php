<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/**
 * Bounded, read-only decoding of historical JSON. Never returns repaired bytes.
 *
 * JSON's last-member-wins behaviour is unsuitable for contractual facts, so
 * member names are checked after escape decoding before an associative read.
 */
final class OrderDeliverySnapshotJson {

	public const MAX_BYTES = 1048576;

	public const MAX_DEPTH = 32;

	private function __construct( private readonly string $raw, private int $offset = 0 ) {
	}

	/** @return array<string, mixed> */
	public static function decode( string $raw ): array {
		if ( '' === $raw || strlen( $raw ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'Snapshot JSON is unavailable.' );
		}

		try {
			$object = json_decode( $raw, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
			if ( ! $object instanceof \stdClass ) {
				throw new \InvalidArgumentException( 'Snapshot JSON is unavailable.' );
			}
			$scanner = new self( $raw );
			$scanner->value();
			$decoded = json_decode( $raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException ) {
			throw new \InvalidArgumentException( 'Snapshot JSON is unavailable.' );
		}

		// Preserve the object/list distinction at the two reserved containers.
		// null is an invalid extension container, not an invented empty envelope.
		if ( property_exists( $object, 'extensions' ) && ! $object->extensions instanceof \stdClass ) {
			$decoded['extensions'] = null;
		}
		if ( property_exists( $object, 'groups' ) && $object->groups instanceof \stdClass ) {
			$decoded['groups'] = false;
		}

		return $decoded;
	}

	/** Stored amounts are decimal strings, never floating-point reconstructions. */
	public static function is_decimal_amount( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/\A[0-9]{1,24}(?:\.[0-9]{1,12})?\z/D', $value );
	}

	private function value(): void {
		$this->whitespace();
		$token = $this->raw[ $this->offset ];
		if ( '{' === $token ) {
			$this->object();
			return;
		}
		if ( '[' === $token ) {
			$this->list();
			return;
		}
		if ( '"' === $token ) {
			$this->string();
			return;
		}
		$length = strlen( $this->raw );
		while ( $this->offset < $length && ! str_contains( ",]} \t\r\n", $this->raw[ $this->offset ] ) ) {
			++$this->offset;
		}
	}

	private function object(): void {
		++$this->offset;
		$this->whitespace();
		$names = [];
		if ( '}' === $this->raw[ $this->offset ] ) {
			++$this->offset;
			return;
		}
		while ( true ) {
			$this->whitespace();
			$start = $this->offset;
			$this->string();
			$name = json_decode( substr( $this->raw, $start, $this->offset - $start ), true, 2, JSON_THROW_ON_ERROR );
			$key = 'member:' . $name;
			if ( isset( $names[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Snapshot JSON is unavailable.' );
			}
			$names[ $key ] = true;
			$this->whitespace();
			++$this->offset; // Colon: syntax was already validated by json_decode.
			$this->value();
			$this->whitespace();
			if ( '}' === $this->raw[ $this->offset++ ] ) {
				return;
			}
		}
	}

	private function list(): void {
		++$this->offset;
		$this->whitespace();
		if ( ']' === $this->raw[ $this->offset ] ) {
			++$this->offset;
			return;
		}
		while ( true ) {
			$this->value();
			$this->whitespace();
			if ( ']' === $this->raw[ $this->offset++ ] ) {
				return;
			}
		}
	}

	private function string(): void {
		++$this->offset;
		while ( true ) {
			$token = $this->raw[ $this->offset++ ];
			if ( '\\' === $token ) {
				++$this->offset;
			} elseif ( '"' === $token ) {
				return;
			}
		}
	}

	private function whitespace(): void {
		$length = strlen( $this->raw );
		while ( $this->offset < $length && str_contains( " \t\r\n", $this->raw[ $this->offset ] ) ) {
			++$this->offset;
		}
	}
}
