<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning;

/**
 * Persists the plugin schema version in wp_options.
 */
final class SchemaVersion {

	public const OPTION_NAME = 'cetech_de_db_version';

	/**
	 * Target schema version for the current plugin tree (Stage 14B shipment persistence foundation).
	 */
	public const TARGET = '4';

	/**
	 * Legacy foundation schema version before configuration tables existed.
	 */
	public const FOUNDATION = '0';

	public static function target(): string {
		return self::TARGET;
	}

	public static function is_up_to_date(): bool {
		return version_compare( self::get(), self::TARGET, '>=' );
	}

	public static function get(): string {
		$stored = get_option( self::OPTION_NAME, null );

		if ( null === $stored ) {
			return self::FOUNDATION;
		}

		return (string) $stored;
	}

	public static function set( string $version ): void {
		update_option( self::OPTION_NAME, $version, false );
	}

	public static function ensure_initialized(): void {
		if ( null === get_option( self::OPTION_NAME, null ) ) {
			add_option( self::OPTION_NAME, self::FOUNDATION, '', false );
		}
	}
}
