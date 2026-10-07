<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DataLifecycle;

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;

/** Test-only transactions and independent physical rows, not a native-WP proof. */
final class UninstallExecutorFixture implements OperationConnectionFactory {
	public const NOW = 1791331200;
	public array $rows = [];
	public array $statements = [];
	public int $opens = 0;
	public int $writes = 0;
	public int $capability_calls = 0;
	public int $role_writes = 0;
	public int $role_refreshes = 0;
	public int $batches = 0;
	public bool $available = true;
	public bool $ready = true;
	public bool $permission_publication = true;
	public bool $advisory_publication = true;
	public bool $unknown_worker_read = false;
	public array $retain = [];
	public ?OperationCommitResult $status_commit = null;
	public ?OperationCommitResult $role_commit = null;
	public array $status_values = [];
	public ?\Closure $after_role_snapshot = null;
	public array $before_roles;
	public object $roles;
	private int $next_id = 0;

	public function __construct() {
		$GLOBALS['blog_id'] = 1;
		$GLOBALS['wpdb'] = (object) [ 'prefix' => 'proof_', 'options' => 'proof_options' ];
		$GLOBALS['cetech_de_test_options'] = [ DataLifecycleManifest::UNINSTALL_INTENT => '1', DataLifecycleManifest::CAPABILITIES_MARKER => 4 ];
		$GLOBALS['cetech_de_test_transients'] = [ DataLifecycleManifest::ACTIVATION_NOTICE => 'old notice' ];
		$GLOBALS['cetech_de_test_cache_deletes'] = [];
		$GLOBALS['cetech_de_test_filters'] = [];
		$this->put( DataLifecycleManifest::UNINSTALL_INTENT, '1' );
		$this->put( DataLifecycleManifest::CAPABILITIES_MARKER, '4' );
		$this->put( '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, 'old notice' );
		$this->put( '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE, (string) ( self::NOW + 60 ) );
		$this->before_roles = [ 'custom_staff' => [ 'name' => 'Custom staff', 'capabilities' => array_fill_keys( [ ...DataLifecycleManifest::CAPABILITIES, 'read', 'foreign_capability' ], true ) ],
			'foreign_role' => [ 'name' => 'Foreign', 'capabilities' => [ 'read' => true, 'foreign_capability' => true ] ] ];
		$this->roles = new class( $this, $this->before_roles ) {
			public string $role_key = 'proof_user_roles';
			public function __construct( private UninstallExecutorFixture $fixture, public array $roles ) {}
			public function for_site( int $site ): void {
				if ( 1 !== $site ) { throw new \RuntimeException( 'PRIVATE_FOREIGN_SITE' ); }
				++$this->fixture->role_refreshes;
				$this->roles = unserialize( $this->fixture->rows[$this->role_key]['option_value'], [ 'allowed_classes' => false ] );
			}
		};
		$GLOBALS['cetech_de_test_wp_roles'] = $this->roles;
		$GLOBALS['cetech_de_test_roles'] = [];
		foreach ( array_keys( $this->before_roles ) as $slug ) {
			$GLOBALS['cetech_de_test_roles'][$slug] = new class( $this, $slug ) {
				public function __construct( private UninstallExecutorFixture $fixture, private string $slug ) {}
				public function remove_cap( string $cap ): void {
					++$this->fixture->capability_calls;
					unset( $this->fixture->roles->roles[$this->slug]['capabilities'][$cap] );
					if ( $this->fixture->permission_publication ) { $this->fixture->put( 'proof_user_roles', serialize( $this->fixture->roles->roles ) ); }
				}
			};
		}
		$this->put( 'proof_user_roles', serialize( $this->before_roles ) );
		$this->put( 'foreign_option', 'PRIVATE_FOREIGN', 'on' );
		$this->put( 'cetech_de_sitewide_defaults', 'PRIVATE_AUTHORED', 'on' );
	}

	public function cleanup(): DataLifecycleCleanupService {
		$store = new class( $this ) extends DataLifecycleOptionsStore {
			public function __construct( private UninstallExecutorFixture $fixture ) {}
			public function invalidate( array $names ): bool { return $this->fixture->advisory_publication && parent::invalidate( $names ); }
		};
		return new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), $this, static fn( int $site ): bool => 1 === $site, $store );
	}

	public function open(): OperationSession {
		++$this->opens;
		if ( ! $this->available ) { throw new \RuntimeException( 'PRIVATE_CONNECTION_SQL_TOKEN' ); }
		return new UninstallExecutorSession( $this );
	}

