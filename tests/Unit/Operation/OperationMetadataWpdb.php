<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;

/** Metadata boundary double; no SQL durability or DDL execution claim. */
final class OperationMetadataWpdb {

	public string $prefix = 'proof_';
	public string $last_error = '';
	public array $tables = [];
	public array $queries = [];
	public array $options;
	public bool $query_failure = false;

	public function __construct() {
		$this->options = [ 'cetech_de_db_version' => '7', 'cetech_de_last_migration_status' => serialize( [ 'status' => 'success', 'migration_id' => '20261006191156_create_operation_tables', 'from_version' => '6', 'to_version' => '7' ] ) ];
		foreach ( OperationStoreSchema::SUFFIXES as $suffix ) {
			$columns = [];
			foreach ( OperationStoreSchema::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $collation ] ) {
				$columns[ $name ] = [ 'Field' => $name, 'Type' => str_replace( [ 'bigint unsigned', 'smallint unsigned' ], [ 'bigint(20) unsigned', 'smallint(5) unsigned' ], $type ), 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ];
			}
			$indexes = [];
			foreach ( OperationStoreSchema::indexes( $suffix ) as $name => $index ) {
				foreach ( $index['columns'] as $position => $column ) {
					$indexes[] = [ 'Key_name' => $name, 'Seq_in_index' => (string) ( $position + 1 ), 'Non_unique' => $index['unique'] ? '0' : '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $column ];
				}
			}
			$table = $this->prefix . 'delivery_engine_' . $suffix;
			$this->tables[ $table ] = [ 'status' => [ 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ], 'columns' => $columns, 'indexes' => $indexes, 'rows' => [] ];
		}
	}

	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

	public function prepare( string $sql, mixed ...$args ): string {
		$index = 0;
		return preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( &$index, $args ): string {
			$value = $args[ $index++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
		}, $sql );
	}

	public function get_row( string $sql, mixed $format = null ): ?array {
		$rows = $this->get_results( $sql, $format );
		return $rows[0] ?? null;
	}

	public function get_results( string $sql, mixed $format = null ): array {
		$this->queries[] = $sql;
		$this->last_error = $this->query_failure ? 'PRIVATE_SQL_SENTINEL' : '';
		if ( $this->query_failure ) { return []; }
		if ( preg_match( "/SHOW TABLE STATUS WHERE Name = '([^']+)'/", $sql, $match ) ) {
			return isset( $this->tables[ $match[1] ] ) ? [ $this->tables[ $match[1] ]['status'] ] : [];
		}
		if ( preg_match( '/SHOW FULL COLUMNS FROM `([^`]+)`/', $sql, $match ) ) { return array_values( $this->tables[ $match[1] ]['columns'] ); }
		if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $sql, $match ) ) { return $this->tables[ $match[1] ]['indexes']; }
		if ( preg_match( "/SELECT option_value FROM `proof_options` WHERE option_name = '([^']+)'/", $sql, $match ) ) { return isset( $this->options[ $match[1] ] ) ? [ [ 'option_value' => $this->options[ $match[1] ] ] ] : []; }
		if ( preg_match( '/SELECT 1 AS present FROM `([^`]+)`/', $sql, $match ) ) { return [] === $this->tables[ $match[1] ]['rows'] ? [] : [ [ 'present' => '1' ] ]; }
		if ( str_contains( $sql, 'OCTET_LENGTH' ) ) { return []; }
		if ( preg_match( '/SELECT MAX\(id\) AS ceiling FROM `([^`]+)`/', $sql, $match ) ) { return [ [ 'ceiling' => empty( $this->tables[ $match[1] ]['rows'] ) ? null : (string) max( array_column( $this->tables[ $match[1] ]['rows'], 'id' ) ) ] ]; }
		if ( preg_match( '/SELECT \* FROM `([^`]+)` WHERE id > ([0-9]+) AND id <= ([0-9]+)/', $sql, $match ) ) {
			return array_slice( array_values( array_filter( $this->tables[ $match[1] ]['rows'], static fn ( array $row ): bool => (int) $row['id'] > (int) $match[2] && (int) $row['id'] <= (int) $match[3] ) ), 0, 100 );
		}
		$this->last_error = 'Unimplemented private metadata query.';
		return [];
	}
}
