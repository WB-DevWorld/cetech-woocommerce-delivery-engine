<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationVersionInfo;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

/**
 * In-memory scoped configuration repository for unit tests.
 */
final class InMemoryScopedConfigurationRepository implements ScopedConfigurationRepositoryInterface {

	/** @var array<int, ScopedConfiguration> */
	private array $by_id = [];

	private int $next_id = 1;

	private int $global_version = 0;

	/** @var array<string, int> */
	private array $call_counts = [];

	private int $write_calls = 0;

	public function getGlobalConfiguration(): ?ScopedConfiguration {
		$this->increment_call_count( __FUNCTION__ );

		foreach ( $this->by_id as $configuration ) {
			$scope = $configuration->scope;

			if (
				ConfigurationScopeType::Global === $scope->scope_type
				&& ConfigurationScope::GLOBAL_SCOPE_ID === $scope->scope_id
				&& ConfigurationScope::DEFAULT_SLICE_KEY === $scope->slice_key
			) {
				return $configuration;
			}
		}

		return null;
	}

	public function ensureGlobalScope(): ScopedConfiguration {
		$existing = $this->getGlobalConfiguration();

		if ( null !== $existing ) {
			return $existing;
		}

		$saved = $this->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global( 1 ) )
		);

		$this->global_version = $saved->scope->config_version;

		return $saved;
	}

	public function findByScope( ConfigurationScopeType $scope_type, int $scope_id ): array {
		$this->increment_call_count( __FUNCTION__ );

		$matches = [];

		foreach ( $this->by_id as $configuration ) {
			if ( $configuration->scope->scope_type === $scope_type && $configuration->scope->scope_id === $scope_id ) {
				$matches[] = $configuration;
			}
		}

		return $matches;
	}

	public function findByScopeAndSlice( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?ScopedConfiguration {
		$this->increment_call_count( __FUNCTION__ );

		foreach ( $this->by_id as $configuration ) {
			$scope = $configuration->scope;

			if (
				$scope->scope_type === $scope_type
				&& $scope->scope_id === $scope_id
				&& $scope->slice_key === $slice_key
			) {
				return $configuration;
			}
		}

		return null;
	}

	public function findByLegacyRuleId( int $legacy_rule_id ): ?ScopedConfiguration {
		$this->increment_call_count( __FUNCTION__ );

		if ( $legacy_rule_id <= 0 ) {
			return null;
		}

		foreach ( $this->by_id as $configuration ) {
			if ( $configuration->scope->legacy_rule_id === $legacy_rule_id ) {
				return $configuration;
			}
		}

		return null;
	}

	public function findByParentProductId( int $parent_product_id ): array {
		$this->increment_call_count( __FUNCTION__ );

		$matches = [];

		foreach ( $this->by_id as $configuration ) {
			if ( $configuration->scope->parent_product_id === $parent_product_id ) {
				$matches[] = $configuration;
			}
		}

		return $matches;
	}

	public function saveScopedConfiguration( ScopedConfiguration $configuration ): ScopedConfiguration {
		$this->increment_call_count( __FUNCTION__ );
		++$this->write_calls;

		$scope = $configuration->scope;

		if ( ConfigurationScopeType::Global !== $scope->scope_type && $scope->scope_id <= 0 ) {
			throw new InvalidConfigurationException( 'Invalid scope_id.' );
		}

		foreach ( $configuration->scalars as $instruction ) {
			if ( ! $instruction instanceof ScalarFieldInstruction ) {
				throw new InvalidConfigurationException( 'Invalid scalar instruction.' );
			}
		}

		foreach ( $configuration->collections as $instruction ) {
			if ( ! $instruction instanceof CollectionFieldInstruction ) {
				throw new InvalidConfigurationException( 'Invalid collection instruction.' );
			}
		}

		$existing = $this->findByScopeAndSlice( $scope->scope_type, $scope->scope_id, $scope->slice_key );

		if ( null !== $existing && null !== $scope->legacy_rule_id ) {
			$by_legacy = $this->findByLegacyRuleId( $scope->legacy_rule_id );

			if ( null !== $by_legacy && $by_legacy->scope->id !== $existing->scope->id ) {
				throw new InvalidConfigurationException( 'legacy_rule_id uniqueness violated.' );
			}
		}

		if ( null !== $existing ) {
			$candidate = $configuration->withScope(
				new ConfigurationScope(
					$existing->scope->id,
					$scope->scope_type,
					$scope->scope_id,
					$scope->slice_key,
					$scope->parent_product_id,
					$scope->status,
					$existing->scope->config_version,
					$scope->source,
					$scope->legacy_rule_id ?? $existing->scope->legacy_rule_id,
					$existing->scope->created_at,
					$existing->scope->updated_at
				)
			);

			if ( $candidate->fingerprint() === $existing->fingerprint() ) {
				return $existing;
			}

			$new_version = $existing->scope->config_version + 1;
			$updated     = $configuration->withScope(
				new ConfigurationScope(
					$existing->scope->id,
					$scope->scope_type,
					$scope->scope_id,
					$scope->slice_key,
					$scope->parent_product_id,
					$scope->status,
					$new_version,
					$scope->source,
					$scope->legacy_rule_id ?? $existing->scope->legacy_rule_id,
					$existing->scope->created_at,
					gmdate( 'Y-m-d H:i:s' )
				)
			);

			$this->by_id[ (int) $existing->scope->id ] = $updated;

			if ( ConfigurationScopeType::Global === $scope->scope_type ) {
				$this->global_version = $new_version;
			}

			return $updated;
		}

		$id         = $this->next_id++;
		$now        = gmdate( 'Y-m-d H:i:s' );
		$version    = max( 1, $scope->config_version );
		$new_scope  = new ConfigurationScope(
			$id,
			$scope->scope_type,
			$scope->scope_id,
			$scope->slice_key,
			$scope->parent_product_id,
			$scope->status,
			$version,
			$scope->source,
			$scope->legacy_rule_id,
			$now,
			$now
		);
		$saved = $configuration->withScope( $new_scope );
		$this->by_id[ $id ] = $saved;

		if ( ConfigurationScopeType::Global === $scope->scope_type ) {
			$this->global_version = $version;
		}

		return $saved;
	}

	public function deleteScope( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		$this->increment_call_count( __FUNCTION__ );
		++$this->write_calls;

		if ( ConfigurationScopeType::Global === $scope_type ) {
			throw new InvalidConfigurationException( 'Global scope cannot be deleted.' );
		}

		$existing = $this->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );

		if ( null === $existing || null === $existing->scope->id ) {
			return false;
		}

		unset( $this->by_id[ $existing->scope->id ] );

		return true;
	}

	public function getVersionInfo( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): ?ConfigurationVersionInfo {
		$this->increment_call_count( __FUNCTION__ );

		$existing = $this->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );

		if ( null === $existing || null === $existing->scope->id ) {
			return null;
		}

		return new ConfigurationVersionInfo(
			$existing->scope->id,
			$existing->scope->scope_type->value,
			$existing->scope->scope_id,
			$existing->scope->slice_key,
			$existing->scope->config_version
		);
	}

	public function getGlobalVersion(): int {
		$this->increment_call_count( __FUNCTION__ );

		$global = $this->getGlobalConfiguration();

		return null === $global ? $this->global_version : $global->scope->config_version;
	}

	/**
	 * @return array<string, int>
	 */
	public function getReadCallCounts(): array {
		return $this->call_counts;
	}

	public function getWriteCalls(): int {
		return $this->write_calls;
	}

	public function resetReadCallCounts(): void {
		$this->call_counts = [];
	}

	public function resetWriteCalls(): void {
		$this->write_calls = 0;
	}

	private function increment_call_count( string $method_name ): void {
		$this->call_counts[ $method_name ] = ( $this->call_counts[ $method_name ] ?? 0 ) + 1;
	}
}
