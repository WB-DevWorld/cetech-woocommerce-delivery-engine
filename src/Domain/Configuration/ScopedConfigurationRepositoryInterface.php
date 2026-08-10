<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Persistence API for scoped configuration storage (no effective resolution).
 */
interface ScopedConfigurationRepositoryInterface {

	public function getGlobalConfiguration(): ?ScopedConfiguration;

	public function ensureGlobalScope(): ScopedConfiguration;

	/**
	 * @return list<ScopedConfiguration>
	 */
	public function findByScope( ConfigurationScopeType $scope_type, int $scope_id ): array;

	public function findByScopeAndSlice( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?ScopedConfiguration;

	public function findByLegacyRuleId( int $legacy_rule_id ): ?ScopedConfiguration;

	/**
	 * @return list<ScopedConfiguration>
	 */
	public function findByParentProductId( int $parent_product_id ): array;

	/**
	 * Persist a full scope instruction set.
	 *
	 * Identical semantic content does not increment config_version.
	 */
	public function saveScopedConfiguration( ScopedConfiguration $configuration ): ScopedConfiguration;

	/**
	 * Remove all field/collection instructions for a non-global scope and delete the scope row.
	 */
	public function deleteScope( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool;

	public function getVersionInfo( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): ?ConfigurationVersionInfo;

	public function getGlobalVersion(): int;
}
