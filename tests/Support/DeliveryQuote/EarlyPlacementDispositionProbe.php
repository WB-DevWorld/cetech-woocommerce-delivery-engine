<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};

/** Faults the actual read-unit transport without replacing its owner or synthetic receipt facts. */
final class EarlyPlacementDispositionProbe implements OperationConnectionFactory {
	public int $opens = 0;
	public function __construct( private QuoteDurableFixtureFactory $factory, private string $fault ) {}
	public function open(): OperationSession {
		++$this->opens;
		return new class( $this->factory->open(), $this->fault ) implements OperationSession {
			public function __construct( private OperationSession $inner, private string $fault ) {}
			public function site_id(): int { return $this->inner->site_id(); }
			public function table_prefix(): string { return $this->inner->table_prefix(); }
			public function charset_collate(): string { return $this->inner->charset_collate(); }
			public function begin(): bool { return $this->inner->begin(); }
			public function commit(): OperationCommitResult { throw new \LogicException( 'Disposition cannot commit.' ); }
			public function rollback(): bool { return $this->inner->rollback(); }
			public function retire(): bool { $ok = $this->inner->retire(); return 'retire_ack' !== $this->fault && $ok; }
			public function is_retired(): bool { return $this->inner->is_retired(); }
			public function in_transaction(): bool { return $this->inner->in_transaction(); }
			public function validate_tables( array $tables ): bool { return $this->inner->validate_tables( $tables ); }
			public function query( string $sql ): int|false { throw new \LogicException( 'Disposition cannot mutate.' ); }
			public function get_row( string $sql ): array|null|false {
				if ( 'native_read' === $this->fault && str_contains( $sql, 'durable_original_native' ) ) { $this->inner->retire(); return false; }
				return $this->inner->get_row( $sql );
			}
			public function get_results( string $sql ): array|false {
				if ( 'receipt_read' === $this->fault && str_contains( $sql, 'operation_records' ) ) { $this->inner->retire(); return false; }
				return $this->inner->get_results( $sql );
			}
			public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); }
			public function errno(): int { return $this->inner->errno(); }
			public function insert_id(): int { return $this->inner->insert_id(); }
		};
	}
}
