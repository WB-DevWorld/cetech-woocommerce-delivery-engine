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

			if ( self::DEFAULT_SLICE_KEY !== $this->slice_key && ! self::is_valid_profile_slice_key( $this->slice_key ) ) {
				throw new InvalidConfigurationException( 'Global scope slice_key must be empty or a fulfilment profile key.' );
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

	public static function profileDefault( string $profile_key, int $config_version = 1, ?int $id = null ): self {
		return new self(
			$id,
			ConfigurationScopeType::Global,
			self::GLOBAL_SCOPE_ID,
			$profile_key,
			null,
			RecordStatus::Active,
			$config_version,
			ConfigurationSource::Native,
			null
		);
	}

	public function is_profile_default(): bool {
		return ConfigurationScopeType::Global === $this->scope_type
			&& self::DEFAULT_SLICE_KEY !== $this->slice_key;
	}

	private static function is_valid_profile_slice_key( string $slice_key ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_\-]{0,63}$/', $slice_key );
	}
}
