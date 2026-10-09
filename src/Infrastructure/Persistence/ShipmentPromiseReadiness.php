<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSourceReceipt;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredObject;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredVersion;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredAssignment;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStorageCodec;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseLifecycleRecordedProfile;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Feature-local read-only inspection. Runtime probes never walk retained history. */
final class ShipmentPromiseReadiness {
	public const MIGRATION_ID = '20261009161941_create_shipment_promise_table';
	private ?object $connection;
	private ?string $probe_collation = null;
	public function __construct( ?object $connection = null ) { $this->connection = $connection ?? ( $GLOBALS['wpdb'] ?? null ); }

	/** All incompatible preexisting structures/rows refuse before the first DDL. */
	public function preflight(): void {
		$this->probe( function (): void { foreach ( ShipmentPromiseSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, true ); } $this->check_stored_records(); } );
	}
	/** Structure only; migration does not require schema10 publication yet. */
	public function verify(): void {
		$this->probe( function (): void { foreach ( ShipmentPromiseSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); } } );
	}
	public function verify_table( string $suffix ): void { $this->probe( fn() => $this->inspect( $suffix, false ) ); }
	/** Migration-only fixed-ceiling walk, exact row codecs and physical backlinks. */
	public function verify_stored_records(): void {
		$this->probe( function (): void { foreach ( ShipmentPromiseSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); } $this->check_stored_records(); } );
	}
	/** @return array{ready:bool,code:string} */
	public function get_status(): array {
		try {
			return $this->probe( function (): array {
				$version = $this->read_option( SchemaVersion::OPTION_NAME );
				if ( null === $version || 1 !== preg_match( '/\A[1-9][0-9]{0,8}\z/D', $version ) || version_compare( $version, '11', '<' ) ) { return [ 'ready' => false, 'code' => 'schema_unavailable' ]; }
				$encoded = $this->read_option( MigrationStatus::OPTION_NAME ); $status = null === $encoded ? null : @unserialize( $encoded, [ 'allowed_classes' => false, 'max_depth' => 8 ] );
				if ( ! is_array( $status ) || 'success' !== ( $status['status'] ?? null ) || ! is_string( $status['to_version'] ?? null ) || $version !== $status['to_version'] || ! is_string( $status['migration_id'] ?? null ) || '' === $status['migration_id'] || ( '11' === $version && self::MIGRATION_ID !== $status['migration_id'] ) ) { return [ 'ready' => false, 'code' => 'migration_unconfirmed' ]; }
				foreach ( ShipmentPromiseSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); }
				if ( ! ( new PromiseStorageReadiness( $this->require_connection() ) )->get_status()['ready'] ) { self::refuse(); }
				return [ 'ready' => true, 'code' => 'ready' ];
			} );
		} catch ( \Throwable ) { return [ 'ready' => false, 'code' => 'store_unverified' ]; }
	}
	public function assert_ready(): void { if ( ! $this->get_status()['ready'] ) { self::refuse(); } }

	private function inspect( string $suffix, bool $partial ): void {
		$expected = ShipmentPromiseSchema::columns( $suffix ); $table = $this->table( $suffix ); $status = $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( null === $status ) { if ( $partial ) { return; } self::refuse(); }
		$this->table_status( $status ); $columns = $this->rows( "SHOW FULL COLUMNS FROM `{$table}`" ); $seen = [];
		foreach ( $columns as $column ) { $name = $column['Field'] ?? null; if ( ! is_string( $name ) || ! isset( $expected[$name] ) || isset( $seen[$name] ) ) { self::refuse(); } $this->column( $column, $expected[$name] ); $seen[$name] = true; }
		$expected_indexes = ShipmentPromiseSchema::indexes( $suffix ); $seen_indexes = $this->indexes( $this->rows( "SHOW INDEX FROM `{$table}`" ), $expected_indexes, false );
		$missing = count( $seen ) !== count( $expected ); foreach ( $expected_indexes as $name => $definition ) { $missing = $missing || ! isset( $seen_indexes[$name] ); }
		if ( $missing && ( ! $partial || null !== $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) ) { self::refuse(); }
	}
	private function table_status( array $status ): void { if ( 'InnoDB' !== ( $status['Engine'] ?? null ) || $this->site_collation() !== ( $status['Collation'] ?? null ) ) { self::refuse(); } }
	private function column( array $column, array $definition ): void {
		[ $type, $nullable, $default, $extra, $purpose ] = $definition; $actual = strtolower( (string) ( $column['Type'] ?? '' ) ); $actual = preg_replace( '/\b(bigint|smallint|int)\([0-9]+\)/', '$1', $actual );
		if ( $type !== $actual || ( $nullable ? 'YES' : 'NO' ) !== ( $column['Null'] ?? null ) || $default !== ( null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'] ) || $extra !== strtolower( (string) ( $column['Extra'] ?? '' ) ) || ( 'site' === $purpose ? $this->site_collation() : $purpose ) !== ( $column['Collation'] ?? null ) ) { self::refuse(); }
	}
	/** Full index width, uniqueness, direction, visibility and no prefix/expression. */
	private function indexes( array $rows, array $expected, bool $allow_other ): array {
		$seen = [];
		foreach ( $rows as $row ) {
			$name = $row['Key_name'] ?? null; if ( ! is_string( $name ) ) { self::refuse(); }
			if ( ! isset( $expected[$name] ) ) { if ( $allow_other ) { continue; } self::refuse(); }
			$position = self::positive( $row['Seq_in_index'] ?? null ); $definition = $expected[$name];
			if ( isset( $seen[$name][$position] ) || ( $definition['unique'] ? '0' : '1' ) !== (string) ( $row['Non_unique'] ?? '' ) || null !== ( $row['Sub_part'] ?? null ) || 'BTREE' !== strtoupper( (string) ( $row['Index_type'] ?? '' ) ) || 'A' !== ( $row['Collation'] ?? null ) || ! isset( $definition['columns'][$position - 1] ) || $definition['columns'][$position - 1] !== ( $row['Column_name'] ?? null ) || null !== ( $row['Expression'] ?? null ) || ( isset( $row['Visible'] ) && 'YES' !== strtoupper( (string) $row['Visible'] ) ) || ( isset( $row['Ignored'] ) && 'NO' !== strtoupper( (string) $row['Ignored'] ) ) ) { self::refuse(); }
			$seen[$name][$position] = true;
		}
		foreach ( $seen as $name => $parts ) { if ( count( $parts ) !== count( $expected[$name]['columns'] ) ) { self::refuse(); } }
		return $seen;
	}

	private function check_stored_records(): void {
  $suffix=ShipmentPromiseSchema::SUFFIX;$table=$this->table($suffix);if(null===$this->row($this->prepare('SHOW TABLE STATUS WHERE Name = %s',$table))||null===$this->row("SELECT 1 AS present FROM `{$table}` LIMIT 1")){return;}$row=$this->row("SELECT COALESCE(MAX(id),0) AS ceiling FROM `{$table}`");$ceiling=self::nonnegative($row['ceiling']??null);
  $this->walk($suffix,$ceiling,function(array $row):void{$this->assert_site($row['site_id']??null);$binding=PromiseSiteBinding::bind(self::positive($row['site_id']),$row['site_key']??'');$stored=\CetechDeliveryEngine\Domain\ServicePromise\Shipment\ShipmentPromiseStored::from_row($row,$binding);$stored->assert_receipts(function(string $namespace):array{$records=$this->table(OperationStoreSchema::RECORDS_SUFFIX);$changes=$this->table(OperationStoreSchema::CHANGES_SUFFIX);$rows=$this->rows($this->prepare("SELECT id,site_id,namespace_hash,intent_hash,namespace_format,intent_format,record_format,operation,operation_version,target_hash,state,publication_state,CASE WHEN OCTET_LENGTH(completion_json)<=16384 THEN completion_json ELSE NULL END AS completion_json,audit_id,row_version,created_at,updated_at,completed_at FROM `{$records}` WHERE site_id=%d AND namespace_hash=%s LIMIT 2",$this->site_id(),$namespace));if(1!==count($rows)){self::refuse();}$events=$this->rows($this->prepare("SELECT id,site_id,operation_id,event_format,CASE WHEN OCTET_LENGTH(event_json)<=16384 THEN event_json ELSE NULL END AS event_json,created_at FROM `{$changes}` WHERE site_id=%d AND operation_id=%d LIMIT 2",$this->site_id(),self::positive($rows[0]['id'])));if(1!==count($events)){self::refuse();}return [$rows[0],$events[0]];});});
 }
 private function site_id():int{$connection=$this->require_connection();return $connection instanceof OperationSession?$connection->site_id():(function_exists('get_current_blog_id')?get_current_blog_id():1);}

	private function walk( string $suffix, int $ceiling, callable $validate ): void {
		$after = 0; $table = $this->table( $suffix );
		while ( $after < $ceiling ) {
			$page = $this->rows( $this->prepare( 'SELECT ' . $this->projection( $suffix ) . " FROM `{$table}` WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100", $after, $ceiling ) ); if ( [] === $page ) { break; } if ( count( $page ) > 100 ) { self::refuse(); }
			foreach ( $page as $raw ) { $row = $this->bounded_row( $suffix, $raw ); $id = self::positive( $row['id'] ?? null ); if ( $id <= $after || $id > $ceiling ) { self::refuse(); } $validate( $row ); $after = $id; }
		}
	}
	private function payload_limits( string $suffix ): array { return ShipmentPromiseSchema::payload_limits( $suffix ); }
	private function projection( string $suffix ): string {
		$limits = $this->payload_limits( $suffix ); $fields = [];
		foreach ( ShipmentPromiseSchema::columns( $suffix ) as $field => $definition ) { $fields[] = isset( $limits[$field] ) ? "CASE WHEN OCTET_LENGTH(`{$field}`) <= {$limits[$field]} THEN `{$field}` ELSE NULL END AS `{$field}`" : "`{$field}`"; }
		foreach ( $limits as $field => $limit ) { $fields[] = "OCTET_LENGTH(`{$field}`) AS `__{$field}_bytes`"; } return implode( ', ', $fields );
	}
	private function bounded_row( string $suffix, array $row ): array {
		foreach ( $this->payload_limits( $suffix ) as $field => $limit ) { $marker = '__' . $field . '_bytes'; if ( ! array_key_exists( $marker, $row ) ) { self::refuse(); } $bytes = $row[$marker]; if ( null !== $bytes && ( self::nonnegative( $bytes ) > $limit || ! is_string( $row[$field] ?? null ) || strlen( $row[$field] ) !== self::nonnegative( $bytes ) ) ) { self::refuse(); } if ( null === $bytes && null !== ( $row[$field] ?? null ) ) { self::refuse(); } unset( $row[$marker] ); }
		return $row;
	}
	private function assert_site( mixed $site ): void { $connection = $this->require_connection(); $expected = $connection instanceof OperationSession ? $connection->site_id() : ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ); if ( self::positive( $site ) !== $expected ) { self::refuse(); } }
	private function site_collation(): string {
		if ( null !== $this->probe_collation ) { return $this->probe_collation; }
		$connection = $this->require_connection(); $declaration = $connection instanceof OperationSession ? $connection->charset_collate() : $connection->get_charset_collate(); $details = ShipmentPromiseSchema::charset_details( $declaration );
		if ( null !== $details['collation'] ) { return $this->probe_collation = $details['collation']; } $row = $this->row( $this->prepare( 'SHOW CHARACTER SET LIKE %s', $details['charset'] ) ); $value = $row['Default collation'] ?? null; if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $value ) ) { self::refuse(); } return $this->probe_collation = strtolower( $value );
	}
	private function table( string $suffix ): string { $prefix = $this->prefix(); $table = $prefix . TableNames::PREFIX . $suffix; if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); } return $table; }
	private function prefix(): string { $connection = $this->require_connection(); $prefix = $connection instanceof OperationSession ? $connection->table_prefix() : (string) ( $connection->prefix ?? '' ); if ( '' === $prefix || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::refuse(); } ShipmentPromiseSchema::tables( $prefix ); return $prefix; }
	private function read_option( string $name ): ?string {
		$table = $this->prefix() . 'options'; $connection = $this->require_connection(); if ( isset( $connection->options ) && is_string( $connection->options ) && $connection->options !== $table ) { self::refuse(); }
		$row = $this->row( $this->prepare( "SELECT CASE WHEN OCTET_LENGTH(option_value) <= 16384 THEN option_value ELSE NULL END AS option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name ) ); return null === $row || ! is_string( $row['option_value'] ?? null ) ? null : $row['option_value'];
	}
	private function probe( callable $work ): mixed {
		$this->probe_collation = null;
		try {
			$connection = $this->require_connection(); $owned = $connection instanceof OperationSession && ! $connection->in_transaction(); if ( $connection instanceof OperationSession && $connection->is_retired() ) { self::refuse(); } if ( $owned && ! $connection->begin() ) { self::refuse(); }
			try { $result = $work(); } catch ( \Throwable ) { if ( $owned && ! $connection->rollback() ) { $connection->retire(); } self::refuse(); }
			if ( $owned && ! $connection->rollback() ) { $connection->retire(); self::refuse(); } return $result;
		} finally { $this->probe_collation = null; }
	}
	private function rows( string $sql ): array { $connection = $this->require_connection(); $rows = $connection instanceof OperationSession ? $connection->get_results( $sql ) : $connection->get_results( $sql, ARRAY_A ); if ( ! is_array( $rows ) || $this->failed_query() ) { self::refuse(); } foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { self::refuse(); } } return $rows; }
	private function row( string $sql ): ?array { $connection = $this->require_connection(); $row = $connection instanceof OperationSession ? $connection->get_row( $sql ) : $connection->get_row( $sql, ARRAY_A ); if ( false === $row || ( null !== $row && ! is_array( $row ) ) || $this->failed_query() ) { self::refuse(); } return $row; }
	private function failed_query(): bool { return $this->connection instanceof OperationSession ? 0 !== $this->connection->errno() : '' !== trim( (string) ( $this->connection->last_error ?? '' ) ); }
	private function prepare( string $sql, mixed ...$args ): string { return $this->require_connection()->prepare( $sql, ...$args ); }
	private function require_connection(): object { if ( ! is_object( $this->connection ) ) { self::refuse(); } return $this->connection; }
	private static function positive( mixed $value ): int { $number = self::nonnegative( $value ); if ( $number < 1 ) { self::refuse(); } return $number; }
	private static function nonnegative( mixed $value ): int { if ( is_int( $value ) && $value >= 0 ) { return $value; } if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) ) { return (int) $value; } self::refuse(); }
	private static function refuse(): never { throw new \RuntimeException( 'Promise storage could not be verified.' ); }
}
