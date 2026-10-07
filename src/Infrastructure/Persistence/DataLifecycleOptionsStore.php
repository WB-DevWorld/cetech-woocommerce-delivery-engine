<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Native option transport; every method stays within the caller's pinned unit. */
class DataLifecycleOptionsStore {
	public const COORDINATOR_OPTION = 'cetech_de_gc_state_geo_v1';
	public const STATUS_OPTION = 'cetech_de_data_lifecycle_uninstall_status';
	public const REVISION_OPTION = 'cetech_de_geography_revision';
	public function options_table( OperationSession $session ): string {
		$table = $session->table_prefix() . 'options';
		if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); }
		return $table;
	}
	public function assert_standard_wordpress_route( OperationSession $session ): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ( $wpdb->options ?? null ) !== $this->options_table( $session ) || ( $wpdb->prefix ?? null ) !== $session->table_prefix() || ( function_exists( 'get_current_blog_id' ) && get_current_blog_id() !== $session->site_id() ) ) { self::refuse(); }
	}
	public function assert_ready( OperationSession $session, int $site_id ): void {
		$table = $this->options_table( $session );
		if ( $site_id < 1 || $session->site_id() !== $site_id || ! $session->in_transaction() || ! $session->validate_tables( [ $table ] ) ) { self::refuse(); }
		$columns = $session->get_results( "SHOW FULL COLUMNS FROM `{$table}`" );
		if ( ! is_array( $columns ) || 4 !== count( $columns ) ) { self::refuse(); }
		$expected = [ 'option_id' => '/\Abigint(?:\([0-9]+\))? unsigned\z/i', 'option_name' => '/\Avarchar\(191\)\z/i', 'option_value' => '/\Alongtext\z/i', 'autoload' => '/\Avarchar\(20\)\z/i' ];
		$seen = [];
		foreach ( $columns as $column ) {
			$name = $column['Field'] ?? null;
			if ( ! is_string( $name ) || ! isset( $expected[$name] ) || isset( $seen[$name] ) || 'NO' !== strtoupper( (string) ( $column['Null'] ?? '' ) ) || 1 !== preg_match( $expected[$name], (string) ( $column['Type'] ?? '' ) ) || ( 'option_id' === $name && 'auto_increment' !== strtolower( (string) ( $column['Extra'] ?? '' ) ) ) ) { self::refuse(); }
			$seen[$name] = true;
		}
		$indexes = $session->get_results( "SHOW INDEX FROM `{$table}`" );
		if ( ! is_array( $indexes ) || count( $indexes ) > 128 ) { self::refuse(); }
		$groups = [];
		foreach ( $indexes as $index ) {
			$name = $index['Key_name'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) { self::refuse(); }
			$groups[$name][] = $index;
		}
		$primary = false; $unique = false;
		foreach ( $groups as $name => $parts ) {
			if ( 1 !== count( $parts ) ) { continue; }
			$part = $parts[0];
			if ( '0' !== (string) ( $part['Non_unique'] ?? '' ) || '1' !== (string) ( $part['Seq_in_index'] ?? '' ) || ! array_key_exists( 'Sub_part', $part ) || null !== $part['Sub_part'] || strtoupper( (string) ( $part['Index_type'] ?? '' ) ) !== 'BTREE' || null !== ( $part['Expression'] ?? null ) ) { continue; }
			if ( 'PRIMARY' === $name && 'option_id' === ( $part['Column_name'] ?? null ) ) { $primary = true; }
			if ( 'option_name' === $name && 'option_name' === ( $part['Column_name'] ?? null ) ) { $unique = true; }
		}
		if ( ! $primary || ! $unique ) { self::refuse(); }
	}
	public function now( OperationSession $session ): int {
		$row = $session->get_row( 'SELECT UTC_TIMESTAMP() AS utc' );
		$value = is_array( $row ) ? ( $row['utc'] ?? null ) : null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value ) ) { self::refuse(); }
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $time || $time->format( 'Y-m-d H:i:s' ) !== $value || $time->getTimestamp() < 0 || $time->getTimestamp() > PHP_INT_MAX - 120 ) { self::refuse(); }
		return $time->getTimestamp();
	}
	public function current_by_name( OperationSession $session, string $name, int $max_bytes = 67584 ): ?array {
		$this->read_name( $name );
		$row = $this->current( $session, 'option_name = %s', [ $name ], $max_bytes );
		if ( null !== $row && $row['option_name'] !== $name ) { self::refuse(); }
		return $row;
	}
	public function current_by_id( OperationSession $session, int $id, int $max_bytes = 67584 ): ?array {
		if ( $id < 1 ) { self::refuse(); }
		return $this->current( $session, 'option_id = %d', [ $id ], $max_bytes );
	}
	private function current( OperationSession $session, string $where, array $parameters, int $max_bytes ): ?array {
		if ( $max_bytes < 1 || $max_bytes > 67584 || ! $session->in_transaction() ) { self::refuse(); }
		$table = $this->options_table( $session );
		$sql = "SELECT option_id, option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS byte_length, autoload FROM `{$table}` WHERE {$where} LIMIT 1 FOR UPDATE";
		$row = $session->get_row( $session->prepare( $sql, [ $max_bytes, ...$parameters ] ) );
		if ( false === $row ) { self::refuse(); }
		return null === $row ? null : self::row( $row, $max_bytes );
	}
	public function max_id( OperationSession $session ): int {
		$table = $this->options_table( $session );
		$row = $session->get_row( "SELECT MAX(option_id) AS ceiling_id FROM `{$table}`" );
		if ( ! is_array( $row ) ) { self::refuse(); }
		return null === ( $row['ceiling_id'] ?? null ) ? 0 : self::integer( $row['ceiling_id'] );
	}
	public function window( OperationSession $session, int $after, int $upper, int $limit = 201 ): array {
		if ( $after < 0 || $upper < $after || $limit < 1 || $limit > 201 || $upper - $after > 1000 ) { self::refuse(); }
		$table = $this->options_table( $session );
		$prefix = str_replace( [ '!', '_', '%' ], [ '!!', '!_', '!%' ], ManagedGeographyCacheIdentity::OPTION_PREFIX ) . '%';
		$sql = "SELECT option_id, option_name, OCTET_LENGTH(option_value) AS byte_length, autoload FROM `{$table}` WHERE option_id > %d AND option_id <= %d AND option_name LIKE %s ESCAPE '!' ORDER BY option_id ASC LIMIT %d";
		$rows = $session->get_results( $session->prepare( $sql, $after, $upper, $prefix, $limit ) );
		if ( ! is_array( $rows ) || count( $rows ) > $limit ) { self::refuse(); }
		$out = []; $last = $after;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { self::refuse(); }
			$copy = self::metadata( $row );
			if ( $copy['option_id'] <= $last || $copy['option_id'] > $upper ) { self::refuse(); }
			$last = $copy['option_id']; $out[] = $copy;
		}
		return $out;
	}
	public function insert( OperationSession $session, string $name, string $value ): int {
		$this->write_name( $name ); self::value( $value ); $table = $this->options_table( $session );
		$result = $session->query( $session->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, 'off' ) );
		if ( 1 !== $result || $session->insert_id() < 1 ) { self::refuse(); }
		return $session->insert_id();
	}
	public function replace( OperationSession $session, int $id, string $name, string $old_value, string $new_value ): bool {
		$this->write_name( $name ); self::value( $old_value ); self::value( $new_value ); if ( $id < 1 ) { self::refuse(); } $table = $this->options_table( $session );
		$result = $session->query( $session->prepare( "UPDATE `{$table}` SET option_value = %s, autoload = %s WHERE option_id = %d AND BINARY option_name = %s AND BINARY option_value = %s", $new_value, 'off', $id, $name, $old_value ) );
		if ( false === $result ) { self::refuse(); } return 1 === $result;
	}
	public function delete( OperationSession $session, int $id, string $name, string $old_value ): bool {
		$this->write_name( $name ); self::value( $old_value ); if ( $id < 1 ) { self::refuse(); } $table = $this->options_table( $session );
		$result = $session->query( $session->prepare( "DELETE FROM `{$table}` WHERE option_id = %d AND BINARY option_name = %s AND BINARY option_value = %s", $id, $name, $old_value ) );
		if ( false === $result ) { self::refuse(); } return 1 === $result;
	}
	public function invalidate( array $option_names ): bool {
		if ( count( $option_names ) > 52 || ! function_exists( 'wp_cache_delete' ) ) { return false; }
		try {
			foreach ( $option_names as $name ) {
				if ( ! is_string( $name ) ) { self::refuse(); } $this->write_name( $name ); wp_cache_delete( $name, 'options' );
				if ( str_starts_with( $name, ManagedGeographyCacheIdentity::OPTION_PREFIX ) ) { wp_cache_delete( substr( $name, strlen( ManagedGeographyCacheIdentity::OPTION_PREFIX ) ), ManagedGeographyCacheIdentity::CACHE_GROUP ); }
			}
			wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
			return true;
		} catch ( \Throwable ) { return false; }
	}
	private function read_name( string $name ): void { if ( self::REVISION_OPTION !== $name ) { $this->write_name( $name ); } }
	private function write_name( string $name ): void { if ( ! in_array( $name, [ self::COORDINATOR_OPTION, self::STATUS_OPTION ], true ) && 1 !== preg_match( '/\A' . preg_quote( ManagedGeographyCacheIdentity::OPTION_PREFIX, '/' ) . '[0-9a-f]{64}\z/D', $name ) ) { self::refuse(); } }
	private static function value( string $value ): void { if ( '' === $value || strlen( $value ) > 67584 || 1 !== preg_match( '//u', $value ) ) { self::refuse(); } }
	private static function metadata( array $row ): array {
		if ( ! is_string( $row['option_name'] ?? null ) || strlen( $row['option_name'] ) > 191 || 1 !== preg_match( '//u', $row['option_name'] ) || ! is_string( $row['autoload'] ?? null ) || strlen( $row['autoload'] ) > 20 ) { self::refuse(); }
		$id = self::integer( $row['option_id'] ?? null ); if ( $id < 1 ) { self::refuse(); }
		return [ 'option_id' => $id, 'option_name' => (string) $row['option_name'], 'byte_length' => self::integer( $row['byte_length'] ?? null ), 'autoload' => (string) $row['autoload'] ];
	}
	private static function row( array $row, int $max_bytes ): array {
		$copy = self::metadata( $row ); $value = $row['option_value'] ?? null;
		if ( ( $copy['byte_length'] > $max_bytes && null !== $value ) || ( $copy['byte_length'] <= $max_bytes && ( ! is_string( $value ) || strlen( $value ) !== $copy['byte_length'] ) ) ) { self::refuse(); }
		return [ 'option_id' => $copy['option_id'], 'option_name' => $copy['option_name'], 'option_value' => is_string( $value ) ? (string) $value : null, 'byte_length' => $copy['byte_length'], 'autoload' => $copy['autoload'] ];
	}
	private static function integer( mixed $value ): int { if ( is_int( $value ) && $value >= 0 ) { return $value; } if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) { self::refuse(); } return (int) $value; }
	private static function refuse(): never { throw new \RuntimeException( 'Data lifecycle options are unavailable.' ); }
}
