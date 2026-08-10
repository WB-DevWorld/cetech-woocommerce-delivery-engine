<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Audit sink for scoped configuration admin changes.
 */
interface ConfigurationChangeAuditorInterface {

	/**
	 * @param array<string, mixed>|null $previous
	 * @param array<string, mixed>|null $new
	 */
	public function log(
		string $action,
		string $entity_type,
		int $entity_id,
		?array $previous = null,
		?array $new = null
	): bool;
}
