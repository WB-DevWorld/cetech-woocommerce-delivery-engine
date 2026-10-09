<?php

declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteReadiness, PromiseStorageSchema};

require_once __DIR__ . '/opening-quote-lifecycle-support.php';
require_once __DIR__ . '/opening-quote-storage-support.php';

/** Pure, explicit internal fixture grant; operation identities and opaque keys alone grant nothing. */
final class CetechNativePromiseAuthorizer implements \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromisePersistenceAuthorizer {
	public bool $admitted = true;
	public function __construct( private readonly \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding $binding ) {}
	public function authorize( \CetechDeliveryEngine\Domain\Contracts\OperationIdentity $identity, \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		return $this->admitted && 'native-p02-internal' === $identity->authority && 'user:1' === $identity->principal && $identity->site_id === $this->binding->native_site_id() && $binding->digest() === $this->binding->digest() && ( ( 0 === $author_user_id && in_array( $identity->operation, [ 'promise.versions.read', 'promise.assignment.read' ], true ) && [ 'kind' => 'global', 'target_id' => 0 ] === $scope ) || $this->authorize_author( $binding, $author_user_id, $scope ) );
	}
	public function authorize_author( \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return $this->admitted && $binding->digest() === $this->binding->digest() && 1 === $author_user_id && [ 'kind' => 'global', 'target_id' => 0 ] === $scope; }
}

/** Canonical P01 DTO builders; no generic row markers are inserted into promise storage. */
final class CetechNativePromiseBodies {
	public static function calendar( string $site, int $version = 1 ): \CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion {
		return \CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion::from_array( [ 'format_version' => 1, 'site_id' => $site, 'calendar_id' => 'native-picking', 'version' => $version, 'timezone' => 'UTC', 'tzdata_version' => timezone_version_get(), 'weekly_openings' => array_fill_keys( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ], [ [ 'open' => '09:00', 'close' => '17:00' ] ] ), 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ] );
	}
	public static function policy( string $site, string $from, string $until, \CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference $calendar, int $version = 1 ): \CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy {
		$ref = $calendar->private_facts();
		return \CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy::from_array( [ 'format_version' => 1, 'site_id' => $site, 'policy_id' => 'native-standard', 'version' => $version, 'effective_from' => $from, 'effective_until' => $until, 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ], 'anchor' => 'order_accepted', 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'UTC', 'graph' => [ 'format_version' => 1, 'components' => [ [ 'format_version' => 1, 'component_id' => 'delivery', 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 0, 'max' => 1, 'unit' => 'business_days', 'calendar' => $ref ], 'operating_calendar' => $ref, 'completion_window_rule' => 'within_open_interval', 'predecessors' => [], 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'source' => [ 'format_version' => 1, 'site_id' => $site, 'source_id' => 'native-configured-phase', 'version' => 1, 'digest' => hash( 'sha256', 'p02-native-configured-phase' ) ] ] ], 'terminal_component_ids' => [ 'delivery' ] ], 'calendars' => [ $ref ], 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'refuse_if_infeasible', 'capacity_mode' => 'none', 'capacity_source' => null ] );
	}
}

/** Actual native SQL in a pre-registered disposable namespace; no inferred deletion authority. */
final class CetechNativePromiseStorageFixture {
	public readonly string $prefix;
	public readonly int $site;
	public readonly mysqli $physical;
	public readonly CetechNativeQuoteLifecycleFactory $factory;
	public ?wpdb $selected = null;
	private array $owned_tables = [];