	public function put( string $name, string $value, string $autoload = 'off', ?int $id = null ): void {
		$id ??= $this->rows[$name]['option_id'] ?? ++$this->next_id;
		$this->next_id = max( $this->next_id, $id );
		$this->rows[$name] = [ 'option_id' => $id, 'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload ];
	}

	public function checkpoint( string $mode, int $ceiling = 5000 ): DataLifecycleProgress {
		$p = DataLifecycleProgress::initial( 1, DataLifecycleRegistry::standard()->policy_digest(), $mode, self::NOW, $ceiling );
		$this->put( DataLifecycleManifest::COORDINATOR_OPTION, $p->to_json() );
		return $p;
	}

	public function cache( int $id, bool $invalid = false ): string {
		$i = ManagedGeographyCacheIdentity::create( 1, 'postcode', 'GH', '', '', (string) $id, 1, '0', 'en_US', 'fixture-salt' );
		$value = $invalid ? '{"renamed":{"private":"PRIVATE_QUERY_IP_PATH_SQL"}}'
			: ManagedGeographyCacheEnvelope::create( $i, [ 'required' => false, 'visible' => false ], self::NOW - 121 )->to_json();
		$this->put( $i->option_name(), $value, 'off', $id );
		return $i->option_name();
	}

	public function sync_api_deletion( string $name ): void {
		if ( in_array( $name, $this->retain, true ) ) { return; }
		if ( in_array( $name, [ DataLifecycleManifest::UNINSTALL_INTENT, DataLifecycleManifest::CAPABILITIES_MARKER ], true )
			&& ! array_key_exists( $name, $GLOBALS['cetech_de_test_options'] ) ) { unset( $this->rows[$name] ); }
		if ( in_array( $name, [ '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE ], true )
			&& ! array_key_exists( DataLifecycleManifest::ACTIVATION_NOTICE, $GLOBALS['cetech_de_test_transients'] ) ) { unset( $this->rows[$name] ); }
	}
}

final class UninstallExecutorSession implements OperationSession {
	private bool $active = false;
	private bool $retired = false;
	private array $rows = [];
	private array $prepared = [];
	private bool $status_write = false;
	private bool $role_write = false;
	private bool $worker_read = false;
	private bool $role_snapshot_read = false;
	private int $insert_id = 0;
	public function __construct( private UninstallExecutorFixture $fixture ) {}
	public function site_id(): int { return 1; }
	public function table_prefix(): string { return 'proof_'; }
	public function charset_collate(): string { return 'utf8mb4'; }
	public function begin(): bool { $this->active = true; $this->rows = $this->fixture->rows; return true; }
	public function in_transaction(): bool { return $this->active; }
	public function is_retired(): bool { return $this->retired; }
	public function validate_tables( array $tables ): bool { return $this->active && $tables === [ 'proof_options' ] && $this->fixture->ready; }
	public function errno(): int { return 0; }
	public function insert_id(): int { return $this->insert_id; }
	public function rollback(): bool {
		if ( $this->worker_read && $this->fixture->unknown_worker_read ) { return false; }
		$this->active = false;
		if ( $this->role_snapshot_read && null !== $this->fixture->after_role_snapshot ) {
			$hook = $this->fixture->after_role_snapshot; $this->fixture->after_role_snapshot = null; $hook();
		}
		return true;
	}
	public function retire(): bool { $this->active = false; $this->retired = true; return true; }
	public function commit(): OperationCommitResult {
		$result = $this->status_write ? ( $this->fixture->status_commit ?? OperationCommitResult::Acknowledged )
			: ( $this->role_write ? ( $this->fixture->role_commit ?? OperationCommitResult::Acknowledged ) : OperationCommitResult::Acknowledged );
		if ( OperationCommitResult::NotSent !== $result ) { $this->fixture->rows = $this->rows; $this->active = false; }
		return $result;
	}
	public function prepare( string $sql, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$key = 'prepared-' . count( $this->prepared ); $this->prepared[$key] = [ $sql, $args ]; return $key;
	}
	private function command( string $sql ): array {
		if ( ! $this->active || $this->retired ) { throw new \RuntimeException( 'PRIVATE_UNOWNED' ); }
		[ $text, $args ] = $this->prepared[$sql] ?? [ $sql, [] ];
		$this->fixture->statements[] = [ $text, $args ]; return [ $text, $args ];
	}
	public function get_row( string $sql ): array|null|false {
		[ $text, $args ] = $this->command( $sql );
		if ( str_starts_with( $text, 'SELECT UTC_TIMESTAMP' ) ) { return [ 'utc' => gmdate( 'Y-m-d H:i:s', UninstallExecutorFixture::NOW ) ]; }
		if ( str_starts_with( $text, 'SELECT MAX' ) ) { return [ 'ceiling_id' => [] === $this->rows ? null : max( array_column( $this->rows, 'option_id' ) ) ]; }
		if ( str_contains( $text, 'WHERE option_name=%s' ) ) {
			$name = $args[0]; $this->fixture->sync_api_deletion( $name );
			if ( 'proof_user_roles' === $name ) { $this->role_snapshot_read = true; }
			return $this->fixture->rows[$name] ?? null;
		}
		if ( str_contains( $text, 'WHERE option_name = %s' ) ) {
			[ $max, $name ] = $args;
			if ( DataLifecycleManifest::COORDINATOR_OPTION === $name ) { $this->worker_read = true; }
			return isset( $this->rows[$name] ) ? $this->bounded( $this->rows[$name], $max ) : null;
		}
		if ( str_contains( $text, 'WHERE option_id = %d' ) ) {
			foreach ( $this->rows as $row ) { if ( $row['option_id'] === $args[1] ) { return $this->bounded( $row, $args[0] ); } }
			return null;
		}
		throw new \RuntimeException( 'PRIVATE_UNEXPECTED_READ' );
	}
	private function bounded( array $row, int $max ): array {
		$row['byte_length'] = strlen( $row['option_value'] ); if ( $row['byte_length'] > $max ) { $row['option_value'] = null; } return $row;
	}
	public function get_results( string $sql ): array|false {
		[ $text, $args ] = $this->command( $sql );
		if ( str_starts_with( $text, 'SHOW FULL COLUMNS' ) ) { return [
			[ 'Field' => 'option_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => 'auto_increment' ],
			[ 'Field' => 'option_name', 'Type' => 'varchar(191)', 'Null' => 'NO', 'Extra' => '' ],
			[ 'Field' => 'option_value', 'Type' => 'longtext', 'Null' => 'NO', 'Extra' => '' ],
			[ 'Field' => 'autoload', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Extra' => '' ],
		]; }
		if ( str_starts_with( $text, 'SHOW INDEX' ) ) { return [
			[ 'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'option_id', 'Sub_part' => null, 'Index_type' => 'BTREE' ],
			[ 'Key_name' => 'option_name', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null, 'Index_type' => 'BTREE' ],
		]; }
		if ( str_contains( $text, 'ORDER BY option_id ASC' ) ) {
			++$this->fixture->batches;
			[ $after, $upper, , $limit ] = $args;
			$rows = array_filter( $this->rows, static fn( array $row ): bool => $row['option_id'] > $after && $row['option_id'] <= $upper && str_starts_with( $row['option_name'], DataLifecycleManifest::CACHE_PREFIX ) );
			usort( $rows, static fn( array $a, array $b ): int => $a['option_id'] <=> $b['option_id'] );
			return array_map( static fn( array $row ): array => [ 'option_id' => $row['option_id'], 'option_name' => $row['option_name'], 'byte_length' => strlen( $row['option_value'] ), 'autoload' => $row['autoload'] ], array_slice( $rows, 0, $limit ) );
		}
		throw new \RuntimeException( 'PRIVATE_UNEXPECTED_ROWS' );
	}
	public function query( string $sql ): int|false {
		[ $text, $args ] = $this->command( $sql ); ++$this->fixture->writes;
		if ( str_starts_with( $text, 'INSERT INTO' ) ) {
			[ $name, $value, $autoload ] = $args;
			if ( isset( $this->rows[$name] ) ) { return false; }
			$this->insert_id = [] === $this->rows ? 1 : max( array_column( $this->rows, 'option_id' ) ) + 1;
			$this->rows[$name] = [ 'option_id' => $this->insert_id, 'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload ];
		} elseif ( str_starts_with( $text, 'UPDATE ' ) ) {
			[ $value, $autoload, $id, $name, $old ] = $args;
			if ( 'proof_user_roles' === $name ) {
				++$this->fixture->role_writes;
				if ( ! $this->fixture->permission_publication ) { return false; }
				$this->role_write = true;
			}
			if ( ! isset( $this->rows[$name] ) || $this->rows[$name]['option_id'] !== $id || $this->rows[$name]['option_value'] !== $old ) { return 0; }
			$this->rows[$name]['option_value'] = $value; $this->rows[$name]['autoload'] = $autoload;
		} elseif ( str_starts_with( $text, 'DELETE FROM' ) ) {
			[ $id, $name, $old ] = $args;
			if ( ! isset( $this->rows[$name] ) || $this->rows[$name]['option_id'] !== $id || $this->rows[$name]['option_value'] !== $old ) { return 0; }
			unset( $this->rows[$name] ); return 1;
		} else { throw new \RuntimeException( 'PRIVATE_UNEXPECTED_WRITE' ); }
		if ( DataLifecycleManifest::UNINSTALL_STATUS === $name ) { $this->status_write = true; $this->fixture->status_values[] = json_decode( $value, true, 16, JSON_THROW_ON_ERROR ); }
		return 1;
	}
}
