<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageSchema;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use PHPUnit\Framework\TestCase;

final class OperationConnectionTest extends TestCase {

	public function test_only_owned_units_can_query_and_ambient_or_nested_units_refuse(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertFalse( $connection->get_row( 'SELECT 1' ) );
		self::assertTrue( $connection->begin() );
		self::assertFalse( $connection->begin() );
		self::assertTrue( $connection->in_transaction() );
		self::assertTrue( $connection->rollback() );
		self::assertFalse( $connection->in_transaction() );
		$ambient = new OperationConnectionTestTransport();
		$ambient->transaction = true;
		self::assertFalse( $this->connection( $ambient )->begin() );
		self::assertNotContains( 'START TRANSACTION', $ambient->statements );
		self::assertTrue( $ambient->closed );
	}

	public function test_declared_same_site_innodb_tables_are_required_before_writes(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertFalse( $connection->query( 'UPDATE op_counter SET value=1 WHERE id=1' ) );
		self::assertFalse( $connection->validate_tables( [ 'other_counter' ] ) );
		self::assertFalse( $connection->validate_tables( [ [] ] ) );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		self::assertSame( 1, $connection->query( 'UPDATE op_counter SET value=1 WHERE id=1' ) );
		self::assertTrue( $connection->rollback() );
		self::assertTrue( $connection->begin() );
		self::assertFalse( $connection->query( 'UPDATE op_counter SET value=2 WHERE id=1' ) );
		self::assertTrue( $connection->rollback() );
	}

