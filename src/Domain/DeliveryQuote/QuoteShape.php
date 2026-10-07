<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Internal exact-schema helpers; no coercion from request values. */
final class QuoteShape {
	public static function fields( array $data, array $fields ): void {
		if ( array_is_list( $data ) || count( $data ) !== count( $fields ) || [] !== array_diff( $fields, array_keys( $data ) ) ) { self::invalid(); }
	}
	public static function integer( mixed $value, int $min = 1, int $max = PHP_INT_MAX ): int {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) { self::invalid(); }
		return $value;
	}
	public static function digest( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $value ) ) { self::invalid(); }
		return $value;
	}
	public static function machine( mixed $value, int $max = 64 ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $max || 1 !== preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/D', $value ) ) { self::invalid(); }
		return $value;
	}
	public static function choice( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) { self::invalid(); }
		return $value;
	}
	public static function object( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) { self::invalid(); }
		return $value;
	}
	public static function list( mixed $value, int $max = 200, int $min = 0 ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $max || count( $value ) < $min ) { self::invalid(); }
		return $value;
	}
	public static function currency( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Z]{3}\z/D', $value ) ) { self::invalid(); }
		return $value;
	}
	public static function invalid(): never { throw new \InvalidArgumentException( 'Invalid delivery quote facts.' ); }
}
