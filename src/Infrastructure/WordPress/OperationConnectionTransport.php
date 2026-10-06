<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

/**
 * One dedicated physical connection. Implementations must never reconnect or
 * replay a statement. Replacement belongs to the factory, outside an owner unit.
 */
interface OperationConnectionTransport {

	public function execute( string $sql ): OperationConnectionResult;

	public function connection_id(): int;

	/** @return array{connection_id:int,in_transaction:bool,autocommit:bool}|null */
	public function transaction_state(): ?array;

	public function escape( string $value ): string;

	public function close(): bool;
}
