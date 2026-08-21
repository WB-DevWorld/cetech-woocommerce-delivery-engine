<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

/**
 * Treat import packages as hostile. No unserialize. No ZIP slip.
 */
final class ImportPackageGuard {

	public static function assert_json_size( string $json, int $max_bytes = 5242880 ): void {
		if ( strlen( $json ) > $max_bytes ) {
			throw new \InvalidArgumentException( 'Import package is too large.' );
		}
	}

	public static function assert_safe_zip_entry( string $name ): void {
		$normalized = str_replace( '\\', '/', $name );
		if ( str_contains( $normalized, '..' ) || str_starts_with( $normalized, '/' ) || str_contains( $normalized, ':' ) ) {
			throw new \InvalidArgumentException( 'Import archive contains an unsafe path.' );
		}
		if ( str_ends_with( strtolower( $normalized ), '.zip' ) || str_ends_with( strtolower( $normalized ), '.php' ) ) {
			throw new \InvalidArgumentException( 'Import archive contains an unexpected nested or executable file.' );
		}
	}
}
