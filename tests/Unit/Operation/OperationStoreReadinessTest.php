<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationStoreReadinessTest extends TestCase {

	public function test_live_marker_status_and_exact_structure_all_required_without_option_cache_truth(): void {
		$db = new OperationMetadataWpdb();
		$inspection = new OperationStoreReadiness( $db );
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], $inspection->get_status() );
		$db->options['cetech_de_db_version'] = '6';
		self::assertSame( [ 'ready' => false, 'code' => 'schema_unavailable' ], $inspection->get_status() );
		$db->options['cetech_de_db_version'] = '7';
		$db->options['cetech_de_last_migration_status'] = serialize( [ 'status' => 'failed', 'to_version' => '7' ] );
		self::assertSame( [ 'ready' => false, 'code' => 'migration_unconfirmed' ], $inspection->get_status() );
		$db->options['cetech_de_last_migration_status'] = serialize( [ 'status' => 'success', 'migration_id' => 'unrelated', 'to_version' => '7' ] );
		self::assertFalse( $inspection->get_status()['ready'] );
		self::assertTrue( count( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'SELECT option_value' ) ) ) >= 7 );
	}

	#[DataProvider( 'incompatible_structures' )]
	public function test_incompatible_existing_definitions_refuse_before_any_ddl( string $case ): void {
		$db = new OperationMetadataWpdb();
		$table = 'proof_delivery_engine_operation_records';
		if ( 'engine' === $case ) { $db->tables[ $table ]['status']['Engine'] = 'MyISAM'; }
		if ( 'table_collation' === $case ) { $db->tables[ $table ]['status']['Collation'] = 'utf8mb4_general_ci'; }
		if ( 'digest_collation' === $case ) { $db->tables[ $table ]['columns']['namespace_hash']['Collation'] = 'ascii_general_ci'; }
		if ( 'digest_length' === $case ) { $db->tables[ $table ]['columns']['namespace_hash']['Type'] = 'char(63)'; }
		if ( 'nullability' === $case ) { $db->tables[ $table ]['columns']['intent_hash']['Null'] = 'YES'; }
		if ( 'type' === $case ) { $db->tables[ $table ]['columns']['row_version']['Type'] = 'int(11) unsigned'; }
		if ( 'default' === $case ) { $db->tables[ $table ]['columns']['record_format']['Default'] = '2'; }
		if ( 'extra_column' === $case ) { $db->tables[ $table ]['columns']['unexpected'] = $db->tables[ $table ]['columns']['site_id']; $db->tables[ $table ]['columns']['unexpected']['Field'] = 'unexpected'; }
		if ( 'unique' === $case ) { $db->tables[ $table ]['indexes'][1]['Non_unique'] = '1'; }
		if ( 'prefix_index' === $case ) { $db->tables[ $table ]['indexes'][2]['Sub_part'] = '12'; }
		if ( 'column_order' === $case ) { $db->tables[ $table ]['indexes'][1]['Column_name'] = 'namespace_hash'; }
		if ( 'partial_index' === $case ) { unset( $db->tables[ $table ]['indexes'][2] ); }
		if ( 'index_kind' === $case ) { $db->tables[ $table ]['indexes'][1]['Index_type'] = 'HASH'; }
		$before = serialize( $db->tables );
		try { ( new OperationStoreReadiness( $db ) )->preflight(); self::fail( 'Incompatible schema passed preflight.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Operation store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
		self::assertSame( [], array_filter( $db->queries, static fn ( string $sql ): bool => 1 === preg_match( '/\A(?:CREATE|ALTER|DROP|UPDATE|DELETE|INSERT)/', $sql ) ) );
	}

	public static function incompatible_structures(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'engine', 'table_collation', 'digest_collation', 'digest_length', 'nullability', 'type', 'default', 'extra_column', 'unique', 'prefix_index', 'column_order', 'partial_index', 'index_kind' ] );
	}

	public function test_only_empty_named_missing_structures_allow_partial_first_install(): void {
		$db = new OperationMetadataWpdb();
		$table = 'proof_delivery_engine_operation_records';
		unset( $db->tables[ $table ]['columns']['completed_at'] );
		$db->tables[ $table ]['indexes'] = array_filter( $db->tables[ $table ]['indexes'], static fn ( array $index ): bool => 'site_state_id' !== $index['Key_name'] );
		unset( $db->tables['proof_delivery_engine_operation_changes'] );
		$inspection = new OperationStoreReadiness( $db );
		$inspection->preflight();
		self::assertFalse( $inspection->get_status()['ready'] );
		$db->tables[ $table ]['rows'] = [ [ 'id' => '1', 'private' => 'PRIVATE_SENTINEL' ] ];
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Operation store could not be verified.' );
		$inspection->preflight();
	}

	public function test_unknown_existing_profile_row_is_preserved_and_refused_by_migration_validation(): void {
		$db = new OperationMetadataWpdb();
		$table = 'proof_delivery_engine_operation_records';
		$db->tables[ $table ]['rows'] = [ [ 'id' => '1', 'operation' => 'unknown.profile', 'operation_version' => '1', 'completion_json' => 'PRIVATE_SENTINEL' ] ];
		$before = serialize( $db->tables );
		try { ( new OperationStoreReadiness( $db ) )->verify_stored_records(); self::fail( 'Unknown profile accepted.' ); }
		catch ( RuntimeException $exception ) { self::assertSame( 'Operation store could not be verified.', $exception->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) );
		self::assertTrue( ( new OperationStoreReadiness( $db ) )->get_status()['ready'] );
		self::assertTrue( count( array_filter( $db->queries, static fn ( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) ) > 0 );
	}

	public function test_query_failure_is_not_table_absence_and_raw_error_is_not_returned(): void {
		$db = new OperationMetadataWpdb();
		$db->query_failure = true;
		self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new OperationStoreReadiness( $db ) )->get_status() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Operation store could not be verified.' );
		( new OperationStoreReadiness( $db ) )->preflight();
	}
}
