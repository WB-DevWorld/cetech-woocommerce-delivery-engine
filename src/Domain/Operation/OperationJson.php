<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** Bounded codec only. DTO/schema validation is required before facts are used. */
final class OperationJson {
	public const MAX_BYTES = 16384;
	public const MAX_DEPTH = 8;
	public const MAX_NODES = 256;

	public static function encode( \stdClass $value ): string {
		try {
			$nodes = 0;
			self::assert_limits( $value, 0, $nodes );
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $json ) > self::MAX_BYTES ) {
				throw new \InvalidArgumentException();
			}
			return $json;
		} catch ( \Throwable ) {
			throw new \InvalidArgumentException( 'Invalid or oversized operation JSON.' );
		}
	}

	public static function decode( string $json ): \stdClass {
		try {
			if ( strlen( $json ) > self::MAX_BYTES ) {
				throw new \InvalidArgumentException();
			}
			$value = json_decode( $json, false, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR );
			if ( ! $value instanceof \stdClass ) {
				throw new \InvalidArgumentException();
			}
			$nodes = 0;
			self::assert_limits( $value, 0, $nodes );
			$offset = 0;
			self::scan_duplicates( $json, $offset );
			return $value;
		} catch ( \Throwable ) {
			throw new \InvalidArgumentException( 'Invalid or oversized operation JSON.' );
		}
	}

	public static function exact_fields( \stdClass $value, array $fields ): array {
		$data = get_object_vars( $value );
		if ( count( $data ) !== count( $fields ) || [] !== array_diff( $fields, array_keys( $data ) ) ) {
			throw new \InvalidArgumentException( 'Operation JSON fields are incomplete or unknown.' );
		}
		return $data;
	}

	private static function assert_limits( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES ) {
			throw new \InvalidArgumentException();
		}
		if ( is_array( $value ) || $value instanceof \stdClass ) {
			foreach ( $value as $item ) {
				self::assert_limits( $item, $depth + 1, $nodes );
			}
		} elseif ( ! is_null( $value ) && ! is_string( $value ) && ! is_bool( $value ) && ! is_int( $value ) ) {
			throw new \InvalidArgumentException();
		}
	}

	/** JSON already passed its parser; this preserves duplicate-key rejection. */
	private static function scan_duplicates( string $json, int &$offset ): void {
		self::skip_space( $json, $offset );
		$kind = $json[ $offset ];
		if ( '{' === $kind ) {
			++$offset;
			$seen = [];
			self::skip_space( $json, $offset );
			while ( '}' !== $json[ $offset ] ) {
				$start = $offset;
				self::skip_string( $json, $offset );
				$key = json_decode( substr( $json, $start, $offset - $start ), true, 2, JSON_THROW_ON_ERROR );
				$marker = ':' . $key;
				if ( isset( $seen[ $marker ] ) ) {
					throw new \InvalidArgumentException();
				}
				$seen[ $marker ] = true;
				self::skip_space( $json, $offset );
				++$offset; // Colon, validated by json_decode.
				self::scan_duplicates( $json, $offset );
				self::skip_space( $json, $offset );
				if ( ',' !== $json[ $offset ] ) {
					break;
				}
				++$offset;
				self::skip_space( $json, $offset );
			}
			++$offset;
		} elseif ( '[' === $kind ) {
			++$offset;
			self::skip_space( $json, $offset );
			while ( ']' !== $json[ $offset ] ) {
				self::scan_duplicates( $json, $offset );
				self::skip_space( $json, $offset );
				if ( ',' !== $json[ $offset ] ) {
					break;
				}
				++$offset;
			}
			++$offset;
		} elseif ( '"' === $kind ) {
			self::skip_string( $json, $offset );
		} else {
			$length = strlen( $json );
			while ( $offset < $length && ! str_contains( " \t\r\n,]}", $json[ $offset ] ) ) {
				++$offset;
			}
		}
	}

	private static function skip_string( string $json, int &$offset ): void {
		++$offset;
		while ( '"' !== $json[ $offset ] ) {
			if ( '\\' === $json[ $offset ] ) {
				++$offset;
			}
			++$offset;
		}
		++$offset;
	}

	private static function skip_space( string $json, int &$offset ): void {
		$length = strlen( $json );
		while ( $offset < $length && str_contains( " \t\r\n", $json[ $offset ] ) ) {
			++$offset;
		}
	}
}
