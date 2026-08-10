<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

final class EffectiveConfiguration {

	/**
	 * @param array<string, EffectiveScalarField>     $scalars
	 * @param array<string, EffectiveCollectionField> $collections
	 * @param list<string>                           $reason_codes
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $slice_key,
		public readonly array $scalars,
		public readonly array $collections,
		public readonly EffectiveFieldState $state,
		public readonly array $reason_codes,
		public readonly EffectiveConfigurationVersionDescriptor $version
	) {
		foreach ( $this->scalars as $key => $field ) {
			if ( ! $field instanceof EffectiveScalarField || $field->field_key !== $key ) {
				throw new InvalidConfigurationException( 'Effective scalar map keys must match field keys.' );
			}
		}

		foreach ( $this->collections as $key => $field ) {
			if ( ! $field instanceof EffectiveCollectionField || $field->field_key !== $key ) {
				throw new InvalidConfigurationException( 'Effective collection map keys must match field keys.' );
			}
		}
	}

	public function scalar( string $field_key ): ?EffectiveScalarField {
		return $this->scalars[ $field_key ] ?? null;
	}

	public function collection( string $field_key ): ?EffectiveCollectionField {
		return $this->collections[ $field_key ] ?? null;
	}

	public function with_validation( EffectiveFieldState $state, array $reason_codes ): self {
		return new self(
			$this->product_id,
			$this->variation_id,
			$this->slice_key,
			$this->scalars,
			$this->collections,
			$state,
			array_values( array_unique( $reason_codes ) ),
			$this->version
		);
	}

	public function with_version( EffectiveConfigurationVersionDescriptor $version ): self {
		return new self(
			$this->product_id,
			$this->variation_id,
			$this->slice_key,
			$this->scalars,
			$this->collections,
			$this->state,
			$this->reason_codes,
			$version
		);
	}
}
