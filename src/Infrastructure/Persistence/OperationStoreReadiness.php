<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use RuntimeException;
use Throwable;

/** Feature-local inspection. No writes, cache truth, conversion or legacy gate. */
final class OperationStoreReadiness {

	public const MIGRATION_ID = '20261006191156_create_operation_tables';
	private ?object $connection;

	public function __construct( ?object $connection = null, private ?OperationProfileRegistry $profiles = null ) {
		$this->connection = $connection ?? ( $GLOBALS['wpdb'] ?? null );
	}

	/** Refuse incompatibility before dbDelta can issue any ALTER. */
	public function preflight(): void {
		$this->probe( function (): void {
			foreach ( OperationStoreSchema::SUFFIXES as $suffix ) {
				$this->inspect( $suffix, true );
			}
			$this->check_stored_records();
		} );
	}

	/** Exact structure; ordinary readiness deliberately does not scan history. */
	public function verify(): void {
		$this->probe( function (): void {
			foreach ( OperationStoreSchema::SUFFIXES as $suffix ) {
				$this->inspect( $suffix, false );
			}
		} );
	}

	/** Fixed ceilings and bounded pages. Unknown profiles/rows are never migrated. */
	public function verify_stored_records(): void {
		$this->probe( fn () => $this->check_stored_records() );
	}

	/** @return array{ready:bool,code:string} */
	public function get_status(): array {
		try {
			return $this->probe( function (): array {
				$version = $this->read_option( SchemaVersion::OPTION_NAME );
				if ( ! is_string( $version ) || 1 !== preg_match( '/\A[1-9][0-9]{0,8}\z/D', $version ) || version_compare( $version, '7', '<' ) ) {
					return [ 'ready' => false, 'code' => 'schema_unavailable' ];
				}
				$encoded = $this->read_option( MigrationStatus::OPTION_NAME );
				$status = is_string( $encoded ) && strlen( $encoded ) <= 16384
					? @unserialize( $encoded, [ 'allowed_classes' => false, 'max_depth' => 8 ] ) : null;
				if ( ! is_array( $status ) || 'success' !== ( $status['status'] ?? null )
					|| $version !== (string) ( $status['to_version'] ?? '' )
					|| ! is_string( $status['migration_id'] ?? null )
					|| ( '7' === $version && self::MIGRATION_ID !== $status['migration_id'] )
				) {
					return [ 'ready' => false, 'code' => 'migration_unconfirmed' ];
				}
				foreach ( OperationStoreSchema::SUFFIXES as $suffix ) {
					$this->inspect( $suffix, false );
				}
				return [ 'ready' => true, 'code' => 'ready' ];
			} );
		} catch ( Throwable ) {
			return [ 'ready' => false, 'code' => 'store_unverified' ];
		}
	}

	public function assert_ready(): void {
		if ( ! $this->get_status()['ready'] ) {
			self::refuse();
		}
	}

