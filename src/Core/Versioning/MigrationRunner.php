<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning;

use CetechDeliveryEngine\Support\Logger;

/**
 * Runs pending schema migrations idempotently, stopping at the first failure.
 */
final class MigrationRunner {

	/** @var list<MigrationInterface> */
	private array $migrations = [];

	public function __construct(
		private Logger $logger
	) {
	}

	/**
	 * @param list<MigrationInterface> $migrations
	 */
	public function set_migrations( array $migrations ): void {
		$this->migrations = $migrations;
	}

	public function run(): void {
		try {
			SchemaVersion::ensure_initialized();
		} catch ( \Throwable $exception ) {
			$this->record_failure( [], $exception );
			return;
		}

		$current = SchemaVersion::get();

		if ( [] === $this->migrations ) {
			$this->logger->info(
				'No migrations registered; no schema changes attempted.',
				[ 'schema_version' => $current ]
			);
			return;
		}

		usort(
			$this->migrations,
			static fn ( MigrationInterface $a, MigrationInterface $b ): int => version_compare( $a->get_version(), $b->get_version() )
		);

		try {
			$this->assert_unambiguous_chain();
		} catch ( \Throwable $exception ) {
			$this->record_failure( [ 'from_version' => $current, 'to_version' => $current ], $exception );
			return;
		}

		if ( ! $this->reconcile_current_status( $current ) ) {
			return;
		}

		foreach ( $this->migrations as $migration ) {
			if ( version_compare( $migration->get_version(), $current, '<=' ) ) {
				continue;
			}

			if ( ! $this->apply_migration( $migration, $current ) ) {
				return;
			}
			$current = SchemaVersion::get();
		}
	}

	private function assert_unambiguous_chain(): void {
		$ids      = [];
		$previous = null;
		foreach ( $this->migrations as $migration ) {
			$id      = $migration->get_id();
			$version = $migration->get_version();
			if ( isset( $ids[ $id ] ) ) {
				throw new \RuntimeException( sprintf( 'Duplicate migration identity: %s.', $id ) );
			}
			if ( null !== $previous && 0 === version_compare( $previous->get_version(), $version ) ) {
				throw new \RuntimeException(
					sprintf( 'Ambiguous migration version %s: %s and %s.', $version, $previous->get_id(), $id )
				);
			}
			$ids[ $id ] = true;
			$previous  = $migration;
		}
	}

	/**
	 * Repair diagnostics for already persisted progress before dependent work.
	 * Never rerun up() or move the durable version backwards for this repair.
	 */
	private function reconcile_current_status( string $current ): bool {
		foreach ( $this->migrations as $migration ) {
			if ( 0 !== version_compare( $migration->get_version(), $current ) ) {
				continue;
			}
			$status = MigrationStatus::get();
			if (
				'success' === ( $status['status'] ?? null )
				&& $migration->get_id() === ( $status['migration_id'] ?? null )
				&& $current === (string) ( $status['to_version'] ?? '' )
			) {
				return true;
			}

			$context = [
				'migration_id' => $migration->get_id(),
				'from_version' => $current,
				'to_version'   => $current,
				'reconciled'   => true,
			];
			try {
				if ( $migration instanceof VerifiableMigrationInterface ) {
					$migration->verify();
				}
				MigrationStatus::record( array_merge( $context, [ 'status' => 'success' ] ) );
				return true;
			} catch ( \Throwable $exception ) {
				$this->record_failure( $context, $exception );
				return false;
			}
		}
		return true;
	}

	private function apply_migration( MigrationInterface $migration, string $from_version ): bool {
		$context = [
			'migration_id' => $migration->get_id(),
			'from_version' => $from_version,
			'to_version'   => $migration->get_version(),
		];
		$this->logger->info( 'Applying migration.', $context );

		try {
			$migration->up();
			if ( $migration instanceof VerifiableMigrationInterface ) {
				$migration->verify();
			}
			SchemaVersion::set( $migration->get_version() );
			MigrationStatus::record( array_merge( $context, [ 'status' => 'success' ] ) );
			$this->logger->info(
				'Migration applied successfully.',
				[ 'migration_id' => $migration->get_id(), 'schema_version' => $migration->get_version() ]
			);
			return true;
		} catch ( \Throwable $exception ) {
			$this->record_failure( $context, $exception );
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function record_failure( array $context, \Throwable $exception ): void {
		$failure = array_merge( $context, [ 'status' => 'failed', 'error' => $exception->getMessage() ] );
		$this->logger->error( 'Migration failed.', $failure );
		try {
			MigrationStatus::record( $failure );
		} catch ( \Throwable $status_exception ) {
			$this->logger->error(
				'Migration failure status could not be persisted.',
				array_merge( $context, [ 'error' => $status_exception->getMessage(), 'migration_error' => $exception->getMessage() ] )
			);
		}
	}
}
