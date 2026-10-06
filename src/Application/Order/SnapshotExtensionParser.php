<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/** Finite built-in readers only. This class never writes Woo meta or live policy. */
final class SnapshotExtensionParser {
	public function read( array $decoded ): SnapshotExtensionSet {
		if ( ! array_key_exists( 'extensions', $decoded ) ) { return new SnapshotExtensionSet(); }
		$entries = $decoded['extensions'];
		if ( ! is_array( $entries ) || ( [] !== $entries && array_is_list( $entries ) ) || count( $entries ) > 16 ) { return self::malformed_set( is_array( $entries ) ? $entries : [] ); }
		try { $nodes = 0; self::bounded( $entries, 0, $nodes ); if ( strlen( self::encode( $entries ) ) > 65536 ) { return self::malformed_set( $entries ); } }
		catch ( \Throwable ) { return self::malformed_set( $entries ); }
		$results = []; $supported = true;
		foreach ( $entries as $name => $entry ) {
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 128 || ! is_array( $entry ) || ! array_key_exists( 'required', $entry ) || ! is_bool( $entry['required'] ) ) {
				$supported = false;
				if ( is_string( $name ) && '' !== $name && strlen( $name ) <= 128 && 1 === preg_match( '//u', $name ) ) { $results[(string) $name] = new SnapshotExtensionReadResult( 'malformed' ); }
				continue;
			}
			if ( true === $entry['required'] ) { $supported = false; $results[(string) $name] = new SnapshotExtensionReadResult( 'unsupported_required' ); continue; }
			if ( count( $entry ) !== 3 || [] !== array_diff( [ 'version', 'required', 'data' ], array_keys( $entry ) ) || ! is_int( $entry['version'] ) || $entry['version'] < 1 || ! is_array( $entry['data'] ) || ( [] !== $entry['data'] && array_is_list( $entry['data'] ) ) || strlen( self::encode( $entry ) ) > 16384 ) {
				$results[(string) $name] = new SnapshotExtensionReadResult( 'malformed' ); continue;
			}
			if ( ! in_array( $name, SnapshotExtensionFacts::NAMES, true ) || 1 !== $entry['version'] ) { $results[(string) $name] = new SnapshotExtensionReadResult( 'ignored_optional' ); continue; }
			try { $results[(string) $name] = new SnapshotExtensionReadResult( 'recorded', SnapshotExtensionFacts::from_payload( $name, $entry['data'] ) ); }
			catch ( \Throwable ) { $results[(string) $name] = new SnapshotExtensionReadResult( 'malformed' ); }
		}
		return new SnapshotExtensionSet( $results, $supported );
	}
	private static function malformed_set( array $entries = [] ): SnapshotExtensionSet {
		$results = [];
		foreach ( SnapshotExtensionFacts::NAMES as $name ) {
			$required = isset( $entries[$name] ) && is_array( $entries[$name] ) && true === ( $entries[$name]['required'] ?? null );
			$results[$name] = new SnapshotExtensionReadResult( $required ? 'unsupported_required' : 'malformed' );
		}
		return new SnapshotExtensionSet( $results, false );
	}
	private static function encode( array $data ): string { return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 8 ); }
	private static function bounded( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > 5 || ++$nodes > 128 ) { throw new \InvalidArgumentException( 'Snapshot extension budget exceeded.' ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && ( strlen( $key ) > 128 || 1 !== preg_match( '//u', $key ) ) ) { throw new \InvalidArgumentException( 'Invalid snapshot extension shape.' ); }
				self::bounded( $item, $depth + 1, $nodes );
			}
		} elseif ( is_string( $value ) ) { if ( strlen( $value ) > 16384 || 1 !== preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'Snapshot extension budget exceeded.' ); } }
		elseif ( null !== $value && ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) ) { throw new \InvalidArgumentException( 'Invalid snapshot extension shape.' ); }
	}
}
