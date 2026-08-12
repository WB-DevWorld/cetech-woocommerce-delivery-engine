<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

/**
 * Loads production contracts that must exist before classes or migrations are evaluated.
 *
 * Composer PSR-4/classmap is still the primary autoloader. These files are also
 * required explicitly so a mixed or incomplete WordPress ZIP replacement cannot
 * fatal later with "Interface not found" while loading an implementing class.
 */
final class RuntimeContracts {

	/**
	 * Relative paths from the plugin root. Exact Linux case.
	 *
	 * @return list<string>
	 */
	public static function relative_paths(): array {
		return [
			'src/Core/Versioning/MigrationInterface.php',
			'src/Core/Versioning/VerifiableMigrationInterface.php',
			'src/Application/Runtime/VariationRelationshipInspectorInterface.php',
		];
	}

	/**
	 * @return list<class-string>
	 */
	public static function interface_names(): array {
		return [
			'CetechDeliveryEngine\\Core\\Versioning\\MigrationInterface',
			'CetechDeliveryEngine\\Core\\Versioning\\VerifiableMigrationInterface',
			'CetechDeliveryEngine\\Application\\Runtime\\VariationRelationshipInspectorInterface',
		];
	}

	/**
	 * @return list<string> Missing relative paths.
	 */
	public static function missing_paths( string $plugin_root ): array {
		$plugin_root = rtrim( str_replace( '\\', '/', $plugin_root ), '/' ) . '/';
		$missing     = [];

		foreach ( self::relative_paths() as $relative ) {
			if ( ! is_readable( $plugin_root . $relative ) ) {
				$missing[] = $relative;
			}
		}

		return $missing;
	}

	/**
	 * Require contract files. Safe to call more than once.
	 *
	 * @return bool True when every contract file was readable and loaded.
	 */
	public static function load( string $plugin_root ): bool {
		$plugin_root = rtrim( str_replace( '\\', '/', $plugin_root ), '/' ) . '/';

		foreach ( self::relative_paths() as $relative ) {
			$path = $plugin_root . $relative;

			if ( ! is_readable( $path ) ) {
				return false;
			}

			require_once $path;
		}

		return true;
	}
}
