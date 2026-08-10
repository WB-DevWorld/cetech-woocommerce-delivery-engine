<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

/**
 * Complete instruction set for one configuration scope.
 */
final class ScopedConfiguration {

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars     keyed by field key
	 * @param array<string, CollectionFieldInstruction> $collections keyed by field key
	 */
	public function __construct(
		public readonly ConfigurationScope $scope,
		public readonly array $scalars = [],
		public readonly array $collections = []
	) {
		foreach ( $this->scalars as $key => $instruction ) {
			if ( ! $instruction instanceof ScalarFieldInstruction || $instruction->field_key !== $key ) {
				throw new InvalidConfigurationException( 'Scalar instruction map keys must match field keys.' );
			}
		}

		foreach ( $this->collections as $key => $instruction ) {
			if ( ! $instruction instanceof CollectionFieldInstruction || $instruction->field_key !== $key ) {
				throw new InvalidConfigurationException( 'Collection instruction map keys must match field keys.' );
			}
		}
	}

	public function fingerprint(): string {
		$scalar_parts = [];

		foreach ( $this->scalars as $key => $instruction ) {
			$scalar_parts[ $key ] = $instruction->fingerprint();
		}

		ksort( $scalar_parts );

		$collection_parts = [];

		foreach ( $this->collections as $key => $instruction ) {
			$collection_parts[ $key ] = $instruction->fingerprint();
		}

		ksort( $collection_parts );

		return hash(
			'sha256',
			json_encode(
				[
					'scope_type'        => $this->scope->scope_type->value,
					'scope_id'          => $this->scope->scope_id,
					'slice_key'         => $this->scope->slice_key,
					'parent_product_id' => $this->scope->parent_product_id,
					'status'            => $this->scope->status->value,
					'scalars'           => $scalar_parts,
					'collections'       => $collection_parts,
				],
				JSON_THROW_ON_ERROR
			)
		);
	}

	public function withScope( ConfigurationScope $scope ): self {
		return new self( $scope, $this->scalars, $this->collections );
	}
}
