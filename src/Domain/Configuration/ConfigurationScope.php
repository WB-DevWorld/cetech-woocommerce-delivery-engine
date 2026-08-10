<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

/**
 * Identity and metadata for one configuration scope row.
 */
final class ConfigurationScope {

	public const GLOBAL_SCOPE_ID = 0;
	public const DEFAULT_SLICE_KEY = '';

	public function __construct(
		public readonly ?int $id,
		public readonly ConfigurationScopeType $scope_type,
		public readonly int $scope_id,
		public readonly string $slice_key,
		public readonly ?int $parent_product_id,
		public readonly RecordStatus $status,
		public readonly int $config_version,
		public readonly ConfigurationSource $source,
		public readonly ?int $legacy_rule_id,
		public readonly ?string $created_at = null,
		public readonly ?string $updated_at = null
	) {
		if ( $this->config_version < 1 ) {
			throw new InvalidConfigurationException( 'Configuration version must be >= 1.' );
		}

		if ( ConfigurationScopeType::Global === $this->scope_type ) {
			if ( self::GLOBAL_SCOPE_ID !== $this->scope_id ) {
				throw new InvalidConfigurationException( 'Global scope_id must be 0.' );
			}

			if ( self::DEFAULT_SLICE_KEY !== $this->slice_key ) {
				throw new InvalidConfigurationException( 'Global scope must use the default slice key.' );
			}

			if ( null !== $this->parent_product_id ) {
				throw new InvalidConfigurationException( 'Global scope cannot have a parent product id.' );
			}
		}

		if ( ConfigurationScopeType::Product === $this->scope_type && $this->scope_id <= 0 ) {
			throw new InvalidConfigurationException( 'Product scope_id must be a positive integer.' );
		}

		if ( ConfigurationScopeType::Variation === $this->scope_type ) {
			if ( $this->scope_id <= 0 ) {
				throw new InvalidConfigurationException( 'Variation scope_id must be a positive integer.' );
			}

			if ( null === $this->parent_product_id || $this->parent_product_id <= 0 ) {
				throw new InvalidConfigurationException( 'Variation scope requires a positive parent_product_id.' );
			}
		}
	}

	public static function global( int $config_version = 1, ?int $id = null ): self {
		return new self(
			$id,
			ConfigurationScopeType::Global,
			self::GLOBAL_SCOPE_ID,
			self::DEFAULT_SLICE_KEY,
			null,
			RecordStatus::Active,
			$config_version,
			ConfigurationSource::Native,
			null
		);
	}
}
