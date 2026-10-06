<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
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

	public function test_nontransactional_participant_refuses(): void {
		$transport = new OperationConnectionTestTransport();
		$transport->engine = 'MyISAM';
		$connection = $this->connection( $transport );
		self::assertTrue( $connection->begin() );
		self::assertFalse( $connection->validate_tables( [ 'op_counter' ] ) );
		self::assertFalse( $connection->query( 'UPDATE op_counter SET value=1 WHERE id=1' ) );
		self::assertTrue( $connection->rollback() );
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
	/** @var list<string> */
	public array $statements = [];

	public function execute( string $sql ): OperationConnectionResult {
		$this->statements[] = $sql;
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
