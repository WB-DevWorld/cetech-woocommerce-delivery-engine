<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationFieldValueType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Storage-contract definition for one configuration field.
 *
 * This is not an effective-resolution catalog.
 */
final class ConfigurationFieldDefinition {

	/**
	 * @param list<ScalarConfigurationMode>|list<CollectionConfigurationMode> $allowed_modes
	 * @param callable(mixed): mixed|null                                      $normalizer
	 * @param callable(mixed): void|null                                       $validator
	 */
	public function __construct(
		public readonly string $key,
		public readonly bool $is_collection,
		public readonly ConfigurationFieldValueType $value_type,
		public readonly array $allowed_modes,
		public readonly bool $allows_disable,
		public readonly bool $preserve_order,
		public readonly bool $dedupe_members,
		public readonly mixed $normalizer = null,
		public readonly mixed $validator = null,
		public readonly bool $is_optional = false
	) {
	}

	public function allows_mode( string $mode ): bool {
		foreach ( $this->allowed_modes as $allowed ) {
			if ( $allowed->value === $mode ) {
				return true;
			}
		}

		return false;
	}
}
