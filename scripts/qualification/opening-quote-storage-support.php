<?php

declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleBootstrap;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;

/** Native disposable storage helper. It never loads the unit bootstrap. */
final class CetechNativeQuoteStorageFixture {

	public readonly string $prefix;
	public readonly mysqli $physical;
	public readonly OperationConnectionFactory $factory;
	public ?wpdb $selected = null;
	private array $tables = [];

	public function __construct( private readonly wpdb $main, public readonly int $site ) {
		$this->prefix = 'gc6_' . bin2hex( random_bytes( 6 ) ) . '_';
		$host = $main->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) || $site < 1 ) { throw new RuntimeException( 'Native quote fixture authority is unavailable.' ); }
		try {
			$this->physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
			if ( $this->physical->connect_errno || ! $this->physical->set_charset( $main->charset ?: 'utf8mb4' ) ) { throw new RuntimeException(); }
		} catch ( Throwable ) { throw new RuntimeException( 'Native quote fixture database is unavailable.' ); }
		$this->factory = OperationConnectionFactory::from_server_configuration( $site, $this->prefix, [ 'host' => $host[0], 'port' => $host[1] ?: 3306, 'socket' => $host[2], 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'charset' => $main->charset ?: 'utf8mb4', 'collation' => $main->collate ?: '' ] );
	}

	/** Reconstruct schema8 from current native definitions, retaining the old32 tables. */
	public function install_schema8(): void {
		if ( 0 !== $this->prefix_table_count() || 32 !== count( DataLifecycleManifest::ORIGINAL_DOMAIN_TABLE_SUFFIXES ) || 35 !== count( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES ) ) { throw new RuntimeException( 'Native quote fixture namespace is unavailable.' ); }
		foreach ( DataLifecycleManifest::ORIGINAL_DOMAIN_TABLE_SUFFIXES as $suffix ) {
			$table = $this->register_table( $this->prefix . 'delivery_engine_' . $suffix );
			$source = $this->main->prefix . 'delivery_engine_' . $suffix;
			self::assert_identifier( $source );
			$this->execute( "CREATE TABLE `{$table}` LIKE `{$source}`" );
		}
		$table = $this->register_table( $this->prefix . 'options' );
		self::assert_identifier( $this->main->options );
		$this->execute( "CREATE TABLE `{$table}` LIKE `{$this->main->options}`" );
		// Register before migration, so partially completed DDL is also removed.
		foreach ( DeliveryQuoteSchema::tables( $this->prefix ) as $table ) { $this->register_table( $table ); }
		$this->execute( "ALTER TABLE `{$this->prefix}delivery_engine_rate_cards` DROP INDEX " . DeliveryQuoteSchema::RATE_INDEX );
		$this->write_option( SchemaVersion::OPTION_NAME, '8' );
		$this->write_option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'from_version' => '7', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) );
		$this->reset_roles( false );
	}

	private function register_table( string $table ): string {
		self::assert_identifier( $table );
		if ( strlen( $table ) > 64 || ! str_starts_with( $table, $this->prefix ) ) { throw new RuntimeException( 'Native quote fixture table is invalid.' ); }
		$this->tables[] = $table;
		return $table;
	}

	private static function assert_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $identifier ) ) { throw new RuntimeException( 'Native quote fixture identity is invalid.' ); }
	}

	public function select(): void {
		$this->selected = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->selected->set_prefix( $this->prefix );
		$this->selected->suppress_errors( true ); $this->selected->hide_errors();
		$GLOBALS['wpdb'] = $this->selected; $GLOBALS['blog_id'] = $this->site;
		$this->reset_roles();
	}

	public function reset_roles( bool $select = true ): void {
		$caps = array_fill_keys( DataLifecycleManifest::CAPABILITIES, true );
		$roles = [ 'administrator' => [ 'name' => 'Administrator', 'capabilities' => $caps + [ 'manage_options' => true, 'read' => true, 'q02_foreign_permission' => true ] ] ];
		$this->write_option( $this->prefix . 'user_roles', serialize( $roles ) );
		if ( $select ) { $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $GLOBALS['wp_user_roles'] = null; $GLOBALS['wp_roles'] = new WP_Roles( $this->site ); }
	}

	public function write_option( string $name, string $value ): void {
		$statement = $this->physical->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value,autoload) VALUES (?,?,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		if ( false === $statement || ! $statement->bind_param( 'ss', $name, $value ) || ! $statement->execute() ) { throw new RuntimeException( 'Native quote fixture option setup failed.' ); }
		$statement->close();
	}

	public function execute( string $sql ): void {
		if ( false === $this->physical->query( $sql ) ) { throw new RuntimeException( 'Native quote fixture physical write failed.' ); }
	}
	public function rows( string $sql ): array {
		$result = $this->physical->query( $sql );
		if ( ! $result instanceof mysqli_result ) { throw new RuntimeException( 'Native quote fixture physical read failed.' ); }
		$rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); return $rows;
	}
	public function quote_rows(): array {
		$rows = [];
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) { $rows[$suffix] = $this->rows( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); }
		return $rows;
	}
	public function prefix_table_count(): int {
		return (int) $this->rows( "SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='{$this->prefix}'" )[0]['total'];
	}
	public function cleanup(): bool {
		$clean = true;
		try { if ( $this->selected instanceof wpdb ) { remove_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ], 0 ); $this->selected->close(); $clean = false === has_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ] ) && $clean; } } catch ( Throwable ) { $clean = false; }
		foreach ( array_reverse( array_unique( $this->tables ) ) as $table ) { try { $this->execute( "DROP TABLE IF EXISTS `{$table}`" ); } catch ( Throwable ) { $clean = false; } }
		try { $clean = 0 === $this->prefix_table_count() && $clean; } catch ( Throwable ) { $clean = false; }
		try { $this->physical->close(); } catch ( Throwable ) { $clean = false; }
		return $clean;
	}

	/** Copy the exact standalone uninstall closure, deliberately excluding Composer. */
	public static function copy_standalone( string $root, string $path ): void {
		if ( ! mkdir( $path, 0700 ) || ! copy( $root . '/uninstall.php', $path . '/uninstall.php' ) ) { throw new RuntimeException( 'Native quote standalone setup failed.' ); }
		foreach ( array_unique( [ 'src/Bootstrap/DataLifecycleBootstrap.php', ...array_map( static fn ( string $file ): string => 'src/' . $file, DataLifecycleBootstrap::FILES ) ] ) as $relative ) {
			if ( ! str_starts_with( $relative, 'src/' ) || str_contains( $relative, '..' ) ) { throw new RuntimeException( 'Native quote standalone dependency is invalid.' ); }
			$target = $path . '/' . $relative;
			if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0700, true ) ) { throw new RuntimeException( 'Native quote standalone setup failed.' ); }
			if ( ! copy( $root . '/' . $relative, $target ) ) { throw new RuntimeException( 'Native quote standalone setup failed.' ); }
		}
	}

	public static function remove_standalone( string $path ): bool {
		if ( dirname( $path ) !== sys_get_temp_dir() || 1 !== preg_match( '/\Acetech-q02-standalone-[a-f0-9]{16}\z/D', basename( $path ) ) || is_link( $path ) ) { return false; }
		if ( ! is_dir( $path ) ) { return true; }
		$ok = true;
		$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $entries as $entry ) { $ok = ( $entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ) ) && $ok; }
		return rmdir( $path ) && $ok;
	}

	/** Fixed bounded wp-load child; private configuration and stderr never enter the receipt. */
	public static function child( string $script, array $config ): array {
		if ( dirname( $script ) !== __DIR__ || ! in_array( basename( $script ), [ 'opening-quote-storage-reader.php', 'opening-data-lifecycle-uninstall-reader.php' ], true ) ) { throw new RuntimeException( 'Native quote reader is invalid.' ); }
		$path = tempnam( sys_get_temp_dir(), 'q02-native-process-' );
		if ( false === $path ) { throw new RuntimeException( 'Native quote reader setup failed.' ); }
		$process = null; $pipes = [];
		try {
			if ( ! chmod( $path, 0600 ) || false === file_put_contents( $path, json_encode( $config, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Native quote reader setup failed.' ); }
			$process = proc_open( [ PHP_BINARY, $script, $path ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Native quote reader could not start.' ); }
			fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
			$output = ''; $errors = ''; $deadline = microtime( true ) + 10; $exit = -1; $status = [ 'running' => true ]; $transport_code = 'completed';
			do {
				$output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); $status = proc_get_status( $process );
				if ( ! $status['running'] ) { $exit = $status['exitcode']; break; }
				if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { $transport_code = 'output_limit'; break; }
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			if ( $status['running'] ) { if ( 'completed' === $transport_code ) { $transport_code = 'timeout'; } proc_terminate( $process, 9 ); }
			$output .= stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = [];
			$closed = proc_close( $process ); $process = null; $decoded = json_decode( $output, true ); $observed_exit = $exit >= 0 ? $exit : $closed;
			if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { $transport_code = 'output_limit'; }
			elseif ( 'completed' === $transport_code && 0 !== $observed_exit ) { $transport_code = 'nonzero_exit'; }
			elseif ( 'completed' === $transport_code && ! is_array( $decoded ) ) { $transport_code = 'invalid_json'; }
			$transport = [ 'transport_code' => $transport_code, 'exit_code' => $observed_exit >= -1 && $observed_exit <= 255 ? $observed_exit : -1, 'signal' => is_int( $status['termsig'] ?? null ) && $status['termsig'] >= 0 && $status['termsig'] <= 64 ? $status['termsig'] : 0, 'stderr_present' => '' !== $errors ];
			if ( 'completed' === $transport_code && is_array( $decoded ) ) { return $decoded + $transport; }
			// Retain only finite child failure facts; no messages, paths, SQL or stderr.
			$phases = [ 'configuration', 'wp_load', 'fixture_authority', 'installed_autoload', 'native_connection', 'readiness', 'read_owner', 'quote_read', 'immutable_comparison', 'read_release' ];
			$classes = [ 'RuntimeException', 'Error', 'TypeError', 'JsonException', 'InvalidArgumentException', 'LogicException', 'OperationStorageException', 'other' ];
			$safe = [ 'status' => 'FAIL', 'phase' => in_array( $decoded['phase'] ?? null, $phases, true ) ? $decoded['phase'] : 'unreported', 'error_class' => in_array( $decoded['error_class'] ?? null, $classes, true ) ? $decoded['error_class'] : 'unreported' ];
			$safe['process_id'] = is_int( $decoded['process_id'] ?? null ) && $decoded['process_id'] > 0 ? $decoded['process_id'] : null;
			foreach ( [ 'wp_load', 'default_object_cache', 'installed_candidate_autoload' ] as $key ) { $safe[$key] = is_bool( $decoded[$key] ?? null ) ? $decoded[$key] : null; }
			foreach ( [ 'exact_row', 'immutable_header', 'immutable_body', 'schema9', 'rolled_back', 'retired' ] as $key ) { $safe[$key] = is_bool( $decoded['checks'][$key] ?? null ) ? $decoded['checks'][$key] : null; }
			return $safe + $transport;
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); }
			unlink( $path );
		}
	}
}
