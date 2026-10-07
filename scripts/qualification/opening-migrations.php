<?php

declare(strict_types=1);

namespace CetechNativeQualification\Migrations;

use CetechDeliveryEngine\Core\Versioning\MigrationDiscovery;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Support\Logger;

/** Only qualification markers are written by these synthetic migrations; no DDL. */
final class NativeSyntheticMigration implements VerifiableMigrationInterface {
	public bool $fail_up = false;
	public bool $fail_verify = false;

	public function __construct( public string $id, public string $version, private string $marker_key ) {}

	public function get_id(): string { return $this->id; }
	public function get_version(): string { return $this->version; }

	public function up(): void {
		$data = \get_option( $this->marker_key );
		++$data['runs'][ $this->id ];
		$data['trace'][] = 'up:' . $this->id;
		$data['applied'][ $this->id ] = true;
		$this->persist( $data );
		if ( $this->fail_up ) {
			throw new \RuntimeException( 'Synthetic qualification up failure: ' . $this->id );
		}
	}

	public function verify(): void {
		$data = \get_option( $this->marker_key );
		++$data['verifications'][ $this->id ];
		$data['trace'][] = 'verify:' . $this->id;
		$this->persist( $data );
		if ( $this->fail_verify || empty( $data['applied'][ $this->id ] ) ) {
			throw new \RuntimeException( 'Synthetic qualification verification failure: ' . $this->id );
		}
	}

	/** @param array<string,mixed> $data */
	private function persist( array $data ): void {
		\update_option( $this->marker_key, $data, false );
		if ( $data !== \get_option( $this->marker_key ) ) {
			throw new \RuntimeException( 'Qualification marker could not be persisted.' );
		}
	}
}

