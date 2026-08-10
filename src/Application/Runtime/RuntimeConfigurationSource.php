<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Internal/admin diagnostic identifier for which configuration source served runtime resolution.
 *
 * Never expose to customers.
 */
final class RuntimeConfigurationSource {

	public const LEGACY = 'legacy';

	public const ECR = 'ecr';

	public const LEGACY_CATEGORY_COMPATIBILITY = 'legacy_category_compatibility';

	private function __construct() {
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return [
			self::LEGACY,
			self::ECR,
			self::LEGACY_CATEGORY_COMPATIBILITY,
		];
	}
}
