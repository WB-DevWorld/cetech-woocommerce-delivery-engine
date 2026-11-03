<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationVersionInfo;
use CetechDeliveryEngine\Domain\Configuration\ScopePresenceQueryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Read-through view of scoped configuration for one catalog scan batch.
 *
 * Within a batch it remembers the global and profile scopes (otherwise reloaded
 * for every product) and answers "no product scope" from one batched presence
 * query. Results are the stored rows unchanged; reset() ends the batch so no
 * memo outlives it. Writes go straight to the inner repository and reset.
 */
final class CatalogScanScopedConfigurationRepository implements ScopedConfigurationRepositoryInterface, ScopePresenceQueryInterface {

	/** @var array<string, ScopedConfiguration|null> */
	private array $global_slices = [];

	/** @var array<int, true> */
	private array $known_absent_products = [];

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $inner
	) {
	}

	/**
	 * Record which of these products have no product scope rows at all.
	 *
	 * @param list<int> $product_ids
	 */
	public function prime_products( array $product_ids ): void {
		if ( ! $this->inner instanceof ScopePresenceQueryInterface || [] === $product_ids ) {
			return;
		}

		$present = array_fill_keys( $this->inner->scopeIdsWithRows( ConfigurationScopeType::Product, $product_ids ), true );
		foreach ( $product_ids as $product_id ) {
			if ( ! isset( $present[ $product_id ] ) ) {
				$this->known_absent_products[ (int) $product_id ] = true;
			}
		}
	}

	public function reset(): void {
		$this->global_slices         = [];
		$this->known_absent_products = [];
	}

	public function scopeIdsWithRows( ConfigurationScopeType $scope_type, array $scope_ids ): array {
		if ( $this->inner instanceof ScopePresenceQueryInterface ) {
			return $this->inner->scopeIdsWithRows( $scope_type, $scope_ids );
		}

		$found = [];
		foreach ( $scope_ids as $scope_id ) {
			if ( [] !== $this->inner->findByScope( $scope_type, (int) $scope_id ) ) {
				$found[] = (int) $scope_id;
			}
		}

		return $found;
	}

	public function getGlobalConfiguration(): ?ScopedConfiguration {
		return $this->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);
	}

	public function ensureGlobalScope(): ScopedConfiguration {
		$this->reset();

		return $this->inner->ensureGlobalScope();
	}

	public function findByScope( ConfigurationScopeType $scope_type, int $scope_id ): array {
		if ( ConfigurationScopeType::Product === $scope_type && isset( $this->known_absent_products[ $scope_id ] ) ) {
			return [];
		}

		return $this->inner->findByScope( $scope_type, $scope_id );
	}

	public function findByScopeAndSlice( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?ScopedConfiguration {
		if ( ConfigurationScopeType::Global !== $scope_type ) {
			if ( ConfigurationScopeType::Product === $scope_type && isset( $this->known_absent_products[ $scope_id ] ) ) {
				return null;
			}

			return $this->inner->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );
		}

		$key = $scope_id . '|' . $slice_key;
		if ( ! array_key_exists( $key, $this->global_slices ) ) {
			$this->global_slices[ $key ] = $this->inner->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );
		}

		return $this->global_slices[ $key ];
	}

	public function findByLegacyRuleId( int $legacy_rule_id ): ?ScopedConfiguration {
		return $this->inner->findByLegacyRuleId( $legacy_rule_id );
	}

	public function findByParentProductId( int $parent_product_id ): array {
		return $this->inner->findByParentProductId( $parent_product_id );
	}

	public function saveScopedConfiguration( ScopedConfiguration $configuration, bool $publish_revision = true ): ScopedConfiguration {
		$this->reset();

		return $this->inner->saveScopedConfiguration( $configuration, $publish_revision );
	}

	public function completeLocalUnit( callable $work ): mixed {
		$this->reset();

		try {
			return $this->inner->completeLocalUnit( $work );
		} finally {
			$this->reset();
		}
	}

	public function publishAcceptedRevision( ScopedConfiguration $configuration ): bool {
		$this->reset();

		return $this->inner->publishAcceptedRevision( $configuration );
	}

	public function lockScopeIdentity( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?array {
		return $this->inner->lockScopeIdentity( $scope_type, $scope_id, $slice_key );
	}

	public function deleteScope( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		$this->reset();

		return $this->inner->deleteScope( $scope_type, $scope_id, $slice_key );
	}

	public function getVersionInfo( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): ?ConfigurationVersionInfo {
		return $this->inner->getVersionInfo( $scope_type, $scope_id, $slice_key );
	}

	public function getGlobalVersion(): int {
		return $this->inner->getGlobalVersion();
	}
}