/**
 * Native WP options + direct MariaDB read-backs + actual production runner/classes.
 * Must run ONLY on the disposable qualification site. Production option keys are
 * unavoidable because SchemaVersion/MigrationStatus have no key injection point.
 * Original raw rows/autoload settings are restored even when a check throws.
 * pre_update_option filters simulate write denial by returning the old value;
 * this does not claim a physical database outage, crash, transaction or rollback.
 *
 * @return \Closure(callable(string,bool,array):void):void
 */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== \getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! \defined( 'WP_ADMIN' ) || true !== WP_ADMIN || '1' !== \get_option( 'cetech_opening_qualification_disposable' ) ) {
		throw new \RuntimeException( 'COR010 qualification requires the explicit disposable-site marker.' );
	}
	if ( ! $wpdb instanceof \wpdb || ! \function_exists( 'get_option' ) || ! \class_exists( MigrationRunner::class ) ) {
		throw new \RuntimeException( 'Native WordPress, database and production autoload must be bootstrapped.' );
	}
	$legacy_six = require dirname( __DIR__, 2 ) . '/database/migrations/20260917120000_create_geography_coverage_tables.php';
	$check( 'NATIVE-COR010-schema-target-preserved', '6' === $legacy_six->get_version(), [ 'historical_schema_version' => $legacy_six->get_version() ] );
	$legacy_seven = require dirname( __DIR__, 2 ) . '/database/migrations/20261006191156_create_operation_tables.php';
	$check( 'NATIVE-C03-schema-seven-target', '7' === $legacy_seven->get_version(), [ 'historical_schema_version' => $legacy_seven->get_version(), 'historical_cor010_fixture_versions' => 'unchanged' ] );
	$legacy_eight = require dirname( __DIR__, 2 ) . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php';
	// Retain the historical case ID and schema8 migration proof while checking
	// the current schema9 candidate explicitly.
	$check( 'NATIVE-C04-schema-eight-target', '8' === $legacy_eight->get_version() && '9' === SchemaVersion::target(), [ 'historical_schema_version' => $legacy_eight->get_version(), 'schema_target' => SchemaVersion::target() ] );
	$keys = [ SchemaVersion::OPTION_NAME, MigrationStatus::OPTION_NAME ];
	$raw_row = static function ( string $key ) use ( $wpdb ): ?array {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s", $key ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Native qualification SQL read failed: ' . $wpdb->last_error );
		}
		return \is_array( $row ) ? $row : null;
	};
	$original = [];
	foreach ( $keys as $key ) { $original[ $key ] = $raw_row( $key ); }
	$marker_key = 'cetech_native_migration_' . \bin2hex( \random_bytes( 8 ) );
	$filters = [];
	$directories = [];
	$fixture_globals = [];
	$read_value = static fn ( string $key ): mixed => null === ( $row = $raw_row( $key ) ) ? null : \maybe_unserialize( $row['option_value'] );
	$snapshot = static function () use ( $read_value, $marker_key ): array {
		return [ 'version' => $read_value( SchemaVersion::OPTION_NAME ), 'status' => $read_value( MigrationStatus::OPTION_NAME ), 'markers' => $read_value( $marker_key ) ];
	};
	$clear_filters = static function () use ( &$filters ): void {
		foreach ( $filters as [ $hook, $filter ] ) { \remove_filter( $hook, $filter, 10 ); }
		$filters = [];
	};
	$deny = static function ( string $option, callable $predicate ) use ( &$filters ): void {
		$hook = 'pre_update_option_' . $option;
		$filter = static fn ( mixed $new, mixed $old, string $name ): mixed => $predicate( $new, $old, $name ) ? $old : $new;
		\add_filter( $hook, $filter, 10, 3 );
		$filters[] = [ $hook, $filter ];
	};
	$reset = static function () use ( $marker_key, $snapshot ): array {
		\update_option( SchemaVersion::OPTION_NAME, '0', false );
		\delete_option( MigrationStatus::OPTION_NAME );
		\update_option( $marker_key, [ 'sentinel' => 'keep-me', 'runs' => [ 'one' => 0, 'two' => 0, 'three' => 0 ], 'verifications' => [ 'one' => 0, 'two' => 0, 'three' => 0 ], 'trace' => [], 'applied' => [] ], false );
		$before = $snapshot();
		if ( '0' !== $before['version'] || null !== $before['status'] || 'keep-me' !== $before['markers']['sentinel'] ) { throw new \RuntimeException( 'Native migration fixture reset failed.' ); }
		$one = new NativeSyntheticMigration( 'one', '1', $marker_key );
		$two = new NativeSyntheticMigration( 'two', '2', $marker_key );
		$three = new NativeSyntheticMigration( 'three', '3', $marker_key );
		$runner = new MigrationRunner( new Logger() );
		$runner->set_migrations( [ $three, $one, $two ] );
		return [ $runner, $one, $two, $three ];
	};
	try {
		[ $runner ] = $reset();
		$before = $snapshot();
		$runner->run();
		$after = $snapshot();
		$check( 'NATIVE-COR010-ordered-durable-success', '3' === $after['version'] && 'success' === $after['status']['status'] && 'three' === $after['status']['migration_id'] && [ 'one' => 1, 'two' => 1, 'three' => 1 ] === $after['markers']['runs'] && [ 'up:one', 'verify:one', 'up:two', 'verify:two', 'up:three', 'verify:three' ] === $after['markers']['trace'], [ 'before' => $before, 'after' => $after ] );
		$check( 'NATIVE-COR010-version-status-readback', SchemaVersion::get() === $after['version'] && MigrationStatus::get() === $after['status'], [ 'sql' => $after ] );
		$runner->run();
		$check( 'NATIVE-COR010-success-repeat-no-work', $after === $snapshot(), [ 'before' => $after, 'after' => $snapshot() ] );
		$native_noop = \update_option( SchemaVersion::OPTION_NAME, '3', false );
		SchemaVersion::set( '3' );
		$fixed_status = [ 'recorded_at' => 'qualification-fixed', 'status' => 'success', 'migration_id' => 'three', 'from_version' => '2', 'to_version' => '3' ];
		MigrationStatus::record( $fixed_status );
		$status_noop = \update_option( MigrationStatus::OPTION_NAME, $fixed_status, false );
		MigrationStatus::record( $fixed_status );
		$check( 'NATIVE-COR010-matching-noop-is-success', false === $native_noop && false === $status_noop && '3' === $read_value( SchemaVersion::OPTION_NAME ) && $fixed_status === $read_value( MigrationStatus::OPTION_NAME ), [ 'native_update_returns' => [ 'version' => $native_noop, 'status' => $status_noop ], 'durable' => $snapshot() ] );

		foreach ( [ 'first-up', 'middle-up', 'middle-verify' ] as $scenario ) {
			[ $runner, $one, $two ] = $reset();
			$failed = 'first-up' === $scenario ? $one : $two;
			$failed->fail_up = 'middle-verify' !== $scenario;
			$failed->fail_verify = 'middle-verify' === $scenario;
			$before = $snapshot();
			$runner->run();
			$after = $snapshot();
			$expected_runs = 'first-up' === $scenario ? [ 'one' => 1, 'two' => 0, 'three' => 0 ] : [ 'one' => 1, 'two' => 1, 'three' => 0 ];
			$check( 'NATIVE-COR010-' . $scenario . '-stops-dependent-work', ( 'first-up' === $scenario ? '0' : '1' ) === $after['version'] && 'failed' === $after['status']['status'] && $failed->id === $after['status']['migration_id'] && $expected_runs === $after['markers']['runs'] && 'keep-me' === $after['markers']['sentinel'] && true === $after['markers']['applied'][ $failed->id ], [ 'before' => $before, 'after' => $after, 'fault' => 'synthetic migration callback exception; earlier markers are retained' ] );
			$failed->fail_up = $failed->fail_verify = false;
			$runner->run();
			$retry = $snapshot();
			$expected_runs[ $failed->id ] = 2;
			$expected_runs['two'] = \max( 1, $expected_runs['two'] );
			$expected_runs['three'] = 1;
			$check( 'NATIVE-COR010-' . $scenario . '-retry-in-order', '3' === $retry['version'] && 'success' === $retry['status']['status'] && $expected_runs === $retry['markers']['runs'] && 'keep-me' === $retry['markers']['sentinel'], [ 'before' => $after, 'after' => $retry ] );
		}

		[ $runner ] = $reset();
		$deny( SchemaVersion::OPTION_NAME, static fn ( mixed $new ): bool => '2' === $new );
		$before = $snapshot();
		$runner->run();
		$after = $snapshot();
		$check( 'NATIVE-COR010-required-version-write-denial-stops', '1' === $after['version'] && 'failed' === $after['status']['status'] && 'two' === $after['status']['migration_id'] && \str_contains( $after['status']['error'], 'Schema version could not be persisted' ) && [ 'one' => 1, 'two' => 1, 'three' => 0 ] === $after['markers']['runs'], [ 'before' => $before, 'after' => $after, 'fault' => 'simulated write denial: native pre_update_option returns old value only for required version 2' ] );
		$clear_filters();
		$runner->run();
		$after_retry = $snapshot();
		$check( 'NATIVE-COR010-required-version-retry', '3' === $after_retry['version'] && [ 'one' => 1, 'two' => 2, 'three' => 1 ] === $after_retry['markers']['runs'], [ 'before' => $after, 'after' => $after_retry ] );

		foreach ( [ 'success-only', 'all-status' ] as $scenario ) {
			[ $runner ] = $reset();
			$deny( MigrationStatus::OPTION_NAME, static fn ( mixed $new ): bool => \is_array( $new ) && '2' === ( $new['to_version'] ?? null ) && ( 'all-status' === $scenario || 'success' === ( $new['status'] ?? null ) ) );
			$before = $snapshot();
			$runner->run();
			$after = $snapshot();
			$expected_status = 'success-only' === $scenario ? 'failed' : 'success';
			$expected_id = 'success-only' === $scenario ? 'two' : 'one';
			$check( 'NATIVE-COR010-' . $scenario . '-denial-preserves-verified-version', '2' === $after['version'] && $expected_status === $after['status']['status'] && $expected_id === $after['status']['migration_id'] && [ 'one' => 1, 'two' => 1, 'three' => 0 ] === $after['markers']['runs'], [ 'before' => $before, 'after' => $after, 'fault' => 'simulated status write denial via native pre_update_option; runner catches persistence failures without dependent work' ] );
			$clear_filters();
			$runner->run();
			$retry = $snapshot();
			$check( 'NATIVE-COR010-' . $scenario . '-retry-reconciles-without-up', '3' === $retry['version'] && 'success' === $retry['status']['status'] && 'three' === $retry['status']['migration_id'] && [ 'one' => 1, 'two' => 1, 'three' => 1 ] === $retry['markers']['runs'] && [ 'one' => 1, 'two' => 2, 'three' => 1 ] === $retry['markers']['verifications'] && [ 'up:one', 'verify:one', 'up:two', 'verify:two', 'verify:two', 'up:three', 'verify:three' ] === $retry['markers']['trace'], [ 'before' => $after, 'after' => $retry ] );
			$runner->run();
			$check( 'NATIVE-COR010-' . $scenario . '-recovered-repeat-no-work', $retry === $snapshot(), [ 'before' => $retry, 'after' => $snapshot() ] );
		}

		[ $runner ] = $reset();
		$deny( MigrationStatus::OPTION_NAME, static fn ( mixed $new ): bool => \is_array( $new ) && '2' === ( $new['to_version'] ?? null ) );
		$runner->run();
		$before = $snapshot();
		$runner->run();
		$after = $snapshot();
		$check( 'NATIVE-COR010-denied-reconciliation-still-stops-dependent-work', '2' === $after['version'] && $before['status'] === $after['status'] && [ 'one' => 1, 'two' => 1, 'three' => 0 ] === $after['markers']['runs'] && [ 'one' => 1, 'two' => 2, 'three' => 0 ] === $after['markers']['verifications'], [ 'before' => $before, 'after' => $after, 'fault' => 'simulated status denial remains active during verified-predecessor reconciliation' ] );
		$clear_filters();
		$runner->run();
		$retry = $snapshot();
		$check( 'NATIVE-COR010-denied-reconciliation-retry-without-up', '3' === $retry['version'] && 'success' === $retry['status']['status'] && [ 'one' => 1, 'two' => 1, 'three' => 1 ] === $retry['markers']['runs'] && [ 'one' => 1, 'two' => 3, 'three' => 1 ] === $retry['markers']['verifications'], [ 'before' => $after, 'after' => $retry ] );

		foreach ( [ 'invalid', 'throw', 'valid' ] as $scenario ) {
			[ $runner, $one, $two, $three ] = $reset();
			$directory = \sys_get_temp_dir() . '/cetech-native-migration-' . \bin2hex( \random_bytes( 8 ) );
			if ( ! \mkdir( $directory, 0700 ) ) { throw new \RuntimeException( 'Cannot create discovery fixture directory.' ); }
			$directories[] = $directory;
			$global_key = 'cetech_native_migration_fixture_' . \bin2hex( \random_bytes( 8 ) );
			$fixture_globals[] = $global_key;
			$GLOBALS[ $global_key ] = [ $one, $two, $three ];
			foreach ( [ '01.php' => 0, '02.php' => 1, '03.php' => 2 ] as $filename => $index ) {
				$content = '<?php return $GLOBALS[' . \var_export( $global_key, true ) . '][' . $index . '];';
				if ( 1 === $index && 'invalid' === $scenario ) { $content = '<?php return false;'; }
				if ( 1 === $index && 'throw' === $scenario ) { $content = '<?php throw new \\RuntimeException("Synthetic discovery load failure");'; }
				if ( false === \file_put_contents( $directory . '/' . $filename, $content ) ) { throw new \RuntimeException( 'Cannot write discovery fixture.' ); }
			}
			$before = $snapshot();
			$discovered = MigrationDiscovery::discover( $directory, new Logger() );
			$runner->set_migrations( $discovered );
			$runner->run();
			$after = $snapshot();
			$condition = 'valid' === $scenario
				? 3 === \count( $discovered ) && '3' === $after['version'] && [ 'one' => 1, 'two' => 1, 'three' => 1 ] === $after['markers']['runs']
				: [] === $discovered && '0' === $after['version'] && 'failed' === $after['status']['status'] && '02.php' === $after['status']['file'] && [ 'one' => 0, 'two' => 0, 'three' => 0 ] === $after['markers']['runs'];
			$check( 'NATIVE-COR010-discovery-' . $scenario, $condition, [ 'before' => $before, 'after' => $after, 'discovered_count' => \count( $discovered ), 'limits' => 'present synthetic files only; no absent-middle manifest or real DDL qualification' ] );
		}
	} finally {
		$cleanup_failures = [];
		$attempt_cleanup = static function ( callable $operation ) use ( &$cleanup_failures ): void {
			try { $operation(); } catch ( \Throwable $exception ) { $cleanup_failures[] = $exception->getMessage(); }
		};
		$attempt_cleanup( $clear_filters );
		// Restore both production rows before optional fixture filesystem cleanup;
		// one restoration failure must not prevent attempting the other row.
		foreach ( $original as $key => $row ) {
			$attempt_cleanup( static function () use ( $wpdb, $key, $row ): void {
				if ( null === $row ) {
					$restored = $wpdb->delete( $wpdb->options, [ 'option_name' => $key ], [ '%s' ] );
				} else {
					// Preserve the serialized value, row identity and autoload setting exactly.
					$restored = $wpdb->replace( $wpdb->options, $row, [ '%d', '%s', '%s', '%s' ] );
				}
				if ( false === $restored ) { throw new \RuntimeException( 'Native qualification original option restoration failed: ' . $key ); }
			} );
			$attempt_cleanup( static fn (): bool => \wp_cache_delete( $key, 'options' ) );
		}
		$attempt_cleanup( static fn (): bool => \wp_cache_delete( 'alloptions', 'options' ) );
		$attempt_cleanup( static fn (): bool => \wp_cache_delete( 'notoptions', 'options' ) );
		$attempt_cleanup( static fn (): bool => \delete_option( $marker_key ) );
		foreach ( $fixture_globals as $key ) { unset( $GLOBALS[ $key ] ); }
		foreach ( $directories as $directory ) {
			$attempt_cleanup( static function () use ( $directory ): void {
				foreach ( \glob( $directory . '/*.php' ) ?: [] as $file ) {
					if ( ! \unlink( $file ) ) { throw new \RuntimeException( 'Cannot remove native discovery fixture file.' ); }
				}
				if ( ! \rmdir( $directory ) ) { throw new \RuntimeException( 'Cannot remove native discovery fixture directory.' ); }
			} );
		}
		$restored_rows = [];
		foreach ( $keys as $key ) { $restored_rows[ $key ] = $raw_row( $key ); }
		$check( 'NATIVE-COR010-original-production-options-restored', $original === $restored_rows && null === $raw_row( $marker_key ) && [] === $cleanup_failures, [ 'before_row_hash' => \hash( 'sha256', \serialize( $original ) ), 'after_row_hash' => \hash( 'sha256', \serialize( $restored_rows ) ), 'fixture_marker_deleted' => null === $raw_row( $marker_key ), 'cleanup_failures' => $cleanup_failures ] );
	}
};
