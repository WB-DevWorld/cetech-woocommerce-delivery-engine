<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

/**
 * Version metadata for configuration scopes.
 */
final class ConfigurationVersionInfo {

	public function __construct(
		public readonly int $scope_row_id,
		public readonly string $scope_type,
		public readonly int $scope_id,
		public readonly string $slice_key,
		public readonly int $config_version
	) {
	}
}
