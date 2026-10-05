<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning;

use CetechDeliveryEngine\Support\Logger;

/**
 * Discovers migration classes from database/migrations/.
 */
final class MigrationDiscovery {

	/**
	 * @return list<MigrationInterface>
	 */
	public static function discover( string $migrations_path, Logger $logger ): array {
		if ( ! is_dir( $migrations_path ) ) {
			$logger->info(
				'Migration directory not found.',
				[ 'path' => $migrations_path ]
			);

			return [];
		}

		$files = glob( trailingslashit( $migrations_path ) . '*.php' );

		if ( false === $files || [] === $files ) {
			return [];
		}

		sort( $files, SORT_STRING );

		$migrations = [];

		foreach ( $files as $file ) {
			$migration = self::load_migration_file( $file, $logger );

			if ( null === $migration ) {
				self::record_discovery_failure( $file, $logger );
				return [];
			}
			$migrations[] = $migration;
		}

		return $migrations;
	}

	private static function record_discovery_failure( string $file, Logger $logger ): void {
		$error = sprintf( 'Migration discovery failed at %s; no migrations will be applied.', basename( $file ) );
		$logger->error( $error );
		try {
			MigrationStatus::record( [ 'status' => 'failed', 'error' => $error, 'file' => basename( $file ) ] );
		} catch ( \Throwable $exception ) {
			$logger->error( 'Migration discovery failure status could not be persisted.', [ 'error' => $exception->getMessage() ] );
		}
	}

	private static function load_migration_file( string $file, Logger $logger ): ?MigrationInterface {
		if ( ! is_readable( $file ) ) {
			$logger->warning(
				'Migration file is not readable.',
				[ 'file' => basename( $file ) ]
			);

			return null;
		}

		try {
			$migration = require $file;
		} catch ( \Throwable $exception ) {
			$logger->error(
				'Migration file failed to load.',
				[
					'file'  => basename( $file ),
					'error' => $exception->getMessage(),
				]
			);

			return null;
		}

		if ( $migration instanceof MigrationInterface ) {
			return $migration;
		}

		$logger->warning(
			'Migration file did not return a MigrationInterface instance.',
			[ 'file' => basename( $file ) ]
		);

		return null;
	}
}
