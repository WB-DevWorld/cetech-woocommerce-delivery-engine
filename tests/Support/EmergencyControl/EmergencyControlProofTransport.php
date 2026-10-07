<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\EmergencyControl;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/** Real native transport with bounded test-only barriers and explicit fault facts. */
final class EmergencyControlProofTransport implements OperationConnectionTransport {
	public array $sql = [];
	public int $commits = 0;
	public int $sent_commits = 0;
	public int $state_writes = 0;
	public int $audit_appends = 0;
	public int $lock_timeouts = 0;
	public int $deadlocks = 0;
	public ?int $fault_commit = null;
	public string $commit_fault = '';
	public bool $reject_audit = false;
	public bool $reject_state = false;
	public bool $reject_rollback = false;
	public bool $reject_close = false;
	public ?\Closure $before = null;
	public ?\Closure $after = null;
	private bool $closed = false;
	public function __construct( private OperationConnectionTransport $native ) {}
	public function native_execute( string $sql ): OperationConnectionResult { return $this->native->execute( $sql ); }
	public function execute( string $sql ): OperationConnectionResult {
		$this->sql[] = $sql;
		if ( null !== $this->before ) { ( $this->before )( $sql, $this ); }
		$state = 1 === preg_match( '/\A\s*(?:UPDATE|INSERT\s+INTO)\s+`?[a-z0-9_]*options`?/i', $sql ) && str_contains( $sql, 'cetech_de_checkout_control_v1' );
		$audit = 1 === preg_match( '/\A\s*INSERT\s+INTO\s+`?[a-z0-9_]*delivery_engine_operation_changes`?/i', $sql );
		if ( $state && $this->reject_state || $audit && $this->reject_audit || $this->reject_rollback && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) { return new OperationConnectionResult( false, false, errno: 45000 ); }
		if ( 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			++$this->commits;
			if ( $this->fault_commit === $this->commits && 'unsent' === $this->commit_fault ) { return new OperationConnectionResult( false, false, errno: 45000 ); }
			++$this->sent_commits; $result = $this->native->execute( $sql );
			if ( $this->fault_commit === $this->commits && 'lost_ack' === $this->commit_fault && $result->acknowledged ) { $result = new OperationConnectionResult( false, true, errno: 2013 ); }
		} else { $result = $this->native->execute( $sql ); }
		if ( $result->acknowledged && $state ) { ++$this->state_writes; }
		if ( $result->acknowledged && $audit ) { ++$this->audit_appends; }
		if ( 1205 === $result->errno ) { ++$this->lock_timeouts; }
		if ( 1213 === $result->errno ) { ++$this->deadlocks; }
		if ( null !== $this->after ) { ( $this->after )( $sql, $this, $result ); }
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { if ( $this->reject_close ) { return false; } $this->closed = $this->native->close(); return $this->closed; }
	public function force_close(): void { if ( ! $this->closed ) { $this->native->close(); $this->closed = true; } }
}