	public function test_quote_source_native_and_coordinator_union_has_a_finite_total_ceiling(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$common = [ 'operation_records', 'operation_changes', 'quotes', 'quote_order_bindings', 'quote_admission_budget', 'options', 'product_delivery_rules', 'delivery_offers', 'rate_cards', 'destination_zones', 'destination_rules', 'posts', 'postmeta', 'term_relationships', 'term_taxonomy', 'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'woocommerce_shipping_zone_methods', 'woocommerce_sessions', 'users', 'usermeta', 'configuration_scopes', 'configuration_fields' ];
		$tables = array_map( static fn( string $suffix ): string => 'op_' . $suffix, $common );
		self::assertTrue( $connection->validate_tables( $tables ) );
		foreach ( $tables as $table ) { self::assertContains( 'SELECT 1 FROM `' . $table . '` LIMIT 0', $transport->statements ); }
		// Q06 also fences six native HPOS order/item tables before placement.
		$native = array_map( static fn( string $suffix ): string => 'op_' . $suffix, [ 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta' ] );
		self::assertTrue( $connection->validate_tables( [ ...$tables, ...$native ] ) );
		$ceiling = [ ...$tables, ...$native, ...array_map( static fn( int $id ): string => 'op_finite_' . $id, range( 1, 11 ) ) ];
		self::assertTrue( $connection->validate_tables( $ceiling ) );
		$before = count( $transport->statements );
		self::assertFalse( $connection->validate_tables( [ ...$ceiling, 'op_finite_41' ] ) );
		self::assertSame( $before, count( $transport->statements ) );
		self::assertFalse( $connection->validate_tables( [ 'op_finite_41' ] ) );
		self::assertSame( $before, count( $transport->statements ) );
		self::assertFalse( $connection->validate_tables( [ ...array_slice( $ceiling, 0, 39 ), 'foreign_options' ] ) );
		self::assertSame( $before, count( $transport->statements ) );
		self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
	}

	public function test_nontransactional_participant_refuses(): void {
		$transport = new OperationConnectionTestTransport();
		$transport->engine = 'MyISAM';
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertFalse( $connection->validate_tables( [ 'op_counter' ] ) );
		self::assertFalse( $connection->query( 'UPDATE op_counter SET value=1 WHERE id=1' ) );
		self::assertTrue( $connection->rollback() );
	}

	public function test_promise_participants_extend_only_the_distinct_owned_union_to_forty_one(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport ); self::assertTrue( $connection->begin() );
		$promise = PromiseStorageSchema::tables( 'op_' ); $base = array_map( static fn( int $id ): string => 'op_finite_' . $id, range( 1, 38 ) ); $union = [ ...$base, ...$promise ];
		self::assertCount( 41, $union ); self::assertTrue( $connection->validate_tables( $base ) ); self::assertTrue( $connection->validate_tables( [ ...$union, ...$union ] ) );
		foreach ( $promise as $table ) { self::assertContains( 'SELECT 1 FROM `' . $table . '` LIMIT 0', $transport->statements ); self::assertSame( 1, $connection->query( 'UPDATE `' . $table . '` SET id=1 WHERE id=1' ) ); }
		$before = count( $transport->statements ); self::assertFalse( $connection->validate_tables( [ 'op_finite_42' ] ) ); self::assertSame( $before, count( $transport->statements ) );
		self::assertFalse( $connection->query( 'UPDATE op_finite_42 SET id=1 WHERE id=1' ) ); self::assertSame( $before, count( $transport->statements ) ); self::assertTrue( $connection->rollback() );
	}

	public function test_promise_ceiling_requires_every_exact_native_table_and_transactional_presence(): void {
		$promise = PromiseStorageSchema::tables( 'op_' ); $base = array_map( static fn( int $id ): string => 'op_finite_' . $id, range( 1, 38 ) );
		foreach ( [ [ ...$base, ...array_slice( $promise, 0, 2 ), 'op_promise_assignments' ], [ ...$base, ...$promise, 'op_finite_42' ], array_map( static fn( int $id ): string => 'op_finite_' . $id, range( 1, 41 ) ) ] as $tables ) {
			$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport ); self::assertTrue( $connection->begin() ); $before = count( $transport->statements ); self::assertFalse( $connection->validate_tables( $tables ) ); self::assertSame( $before, count( $transport->statements ) ); self::assertTrue( $connection->rollback() );
		}
		foreach ( [ 'missing', 'nontransactional' ] as $failure ) {
			$transport = new OperationConnectionTestTransport(); if ( 'missing' === $failure ) { $transport->missing_table = $promise[0]; } else { $transport->engine = 'MyISAM'; }
			$connection = $this->connection( $transport ); self::assertTrue( $connection->begin() ); self::assertFalse( $connection->validate_tables( [ ...$promise, ...$base ] ) ); self::assertFalse( $connection->query( 'UPDATE `' . $promise[0] . '` SET id=1 WHERE id=1' ) ); self::assertTrue( $connection->rollback() );
		}
	}

	public function test_implicit_commit_multi_statement_and_external_sql_refuse_before_dispatch(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		$forbidden = [
			'CREATE TABLE op_new (id int)',
			'ALTER TABLE op_counter ADD value int',
			'TRUNCATE TABLE op_counter',
			'START TRANSACTION',
			'COMMIT',
			'SET autocommit=1',
			'LOCK TABLES op_counter WRITE',
			'UPDATE op_counter SET value=1; COMMIT',
			'UPDATE op_counter, other_counter SET value=1',
			'UPDATE op_counter SET value=hidden_effect()',
			'INSERT INTO op_counter(id) SELECT id FROM other_counter',
			'INSERT INTO op_counter(id) VALUES (1) ON DUPLICATE KEY UPDATE id=1',
			'SELECT hidden_effect()',
			'SELECT other_database.NOW()',
			'SELECT 1 INTO OUTFILE \'/private/file\'',
			'SELECT 1 INTO @shared_session_state',
		];
		foreach ( $forbidden as $sql ) {
			$before = count( array_filter( $transport->statements, static fn( string $statement ): bool => $statement === $sql ) );
			self::assertFalse( $connection->query( $sql ) );
			self::assertSame( $before, count( array_filter( $transport->statements, static fn( string $statement ): bool => $statement === $sql ) ) );
		}
		self::assertTrue( $connection->rollback() );
	}

	public function test_prepared_literal_does_not_become_structural_sql(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		$sql = $connection->prepare( 'UPDATE op_counter SET text=%s WHERE id=%d', [ "private'; COMMIT; --", 1 ] );
		self::assertSame( 1, $connection->query( $sql ) );
		self::assertSame( 11, $connection->insert_id() );
		self::assertTrue( $connection->rollback() );
	}

	public function test_nested_promise_json_at_native_and_packet_limits_dispatches_without_regex_stack_work(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() ); self::assertTrue( $connection->validate_tables( [ 'op_woocommerce_sessions' ] ) );
		$context = \CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture::context()->to_private_json();
		foreach ( [ 12087, 65536 ] as $bytes ) {
			$packet = [ 'format' => 1, 'issue_context_json' => $context, 'original_context_json' => $context, 'customer_note' => "quoted ' and \\\"; COMMIT; -- hidden_effect() # /* private */", 'padding' => '' ];
			$encoded = json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			self::assertLessThan( $bytes, strlen( $encoded ) ); $packet['padding'] = str_repeat( 'x', $bytes - strlen( $encoded ) );
			$json = json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); self::assertSame( $bytes, strlen( $json ) ); self::assertSame( $packet, json_decode( $json, true, 16, JSON_THROW_ON_ERROR ) );
			$sql = $connection->prepare( 'UPDATE op_woocommerce_sessions SET session_value=%s,session_expiry=%d WHERE session_id=%d AND BINARY session_value=BINARY %s', [ $json, 1791540000, 7, $json ] );
			self::assertSame( 1, $connection->query( $sql ) ); self::assertTrue( in_array( $sql, $transport->statements, true ) );
		}
		self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
	}

	public function test_long_literals_keep_escape_parity_doubled_quotes_and_real_sql_boundaries(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport ); self::assertTrue( $connection->begin() );
		$long = str_repeat( "escaped \\\" private' \\ ", 3000 ) . 'Aéroport 📦 日本語';
		$prepared = $connection->prepare( 'SELECT %s AS original', $long . '; COMMIT; -- # /* hidden_effect() INTO OUTFILE */' );
		self::assertIsArray( $connection->get_row( $prepared ) ); self::assertTrue( in_array( $prepared, $transport->statements, true ) );
		foreach ( [ "SELECT '" . str_repeat( "text''; COMMIT; -- ", 3000 ) . "end'", 'SELECT "' . str_repeat( 'text""; COMMIT; -- ', 3000 ) . 'end"', "SELECT '" . str_repeat( '\\', 6000 ) . "'" ] as $sql ) { self::assertIsArray( $connection->get_row( $sql ) ); self::assertTrue( in_array( $sql, $transport->statements, true ) ); }
		$forbidden = [ $prepared . '; COMMIT', $prepared . ' -- hidden', $prepared . ' # hidden', $prepared . ' /* hidden */', $prepared . ',hidden_effect()', $prepared . ' INTO OUTFILE \'/private/file\'', $prepared . ' INTO @private', "SELECT '" . str_repeat( '\\', 6000 ) . "'; COMMIT", "SELECT '" . str_repeat( 'text', 16384 ), "SELECT '" . str_repeat( 'text', 16384 ) . "\\'", "SELECT '" . str_repeat( 'text', 16384 ) . '\\', 'SELECT "' . str_repeat( 'text', 16384 ), "SELECT 'closed' 'unterminated", "SELECT '" . str_repeat( '\\', 6001 ) . "'" ];
		foreach ( $forbidden as $sql ) { $before = count( $transport->statements ); self::assertFalse( $connection->get_row( $sql ) ); self::assertSame( $before, count( $transport->statements ) ); }
		self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
	}

	public function test_only_unqualified_ascii_function_tokens_can_use_the_existing_allowlist(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport ); self::assertTrue( $connection->begin() ); self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		foreach ( [ 'SELECT NOW()', 'SELECT COUNT(*) FROM `op_counter`', 'SELECT `value` FROM `op_counter`', "SHOW TABLE STATUS LIKE 'op_counter'", 'SHOW INDEX FROM `op_counter`', $connection->prepare( 'SELECT %s AS original', 'Aéroport 📦 日本語 $SUM(1) `hidden_effect`(1)' ) ] as $sql ) { self::assertIsArray( $connection->get_row( $sql ) ); }
		foreach ( [ 'SELECT `hidden_effect`(1)', 'SELECT `NOW`()', 'SELECT `hidden``effect`(1)', 'SELECT `hidden effect`(1)', 'SELECT `hidden$effect`(1)', 'SELECT `schema`.`hidden_effect`(1)', 'SELECT `$`.NOW()', 'SELECT $SUM(1)', 'SELECT 0hidden_effect(1)', 'SELECT SUMé(1)', 'SELECT éeffect(1)', 'SELECT private.NOW()', 'SHOW TABLES WHERE hidden_effect(1)', 'SHOW TABLES WHERE `hidden_effect`(1)', 'SHOW TABLES WHERE $SUM(1)', 'SHOW TABLES WHERE private.NOW()', 'UPDATE op_counter SET value=`hidden_effect`(1) WHERE id=1' ] as $sql ) { $before = count( $transport->statements ); self::assertFalse( $connection->query( $sql ) ); self::assertSame( $before, count( $transport->statements ) ); }
		self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
	}

	public function test_fixed_options_unique_index_is_sql_syntax_without_allowing_index_calls(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$sql = $connection->prepare( 'SELECT option_id FROM `op_options` FORCE INDEX (`option_name`) WHERE option_name = %s LIMIT 1 FOR UPDATE', 'cetech_de_checkout_control_v1' );
		self::assertIsArray( $connection->get_row( $sql ) );
		self::assertContains( $sql, $transport->statements );
		foreach ( [ 'SELECT INDEX(1)', 'SELECT * FROM `op_options` FORCE INDEX (`other_index`)', 'SELECT * FROM `op_options` FORCE INDEX (hidden_effect())', 'SELECT hidden_effect() FROM `op_options` FORCE INDEX (`option_name`)', 'SELECT private.INDEX(1)', 'SELECT 1 /* FORCE INDEX (`option_name`) */' ] as $forbidden ) {
			self::assertFalse( $connection->get_row( $forbidden ) );
			self::assertNotContains( $forbidden, $transport->statements );
		}
		self::assertTrue( $connection->rollback() );
		self::assertTrue( $connection->retire() );
	}

	public function test_fixed_quote_range_index_is_syntax_but_unknown_index_functions_refuse(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$sql = $connection->prepare( 'SELECT id FROM `op_rate_cards` FORCE INDEX (`quote_candidate_range`) WHERE delivery_offer_id = %d AND destination_zone_id = %d AND base_currency = %s ORDER BY id LIMIT 1001 FOR UPDATE', [ 20, 50, 'GHS' ] );
		self::assertIsArray( $connection->get_row( $sql ) );
		self::assertContains( $sql, $transport->statements );
		foreach ( [ 'SELECT INDEX(1)', 'SELECT * FROM `op_rate_cards` FORCE INDEX (`other_range`)', 'SELECT * FROM `op_rate_cards` FORCE INDEX (hidden_effect())', 'SELECT hidden_effect() FROM `op_rate_cards` FORCE INDEX (`quote_candidate_range`)', 'SELECT private.INDEX(1)', 'SELECT 1 /* FORCE INDEX (`quote_candidate_range`) */' ] as $forbidden ) {
			self::assertFalse( $connection->get_row( $forbidden ) );
			self::assertNotContains( $forbidden, $transport->statements );
		}
		self::assertTrue( $connection->rollback() );
		self::assertTrue( $connection->retire() );
	}

	public function test_native_receipt_text_probe_is_bounded_sql_without_admitting_unknown_functions(): void {
		$transport = new OperationConnectionTestTransport(); $connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$sql = $connection->prepare( 'SELECT session_id,session_key,LEFT(session_value,16385) AS session_value,session_expiry FROM `op_woocommerce_sessions` WHERE session_key = %s ORDER BY session_id LIMIT 2 FOR UPDATE', [ 'private_native_session' ] );
		self::assertIsArray( $connection->get_row( $sql ) ); self::assertContains( $sql, $transport->statements );
		foreach ( [ 'SELECT LEFT(hidden_effect(),16385)', 'SELECT private.LEFT(session_value,16385)', 'SELECT LEFT(session_value,16385),hidden_effect() FROM `op_woocommerce_sessions`' ] as $forbidden ) {
			self::assertFalse( $connection->get_row( $forbidden ) ); self::assertNotContains( $forbidden, $transport->statements );
		}
		self::assertTrue( $connection->rollback() ); self::assertTrue( $connection->retire() );
	}

	public function test_pinned_server_identity_change_refuses_without_sending_a_write(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		$transport->server_id = 2;
		$sql = 'UPDATE op_counter SET value=1 WHERE id=1';
		self::assertFalse( $connection->query( $sql ) );
		self::assertNotContains( $sql, $transport->statements );
		self::assertTrue( $connection->is_retired() );
		self::assertFalse( $connection->begin() );
	}

	public function test_intermediate_disconnect_is_never_reconnected_or_reissued(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		$transport->disconnect_update = true;
		$sql = 'UPDATE op_counter SET value=1 WHERE id=1';
		self::assertFalse( $connection->query( $sql ) );
		self::assertSame( 2013, $connection->errno() );
		self::assertTrue( $connection->is_retired() );
		self::assertFalse( $connection->query( $sql ) );
		self::assertFalse( $connection->get_row( 'SELECT 1' ) );
		self::assertFalse( $connection->rollback() );
		self::assertSame( 1, count( array_filter( $transport->statements, static fn( string $statement ): bool => $statement === $sql ) ) );
		$this->expectException( \RuntimeException::class );
		$connection->prepare( 'SELECT %s', 'private' );
	}

	public function test_deadlock_ended_transaction_cannot_turn_later_write_into_autocommit(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertTrue( $connection->validate_tables( [ 'op_counter' ] ) );
		$transport->transaction = false;
		$sql = 'UPDATE op_counter SET value=2 WHERE id=1';
		self::assertFalse( $connection->query( $sql ) );
		self::assertNotContains( $sql, $transport->statements );
		self::assertTrue( $connection->is_retired() );
	}

	public function test_only_positive_pre_dispatch_proof_reports_commit_not_sent(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$transport->unsent_commit = true;
		self::assertSame( OperationCommitResult::NotSent, $connection->commit() );
		self::assertFalse( $connection->is_retired() );
		self::assertTrue( $connection->rollback() );
		self::assertFalse( $transport->transaction );
	}

	public function test_dispatched_commit_error_is_unconfirmed_not_unsent(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$transport->failed_commit = true;
		self::assertSame( OperationCommitResult::Unconfirmed, $connection->commit() );
		self::assertTrue( $connection->is_retired() );
		self::assertFalse( $connection->rollback() );
	}

	public function test_lost_commit_ack_retires_original_handle_and_retains_uncertainty(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$transport->lost_commit_ack = true;
		self::assertSame( OperationCommitResult::Unconfirmed, $connection->commit() );
		self::assertFalse( $transport->transaction );
		self::assertTrue( $connection->is_retired() );
		self::assertFalse( $connection->begin() );
		self::assertSame( 1, count( array_filter( $transport->statements, static fn( string $sql ): bool => 'COMMIT' === $sql ) ) );
	}

	public function test_failed_rollback_and_failed_retirement_never_become_confirmed(): void {
		$transport = new OperationConnectionTestTransport();
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		$transport->failed_rollback = true;
		$transport->failed_close = true;
		self::assertFalse( $connection->rollback() );
		self::assertTrue( $connection->is_retired() );
		self::assertFalse( $connection->retire() );
		self::assertFalse( $connection->retire() );
	}

	public function test_unsupported_transaction_introspection_refuses_only_new_session(): void {
		$transport = new OperationConnectionTestTransport();
		$transport->unsupported_state = true;
		$connection = $this->connection( $transport );
		self::assertFalse( $connection->begin() );
		self::assertTrue( $connection->is_retired() );
		self::assertNotContains( 'START TRANSACTION', $transport->statements );
	}

	private function connection( OperationConnectionTestTransport $transport ): OperationConnection {
		return new OperationConnection( 4, 'op_', $transport, 'utf8mb4', 'utf8mb4_unicode_ci' );
	}
}

