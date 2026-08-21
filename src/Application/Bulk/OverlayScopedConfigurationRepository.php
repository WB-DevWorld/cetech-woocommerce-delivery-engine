<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationVersionInfo;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Read-only overlay used by dry-run preview so EffectiveConfigurationResolver
 * remains the only resolver. Writes are rejected.
 */
final class OverlayScopedConfigurationRepository implements ScopedConfigurationRepositoryInterface {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $inner,
		private readonly ?ScopedConfiguration $overlay,
		private readonly bool $overlay_deleted = false,
		private readonly ?ConfigurationScopeType $deleted_scope_type = null,
		private readonly int $deleted_scope_id = 0,
		private readonly string $deleted_slice_key = ''
	) {
	}

	public function getGlobalConfiguration(): ?ScopedConfiguration {
		return $this->inner->getGlobalConfiguration();
	}

	public function ensureGlobalScope(): ScopedConfiguration {
		return $this->inner->ensureGlobalScope();
	}

	public function findByScope( ConfigurationScopeType $scope_type, int $scope_id ): array {
		$found = $this->inner->findByScope( $scope_type, $scope_id );
		$filtered = [];
		foreach ( $found as $configuration ) {
			if ( $this->matches_identity( $scope_type, $configuration->scope->scope_id, $configuration->scope->slice_key ) && $this->overlay_deleted ) {
				continue;
			}
			if ( $this->matches_overlay( $configuration->scope ) ) {
				continue;
			}
			$filtered[] = $configuration;
		}

		if ( $this->overlay instanceof ScopedConfiguration && $this->matches_identity( $scope_type, $scope_id, $this->overlay->scope->slice_key ) ) {
			$filtered[] = $this->overlay;
		}

		return $filtered;
	}

	public function findByScopeAndSlice( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?ScopedConfiguration {
		if ( $this->matches_identity( $scope_type, $scope_id, $slice_key ) ) {
			if ( $this->overlay_deleted ) {
				return null;
			}
			if ( $this->overlay instanceof ScopedConfiguration ) {
				return $this->overlay;
			}
		}

		return $this->inner->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );
	}

	public function findByLegacyRuleId( int $legacy_rule_id ): ?ScopedConfiguration {
		return $this->inner->findByLegacyRuleId( $legacy_rule_id );
	}

	public function findByParentProductId( int $parent_product_id ): array {
		$found = $this->inner->findByParentProductId( $parent_product_id );
		if ( ! $this->overlay instanceof ScopedConfiguration && ! $this->overlay_deleted ) {
			return $found;
		}

		$filtered = [];
		foreach ( $found as $configuration ) {
			if ( $this->matches_overlay( $configuration->scope ) ) {
				continue;
			}
			$filtered[] = $configuration;
		}

		if (
			$this->overlay instanceof ScopedConfiguration
			&& ConfigurationScopeType::Variation === $this->overlay->scope->scope_type
			&& $this->overlay->scope->parent_product_id === $parent_product_id
		) {
			$filtered[] = $this->overlay;
		}

		return $filtered;
	}

	public function saveScopedConfiguration( ScopedConfiguration $configuration ): ScopedConfiguration {
		throw new \LogicException( 'Dry-run overlay repository cannot persist configuration.' );
	}

	public function deleteScope( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		throw new \LogicException( 'Dry-run overlay repository cannot delete configuration.' );
	}

	public function getVersionInfo( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): ?ConfigurationVersionInfo {
		return $this->inner->getVersionInfo( $scope_type, $scope_id, $slice_key );
	}

	public function getGlobalVersion(): int {
		return $this->inner->getGlobalVersion();
	}

	private function matches_overlay( ConfigurationScope $scope ): bool {
		if ( $this->overlay instanceof ScopedConfiguration ) {
			$overlay = $this->overlay->scope;

			return $scope->scope_type === $overlay->scope_type
				&& $scope->scope_id === $overlay->scope_id
				&& $scope->slice_key === $overlay->slice_key;
		}

		return false;
	}

	private function matches_identity( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): bool {
		if ( $this->overlay instanceof ScopedConfiguration ) {
			$overlay = $this->overlay->scope;

			return $overlay->scope_type === $scope_type
				&& $overlay->scope_id === $scope_id
				&& $overlay->slice_key === $slice_key;
		}

		return $this->overlay_deleted
			&& $this->deleted_scope_type === $scope_type
			&& $this->deleted_scope_id === $scope_id
			&& $this->deleted_slice_key === $slice_key;
	}
}
