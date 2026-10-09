<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning;

/**
 * Persists the plugin schema version in wp_options.
 */
final class SchemaVersion {

	public const OPTION_NAME = 'cetech_de_db_version';

	/**
	 * Target schema version for the current plugin tree (additive immutable promise storage).
	 */
	public const TARGET = '10';

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
		$stored = get_option( self::OPTION_NAME, null );
		if ( null === $stored || $version !== (string) $stored ) {
			throw new \RuntimeException( 'Schema version could not be persisted.' );
		}
	}

	public static function ensure_initialized(): void {
		if ( null === get_option( self::OPTION_NAME, null ) ) {
			add_option( self::OPTION_NAME, self::FOUNDATION, '', false );
		}
		if ( null === get_option( self::OPTION_NAME, null ) ) {
			throw new \RuntimeException( 'Schema version could not be initialized.' );
		}
	}
}