/** Synthetic transport tests ownership; it is never used by real SQL proofs. */
final class OperationConnectionTestTransport implements OperationConnectionTransport {

	public int $server_id = 1;
	public bool $transaction = false;
	public bool $closed = false;
	public bool $unsupported_state = false;
	public bool $disconnect_update = false;
	public bool $unsent_commit = false;
	public bool $failed_commit = false;
	public bool $lost_commit_ack = false;
	public bool $failed_rollback = false;
	public bool $failed_close = false;
	public string $engine = 'InnoDB';
	public ?string $missing_table = null;
	/** @var list<string> */
	public array $statements = [];

	public function execute( string $sql ): OperationConnectionResult {
		$this->statements[] = $sql;
		if ( null !== $this->missing_table && 'SELECT 1 FROM `' . $this->missing_table . '` LIMIT 0' === $sql ) { return new OperationConnectionResult( false, true, errno: 1146 ); }
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction = true;
		}
		if ( 'COMMIT' === $sql ) {
			if ( $this->unsent_commit ) {
				return new OperationConnectionResult( false, false );
			}
			if ( $this->failed_commit ) {
				return new OperationConnectionResult( false, true, errno: 1064 );
			}
			$this->transaction = false;
			if ( $this->lost_commit_ack ) {
				return new OperationConnectionResult( false, true );
			}
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( $this->failed_rollback ) {
				return new OperationConnectionResult( false, true );
			}
			$this->transaction = false;
		}
		if ( str_starts_with( $sql, 'UPDATE' ) && $this->disconnect_update ) {
			$this->server_id = 0;
			return new OperationConnectionResult( false, true, errno: 2013 );
		}
		if ( str_contains( $sql, 'information_schema.TABLES' ) ) {
			return new OperationConnectionResult( true, true, [ [ 'engine' => $this->engine ] ] );
		}
		return new OperationConnectionResult( true, true, [ [ 'value' => '1' ] ], 1, 11 );
	}

	public function connection_id(): int {
		return $this->closed ? 0 : $this->server_id;
	}

	public function transaction_state(): ?array {
		return $this->closed || $this->unsupported_state ? null : [ 'connection_id' => $this->server_id, 'in_transaction' => $this->transaction, 'autocommit' => true ];
	}

	public function escape( string $value ): string {
		return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
	}

	public function close(): bool {
		$this->closed = true;
		return ! $this->failed_close;
	}
}