	public function __construct( private readonly wpdb $main ) {
		$this->prefix = 'gc6_' . bin2hex( random_bytes( 6 ) ) . '_'; $this->site = 99176;
		$host = $main->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) ) { throw new RuntimeException( 'P02 fixture native configuration is unavailable.' ); }
		try { $this->physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] ); if ( $this->physical->connect_errno || ! $this->physical->set_charset( $main->charset ?: 'utf8mb4' ) ) { throw new RuntimeException(); } }
		catch ( Throwable ) { throw new RuntimeException( 'P02 fixture database is unavailable.' ); }
		$this->factory = new CetechNativeQuoteLifecycleFactory( $main, $this->site, $this->prefix );
	}
	public function install_schema9(): void {
		if ( 0 !== $this->table_count() || 35 !== count( DataLifecycleManifest::RETAINED_QUOTE_DOMAIN_TABLE_SUFFIXES ) || 38 !== count( DataLifecycleManifest::RETAINED_PROMISE_DOMAIN_TABLE_SUFFIXES ) || 3 !== count( PromiseStorageSchema::SUFFIXES ) ) { throw new RuntimeException( 'P02 fixture namespace or source inventory differs.' ); }
		// Register all names before the first write, including partially created migration tables.
		foreach ( DataLifecycleManifest::RETAINED_PROMISE_DOMAIN_TABLE_SUFFIXES as $suffix ) { $this->register( $this->prefix . 'delivery_engine_' . $suffix ); }
		$this->register( $this->prefix . 'options' );
		foreach ( DataLifecycleManifest::RETAINED_QUOTE_DOMAIN_TABLE_SUFFIXES as $suffix ) {
			$table = $this->prefix . 'delivery_engine_' . $suffix; $source = $this->main->prefix . 'delivery_engine_' . $suffix; self::identifier( $source );
			$this->execute( "CREATE TABLE `{$table}` LIKE `{$source}`" );
		}
		self::identifier( $this->main->options ); $this->execute( "CREATE TABLE `{$this->prefix}options` LIKE `{$this->main->options}`" );
		$this->write_option( SchemaVersion::OPTION_NAME, '9' );
		$this->write_option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'from_version' => '8', 'to_version' => '9', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID ] ) );
		$this->reset_roles( false );
	}
	private function register( string $name ): void {
		self::identifier( $name ); if ( strlen( $name ) > 64 || ! str_starts_with( $name, $this->prefix ) || in_array( $name, $this->owned_tables, true ) ) { throw new RuntimeException( 'P02 fixture owned table is invalid.' ); } $this->owned_tables[] = $name;
	}
	public static function identifier( string $name ): void { if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $name ) ) { throw new RuntimeException( 'P02 fixture identifier is invalid.' ); } }
	public function literal( string $value ): string { return "'" . $this->physical->real_escape_string( $value ) . "'"; }
	public function execute( string $sql ): void { if ( false === $this->physical->query( $sql ) ) { throw new RuntimeException( 'P02 fixture native write failed.', $this->physical->errno ); } }
	public function rows( string $sql ): array { $result = $this->physical->query( $sql ); if ( ! $result instanceof mysqli_result ) { throw new RuntimeException( 'P02 fixture native read failed.', $this->physical->errno ); } $rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); return $rows; }
	public function table_count(): int { return (int) $this->rows( "SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='{$this->prefix}'" )[0]['total']; }
	public function snapshot( array $suffixes = DataLifecycleManifest::RETAINED_PROMISE_DOMAIN_TABLE_SUFFIXES ): array {
		$result = []; foreach ( $suffixes as $suffix ) { if ( ! in_array( $suffix, DataLifecycleManifest::RETAINED_PROMISE_DOMAIN_TABLE_SUFFIXES, true ) ) { throw new RuntimeException( 'P02 fixture snapshot scope is invalid.' ); } $result[$suffix] = $this->rows( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); } return $result;
	}
	public function definitions( array $suffixes ): array {
		$result = []; foreach ( $suffixes as $suffix ) { if ( ! in_array( $suffix, DataLifecycleManifest::RETAINED_PROMISE_DOMAIN_TABLE_SUFFIXES, true ) ) { throw new RuntimeException( 'P02 fixture definition scope is invalid.' ); } $table = $this->prefix . 'delivery_engine_' . $suffix; $rows = $this->rows( "SHOW CREATE TABLE `{$table}`" ); if ( 1 !== count( $rows ) ) { throw new RuntimeException( 'P02 fixture definition is unavailable.' ); } $result[$suffix] = array_values( $rows[0] )[1]; } return $result;
	}
	public function select(): void {
		$this->selected = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $this->selected->set_prefix( $this->prefix ); $this->selected->suppress_errors( true ); $this->selected->hide_errors();
		$GLOBALS['wpdb'] = $this->selected; $GLOBALS['blog_id'] = $this->site; $this->reset_roles();
	}
	/** Native deactivation may query Action Scheduler; give it empty owned stores. */
	public function prepare_scheduler(): void {
		if ( ! $this->selected instanceof wpdb ) { throw new RuntimeException( 'P02 fixture scheduler context is unavailable.' ); }
		foreach ( [ 'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs' ] as $property ) {
			$source = isset( $this->main->{$property} ) ? $this->main->{$property} : $this->main->prefix . $property; self::identifier( $source );
			if ( ! str_starts_with( $source, $this->main->prefix ) ) { throw new RuntimeException( 'P02 fixture scheduler source is outside the marked site.' ); }
			$table = $this->prefix . $property; $this->register( $table ); $this->execute( "CREATE TABLE `{$table}` LIKE `{$source}`" ); $this->selected->{$property} = $table;
		}
	}
	public function reset_roles( bool $select = true ): void {
		$caps = array_fill_keys( DataLifecycleManifest::CAPABILITIES, true ); $roles = [ 'administrator' => [ 'name' => 'Administrator', 'capabilities' => $caps + [ 'manage_options' => true, 'read' => true ] ] ];
		$this->write_option( $this->prefix . 'user_roles', serialize( $roles ) );
		if ( $select ) { $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $GLOBALS['wp_user_roles'] = null; $GLOBALS['wp_roles'] = new WP_Roles( $this->site ); }
	}
	public function write_option( string $name, string $value ): void {
		$statement = $this->physical->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value,autoload) VALUES (?,?,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		if ( false === $statement || ! $statement->bind_param( 'ss', $name, $value ) || ! $statement->execute() ) { throw new RuntimeException( 'P02 fixture option setup failed.' ); } $statement->close();
	}
	public function cleanup(): bool {
		$ok = $this->factory->close_all();
		try { if ( $this->selected instanceof wpdb ) { remove_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ], 0 ); $this->selected->close(); $ok = false === has_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ] ) && $ok; } } catch ( Throwable ) { $ok = false; }
		foreach ( array_reverse( $this->owned_tables ) as $table ) { try { $this->execute( "DROP TABLE IF EXISTS `{$table}`" ); } catch ( Throwable ) { $ok = false; } }
		try { $ok = 0 === $this->table_count() && $ok; $this->physical->close(); } catch ( Throwable ) { $ok = false; } return $ok;
	}
	/** Fixed reader, finite output and private temporary configuration removed on every path. */
	public static function reader( array $config ): array {
		$path = tempnam( sys_get_temp_dir(), 'p02-native-reader-' ); $process = null; $pipes = [];
		if ( false === $path ) { throw new RuntimeException( 'P02 native reader configuration is unavailable.' ); }
		try {
			if ( ! chmod( $path, 0600 ) || false === file_put_contents( $path, json_encode( $config, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'P02 native reader setup failed.' ); }
			$process = proc_open( [ PHP_BINARY, __DIR__ . '/opening-promise-storage-reader.php', $path ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'P02 native reader did not start.' ); }
			fclose( $pipes[0] ); unset( $pipes[0] ); foreach ( $pipes as $pipe ) { stream_set_blocking( $pipe, false ); }
			$output = ''; $errors = ''; $deadline = microtime( true ) + 10; $exit = -1;
			do { $output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); $status = proc_get_status( $process ); if ( ! $status['running'] ) { $exit = $status['exitcode']; break; } if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
			if ( $status['running'] ) { proc_terminate( $process, 9 ); }
			$output .= stream_get_contents( $pipes[1] ); foreach ( $pipes as $pipe ) { fclose( $pipe ); } $pipes = []; $closed = proc_close( $process ); $process = null;
			$decoded = json_decode( $output, true );
			if ( $status['running'] || strlen( $output ) > 4096 || strlen( $errors ) > 16384 || 0 !== ( $exit >= 0 ? $exit : $closed ) || ! is_array( $decoded ) ) { return [ 'status' => 'FAIL', 'transport_complete' => false ]; }
			return $decoded + [ 'transport_complete' => true ];
		} finally { foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } } if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); } unlink( $path ); }
	}
}
