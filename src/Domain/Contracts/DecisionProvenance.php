<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

/** Internal provenance facts, excluding field values and contributing members. */
final readonly class DecisionProvenance {

	public function __construct(
		public string $field_key,
		public EffectiveFieldState $state,
		public ?ConfigurationScopeType $source_scope,
		public string $source_label,
		public ?int $scope_row_id = null,
		public ?int $configuration_version = null
	) {
		if ( ! in_array( $this->field_key, ConfigurationFieldKey::all(), true )
			|| ( null !== $this->scope_row_id && $this->scope_row_id < 1 )
			|| ( null !== $this->configuration_version && $this->configuration_version < 0 )
			|| ! $this->has_coherent_source()
		) {
			throw new \InvalidArgumentException( 'Decision provenance is invalid.' );
		}
	}

	/** Explicit projection for a separately authorized administrative reader. */
	public function admin_fields(): array {
		return [
			'field_key'             => $this->field_key,
			'state'                 => $this->state->value,
			'source_scope'          => $this->source_scope?->value,
			'source_label'          => $this->source_label,
			'scope_row_id'          => $this->scope_row_id,
			'configuration_version' => $this->configuration_version,
		];
	}

	private function has_coherent_source(): bool {
		if ( in_array( $this->source_label, [ 'system_default', 'hard_constraint' ], true ) ) {
			return null === $this->source_scope && null === $this->scope_row_id && null === $this->configuration_version;
		}
		if ( 'explicit_disable' === $this->source_label ) {
			return null !== $this->source_scope;
		}
		if ( 'global' === $this->source_label && null === $this->source_scope ) {
			return null === $this->scope_row_id && null === $this->configuration_version;
		}
		return in_array( $this->source_label, [ 'global', 'product', 'variation' ], true )
			&& $this->source_label === $this->source_scope?->value;
	}
}
