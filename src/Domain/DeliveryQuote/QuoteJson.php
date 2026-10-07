<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Private canonical JSON codec; schemas are validated by the typed carriers. */
final class QuoteJson {
	public const MAX_BYTES = 65536;
	public const MAX_DEPTH = 16;
	public const MAX_NODES = 4096;
	public static function encode( array $value, int $max_bytes = self::MAX_BYTES ): string {
		try {
			self::budget( $max_bytes ); if ( array_is_list( $value ) ) { QuoteShape::invalid(); }
			$nodes = 0; $normalized = self::normalize( $value, 0, $nodes );
			$json = json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $json ) > $max_bytes ) { QuoteShape::invalid(); }
			return $json;
		} catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	public static function decode( string $json, int $max_bytes = self::MAX_BYTES ): array {
		try {
			self::budget( $max_bytes ); if ( strlen( $json ) > $max_bytes ) { QuoteShape::invalid(); }
			$value = json_decode( $json, false, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR );
			if ( ! $value instanceof \stdClass ) { QuoteShape::invalid(); }
			$nodes = 0; self::normalize( $value, 0, $nodes ); $offset = 0; self::scan_duplicates( $json, $offset );
			return self::arrays( $value );
		} catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	/** Validates budgets and detaches references before exact semantic validation. */
	public static function detach( array $value, int $max_bytes = self::MAX_BYTES ): array { return self::decode( self::encode( $value, $max_bytes ), $max_bytes ); }
	private static function budget( int $max_bytes ): void { if ( $max_bytes < 1 || $max_bytes > self::MAX_BYTES ) { QuoteShape::invalid(); } }
	private static function normalize( mixed $value, int $depth, int &$nodes ): mixed {
		if ( $depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) { $out = []; foreach ( $value as $item ) { $out[] = self::normalize( $item, $depth + 1, $nodes ); } return $out; }
			foreach ( array_keys( $value ) as $key ) { if ( ! is_string( $key ) ) { QuoteShape::invalid(); } }
			ksort( $value, SORT_STRING ); $out = new \stdClass(); foreach ( $value as $key => $item ) { $out->{$key} = self::normalize( $item, $depth + 1, $nodes ); } return $out;
		}
		if ( $value instanceof \stdClass && \stdClass::class === get_class( $value ) ) { $data = get_object_vars( $value ); ksort( $data, SORT_STRING ); $out = new \stdClass(); foreach ( $data as $key => $item ) { $out->{$key} = self::normalize( $item, $depth + 1, $nodes ); } return $out; }
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) { return $value; }
		QuoteShape::invalid();
	}
	private static function arrays( mixed $value ): mixed {
		if ( $value instanceof \stdClass ) { $out = []; foreach ( $value as $key => $item ) { $out[$key] = self::arrays( $item ); } return $out; }
		if ( is_array( $value ) ) { return array_map( self::arrays( ... ), $value ); }
		return $value;
	}
	/** Called only after JSON syntax passed; decoded property names detect escaped duplicates. */
	private static function scan_duplicates( string $json, int &$offset ): void {
		self::skip_space( $json, $offset ); $kind = $json[$offset];
		if ( '{' === $kind ) {
			++$offset; $seen = []; self::skip_space( $json, $offset );
			while ( '}' !== $json[$offset] ) {
				$start = $offset; self::skip_string( $json, $offset ); $key = json_decode( substr( $json, $start, $offset - $start ), true, 2, JSON_THROW_ON_ERROR ); $marker = ':' . $key;
				if ( isset( $seen[$marker] ) ) { QuoteShape::invalid(); } $seen[$marker] = true;
				self::skip_space( $json, $offset ); ++$offset; self::scan_duplicates( $json, $offset ); self::skip_space( $json, $offset );
				if ( ',' !== $json[$offset] ) { break; } ++$offset; self::skip_space( $json, $offset );
			} ++$offset;
		} elseif ( '[' === $kind ) {
			++$offset; self::skip_space( $json, $offset );
			while ( ']' !== $json[$offset] ) { self::scan_duplicates( $json, $offset ); self::skip_space( $json, $offset ); if ( ',' !== $json[$offset] ) { break; } ++$offset; }
			++$offset;
		} elseif ( '"' === $kind ) { self::skip_string( $json, $offset ); }
		else { $length = strlen( $json ); while ( $offset < $length && ! str_contains( " \t\r\n,]}", $json[$offset] ) ) { ++$offset; } }
	}
	private static function skip_string( string $json, int &$offset ): void { ++$offset; while ( '"' !== $json[$offset] ) { if ( '\\' === $json[$offset] ) { ++$offset; } ++$offset; } ++$offset; }
	private static function skip_space( string $json, int &$offset ): void { $length = strlen( $json ); while ( $offset < $length && str_contains( " \t\r\n", $json[$offset] ) ) { ++$offset; } }
}
