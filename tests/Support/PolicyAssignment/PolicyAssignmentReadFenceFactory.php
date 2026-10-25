<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\PolicyAssignment;

use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};

/** Finite owner-ack faults around the real sequential PDO model; no physical SQL claim. */
final class PolicyAssignmentReadFenceFactory implements OperationConnectionFactory {
	public int $rollback_calls = 0;
	public int $retire_calls = 0;
	public ?OperationSession $inner = null;
	public function __construct( private OperationConnectionFactory $factory, public readonly string $fault ) {}
	public function open(): OperationSession { $this->inner = $this->factory->open(); return new PolicyAssignmentReadFenceSession( $this->inner, $this ); }
}

final class PolicyAssignmentReadFenceSession implements OperationSession {
	private bool $begun = false;
	public function __construct( private OperationSession $inner, private PolicyAssignmentReadFenceFactory $factory ) {}
	public function site_id(): int { if ( 'late_site_throw' === $this->factory->fault && $this->factory->retire_calls > 0 ) { throw new \RuntimeException( 'Owned test metadata refused.' ); } return $this->inner->site_id(); }
	public function table_prefix(): string { return 'prefix_drift' === $this->factory->fault && $this->begun ? 'foreign_owned_route_' : $this->inner->table_prefix(); }
	public function charset_collate(): string { return $this->inner->charset_collate(); }
	public function begin(): bool { $result = $this->inner->begin(); $this->begun = $result; return $result; }
	public function commit(): OperationCommitResult { return $this->inner->commit(); }
	public function rollback(): bool { ++$this->factory->rollback_calls; if ( 'rollback_throw' === $this->factory->fault ) { throw new \RuntimeException( 'Owned test rollback acknowledgement lost.' ); } if ( 'rollback_false' === $this->factory->fault ) { return false; } return $this->inner->rollback(); }
	public function retire(): bool { ++$this->factory->retire_calls; $result = $this->inner->retire(); if ( 'retire_throw' === $this->factory->fault ) { throw new \RuntimeException( 'Owned test retirement acknowledgement lost.' ); } return 'retire_false' === $this->factory->fault ? false : $result; }
	public function is_retired(): bool { return $this->inner->is_retired(); }
	public function in_transaction(): bool { if ( 'metadata_throws_after_begin' === $this->factory->fault && $this->begun ) { throw new \RuntimeException( 'Owned test transaction metadata unavailable.' ); } return $this->inner->in_transaction(); }
	public function validate_tables( array $names ): bool { return $this->inner->validate_tables( $names ); }
	public function query( string $sql ): int|false { return $this->inner->query( $sql ); }
	public function get_row( string $sql ): array|null|false { return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->inner->errno(); }
	public function insert_id(): int { return $this->inner->insert_id(); }
}
