<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/**
 * Explicit fault seam around a real native transport. Lost-ack mode sends the
 * actual COMMIT first; unsent mode does not send it. No statement is retried.
 */
final class OperationProofTransport implements OperationConnectionTransport {
	public int $commits = 0;
	public int $sent_commits = 0;
	public int $calls = 0;
	public int $close_calls = 0;
	public int $lock_wait_timeouts = 0;
	public int $deadlocks = 0;
	public ?int $fault_commit = null;
	public string $commit_fault = '';
	public bool $reject_audit = false;
	public bool $disconnect_after_mutation = false;
	public bool $reject_rollback = false;
	public bool $reject_close = false;
	private bool $audit_rejected = false;

	public function __construct( private OperationConnectionTransport $native ) {}
	public function execute( string $sql ): OperationConnectionResult {
		++$this->calls;
		if ( 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			++$this->commits;
			if ( $this->fault_commit === $this->commits && 'unsent' === $this->commit_fault ) {
				return new OperationConnectionResult( false, false, errno: 45000 );
			}
			++$this->sent_commits;
			$result = $this->native->execute( $sql );
			if ( $this->fault_commit === $this->commits && 'lost_ack' === $this->commit_fault && $result->acknowledged ) {
				return new OperationConnectionResult( false, true, errno: 2013 );
			}
			return $result;
		}
		if ( $this->reject_rollback && $this->audit_rejected && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) {
			return new OperationConnectionResult( false, false, errno: 2006 );
		}
		if ( $this->reject_audit && 1 === preg_match( '/\A\s*INSERT\s+INTO\s+`?[a-z0-9_]*delivery_engine_operation_changes`?/i', $sql ) ) {
			$this->audit_rejected = true;
			return new OperationConnectionResult( false, false, errno: 45000 );
		}
		$result = $this->native->execute( $sql );
		if ( 1205 === $result->errno ) { ++$this->lock_wait_timeouts; }
		if ( 1213 === $result->errno ) { ++$this->deadlocks; }
		if ( $this->disconnect_after_mutation && 1 === preg_match( '/\A\s*UPDATE\s+`?[a-z0-9_]*operation_fixture_counter`?/i', $sql ) && $result->acknowledged ) {
			$this->disconnect_after_mutation = false;
			$this->native->close();
			return new OperationConnectionResult( false, true, errno: 2013 );
		}
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { ++$this->close_calls; return ! $this->reject_close && $this->native->close(); }
	public function force_close(): void { $this->native->close(); }
}
