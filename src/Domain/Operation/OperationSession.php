<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** Dedicated authoritative owner. Queries never reconnect or retry implicitly. */
interface OperationSession {
	public function site_id(): int;
	public function table_prefix(): string;
	public function charset_collate(): string;
	public function begin(): bool;
	public function commit(): OperationCommitResult;
	public function rollback(): bool;
	public function retire(): bool;
	public function is_retired(): bool;
	public function in_transaction(): bool;
	public function validate_tables( array $table_names ): bool;
	public function query( string $sql ): int|false;
	public function get_row( string $sql ): array|null|false;
	public function get_results( string $sql ): array|false;
	public function prepare( string $sql, mixed ...$args ): string;
	public function errno(): int;
	public function insert_id(): int;
}
