<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\Operation;

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageSchema;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofFactory;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofObserver;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProfile;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Physical disposable proofs. An explicitly selected group never silently skips. */
#[Group( 'operation-store-real-db' )]
final class OperationStoreRealDatabaseTest extends TestCase {

	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = OperationProofDatabase::connect();
		$this->prefix = OperationProofDatabase::prefix();
		OperationProofDatabase::install( $this->database, $this->prefix );
	}

	protected function tearDown(): void {
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { OperationProofDatabase::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}

	private function identity( string $token = 'original', string $principal = 'staff:9', int $site = 1, string $operation = 'fixture.counter_update', int $version = 1 ): OperationIdentity {
		return new OperationIdentity( $site, 'wordpress', $principal, $operation, $version, 'counter:1', $token );
	}
	private function command( int $value = 7, int $revision = 1 ): array { return [ 'row_id' => 1, 'expected_revision' => $revision, 'value' => $value ]; }
	private function stack( ?OperationProofProfile $profile = null, ?callable $configure = null, ?OperationPhaseObserver $observer = null, int $site = 1 ): array {
		$profile ??= new OperationProofProfile( $this->prefix );
		$factory = new OperationProofFactory( $this->prefix, $site, $configure );
		$this->factories[] = $factory;
		return [ new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $factory, observer: $observer ), $profile, $factory ];
	}
	private function row_count( string $suffix ): int { return (int) OperationProofDatabase::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function record(): array {
		return OperationProofDatabase::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_records` ORDER BY id ASC LIMIT 1" ) ?? [];
	}
	private function resource(): array { return OperationProofDatabase::resource( $this->database, $this->prefix ); }
	private function directory(): string { $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; return $directory; }
	private function worker( array $arguments ): OperationProofProcess {
		return new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $arguments, JSON_THROW_ON_ERROR ) ] );
	}

	public function test_physical_tables_and_full_unique_indexes_match_production_ddl(): void {
		[ , , $factory ] = $this->stack();
		$session = $factory->open();
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new OperationStoreReadiness( $session ) )->get_status() );
		foreach ( OperationStoreSchema::SUFFIXES as $suffix ) {
			$table = $this->prefix . 'delivery_engine_' . $suffix;
			self::assertSame( 'InnoDB', OperationProofDatabase::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$table}'" )['Engine'] );
			$result = $this->database->query( "SHOW INDEX FROM `{$table}`" );
			foreach ( $result->fetch_all( MYSQLI_ASSOC ) as $index ) { self::assertNull( $index['Sub_part'] ); }
		}
		self::assertSame( 0, $this->row_count( 'operation_records' ) );
	}

	#[DataProvider( 'literal_modes' )]
	public function test_native_transport_pins_literal_sql_modes_before_owned_dispatch( string $mode, bool $supported ): void {
		$native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) );
		$transport = new OperationProofTransport( $native ); $connection = new OperationConnection( 1, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' );
		try {
			self::assertTrue( $native->execute( "SET SESSION sql_mode='{$mode}'" )->acknowledged );
			$before = $transport->calls;
			if ( ! $supported ) {
				self::assertNull( $native->transaction_state() ); self::assertFalse( $connection->begin() ); self::assertTrue( $connection->is_retired() );
				self::assertFalse( $connection->get_row( 'SELECT "private"."hidden_effect"(1)' ) ); self::assertSame( $before, $transport->calls ); self::assertSame( 1, $transport->close_calls );
				return;
			}
			self::assertIsArray( $native->transaction_state() ); self::assertTrue( $connection->begin() );
			$packet = [ 'input_json' => '{"quoted":"private \\"; COMMIT; --"}', 'result_json' => '{"state":"absolute_window"}', 'padding' => '' ]; $encoded = json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); $packet['padding'] = str_repeat( 'x', 65536 - strlen( $encoded ) ); $value = json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); self::assertSame( 65536, strlen( $value ) );
			$row = $connection->get_row( $connection->prepare( 'SELECT %s AS original_packet', $value ) ); self::assertIsArray( $row ); self::assertSame( hash( 'sha256', $value ), hash( 'sha256', $row['original_packet'] ) ); self::assertSame( $before + 2, $transport->calls ); self::assertTrue( $connection->rollback() );
		} finally { self::assertTrue( $connection->retire() ); }
	}
	public static function literal_modes(): array {
		return [ 'default literals' => [ '', true ], 'strict transactional' => [ 'STRICT_TRANS_TABLES', true ], 'traditional' => [ 'TRADITIONAL', true ], 'ANSI identifiers' => [ 'ANSI_QUOTES', false ], 'ANSI combined mode' => [ 'ANSI', false ], 'backslash disabled' => [ 'NO_BACKSLASH_ESCAPES', false ], 'both unsupported semantics' => [ 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES', false ] ];
	}
	#[DataProvider( 'unsupported_literal_modes' )]
	public function test_native_literal_mode_change_retires_existing_owner_before_any_following_statement( string $mode ): void {
		$native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) ); $transport = new OperationProofTransport( $native ); $connection = new OperationConnection( 1, $this->prefix, $transport );
		try {
			self::assertTrue( $native->execute( "SET SESSION sql_mode=''" )->acknowledged ); self::assertTrue( $connection->begin() ); self::assertTrue( $native->execute( "SET SESSION sql_mode='{$mode}'" )->acknowledged ); $before = $transport->calls;
			self::assertFalse( $connection->get_row( 'SELECT "private"."hidden_effect"(1)' ) ); self::assertSame( $before, $transport->calls ); self::assertTrue( $connection->is_retired() ); self::assertSame( 1, $transport->close_calls ); self::assertFalse( $connection->begin() );
		} finally { self::assertTrue( $connection->retire() ); }
	}
	public static function unsupported_literal_modes(): array { return [ [ 'ANSI_QUOTES' ], [ 'ANSI' ], [ 'NO_BACKSLASH_ESCAPES' ], [ 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES' ] ]; }
	public function test_actual_quoted_function_cannot_run_its_native_effect_through_owned_select(): void {
		$name = $this->prefix . 'effect'; $table = $this->prefix . 'operation_fixture_counter'; $native = null; $connection = null;
		OperationProofDatabase::execute( $this->database, "CREATE FUNCTION `{$name}`(requested_value INT) RETURNS INT MODIFIES SQL DATA BEGIN UPDATE `{$table}` SET value=requested_value WHERE id=1; RETURN requested_value; END" );
		try {
			$native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) ); self::assertTrue( $native->execute( "SET SESSION sql_mode=''" )->acknowledged ); $transport = new OperationProofTransport( $native ); $connection = new OperationConnection( 1, $this->prefix, $transport ); self::assertTrue( $connection->begin() );
			foreach ( [ "SELECT `{$name}`(7)", "SHOW TABLES WHERE {$name}(7)", "SHOW TABLES WHERE `{$name}`(7)" ] as $sql ) { $before = $transport->calls; self::assertFalse( $connection->get_row( $sql ) ); self::assertSame( $before, $transport->calls ); }
			self::assertSame( '0', $connection->get_row( "SELECT value FROM `{$table}` WHERE id=1" )['value'] ); self::assertTrue( $connection->rollback() );
		} finally { if ( null !== $connection ) { $connection->retire(); } elseif ( null !== $native ) { $native->close(); } OperationProofDatabase::execute( $this->database, "DROP FUNCTION `{$name}`" ); }
		self::assertSame( 0, $this->resource()['value'] );
	}
	public function test_actual_promise_union_allows_forty_one_only_after_each_native_participant_exists(): void {
		$promise = PromiseStorageSchema::tables( $this->prefix ); $base = array_map( fn( int $id ): string => $this->prefix . 'finite_' . $id, range( 1, 38 ) ); $union = [ ...$promise, ...$base ]; $created = []; $connection = null;
		try {
			foreach ( PromiseStorageSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->prefix . 'delivery_engine_' ) as $suffix => $statement ) { OperationProofDatabase::execute( $this->database, $statement ); $created[] = $this->prefix . 'delivery_engine_' . $suffix; }
			foreach ( $base as $table ) { OperationProofDatabase::execute( $this->database, 'CREATE TABLE `' . $table . '` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB' ); $created[] = $table; }
			$native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) ); $transport = new OperationProofTransport( $native ); $connection = new OperationConnection( 1, $this->prefix, $transport );
			self::assertTrue( $connection->begin() ); self::assertTrue( $connection->validate_tables( [ ...$union, ...$union ] ) ); self::assertSame( '0', $connection->get_row( 'SELECT COUNT(*) AS total FROM `' . $promise[0] . '`' )['total'] );
			$before = $transport->calls; self::assertFalse( $connection->validate_tables( [ $this->prefix . 'finite_42' ] ) ); self::assertSame( $before, $transport->calls ); self::assertFalse( $connection->query( 'UPDATE `' . $this->prefix . 'finite_42` SET id=1' ) ); self::assertSame( $before, $transport->calls ); self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
			OperationProofDatabase::execute( $this->database, 'DROP TABLE `' . $promise[0] . '`' ); $created = array_values( array_diff( $created, [ $promise[0] ] ) );
			$native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) ); $connection = new OperationConnection( 1, $this->prefix, $native ); self::assertTrue( $connection->begin() ); self::assertFalse( $connection->validate_tables( $union ) ); self::assertSame( 1146, $connection->errno() ); self::assertFalse( $connection->query( 'UPDATE `' . $promise[0] . '` SET id=1' ) ); self::assertTrue( $connection->rollback() );
		} finally { if ( null !== $connection ) { $connection->retire(); } foreach ( array_reverse( $created ) as $table ) { OperationProofDatabase::execute( $this->database, 'DROP TABLE `' . $table . '`' ); } }
	}

	public function test_changed_acceptance_and_replay_preserve_first_effect_audit_and_private_token(): void {
		[ $coordinator, $profile ] = $this->stack();
		$first_request = RequestContext::create();
		$first = $coordinator->attempt( $this->identity( 'PRIVATE-FIXTURE-TOKEN' ), $this->command(), $first_request );
		self::assertSame( 'accepted', $first->outcome->state );
		self::assertSame( [ 'row_id' => 1, 'revision' => 2, 'value' => 7 ], $first->completion->result );
		$before = $this->record();
		$second_request = RequestContext::create();
		$second = $coordinator->attempt( $this->identity( 'PRIVATE-FIXTURE-TOKEN' ), [ 'value' => 7, 'expected_revision' => 1, 'row_id' => 1 ], $second_request );
		self::assertSame( 'accepted', $second->outcome->state ); self::assertTrue( $second->replayed );
		self::assertSame( 1, $profile->mutation_calls ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( $before, $this->record() );
		$event = json_decode( OperationProofDatabase::scalar( $this->database, "SELECT event_json FROM `{$this->prefix}delivery_engine_operation_changes`" ), true, 8, JSON_THROW_ON_ERROR );
		self::assertSame( $first_request->request_id, $event['request_id'] ); self::assertNotSame( $second_request->request_id, $event['request_id'] );
		self::assertStringNotContainsString( 'PRIVATE-FIXTURE-TOKEN', json_encode( [ $before, $event ], JSON_THROW_ON_ERROR ) );
	}

	public function test_fresh_os_process_replays_after_unrelated_material_event_pressure(): void {
		[ $coordinator ] = $this->stack();
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() )->outcome->state );
		$original = $this->record();
		$original_event = OperationProofDatabase::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_changes` ORDER BY id ASC LIMIT 1" );
		for ( $number = 0; $number < 101; ++$number ) {
			$outcome = $coordinator->attempt( $this->identity( 'later-' . $number ), $this->command( 8 + $number, 2 + $number ), RequestContext::create() );
			self::assertSame( 'accepted', $outcome->outcome->state );
		}
		$latest_resource = $this->resource();
		self::assertSame( 103, $latest_resource['revision'] ); self::assertSame( 108, $latest_resource['value'] );
		$fresh = $this->worker( [ 'token' => 'original' ] )->finish();
		self::assertSame( 'accepted', $fresh['state'] ); self::assertTrue( $fresh['replayed'] );
		self::assertSame( [ 'row_id' => 1, 'revision' => 2, 'value' => 7 ], $fresh['result'] );
		self::assertSame( $original, $this->record() ); self::assertSame( $latest_resource, $this->resource() );
		self::assertSame( $original_event, OperationProofDatabase::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_changes` ORDER BY id ASC LIMIT 1" ) );
		self::assertSame( 102, $this->row_count( 'operation_changes' ) ); self::assertSame( 102, $this->row_count( 'operation_records' ) );
	}

	public function test_simultaneous_processes_same_intent_accept_once_and_replay_once(): void {
		$directory = $this->directory();
		$left = $this->worker( [ 'token' => 'simultaneous', 'start_ready' => $directory . '/left', 'start_release' => $directory . '/go' ] );
		$right = $this->worker( [ 'token' => 'simultaneous', 'start_ready' => $directory . '/right', 'start_release' => $directory . '/go' ] );
		OperationProofProcess::wait_for( $directory . '/left' ); OperationProofProcess::wait_for( $directory . '/right' ); OperationProofBarrier::signal( $directory . '/go' );
		$results = [ $left->finish(), $right->finish() ];
		self::assertContains( 'accepted', array_column( $results, 'state' ) );
		self::assertSame( 1, count( array_filter( $results, static fn( array $result ): bool => 'accepted' === $result['state'] && ! $result['replayed'] ) ) );
		$accepted_record = $this->record();
		self::assertSame( 'accepted', $accepted_record['state'] );
		foreach ( $results as $first_response ) {
			if ( 'accepted' === $first_response['state'] ) { self::assertNull( $first_response['error_code'] ); continue; }
			// Retain the actual uncertain first response. A deadlock during the
			// current receipt/event read cannot establish which peer committed.
			self::assertSame( 'unconfirmed', $first_response['state'], json_encode( $first_response, JSON_THROW_ON_ERROR ) );
			self::assertSame( 'outcome_unknown', $first_response['error_code'] );
			self::assertNull( $first_response['accepted'] ); self::assertNull( $first_response['result'] );
			self::assertSame( 1, $first_response['deadlocks'] );
			$resolved = $this->worker( [ 'token' => 'simultaneous', 'action' => 'reconcile' ] )->finish();
			self::assertSame( 'accepted', $resolved['state'] ); self::assertTrue( $resolved['replayed'] );
			self::assertSame( $accepted_record, $this->record() );
		}
		$replay = $this->worker( [ 'token' => 'simultaneous' ] )->finish();
		self::assertSame( 'accepted', $replay['state'] ); self::assertTrue( $replay['replayed'] );
		self::assertSame( [ 'row_id' => 1, 'revision' => 2, 'value' => 7 ], $replay['result'] );
		self::assertSame( $accepted_record, $this->record() );
		self::assertNotSame( $results[0]['request_id'], $results[1]['request_id'] );
		self::assertSame( 1, $this->row_count( 'operation_records' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 2, $this->resource()['revision'] );
	}

	public function test_two_tokens_share_target_lock_and_original_revision_allows_one_change(): void {
		$directory = $this->directory();
		$left = $this->worker( [ 'token' => 'left', 'value' => 7, 'start_ready' => $directory . '/left', 'start_release' => $directory . '/go' ] );
		$right = $this->worker( [ 'token' => 'right', 'value' => 8, 'start_ready' => $directory . '/right', 'start_release' => $directory . '/go' ] );
		OperationProofProcess::wait_for( $directory . '/left' ); OperationProofProcess::wait_for( $directory . '/right' ); OperationProofBarrier::signal( $directory . '/go' );
		$results = [ $left->finish(), $right->finish() ]; $states = array_column( $results, 'state' ); sort( $states );
		self::assertSame( [ 'accepted', 'rejected' ], $states );
		$loser = 'rejected' === $results[0]['state'] ? 0 : 1;
		self::assertFalse( $results[ $loser ]['accepted'] );
		// InnoDB may choose a deadlock victim while two absent audit-index gaps
		// contend. Preserve that first known rejection, then make one explicit
		// original-envelope retry after the winner committed; never retry blindly.
		self::assertContains( $results[ $loser ]['error_code'], [ 'stale_revision', 'temporarily_unavailable' ] );
		[ $coordinator ] = $this->stack();
		$retry = $coordinator->attempt( $this->identity( 0 === $loser ? 'left' : 'right' ), $this->command( 0 === $loser ? 7 : 8 ), RequestContext::create() );
		self::assertSame( 'stale_revision', $retry->outcome->error->code );
		self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 2, $this->resource()['revision'] ); self::assertContains( $this->resource()['value'], [ 7, 8 ] );
	}

	public function test_changed_intent_conflicts_without_overwriting_original_receipt(): void {
		[ $coordinator ] = $this->stack();
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() )->outcome->state ); $before = $this->record();
		$conflict = $coordinator->attempt( $this->identity(), $this->command( 8 ), RequestContext::create() );
		self::assertSame( 'intent_conflict', $conflict->outcome->error->code ); self::assertSame( $before, $this->record() ); self::assertSame( 7, $this->resource()['value'] ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_no_change_is_terminal_even_after_resource_is_changed_by_another_writer(): void {
		[ $coordinator, $profile ] = $this->stack();
		self::assertSame( 'not_applicable', $coordinator->attempt( $this->identity(), $this->command( 0 ), RequestContext::create() )->outcome->state );
		OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}operation_fixture_counter` SET value=99,revision=2 WHERE id=1" );
		$result = $coordinator->attempt( $this->identity(), $this->command( 0 ), RequestContext::create() );
		self::assertSame( 'not_applicable', $result->outcome->state ); self::assertTrue( $result->replayed ); self::assertSame( 99, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) ); self::assertSame( 1, $profile->mutation_calls );
	}

	public function test_denied_authority_never_opens_connection_and_revoked_replay_discloses_nothing(): void {
		[ $coordinator, $profile, $factory ] = $this->stack();
		$profile->allowed = false;
		$denied = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $denied->outcome->error->code ); self::assertCount( 0, $factory->sessions );
		$profile->allowed = true;
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() )->outcome->state );
		$opened = count( $factory->sessions ); $profile->allowed = false;
		$replay = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $replay->outcome->error->code ); self::assertNull( $replay->completion ); self::assertCount( $opened, $factory->sessions ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_cross_principal_site_operation_and_version_are_distinct_namespaces(): void {
		[ $coordinator ] = $this->stack();
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity( 'shared' ), $this->command(), RequestContext::create() )->outcome->state );
		$variants = [ [ 'staff:10', 1, 'fixture.counter_update', 1 ], [ 'staff:9', 2, 'fixture.counter_update', 1 ], [ 'staff:9', 1, 'fixture.counter_alternate', 1 ], [ 'staff:9', 1, 'fixture.counter_update', 2 ] ];
		foreach ( $variants as [ $principal, $site, $operation, $version ] ) {
			[ $other ] = $this->stack( new OperationProofProfile( $this->prefix, name: $operation, contract_version: $version ), site: $site );
			$result = $other->attempt( $this->identity( 'shared', $principal, $site, $operation, $version ), $this->command(), RequestContext::create() );
			self::assertSame( 'stale_revision', $result->outcome->error->code ); self::assertFalse( $result->replayed ); self::assertNotSame( 'accepted', $result->completion?->state );
		}
		self::assertSame( 5, $this->row_count( 'operation_records' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_rejected_audit_rolls_back_business_then_same_intent_can_apply_once(): void {
		[ $coordinator, , $factory ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { $transport->reject_audit = 1 === $open; } );
		$rejected = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $rejected->outcome->state ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 1, $this->resource()['revision'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
		$retry = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $retry->outcome->state ); self::assertSame( 7, $this->resource()['value'] ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertGreaterThanOrEqual( 3, count( $factory->sessions ) );
	}

	public function test_driver_proven_unsent_effect_commit_rolls_back_and_records_known_rejection(): void {
		[ $coordinator, , $factory ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'unsent'; } } );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertFalse( $result->outcome->mutation_accepted ); self::assertSame( 1, $factory->transports[0]->sent_commits ); self::assertSame( 2, $factory->transports[0]->commits );
		self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) ); self::assertSame( 'rejected', $this->record()['state'] );
	}

	public function test_real_commit_with_injected_lost_ack_is_unconfirmed_and_fresh_reconcile_accepts(): void {
		[ $coordinator, $profile, $factory ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$lost = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $lost->outcome->state ); self::assertNull( $lost->outcome->mutation_accepted ); self::assertSame( 2, $factory->transports[0]->sent_commits );
		self::assertSame( 7, $this->resource()['value'] ); self::assertSame( 'accepted', $this->record()['state'] ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertTrue( $factory->sessions[0]->is_retired() );
		self::assertFalse( $factory->sessions[0]->query( "UPDATE `{$this->prefix}operation_fixture_counter` SET value=88 WHERE id=1" ) );
		$resolved = $coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state ); self::assertTrue( $resolved->replayed ); self::assertSame( 1, $profile->mutation_calls ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_lost_reservation_ack_never_runs_effect_and_reconcile_only_marks_known_no_effect(): void {
		[ $coordinator, $profile ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->fault_commit = 1; $transport->commit_fault = 'lost_ack'; } } );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertSame( 'pending', $this->record()['state'] ); self::assertSame( 0, $profile->mutation_calls );
		$reconciled = $coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $reconciled->outcome->state ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->resource()['value'] );
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() )->outcome->state ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_actual_connection_close_during_intermediate_write_never_reconnects_or_autocommits(): void {
		[ $coordinator, , $factory ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { $transport->disconnect_after_mutation = 1 === $open; } );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 'pending', $this->record()['state'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) ); self::assertTrue( $factory->sessions[0]->is_retired() );
		$calls = $factory->transports[0]->calls; self::assertFalse( $factory->sessions[0]->query( 'SELECT 1' ) ); self::assertSame( $calls, $factory->transports[0]->calls );
	}

	#[DataProvider( 'crash_phases' )]
	public function test_real_process_kill_leaves_reservation_and_reconciliation_never_mutates( string $phase ): void {
		$directory = $this->directory();
		$process = $this->worker( [ 'token' => 'crashed', 'phase' => $phase, 'phase_ready' => $directory . '/paused', 'phase_release' => $directory . '/unused' ] );
		OperationProofProcess::wait_for( $directory . '/paused' );
		self::assertSame( 'pending', $this->record()['state'] ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
		self::assertSame( 9, $process->kill_and_wait() );
		[ $coordinator, $profile ] = $this->stack();
		$reconciled = $coordinator->reconcile( $this->identity( 'crashed' ), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $reconciled->outcome->state ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->resource()['value'] );
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity( 'crashed' ), $this->command(), RequestContext::create() )->outcome->state ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public static function crash_phases(): array { return array_map( static fn ( string $phase ): array => [ $phase ], OperationPhaseObserver::PHASES ); }

	public function test_actual_lock_wait_bound_preserves_pending_without_second_effect(): void {
		$directory = $this->directory();
		$process = $this->worker( [ 'token' => 'waiting', 'phase' => 'reservation_committed', 'phase_ready' => $directory . '/reserved', 'phase_release' => $directory . '/go' ] );
		OperationProofProcess::wait_for( $directory . '/reserved' );
		$locker = OperationProofDatabase::connect(); $locker->begin_transaction();
		OperationProofDatabase::row( $locker, "SELECT id FROM `{$this->prefix}delivery_engine_operation_records` FOR UPDATE" );
		$started = microtime( true ); OperationProofBarrier::signal( $directory . '/go' );
		self::assertSame( 0, $this->resource()['value'] );
		$result = $process->finish(); $elapsed = microtime( true ) - $started; $locker->rollback(); $locker->close();
		self::assertSame( 1, $result['lock_wait_timeouts'], 'The actual MariaDB transport must return its native lock-timeout errno.' );
		self::assertGreaterThanOrEqual( 1.7, $elapsed ); self::assertLessThan( 5.0, $elapsed );
		self::assertSame( 'unconfirmed', $result['state'] ); self::assertSame( 'pending', $this->record()['state'] ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}

	public function test_failed_rollback_and_failed_retirement_remain_unconfirmed_until_session_ends(): void {
		[ $coordinator, , $factory ] = $this->stack( configure: static function ( OperationProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->reject_audit = true; $transport->reject_rollback = true; $transport->reject_close = true; } } );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertTrue( $factory->sessions[0]->is_retired() ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 'pending', $this->record()['state'] );
		$factory->close_all();
		[ $fresh, $profile ] = $this->stack();
		self::assertSame( 'rejected', $fresh->reconcile( $this->identity(), $this->command(), RequestContext::create() )->outcome->state ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}

	public function test_publication_retry_preserves_receipt_event_and_never_repeats_business_effect(): void {
		[ $coordinator, $profile ] = $this->stack( new OperationProofProfile( $this->prefix, publication: true ) ); $profile->publish_allowed = false;
		$first = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $first->outcome->state ); self::assertTrue( $first->outcome->mutation_accepted ); self::assertTrue( $first->outcome->publication_pending );
		$receipt = $this->record()['completion_json']; $profile->publish_allowed = true;
		$retry = $coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $retry->outcome->state ); self::assertSame( 1, $profile->mutation_calls ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( $receipt, $this->record()['completion_json'] ); self::assertSame( 'published', $this->record()['publication_state'] ); self::assertSame( 2, $this->resource()['published_revision'] );
	}

	public function test_snapshot_opened_before_other_writer_does_not_override_locked_current_revision(): void {
		[ $coordinator, $profile ] = $this->stack(); $snapshots = [];
		$profile->before_current_lock = function ( OperationSession $session ) use ( &$snapshots ): void {
			$snapshots[] = (int) $session->get_row( "SELECT revision FROM `{$this->prefix}operation_fixture_counter` WHERE id=1" )['revision'];
			OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}operation_fixture_counter` SET revision=2,value=99 WHERE id=1" );
			$snapshots[] = (int) $session->get_row( "SELECT revision FROM `{$this->prefix}operation_fixture_counter` WHERE id=1" )['revision'];
		};
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( [ 1, 1 ], $snapshots, 'The owner must actually retain its older repeatable-read view.' );
		self::assertSame( 'stale_revision', $result->outcome->error->code ); self::assertSame( 99, $this->resource()['value'] ); self::assertSame( 2, $this->resource()['revision'] ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}

	public function test_revocation_after_real_commit_hides_completion_without_erasing_accepted_truth(): void {
		$profile = new OperationProofProfile( $this->prefix );
		$observer = new class( $profile ) implements OperationPhaseObserver {
			public function __construct( private OperationProofProfile $profile ) {}
			public function observe( string $phase, OperationIdentity $identity ): void { if ( 'before_effect_commit' === $phase ) { $this->profile->allowed = false; } }
		};
		[ $coordinator ] = $this->stack( $profile, observer: $observer );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion ); self::assertSame( 'accepted', $this->record()['state'] ); self::assertSame( 7, $this->resource()['value'] ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_real_publication_marker_commit_with_lost_ack_replays_without_second_publish(): void {
		[ $coordinator, $profile, $factory ] = $this->stack( new OperationProofProfile( $this->prefix, publication: true ), static function ( OperationProofTransport $transport, int $open ): void { if ( 2 === $open ) { $transport->fault_commit = 1; $transport->commit_fault = 'lost_ack'; } } );
		$lost = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $lost->outcome->state ); self::assertTrue( $lost->outcome->mutation_accepted ); self::assertSame( 'published', $this->record()['publication_state'] ); self::assertSame( 1, $factory->transports[1]->sent_commits );
		$replayed = $coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $replayed->outcome->state ); self::assertSame( 1, $profile->publish_calls ); self::assertSame( 1, $profile->mutation_calls ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_older_publication_does_not_regress_a_newer_profile_defined_publication(): void {
		[ $old, $old_profile ] = $this->stack( new OperationProofProfile( $this->prefix, publication: true ) ); $old_profile->publish_allowed = false;
		self::assertSame( 'unconfirmed', $old->attempt( $this->identity( 'old' ), $this->command(), RequestContext::create() )->outcome->state ); $old_receipt = $this->record()['completion_json'];
		[ $new ] = $this->stack( new OperationProofProfile( $this->prefix, publication: true ) );
		self::assertSame( 'accepted', $new->attempt( $this->identity( 'new' ), $this->command( 8, 2 ), RequestContext::create() )->outcome->state ); self::assertSame( 3, $this->resource()['published_revision'] );
		$old_profile->publish_allowed = true;
		self::assertSame( 'accepted', $old->reconcile( $this->identity( 'old' ), $this->command(), RequestContext::create() )->outcome->state );
		self::assertSame( 3, $this->resource()['published_revision'] ); self::assertSame( 8, $this->resource()['value'] ); self::assertSame( $old_receipt, $this->record()['completion_json'] ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}

	#[DataProvider( 'participant_failures' )]
	public function test_nontransactional_cross_scope_or_ambient_participant_refuses_before_effect( string $fault ): void {
		[ $coordinator, $profile ] = $this->stack( configure: 'ambient' === $fault ? static function ( OperationProofTransport $transport ): void { $transport->execute( 'START TRANSACTION' ); } : null );
		if ( 'myisam' === $fault ) { OperationProofDatabase::execute( $this->database, "ALTER TABLE `{$this->prefix}operation_fixture_counter` ENGINE=MyISAM" ); }
		if ( 'foreign' === $fault ) { $profile->participant_override = [ 'foreign_operation_fixture_counter' ]; }
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertNotSame( 'accepted', $result->outcome->state ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_records' ) ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public static function participant_failures(): array { return [ [ 'myisam' ], [ 'foreign' ], [ 'ambient' ] ]; }

	#[DataProvider( 'bookkeeping_winners' )]
	public function test_rejection_bookkeeping_never_overwrites_concurrent_accepted_or_no_change_truth( int $value, string $state, int $events ): void {
		$winner = null;
		[ $coordinator ] = $this->stack( configure: function ( OperationProofTransport $transport, int $open ) use ( $value, &$winner ): void {
			if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'unsent'; }
			if ( 2 === $open ) { [ $other ] = $this->stack(); $winner = $other->attempt( $this->identity(), $this->command( $value ), RequestContext::create() ); }
		} );
		$result = $coordinator->attempt( $this->identity(), $this->command( $value ), RequestContext::create() );
		self::assertSame( $state, $winner->outcome->state ); self::assertSame( $state, $result->outcome->state ); self::assertTrue( $result->replayed ); self::assertSame( $state, $this->record()['state'] ); self::assertSame( $value, $this->resource()['value'] ); self::assertSame( $events, $this->row_count( 'operation_changes' ) );
	}
	public static function bookkeeping_winners(): array { return [ [ 7, 'accepted', 1 ], [ 0, 'not_applicable', 0 ] ]; }

	#[DataProvider( 'corrupt_evidence' )]
	public function test_corrupt_or_unknown_stored_evidence_is_unconfirmed_and_preserved( string $fault ): void {
		[ $coordinator, $profile ] = $this->stack();
		self::assertSame( 'accepted', $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() )->outcome->state );
		if ( 'record_format' === $fault ) { OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET record_format=2" ); }
		elseif ( 'completion_extra' === $fault ) { $json = json_decode( $this->record()['completion_json'], true, 8, JSON_THROW_ON_ERROR ); $json['renamed_private'] = [ 'address' => 'PRIVATE_SENTINEL' ]; $encoded = $this->database->real_escape_string( json_encode( $json, JSON_THROW_ON_ERROR ) ); OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET completion_json='{$encoded}'" ); }
		elseif ( 'event_missing' === $fault ) { OperationProofDatabase::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_operation_changes`" ); }
		elseif ( 'orphan_on_pending' === $fault ) { OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET state='pending',completion_json=NULL,audit_id=NULL,completed_at=NULL,publication_state='none'" ); }
		elseif ( 'semantic_link' === $fault ) { $json = json_decode( $this->record()['completion_json'], true, 8, JSON_THROW_ON_ERROR ); $json['result']['revision'] = 99; $encoded = $this->database->real_escape_string( json_encode( $json, JSON_THROW_ON_ERROR ) ); OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET completion_json='{$encoded}'" ); }
		elseif ( 'wrong_link' === $fault ) { OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET audit_id=999" ); }
		else { OperationProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_changes` SET event_format=2" ); }
		$before = $this->record(); $result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertSame( 'outcome_unknown', $result->outcome->error->code ); self::assertNull( $result->completion ); self::assertSame( $before, $this->record() ); self::assertSame( 1, $profile->mutation_calls ); self::assertSame( 7, $this->resource()['value'] ); self::assertStringNotContainsString( 'PRIVATE_SENTINEL', json_encode( $result->outcome->error->to_array(), JSON_THROW_ON_ERROR ) );
	}
	public static function corrupt_evidence(): array { return [ [ 'record_format' ], [ 'completion_extra' ], [ 'event_missing' ], [ 'wrong_link' ], [ 'event_format' ], [ 'orphan_on_pending' ], [ 'semantic_link' ] ]; }

	#[DataProvider( 'unready_storage' )]
	public function test_actual_partial_schema_engine_index_and_option_truth_fail_feature_readiness( string $fault ): void {
		if ( 'missing_table' === $fault ) { OperationProofDatabase::execute( $this->database, "DROP TABLE `{$this->prefix}delivery_engine_operation_changes`" ); }
		elseif ( 'wrong_engine' === $fault ) { OperationProofDatabase::execute( $this->database, "ALTER TABLE `{$this->prefix}delivery_engine_operation_changes` ENGINE=MyISAM" ); }
		elseif ( 'prefix_index' === $fault ) { OperationProofDatabase::execute( $this->database, "ALTER TABLE `{$this->prefix}delivery_engine_operation_records` DROP INDEX site_namespace, ADD UNIQUE KEY site_namespace(site_id,namespace_hash(16))" ); }
		elseif ( 'schema_six' === $fault ) { OperationProofDatabase::option( $this->database, $this->prefix, SchemaVersion::OPTION_NAME, '6' ); }
		else { OperationProofDatabase::option( $this->database, $this->prefix, MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'failed', 'to_version' => '7', 'migration_id' => OperationStoreReadiness::MIGRATION_ID ] ) ); }
		[ $coordinator, $profile, $factory ] = $this->stack();
		self::assertFalse( ( new OperationStoreReadiness( $factory->open() ) )->get_status()['ready'] );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertNotSame( 'accepted', $result->outcome->state ); self::assertSame( 0, $profile->mutation_calls ); self::assertSame( 0, $this->resource()['value'] ); self::assertSame( 0, $this->row_count( 'operation_records' ) );
	}
	public static function unready_storage(): array { return [ [ 'missing_table' ], [ 'wrong_engine' ], [ 'prefix_index' ], [ 'schema_six' ], [ 'status_failed' ] ]; }
}
