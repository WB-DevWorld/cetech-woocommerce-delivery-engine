<?php

declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase;

/** Native-only fixture support; no production registration or unit bootstrap. */
final class CetechNativeDataLifecycleFixture {

	public readonly string $prefix;
	public readonly int $site;
	public readonly mysqli $physical;
	public readonly wpdb $main;
	public readonly OperationConnectionFactory $factory;
	public ?wpdb $selected = null;
	private array $tables = [];

	public function __construct( wpdb $main ) {
		$this->main = $main;
		$this->site = (int) get_current_blog_id();
		// The longest preserved table name needs 46 characters after the
		// raw prefix. Reuse the C06 SQL fixture's validated 17-byte namespace.
		$this->prefix = DataLifecycleProofDatabase::prefix();
		DataLifecycleProofDatabase::validate_prefix( $this->prefix );
		$host = $main->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) ) { throw new RuntimeException( 'Lifecycle fixture database authority is unavailable.' ); }
		try {
			$this->physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
			if ( $this->physical->connect_errno || ! $this->physical->set_charset( 'utf8mb4' ) ) { throw new RuntimeException(); }
		} catch ( Throwable ) { throw new RuntimeException( 'Lifecycle fixture database is unavailable.' ); }
		$this->factory = OperationConnectionFactory::from_server_configuration( $this->site, $this->prefix, [ 'host' => $host[0], 'port' => $host[1] ?: 3306, 'socket' => $host[2], 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci' ] );
	}

	public function install(): void {
		if ( 0 !== (int) $this->scalar( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,LENGTH('{$this->prefix}'))='{$this->prefix}'" ) ) { throw new RuntimeException( 'Lifecycle fixture prefix is occupied.' ); }
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { if ( strlen( $this->prefix . 'delivery_engine_' . $suffix ) > 64 ) { throw new RuntimeException( 'Lifecycle fixture table identity exceeds the native limit.' ); } }
		$charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		foreach ( OperationStoreSchema::create_table_statements( $charset, $this->prefix . 'delivery_engine_' ) as $suffix => $sql ) { $this->create_table( $this->prefix . 'delivery_engine_' . $suffix, $sql ); }
		$this->create_table( $this->prefix . 'options', "CREATE TABLE `{$this->prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB {$charset}" );
		$this->create_table( $this->prefix . 'operation_fixture_counter', "CREATE TABLE `{$this->prefix}operation_fixture_counter` (id bigint unsigned NOT NULL PRIMARY KEY, revision bigint unsigned NOT NULL, value bigint NOT NULL, published_revision bigint unsigned NOT NULL DEFAULT 0) ENGINE=InnoDB {$charset}" );
		$this->execute( "INSERT INTO `{$this->prefix}operation_fixture_counter` (id,revision,value) VALUES (1,1,0)" );
		foreach ( RuleLifecycleSchema::create_table_statements( $charset, $this->prefix . 'delivery_engine_' ) as $suffix => $sql ) { $this->create_table( $this->prefix . 'delivery_engine_' . $suffix, $sql ); }
		$this->write_option( 'cetech_de_db_version', '8' );
		$this->write_option( 'cetech_de_last_migration_status', serialize( [ 'status' => 'success', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) );
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) {
			$table = $this->prefix . 'delivery_engine_' . $suffix;
			if ( in_array( $suffix, [ 'operation_records', 'operation_changes', 'rule_family_guards', 'logical_rules', 'rule_versions' ], true ) ) { continue; }
			$source = $this->main->prefix . 'delivery_engine_' . $suffix;
			if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $source ) ) { throw new RuntimeException( 'Lifecycle fixture source identity is invalid.' ); }
			$this->create_table( $table, "CREATE TABLE `{$table}` LIKE `{$source}`" );
			$columns = $this->rows( "SHOW FULL COLUMNS FROM `{$table}`" );
			$names = [];
			$values = [];
			foreach ( $columns as $column ) {
				if ( str_contains( (string) $column['Extra'], 'auto_increment' ) ) { continue; }
				$name = (string) $column['Field'];
				if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $name ) ) { throw new RuntimeException( 'Lifecycle fixture column identity is invalid.' ); }
				$names[] = '`' . $name . '`';
				$values[] = $this->sentinel_value( $column );
			}
			$this->execute( "INSERT INTO `{$table}` (" . implode( ',', $names ) . ') VALUES (' . implode( ',', $values ) . ')' );
		}
	}

	private function create_table( string $table, string $sql ): void {
		if ( strlen( $table ) > 64 || ! str_starts_with( $table, $this->prefix ) || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) { throw new RuntimeException( 'Lifecycle fixture table identity is invalid.' ); }
		$this->execute( $sql );
		$this->tables[] = $table;
	}

	/** Full schemas and physical marker rows; no claim that arbitrary markers are business DTOs. */
	private function sentinel_value( array $column ): string {
		$type = strtolower( (string) $column['Type'] );
		if ( str_starts_with( $type, 'datetime' ) || str_starts_with( $type, 'timestamp' ) ) { return "'2026-10-06 00:00:00'"; }
		if ( null !== $column['Default'] ) { return $this->literal( (string) $column['Default'] ); }
		if ( 'YES' === $column['Null'] ) { return 'NULL'; }
		if ( preg_match( '/^(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|bit)/', $type ) ) { return '1'; }
		if ( 'date' === $type ) { return "'2026-10-06'"; }
		if ( str_starts_with( $type, 'enum(' ) && preg_match( "/^enum\('([^']*)'/", $type, $match ) ) { return $this->literal( $match[1] ); }
		if ( preg_match( '/^(?:var)?char\(([0-9]+)\)/', $type, $match ) ) {
			$length = (int) $match[1];
			return $this->literal( 36 === $length ? 'c0600000-0000-4000-8000-000000000001' : substr( 'c06-preservation-sentinel', 0, max( 1, $length ) ) );
		}
		return "'{}'";
	}

	public function seed_preserved_options(): void {
		foreach ( DataLifecycleManifest::PRESERVED_OPTIONS as $name ) {
			if ( in_array( $name, [ 'cetech_de_db_version', 'cetech_de_last_migration_status', DataLifecycleManifest::COORDINATOR_OPTION, DataLifecycleManifest::UNINSTALL_STATUS ], true ) ) { continue; }
			$value = str_starts_with( $name, 'cetech_de_enable_' ) || 'cetech_de_demo_data_on_activation' === $name ? '1' : serialize( [ 'fixture' => 'PRIVATE-C06-PRESERVED-OPTION', 'revision' => 7 ] );
			if ( in_array( $name, [ 'cetech_de_geography_revision', 'cetech_de_global_configuration_version' ], true ) ) { $value = '7'; }
			$this->write_option( $name, $value );
		}
	}

	public function roles(): array {
		$caps = array_fill_keys( DataLifecycleManifest::CAPABILITIES, true );
		return [
			'administrator' => [ 'name' => 'Administrator', 'capabilities' => $caps + [ 'manage_options' => true, 'read' => true, 'c06_foreign_role_permission' => true ] ],
			'shop_manager' => [ 'name' => 'Shop Manager', 'capabilities' => $caps + [ 'read' => true, 'c06_foreign_role_permission' => true ] ],
			'c06_fixture_custom' => [ 'name' => 'C06 fixture custom', 'capabilities' => $caps + [ 'read' => true, 'c06_foreign_role_permission' => true ] ],
		];
	}

	public function reset_roles(): void {
		$this->write_option( $this->prefix . 'user_roles', serialize( $this->roles() ) );
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		$GLOBALS['wp_user_roles'] = null;
		$GLOBALS['wp_roles'] = new WP_Roles( $this->site );
	}

	public function select(): void {
		$this->selected = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->selected->set_prefix( $this->prefix );
		$this->selected->suppress_errors( true );
		$this->selected->hide_errors();
		$GLOBALS['wpdb'] = $this->selected;
		$this->reset_roles();
	}

	public function domain_rows(): array {
		$result = [];
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { $result[ $suffix ] = $this->rows( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id ASC" ); }
		return $result;
	}

	public function preserved_options(): array {
		$names = array_values( array_diff( DataLifecycleManifest::PRESERVED_OPTIONS, [ DataLifecycleManifest::COORDINATOR_OPTION, DataLifecycleManifest::UNINSTALL_STATUS ] ) );
		$names = implode( ',', array_map( $this->literal( ... ), $names ) );
		return $this->rows( "SELECT option_id,option_name,option_value,autoload FROM `{$this->prefix}options` WHERE option_name IN ({$names}) ORDER BY option_id ASC" );
	}

	public function option_rows(): array { return $this->rows( "SELECT option_id,option_name,option_value,autoload FROM `{$this->prefix}options` ORDER BY option_id ASC" ); }
	public function write_option( string $name, string $value ): void {
		DataLifecycleProofDatabase::validate_prefix( $this->prefix );
		$statement = $this->physical->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES (?,?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		if ( false === $statement || ! $statement->bind_param( 'ss', $name, $value ) || ! $statement->execute() ) { throw new RuntimeException( 'Native lifecycle option setup failed.' ); }
		$statement->close();
	}
	public function option( string $name ): ?array { return OperationProofDatabase::row( $this->physical, "SELECT option_id,option_name,option_value,autoload FROM `{$this->prefix}options` WHERE option_name=" . $this->literal( $name ) ); }
	public function scalar( string $sql ): mixed { return OperationProofDatabase::scalar( $this->physical, $sql ); }
	public function execute( string $sql ): void { OperationProofDatabase::execute( $this->physical, $sql ); }
	public function literal( string $value ): string { return "'" . $this->physical->real_escape_string( $value ) . "'"; }
	public function rows( string $sql ): array {
		$result = $this->physical->query( $sql );
		if ( ! $result instanceof mysqli_result ) { throw new RuntimeException( 'Native lifecycle physical read failed.' ); }
		$rows = $result->fetch_all( MYSQLI_ASSOC );
		$result->free();
		return $rows;
	}

	public function cleanup(): bool {
		DataLifecycleProofDatabase::validate_prefix( $this->prefix );
		$clean = true;
		try { if ( $this->selected instanceof wpdb ) { $this->selected->close(); } } catch ( Throwable ) { $clean = false; }
		foreach ( array_reverse( array_unique( $this->tables ) ) as $table ) { try { $this->execute( "DROP TABLE IF EXISTS `{$table}`" ); } catch ( Throwable ) { $clean = false; } }
		try { $clean = $clean && 0 === (int) $this->scalar( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,LENGTH('{$this->prefix}'))='{$this->prefix}'" ); } catch ( Throwable ) { $clean = false; }
		try { $this->physical->close(); } catch ( Throwable ) { $clean = false; }
		return $clean;
	}

	/** Fixed native child entry points only; private input and stderr are never emitted. */
	public static function child( string $script, array $config ): array {
		if ( ! in_array( basename( $script ), [ 'opening-data-lifecycle-option-reader.php', 'opening-data-lifecycle-uninstall-reader.php' ], true ) || dirname( $script ) !== __DIR__ ) { throw new RuntimeException( 'Invalid native lifecycle process.' ); }
		$path = tempnam( sys_get_temp_dir(), 'c06-native-process-' );
		if ( false === $path ) { throw new RuntimeException( 'Native lifecycle process configuration failed.' ); }
		$process = null; $pipes = [];
		try {
			if ( ! chmod( $path, 0600 ) || false === file_put_contents( $path, json_encode( $config, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Native lifecycle process configuration failed.' ); }
			$process = proc_open( [ PHP_BINARY, $script, $path ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Native lifecycle process is unavailable.' ); }
			fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
			$output = ''; $errors = ''; $deadline = microtime( true ) + 10; $exit = -1;
			do { $output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); $status = proc_get_status( $process ); if ( ! $status['running'] ) { $exit = $status['exitcode']; break; } if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
			if ( $status['running'] ) { proc_terminate( $process, 9 ); }
			$output .= stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = []; $closed = proc_close( $process ); $process = null;
			$decoded = json_decode( $output, true );
			return 0 === ( $exit >= 0 ? $exit : $closed ) && is_array( $decoded ) ? $decoded : [ 'status' => 'FAIL' ];
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); }
			unlink( $path );
		}
	}
}
