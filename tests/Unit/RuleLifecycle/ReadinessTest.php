<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use CetechDeliveryEngine\Tests\Unit\Operation\OperationMetadataWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadinessTest extends TestCase {

	public function test_published_successor_history_requires_the_exact_atomic_predecessor_cutoff(): void {
		[ $db, $families ] = self::population(); $profile = $families->get( 'fixture_availability_v1' );
		$from = RuleTime::parse( '2026-10-06 22:12:16.000000' ); $cutover = RuleTime::parse( '2026-10-06 22:13:16.000000' );
		$old =& $db->tables['proof_delivery_engine_rule_versions']['rows'][0];
		$old['state'] = 'retired'; $old['row_revision'] = '2'; $old['effective_from'] = $from->sql(); $old['sealed_at'] = $from->sql(); $old['published_at'] = $from->sql(); $old['retired_at'] = $cutover->sql(); $old['updated_at'] = $cutover->sql();
		$old['content_hash'] = RuleContent::hash( $profile, [ 'availability' => 'allow' ], RuleStartMode::Immediate, $from, null, 0, null );
		$new = $old; $new['id'] = '2'; $new['version_uuid'] = '10000000-0000-4000-8000-000000000003'; $new['version_sequence'] = '2'; $new['state'] = 'published'; $new['row_revision'] = '1';
		$new['supersedes_version_id'] = '1'; $new['effective_from'] = $cutover->sql(); $new['sealed_at'] = $cutover->sql(); $new['published_at'] = $cutover->sql(); $new['retired_at'] = null; $new['created_at'] = $cutover->sql();
		$new['content_hash'] = RuleContent::hash( $profile, [ 'availability' => 'allow' ], RuleStartMode::Immediate, $cutover, null, 0, 1 );
		$db->tables['proof_delivery_engine_rule_versions']['rows'][] = $new;
		$logical =& $db->tables['proof_delivery_engine_logical_rules']['rows'][0];
		$logical['last_version_sequence'] = '2'; $logical['revision'] = '3'; $logical['draft_version_id'] = null; $logical['current_published_version_id'] = '2'; $logical['updated_at'] = $cutover->sql();
		$db->tables['proof_delivery_engine_rule_family_guards']['rows'][0]['updated_at'] = $cutover->sql();
		$inspection = new RuleLifecycleReadiness( $db, $families ); $inspection->verify_stored_records();
		$old['retired_at'] = '2026-10-06 22:13:15.000000'; $before = serialize( $db->tables );
		try { $inspection->verify_stored_records(); self::fail( 'Non-atomic cutover accepted.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
	}

	public function test_registered_population_is_streamed_with_original_ceilings_and_reciprocal_links(): void {
		[ $db, $families ] = self::population();
		$before = serialize( $db->tables );
		( new RuleLifecycleReadiness( $db, $families ) )->verify_stored_records();
		self::assertSame( $before, serialize( $db->tables ) );
		$ceilings = array_keys( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'MAX(id) AS ceiling' ) ) );
		$pages = array_keys( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) );
		self::assertCount( 3, $ceilings ); self::assertCount( 3, $pages );
		self::assertLessThan( min( $pages ), max( $ceilings ) );
	}

	#[DataProvider( 'corrupt_population' )]
	public function test_corrupt_population_or_relationship_refuses_without_reinterpreting_data( string $case ): void {
		[ $db, $families ] = self::population();
		$rules = 'proof_delivery_engine_logical_rules'; $versions = 'proof_delivery_engine_rule_versions';
		if ( 'pointer' === $case ) { $db->tables[ $rules ]['rows'][0]['draft_version_id'] = '2'; }
		if ( 'backlink' === $case ) { $db->tables[ $versions ]['rows'][0]['logical_rule_id'] = '2'; }
		if ( 'sequence' === $case ) { $db->tables[ $rules ]['rows'][0]['last_version_sequence'] = '2'; }
		if ( 'hash' === $case ) { $db->tables[ $rules ]['rows'][0]['scope_hash'] = str_repeat( '0', 64 ); }
		if ( 'extra_field' === $case ) { $db->tables[ $versions ]['rows'][0]['private_unknown'] = 'PRIVATE_POPULATION_SENTINEL'; }
		if ( 'scheduled_revision' === $case ) { $db->tables[ $versions ]['rows'][0]['scheduled_revision'] = '1'; }
		if ( 'state' === $case ) { $db->tables[ $versions ]['rows'][0]['state'] = 'published'; }
		$before = serialize( $db->tables );
		try { ( new RuleLifecycleReadiness( $db, $families ) )->verify_stored_records(); self::fail( 'Corrupt population accepted.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
	}

	public static function corrupt_population(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'pointer', 'backlink', 'sequence', 'hash', 'extra_field', 'scheduled_revision', 'state' ] );
	}

	private static function population(): array {
		$db = self::metadata(); $profile = new RuleProofFamily(); $families = new RuleFamilyRegistry( [ $profile ] );
		$time = '2026-10-06 22:12:16.000000'; $scope = [ 'type' => 'product', 'id' => 50, 'parent_id' => 0 ]; $payload = [ 'availability' => 'allow' ];
		$db->tables['proof_delivery_engine_rule_family_guards']['rows'] = [ [ 'id' => '1', 'site_id' => '1', 'family_code' => $profile->family(), 'family_format' => '1', 'policy_hash' => $profile->policy_hash(), 'revision' => '2', 'created_at' => $time, 'updated_at' => $time ] ];
		$db->tables['proof_delivery_engine_logical_rules']['rows'] = [ [ 'id' => '1', 'site_id' => '1', 'family_guard_id' => '1', 'logical_uuid' => '10000000-0000-4000-8000-000000000001', 'scope_format' => '1', 'scope_json' => $profile->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $profile, $scope ), 'revision' => '1', 'last_version_sequence' => '1', 'current_published_version_id' => null, 'draft_version_id' => '1', 'scheduled_version_id' => null, 'created_at' => $time, 'updated_at' => $time ] ];
		$db->tables['proof_delivery_engine_rule_versions']['rows'] = [ [ 'id' => '1', 'site_id' => '1', 'logical_rule_id' => '1', 'version_uuid' => '10000000-0000-4000-8000-000000000002', 'version_sequence' => '1', 'row_revision' => '1', 'state' => 'draft', 'payload_format' => '1', 'payload_json' => $profile->payload_schema()->encode( $payload, 16384 ), 'content_hash' => RuleContent::hash( $profile, $payload, RuleStartMode::Immediate, null, null, 0, null ), 'priority' => '0', 'start_mode' => 'immediate', 'effective_from' => null, 'effective_until' => null, 'author_user_id' => '1', 'change_reason' => 'PRIVATE_DRAFT_REASON', 'supersedes_version_id' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null, 'sealed_at' => null, 'scheduled_at' => null, 'published_at' => null, 'retired_at' => null, 'created_at' => $time, 'updated_at' => $time ] ];
		return [ $db, $families ];
	}

	public function test_explicit_session_probe_rolls_back_its_read_unit_without_committing_or_nesting(): void {
		$session = self::session( self::metadata() );
		self::assertTrue( ( new RuleLifecycleReadiness( $session ) )->get_status()['ready'] );
		self::assertSame( [ 1, 1, 0, false ], [ $session->begins, $session->rollbacks, $session->commits, $session->in_transaction() ] );
		$session->begin();
		self::assertTrue( ( new RuleLifecycleReadiness( $session ) )->get_status()['ready'] );
		self::assertSame( [ 2, 1, 0, true ], [ $session->begins, $session->rollbacks, $session->commits, $session->in_transaction() ] );
		$session->rollback();
		$session->rollback_allowed = false;
		self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new RuleLifecycleReadiness( $session ) )->get_status() );
		self::assertTrue( $session->is_retired() );
	}

	private static function session( object $db ): OperationSession {
		return new class( $db ) implements OperationSession {
			public int $begins = 0;
			public int $rollbacks = 0;
			public int $commits = 0;
			public bool $rollback_allowed = true;
			private bool $active = false;
			private bool $retired = false;
			public function __construct( private object $db ) {}
			public function site_id(): int { return 1; }
			public function table_prefix(): string { return $this->db->prefix; }
			public function charset_collate(): string { return $this->db->get_charset_collate(); }
			public function begin(): bool { ++$this->begins; if ( $this->active || $this->retired ) { return false; } return $this->active = true; }
			public function commit(): OperationCommitResult { ++$this->commits; return OperationCommitResult::NotSent; }
			public function rollback(): bool { ++$this->rollbacks; if ( ! $this->rollback_allowed ) { return false; } $this->active = false; return true; }
			public function retire(): bool { $this->retired = true; $this->active = false; return true; }
			public function is_retired(): bool { return $this->retired; }
			public function in_transaction(): bool { return $this->active; }
			public function validate_tables( array $table_names ): bool { return false; }
			public function query( string $sql ): int|false { return false; }
			public function get_row( string $sql ): array|null|false { return $this->active && ! $this->retired ? $this->db->get_row( $sql ) : false; }
			public function get_results( string $sql ): array|false { return $this->active && ! $this->retired ? $this->db->get_results( $sql ) : false; }
			public function prepare( string $sql, mixed ...$args ): string { return $this->db->prepare( $sql, ...$args ); }
			public function errno(): int { return '' === $this->db->last_error ? 0 : 1; }
			public function insert_id(): int { return 0; }
		};
	}

	/** Metadata-only double: neither DDL nor SQL durability is claimed. */
	public static function metadata(): object {
		$base = new OperationMetadataWpdb();
		$base->options = [ 'cetech_de_db_version' => '8', 'cetech_de_last_migration_status' => serialize( [ 'status' => 'success', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID, 'from_version' => '7', 'to_version' => '8' ] ) ];
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
			$columns = [];
			foreach ( RuleLifecycleSchema::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $collation ] ) {
				$columns[ $name ] = [ 'Field' => $name, 'Type' => preg_replace( '/\A(bigint|smallint|int)( unsigned)?\z/', '$1(11)$2', $type ), 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ];
			}
			$indexes = [];
			foreach ( RuleLifecycleSchema::indexes( $suffix ) as $name => $index ) {
				foreach ( $index['columns'] as $position => $column ) { $indexes[] = [ 'Key_name' => $name, 'Seq_in_index' => (string) ( $position + 1 ), 'Non_unique' => $index['unique'] ? '0' : '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $column ]; }
			}
			$table = $base->prefix . 'delivery_engine_' . $suffix;
			$base->tables[ $table ] = [ 'status' => [ 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ], 'columns' => $columns, 'indexes' => $indexes, 'rows' => [] ];
		}
		return new class( $base ) {
			public string $prefix = 'proof_';
			public string $last_error = '';
			public array $tables;
			public array $options;
			public array $queries;
			public bool $query_failure;
			public function __construct( private OperationMetadataWpdb $base ) {
				$this->tables =& $base->tables; $this->options =& $base->options; $this->queries =& $base->queries; $this->query_failure =& $base->query_failure; $this->last_error =& $base->last_error;
			}
			public function get_charset_collate(): string { return $this->base->get_charset_collate(); }
			public function prepare( string $sql, mixed ...$args ): string { return $this->base->prepare( $sql, ...$args ); }
			public function get_row( string $sql, mixed $format = null ): ?array { return $this->get_results( $sql, $format )[0] ?? null; }
			public function get_results( string $sql, mixed $format = null ): array {
				if ( ! $this->query_failure && str_contains( $sql, 'OCTET_LENGTH' ) && preg_match( '/FROM `([^`]+)`/', $sql, $match ) ) {
					$this->queries[] = $sql; $this->last_error = '';
					foreach ( $this->tables[ $match[1] ]['rows'] as $row ) {
						foreach ( [ 'scope_json' => 4096, 'payload_json' => 16384, 'change_reason' => 512 ] as $field => $limit ) {
							if ( str_contains( $sql, '`' . $field . '`' ) && strlen( (string) ( $row[ $field ] ?? '' ) ) > $limit ) { return [ [ 'id' => $row['id'] ] ]; }
						}
					}
					return [];
				}
				if ( ! $this->query_failure && preg_match( '/SELECT \* FROM `([^`]+)` WHERE id = ([0-9]+) LIMIT 1/', $sql, $match ) ) {
					$this->queries[] = $sql; $this->last_error = '';
					return array_values( array_filter( $this->tables[ $match[1] ]['rows'], static fn ( array $row ): bool => (string) $row['id'] === $match[2] ) );
				}
				if ( ! $this->query_failure && preg_match( '/SELECT MAX\(version_sequence\) AS last_sequence FROM `([^`]+)` WHERE site_id = ([0-9]+) AND logical_rule_id = ([0-9]+) AND id <= ([0-9]+)/', $sql, $match ) ) {
					$this->queries[] = $sql; $this->last_error = '';
					$rows = array_filter( $this->tables[ $match[1] ]['rows'], static fn ( array $row ): bool => (string) $row['site_id'] === $match[2] && (string) $row['logical_rule_id'] === $match[3] && (int) $row['id'] <= (int) $match[4] );
					return [ [ 'last_sequence' => [] === $rows ? null : (string) max( array_column( $rows, 'version_sequence' ) ) ] ];
				}
				return $this->base->get_results( $sql, $format );
			}
		};
	}

	public function test_authoritative_schema_status_and_all_five_tables_are_required(): void {
		$db = self::metadata(); $inspection = new RuleLifecycleReadiness( $db );
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], $inspection->get_status() );
		$db->options['cetech_de_db_version'] = '7';
		self::assertSame( [ 'ready' => false, 'code' => 'schema_unavailable' ], $inspection->get_status() );
		$db->options['cetech_de_db_version'] = '8';
		$db->options['cetech_de_last_migration_status'] = serialize( [ 'status' => 'failed', 'to_version' => '8' ] );
		self::assertSame( [ 'ready' => false, 'code' => 'migration_unconfirmed' ], $inspection->get_status() );
		$db->options['cetech_de_last_migration_status'] = serialize( [ 'status' => 'success', 'migration_id' => 'unrelated', 'to_version' => '8' ] );
		self::assertFalse( $inspection->get_status()['ready'] );
		$db->options['cetech_de_last_migration_status'] = serialize( [ 'status' => 'success', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID, 'to_version' => '8' ] );
		unset( $db->tables['proof_delivery_engine_operation_changes'] );
		self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], $inspection->get_status() );
		self::assertGreaterThanOrEqual( 7, count( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'SELECT option_value' ) ) ) );
	}

	#[DataProvider( 'incompatible_structures' )]
	public function test_incompatible_existing_definition_refuses_before_any_ddl( string $case ): void {
		$db = self::metadata(); $table = 'proof_delivery_engine_rule_versions';
		if ( 'engine' === $case ) { $db->tables[ $table ]['status']['Engine'] = 'MyISAM'; }
		if ( 'table_collation' === $case ) { $db->tables[ $table ]['status']['Collation'] = 'utf8mb4_general_ci'; }
		if ( 'digest_collation' === $case ) { $db->tables[ $table ]['columns']['content_hash']['Collation'] = 'ascii_general_ci'; }
		if ( 'digest_length' === $case ) { $db->tables[ $table ]['columns']['content_hash']['Type'] = 'char(63)'; }
		if ( 'nullability' === $case ) { $db->tables[ $table ]['columns']['scheduled_revision']['Null'] = 'NO'; }
		if ( 'type' === $case ) { $db->tables[ $table ]['columns']['row_revision']['Type'] = 'int(11) unsigned'; }
		if ( 'default' === $case ) { $db->tables[ $table ]['columns']['payload_format']['Default'] = '2'; }
		if ( 'extra_column' === $case ) { $db->tables[ $table ]['columns']['unexpected'] = $db->tables[ $table ]['columns']['site_id']; $db->tables[ $table ]['columns']['unexpected']['Field'] = 'unexpected'; }
		if ( 'unique' === $case ) { $db->tables[ $table ]['indexes'][1]['Non_unique'] = '1'; }
		if ( 'prefix_index' === $case ) { $db->tables[ $table ]['indexes'][2]['Sub_part'] = '12'; }
		if ( 'column_order' === $case ) { $db->tables[ $table ]['indexes'][1]['Column_name'] = 'version_uuid'; }
		if ( 'partial_index' === $case ) { unset( $db->tables[ $table ]['indexes'][2] ); }
		if ( 'index_kind' === $case ) { $db->tables[ $table ]['indexes'][1]['Index_type'] = 'HASH'; }
		if ( 'signed_priority' === $case ) { $db->tables[ $table ]['columns']['priority']['Type'] = 'int(11) unsigned'; }
		$before = serialize( $db->tables );
		try { ( new RuleLifecycleReadiness( $db ) )->preflight(); self::fail( 'Incompatible schema passed.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
		self::assertSame( [], array_filter( $db->queries, static fn ( string $sql ): bool => 1 === preg_match( '/\A(?:CREATE|ALTER|DROP|UPDATE|DELETE|INSERT)/', $sql ) ) );
	}

	public static function incompatible_structures(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'engine', 'table_collation', 'digest_collation', 'digest_length', 'nullability', 'type', 'default', 'extra_column', 'unique', 'prefix_index', 'column_order', 'partial_index', 'index_kind', 'signed_priority' ] );
	}

	public function test_empty_compatible_partial_install_only_allows_named_missing_structures(): void {
		$db = self::metadata(); $table = 'proof_delivery_engine_rule_versions';
		unset( $db->tables[ $table ]['columns']['scheduled_revision'], $db->tables['proof_delivery_engine_logical_rules'] );
		$db->tables[ $table ]['indexes'] = array_filter( $db->tables[ $table ]['indexes'], static fn ( array $index ): bool => 'activation_due' !== $index['Key_name'] );
		$inspection = new RuleLifecycleReadiness( $db ); $inspection->preflight();
		self::assertFalse( $inspection->get_status()['ready'] );
		$db->tables[ $table ]['rows'] = [ [ 'id' => '1', 'private' => 'PRIVATE_SENTINEL' ] ];
		$this->expectExceptionMessage( 'Rule lifecycle store could not be verified.' ); $inspection->preflight();
	}

	public function test_unknown_existing_family_is_preserved_and_validation_is_not_a_runtime_history_scan(): void {
		$db = self::metadata(); $table = 'proof_delivery_engine_rule_family_guards';
		$db->tables[ $table ]['rows'] = [ [ 'id' => '1', 'site_id' => '1', 'family_code' => 'unknown.family', 'private' => 'PRIVATE_SENTINEL' ] ];
		$before = serialize( $db->tables );
		try { ( new RuleLifecycleReadiness( $db ) )->verify_stored_records(); self::fail( 'Unknown family passed.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
		self::assertTrue( ( new RuleLifecycleReadiness( $db ) )->get_status()['ready'] );
		self::assertCount( 3, array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'MAX(id) AS ceiling' ) ) );
		self::assertNotEmpty( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) );
	}

	public function test_oversized_private_payload_is_refused_before_hydration_and_preserved(): void {
		$db = self::metadata(); $table = 'proof_delivery_engine_rule_versions';
		$db->tables[ $table ]['rows'] = [ [ 'id' => '1', 'payload_json' => str_repeat( 'x', 16385 ) ] ]; $before = serialize( $db->tables );
		try { ( new RuleLifecycleReadiness( $db ) )->preflight(); self::fail( 'Oversized payload passed.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
		self::assertEmpty( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'SELECT *' ) ) );
	}

	public function test_query_failure_is_not_absence_and_private_sql_error_is_not_exposed(): void {
		$db = self::metadata(); $db->query_failure = true;
		self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new RuleLifecycleReadiness( $db ) )->get_status() );
		$this->expectExceptionMessage( 'Rule lifecycle store could not be verified.' ); ( new RuleLifecycleReadiness( $db ) )->preflight();
	}
}
