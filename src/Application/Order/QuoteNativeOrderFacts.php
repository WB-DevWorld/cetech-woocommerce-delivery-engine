<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

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
