<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\EffectiveCollectionField;
use CetechDeliveryEngine\Domain\Configuration\EffectiveScalarField;

final class ConfigurationFingerprintBuilder {

	/**
	 * @param array<string, EffectiveScalarField>     $scalars
	 * @param array<string, EffectiveCollectionField> $collections
	 */
	public function build(
		int $product_id,
		?int $variation_id,
		string $slice_key,
		int $global_version,
		int $product_version,
		int $variation_version,
		array $scalars = [],
		array $collections = []
	): string {
		$scalar_parts = [];
		foreach ( $scalars as $key => $field ) {
			$scalar_parts[ $key ] = [
				'state'        => $field->state->value,
				'value'        => $field->value,
				'reasons'      => $field->reason_codes,
				'source'       => $field->provenance->source_label,
				'source_scope' => $field->provenance->source_scope?->value,
			];
		}
		ksort( $scalar_parts );

		$collection_parts = [];
		foreach ( $collections as $key => $field ) {
			$mutation_parts = array_map(
				static function ( $step ): array {
					$data = $step->to_array();
					unset( $data['scope_row_id'] );

					return $data;
				},
				$field->mutation_steps
			);

			$collection_parts[ $key ] = [
				'state'        => $field->state->value,
				'members'      => $field->members,
				'reasons'      => $field->reason_codes,
				'source'       => $field->provenance->source_label,
				'source_scope' => $field->provenance->source_scope?->value,
				'mutations'    => $mutation_parts,
			];
		}
		ksort( $collection_parts );

		return hash(
			'sha256',
			json_encode(
				[
					'product_id'        => $product_id,
					'variation_id'      => $variation_id,
					'slice_key'         => $slice_key,
					'global_version'    => $global_version,
					'product_version'   => $product_version,
					'variation_version' => $variation_version,
					'scalars'           => $scalar_parts,
					'collections'       => $collection_parts,
				],
				JSON_THROW_ON_ERROR
			)
		);
	}
}
