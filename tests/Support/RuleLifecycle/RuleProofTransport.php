<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;

/** Test-only faults around actual native DML/COMMIT; never retries a statement. */
final class RuleProofTransport implements OperationConnectionTransport {
	public int $commits = 0;
	public int $sent_commits = 0;
	public int $deadlocks = 0;
	public int $lock_wait_timeouts = 0;
	public ?int $fault_commit = null;
	public string $commit_fault = '';
	public bool $reject_audit = false;
	public bool $reject_completion = false;
	public bool $reject_rollback = false;
	public bool $reject_close = false;
	public ?int $pause_commit = null;
	public ?string $commit_ready = null;
	public ?string $commit_release = null;
	public ?\Closure $before_guard_lock = null;
	public ?\Closure $before_record_lock = null;
	private bool $guard_observed = false;
	private bool $record_observed = false;
	private bool $statement_rejected = false;

	public function __construct( private OperationConnectionTransport $native ) {}
	public function execute( string $sql ): OperationConnectionResult {
		if ( ! $this->record_observed && null !== $this->before_record_lock && 1 === preg_match( '/\A\s*SELECT\b.*\bFROM\s+`?[a-z0-9_]*delivery_engine_operation_records`?.*\bFOR\s+UPDATE\b/is', $sql ) ) {
			$this->record_observed = true;
			( $this->before_record_lock )( $this->native );
		}
		if ( ! $this->guard_observed && null !== $this->before_guard_lock && 1 === preg_match( '/\A\s*SELECT\b.*\bFROM\s+`?[a-z0-9_]*delivery_engine_rule_family_guards`?.*\bFOR\s+UPDATE\b/is', $sql ) ) {
			$this->guard_observed = true;
			( $this->before_guard_lock )( $this->native );
		}
		if ( 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			++$this->commits;
			if ( $this->fault_commit === $this->commits && 'unsent' === $this->commit_fault ) { return new OperationConnectionResult( false, false, errno: 45000 ); }
			++$this->sent_commits; $result = $this->native->execute( $sql );
			if ( $this->pause_commit === $this->commits && $result->acknowledged ) { OperationProofBarrier::pause( (string) $this->commit_ready, (string) $this->commit_release ); }
			if ( $this->fault_commit === $this->commits && 'lost_ack' === $this->commit_fault && $result->acknowledged ) { return new OperationConnectionResult( false, true, errno: 2013 ); }
			return $result;
		}
		if ( $this->reject_rollback && $this->statement_rejected && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) { return new OperationConnectionResult( false, false, errno: 2006 ); }
		if ( ( $this->reject_audit && 1 === preg_match( '/\A\s*INSERT\s+INTO\s+`?[a-z0-9_]*delivery_engine_operation_changes`?/i', $sql ) )
			|| ( $this->reject_completion && 1 === preg_match( '/\A\s*UPDATE\s+`?[a-z0-9_]*delivery_engine_operation_records`?\s+SET\s+state\s*=/i', $sql ) ) ) {
			$this->statement_rejected = true;
			return new OperationConnectionResult( false, false, errno: 45000 );
		}
		$result = $this->native->execute( $sql );
		if ( 1213 === $result->errno ) { ++$this->deadlocks; }
		if ( 1205 === $result->errno ) { ++$this->lock_wait_timeouts; }
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { return ! $this->reject_close && $this->native->close(); }
	public function force_close(): void { $this->native->close(); }
}
