<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** SQL scalar boundaries only; no coercion from floats, objects or unknown fields. */
final class QuoteStorageCodec {
	public static function exact( array $row, array $fields ): void { QuoteShape::fields( $row, $fields ); }
	public static function integer( mixed $value, int $min = 1, int $max = PHP_INT_MAX ): int {
		if ( is_int( $value ) ) { return QuoteShape::integer( $value, $min, $max ); }
		$largest = (string) $max;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > strlen( $largest )
			|| ( strlen( $value ) === strlen( $largest ) && strcmp( $value, $largest ) > 0 ) ) { QuoteShape::invalid(); }
		return QuoteShape::integer( (int) $value, $min, $max );
	}
	public static function time( mixed $value ): QuoteTime { if ( ! is_string( $value ) ) { QuoteShape::invalid(); } return QuoteTime::parse( $value ); }
	public static function uuid( mixed $value ): QuoteId { if ( ! is_string( $value ) ) { QuoteShape::invalid(); } return QuoteId::from_string( $value ); }
	public static function json( mixed $value, int $max = QuoteJson::MAX_BYTES ): array { if ( ! is_string( $value ) ) { QuoteShape::invalid(); } return QuoteJson::decode( $value, $max ); }
	public static function nullable_time( mixed $value ): ?QuoteTime { return null === $value ? null : self::time( $value ); }
	public static function nullable_digest( mixed $value ): ?string { return null === $value ? null : QuoteShape::digest( $value ); }
}
