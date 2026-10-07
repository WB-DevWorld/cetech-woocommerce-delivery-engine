<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DataLifecycle;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/** Actual SQL transport with explicit disposable fault/barrier seams; never retries. */
final class DataLifecycleProofTransport implements OperationConnectionTransport {
	public int $commits = 0;
	public int $sent_commits = 0;
	public int $deletes = 0;
	public int $checkpoints = 0;
	public bool $reject_delete = false;
	public bool $reject_checkpoint = false;
	public bool $reject_rollback = false;
	public bool $reject_close = false;
	public string $commit_fault = '';
	public array $sql = [];
	public ?\Closure $before = null;
	public ?\Closure $after = null;
	public function __construct( private OperationConnectionTransport $native ) {}
	public function execute( string $sql ): OperationConnectionResult {
		$this->sql[] = $sql;
		if ( null !== $this->before ) { ( $this->before )( $sql, $this ); }
		$delete = 1 === preg_match( '/\A\s*DELETE\s+FROM\s+`?[a-z0-9_]*options`?/i', $sql );
		$checkpoint = 1 === preg_match( '/\A\s*(?:UPDATE|INSERT\s+INTO)\s+`?[a-z0-9_]*options`?/i', $sql ) && str_contains( $sql, 'cetech_de_gc_state_geo_v1' );
		if ( ( $delete && $this->reject_delete ) || ( $checkpoint && $this->reject_checkpoint ) || ( $this->reject_rollback && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) ) { return new OperationConnectionResult( false, false, errno: 45000 ); }
		if ( 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			++$this->commits;
			if ( 'unsent' === $this->commit_fault ) { $this->commit_fault = ''; return new OperationConnectionResult( false, false, errno: 45000 ); }
			++$this->sent_commits;
			$result = $this->native->execute( $sql );
			if ( 'lost_ack' === $this->commit_fault && $result->acknowledged ) { $this->commit_fault = ''; return new OperationConnectionResult( false, true, errno: 2013 ); }
		} else { $result = $this->native->execute( $sql ); }
		if ( $result->acknowledged && $delete ) { ++$this->deletes; }
		if ( $result->acknowledged && $checkpoint ) { ++$this->checkpoints; }
		if ( null !== $this->after ) { ( $this->after )( $sql, $this, $result ); }
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { return ! $this->reject_close && $this->native->close(); }
	public function force_close(): void { $this->native->close(); }
}