	private function inspect( string $suffix, bool $partial ): void {
		$table = $this->table( $suffix );
		$status = $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( null === $status ) {
			if ( $partial ) { return; }
			self::refuse();
		}
		$collation = $this->site_collation();
		if ( 'InnoDB' !== ( $status['Engine'] ?? null ) || $collation !== ( $status['Collation'] ?? null ) ) {
			self::refuse();
		}
		$columns = $this->rows( "SHOW FULL COLUMNS FROM `{$table}`" );
		$expected = OperationStoreSchema::columns( $suffix );
		$seen = [];
		foreach ( $columns as $column ) {
			$name = $column['Field'] ?? null;
			if ( ! is_string( $name ) || ! isset( $expected[ $name ] ) || isset( $seen[ $name ] ) ) { self::refuse(); }
			$seen[ $name ] = true;
			[ $type, $nullable, $default, $extra, $purpose ] = $expected[ $name ];
			$actual_type = strtolower( (string) ( $column['Type'] ?? '' ) );
			$actual_type = preg_replace( '/\b(bigint|smallint)\([0-9]+\)/', '$1', $actual_type );
			if ( $type !== $actual_type || ( $nullable ? 'YES' : 'NO' ) !== ( $column['Null'] ?? null )
				|| $default !== ( null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'] )
				|| $extra !== strtolower( (string) ( $column['Extra'] ?? '' ) )
				|| ( 'site' === $purpose ? $collation : $purpose ) !== ( $column['Collation'] ?? null )
			) { self::refuse(); }
		}
		$indexes = $this->rows( "SHOW INDEX FROM `{$table}`" );
		$expected_indexes = OperationStoreSchema::indexes( $suffix );
		$seen_indexes = [];
		foreach ( $indexes as $index ) {
			$name = $index['Key_name'] ?? null;
			$position = self::positive_integer( $index['Seq_in_index'] ?? null );
			if ( ! is_string( $name ) || ! isset( $expected_indexes[ $name ] )
				|| isset( $seen_indexes[ $name ][ $position ] )
				|| ( $expected_indexes[ $name ]['unique'] ? '0' : '1' ) !== (string) ( $index['Non_unique'] ?? '' )
				|| null !== ( $index['Sub_part'] ?? null ) || 'BTREE' !== strtoupper( (string) ( $index['Index_type'] ?? '' ) )
				|| 'A' !== ( $index['Collation'] ?? null )
				|| ! isset( $expected_indexes[ $name ]['columns'][ $position - 1 ] )
				|| $expected_indexes[ $name ]['columns'][ $position - 1 ] !== ( $index['Column_name'] ?? null )
			) { self::refuse(); }
			$seen_indexes[ $name ][ $position ] = true;
		}
		$missing = count( $seen ) !== count( $expected );
		foreach ( $expected_indexes as $name => $definition ) {
			if ( isset( $seen_indexes[ $name ] ) && count( $seen_indexes[ $name ] ) !== count( $definition['columns'] ) ) { self::refuse(); }
			$missing = $missing || ! isset( $seen_indexes[ $name ] );
		}
		if ( $missing ) {
			// A partial first install can have only approved compatible structures
			// and no rows. Populated/incompatible partial stores are not repaired.
			if ( ! $partial || null !== $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) { self::refuse(); }
		}
	}

	private function check_stored_records(): void {
		$records = $this->table( OperationStoreSchema::RECORDS_SUFFIX );
		$changes = $this->table( OperationStoreSchema::CHANGES_SUFFIX );
		$records_exist = null !== $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $records ) );
		$changes_exist = null !== $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $changes ) );
		$populated = [];
		foreach ( [ $records => $records_exist, $changes => $changes_exist ] as $table => $exists ) {
			if ( ! $exists ) { continue; }
			if ( null === $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) { continue; }
			$populated[ $table ] = true;
			if ( ! $records_exist || ! $changes_exist ) { self::refuse(); }
			$payload = $table === $records ? 'completion_json' : 'event_json';
			if ( null !== $this->row( "SELECT id FROM `{$table}` WHERE OCTET_LENGTH(`{$payload}`) > 16384 LIMIT 1" ) ) { self::refuse(); }
		}
		if ( ! $records_exist || ! $changes_exist || [] === $populated ) { return; }
		$profiles = $this->profiles ?? new OperationProfileRegistry();
		$this->walk( $records, function ( array $record ) use ( $profiles, $changes ): void {
			$profile = $profiles->get( (string) ( $record['operation'] ?? '' ), self::positive_integer( $record['operation_version'] ?? null ) );
			$event = null;
			if ( null !== ( $record['audit_id'] ?? null ) ) {
				$event = $this->row( $this->prepare( "SELECT * FROM `{$changes}` WHERE id = %d LIMIT 1", self::positive_integer( $record['audit_id'] ) ) );
				if ( null === $event ) { self::refuse(); }
			}
			OperationRecord::from_row( $record, $profile, $event );
			$this->assert_site( $record['site_id'] ?? null );
		} );
		$this->walk( $changes, function ( array $event ) use ( $records, $profiles ): void {
			$record = $this->row( $this->prepare( "SELECT * FROM `{$records}` WHERE id = %d LIMIT 1", self::positive_integer( $event['operation_id'] ?? null ) ) );
			if ( null === $record || 'accepted' !== ( $record['state'] ?? null )
				|| self::positive_integer( $record['audit_id'] ?? null ) !== self::positive_integer( $event['id'] ?? null )
			) { self::refuse(); }
			$profile = $profiles->get( (string) ( $record['operation'] ?? '' ), self::positive_integer( $record['operation_version'] ?? null ) );
			OperationRecord::from_row( $record, $profile, $event );
			$this->assert_site( $event['site_id'] ?? null );
		} );
	}

	private function walk( string $table, callable $validate ): void {
		$row = $this->row( "SELECT MAX(id) AS ceiling FROM `{$table}`" );
		$ceiling = null === ( $row['ceiling'] ?? null ) ? 0 : self::positive_integer( $row['ceiling'] );
		$after = 0;
		while ( $after < $ceiling ) {
			$page = $this->rows( $this->prepare( "SELECT * FROM `{$table}` WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100", $after, $ceiling ) );
			if ( [] === $page ) { break; }
			if ( count( $page ) > 100 ) { self::refuse(); }
			foreach ( $page as $record ) {
				$id = self::positive_integer( $record['id'] ?? null );
				if ( $id <= $after || $id > $ceiling ) { self::refuse(); }
				$validate( $record );
				$after = $id;
			}
		}
	}

	private function assert_site( mixed $site ): void {
		$expected = $this->connection instanceof OperationSession ? $this->connection->site_id()
			: ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
		if ( self::positive_integer( $site ) !== $expected ) { self::refuse(); }
	}

	private function site_collation(): string {
		$connection = $this->require_connection();
		$declaration = $connection instanceof OperationSession ? $connection->charset_collate() : $connection->get_charset_collate();
		$details = OperationStoreSchema::charset_details( $declaration );
		if ( null !== $details['collation'] ) { return $details['collation']; }
		$row = $this->row( $this->prepare( 'SHOW CHARACTER SET LIKE %s', $details['charset'] ) );
		$value = $row['Default collation'] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $value ) ) { self::refuse(); }
		return strtolower( $value );
	}

	private function table( string $suffix ): string {
		$connection = $this->require_connection();
		$prefix = $connection instanceof OperationSession ? $connection->table_prefix() : (string) ( $connection->prefix ?? '' );
		$table = $prefix . TableNames::PREFIX . $suffix;
		if ( '' === $prefix || strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); }
		return $table;
	}

	private function read_option( string $name ): ?string {
		$connection = $this->require_connection();
		$prefix = $connection instanceof OperationSession ? $connection->table_prefix() : (string) ( $connection->prefix ?? '' );
		$table = $prefix . 'options';
		if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); }
		$row = $this->row( $this->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name ) );
		return null === $row ? null : ( is_string( $row['option_value'] ?? null ) ? $row['option_value'] : null );
	}

	private function probe( callable $work ): mixed {
		$connection = $this->require_connection();
		$owned = $connection instanceof OperationSession && ! $connection->in_transaction();
		if ( $owned && ! $connection->begin() ) { self::refuse(); }
		try {
			$result = $work();
		} catch ( Throwable ) {
			if ( $owned && ! $connection->rollback() ) { $connection->retire(); }
			self::refuse();
		}
		if ( $owned && ! $connection->rollback() ) {
			$connection->retire();
			self::refuse();
		}
		return $result;
	}

	private function rows( string $sql ): array {
		$connection = $this->require_connection();
		$result = $connection instanceof OperationSession ? $connection->get_results( $sql ) : $connection->get_results( $sql, ARRAY_A );
		if ( ! is_array( $result ) || $this->failed_query() ) { self::refuse(); }
		foreach ( $result as $row ) { if ( ! is_array( $row ) ) { self::refuse(); } }
		return $result;
	}

	private function row( string $sql ): ?array {
		$connection = $this->require_connection();
		$result = $connection instanceof OperationSession ? $connection->get_row( $sql ) : $connection->get_row( $sql, ARRAY_A );
		if ( false === $result || ( null !== $result && ! is_array( $result ) ) || $this->failed_query() ) { self::refuse(); }
		return $result;
	}

	private function failed_query(): bool {
		return $this->connection instanceof OperationSession ? 0 !== $this->connection->errno()
			: '' !== trim( (string) ( $this->connection->last_error ?? '' ) );
	}

	private function prepare( string $sql, mixed ...$args ): string {
		return $this->require_connection()->prepare( $sql, ...$args );
	}

	private function require_connection(): object {
		if ( ! is_object( $this->connection ) ) { self::refuse(); }
		return $this->connection;
	}

	private static function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) { return $value; }
		if ( is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value )
			&& ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) )
		) { return (int) $value; }
		self::refuse();
	}

	private static function refuse(): never {
		throw new RuntimeException( 'Operation store could not be verified.' );
	}
}
