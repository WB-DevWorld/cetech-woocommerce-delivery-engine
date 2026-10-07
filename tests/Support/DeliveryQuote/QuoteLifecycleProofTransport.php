<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/** Finite test-only barriers/fault facts around actual native SQL, never a fake database. */
final class QuoteLifecycleProofTransport implements OperationConnectionTransport {
	public array $sql = [];
	public int $commits = 0;
	public int $sent_commits = 0;
	public int $quote_writes = 0;
	public int $budget_writes = 0;
	public int $record_writes = 0;
	public int $audit_appends = 0;
	public int $lock_timeouts = 0;
	public int $deadlocks = 0;
	public ?string $deadlock_trace = null;
	public ?int $fault_commit = null;
	public string $commit_fault = '';
	public bool $reject_audit = false;
	public bool $reject_completion = false;
	public bool $reject_quote = false;
	public bool $miss_quote_cas = false;
	public bool $reject_rollback = false;
	public bool $reject_close = false;
	public ?\Closure $before = null;
	public ?\Closure $after = null;
	private bool $failed = false;
	private bool $closed = false;
	public function __construct( private OperationConnectionTransport $native ) {}
	public function native_execute( string $sql ): OperationConnectionResult { return $this->native->execute( $sql ); }
	public function execute( string $sql ): OperationConnectionResult {
		$this->sql[] = $sql;
		if ( null !== $this->before ) { ( $this->before )( $sql, $this ); }
		$write = 1 === preg_match( '/\A\s*(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\b/i', $sql );
		$quote = $write && str_contains( $sql, 'delivery_engine_delivery_quotes' );
		$budget = $write && str_contains( $sql, 'delivery_engine_delivery_quote_budget_windows' );
		$record = $write && str_contains( $sql, 'delivery_engine_operation_records' );
		$audit = 1 === preg_match( '/\A\s*INSERT\s+INTO\b/i', $sql ) && str_contains( $sql, 'delivery_engine_operation_changes' );
		$completion = $record && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'completion_json =' );
		if ( $quote && $this->miss_quote_cas && str_starts_with( $sql, 'UPDATE ' ) ) { $this->failed = true; return new OperationConnectionResult( true, false, affected_rows: 0 ); }
		if ( $quote && $this->reject_quote || $audit && $this->reject_audit || $completion && $this->reject_completion
			|| $this->failed && $this->reject_rollback && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) {
			$this->failed = true; return new OperationConnectionResult( false, false, errno: 45000 );
		}
		if ( 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			++$this->commits;
			if ( $this->fault_commit === $this->commits && 'unsent' === $this->commit_fault ) { $this->failed = true; return new OperationConnectionResult( false, false, errno: 45000 ); }
			++$this->sent_commits; $result = $this->native->execute( $sql );
			if ( $this->fault_commit === $this->commits && 'lost_ack' === $this->commit_fault && $result->acknowledged ) { $this->failed = true; $result = new OperationConnectionResult( false, true, errno: 2013 ); }
		} else { $result = $this->native->execute( $sql ); }
		if ( $result->acknowledged ) { $this->quote_writes += $quote ? 1 : 0; $this->budget_writes += $budget ? 1 : 0; $this->record_writes += $record ? 1 : 0; $this->audit_appends += $audit ? 1 : 0; }
		if ( 1205 === $result->errno ) { ++$this->lock_timeouts; } if ( 1213 === $result->errno ) { ++$this->deadlocks; $trace=$this->native->execute('SHOW ENGINE INNODB STATUS');$this->deadlock_trace=$trace->rows[0]['Status']??null; }
		if ( null !== $this->after ) { ( $this->after )( $sql, $this, $result ); }
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { if ( $this->reject_close ) { return false; } $this->closed = $this->native->close(); return $this->closed; }
	public function force_close(): void { if ( ! $this->closed ) { $this->native->close(); $this->closed = true; } }
}
