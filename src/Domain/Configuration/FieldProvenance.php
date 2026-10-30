<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

final class FieldProvenance {

	/**
	 * @param list<CollectionMutationStep> $contributing_mutations
	 */
	public function __construct(
		public readonly ?ConfigurationScopeType $source_scope,
		public readonly string $source_label,
		public readonly ?int $scope_row_id = null,
		public readonly array $contributing_mutations = [],
		public readonly ?ConfigurationScopeType $ignored_inactive_scope = null
	) {
		foreach ( $this->contributing_mutations as $mutation ) {
			if ( ! $mutation instanceof CollectionMutationStep ) {
				throw new InvalidConfigurationException( 'Contributing mutations must be CollectionMutationStep objects.' );
			}
		}
	}

	public static function unresolved(): self {
		return new self( null, 'global' );
	}

	public static function system_default(): self {
		return new self( null, 'system_default' );
	}

	public static function explicit_disable( ConfigurationScopeType $scope, ?int $scope_row_id ): self {
		return new self( $scope, 'explicit_disable', $scope_row_id );
	}

	/**
	 * COR-012 (D3a): the same provenance, noting that an inactive item scope configured this field
	 * and was ignored. The effective value is unchanged.
	 */
	public function withIgnoredInactive( ConfigurationScopeType $scope ): self {
		return new self( $this->source_scope, $this->source_label, $this->scope_row_id, $this->contributing_mutations, $scope );
	}
}
