<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Validated write intent for one scoped configuration save.
 */
final class ScopedConfigurationWriteCommand {

	/**
	 * @param array<string, mixed> $raw_fields field_key => submitted field payload
	 */
	public function __construct(
		public readonly ConfigurationScopeType $scope_type,
		public readonly int $scope_id,
		public readonly string $slice_key,
		public readonly ?int $parent_product_id,
		public readonly array $raw_fields,
		public readonly bool $create_slice_if_missing = false
	) {
	}
}
