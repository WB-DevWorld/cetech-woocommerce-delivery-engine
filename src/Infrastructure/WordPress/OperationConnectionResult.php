<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

/** Private transport facts. Never contains SQL or server/exception text. */
final readonly class OperationConnectionResult {

	/** @param list<array<string, mixed>> $rows */
	public function __construct(
		public bool $acknowledged,
		public bool $sent,
		public array $rows = [],
		public int $affected_rows = 0,
		public int $insert_id = 0,
		public int $errno = 0
	) {
	}
}
