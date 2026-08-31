<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationSet;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Maps constrained EffectiveConfiguration slices into the legacy runtime rule model.
 *
 * Downstream services continue to consume ProductRuleResolutionResult / ResolvedProductDeliveryRule.
 * Does not expose provenance or private entity labels to callers beyond internal numeric IDs
 * already required by RateQuoteEngine matching.
 */
final class EcrToRuntimeConfigurationAdapter {

	/**
	 * @return array{
	 *     result: ProductRuleResolutionResult,
	 *     configuration_fingerprint: string|null,
	 *     quote_dimensions_by_availability: array<string, array{
	 *         logistics_profile_id: int|null,
	 *         supplier_id: int|null,
	 *         origin_id: int|null
	 *     }>
	 * }
	 */
	public function adapt(
		int $product_id,
		EffectiveConfigurationSet $set,
		string $input_target_type = ProductTargetType::Product->value,
		?int $variation_id = null
	): array {
		$input_target_type   = sanitize_key( $input_target_type );
		$result_target_id    = $this->result_target_id( $input_target_type, $product_id, $variation_id );
		$chosen              = [];
		$explanations        = [];
		$matched             = [];
		$dimensions          = [];
		$fingerprints        = [];
		$has_invalid_slice   = false;
		$has_unresolved_only = true;

		foreach ( $set->ordered_slice_keys as $slice_key ) {
			$configuration = $set->for_slice( $slice_key );

			if ( ! $configuration instanceof EffectiveConfiguration ) {
				continue;
			}

			$fingerprints[] = $configuration->version->fingerprint;

			if ( EffectiveFieldState::Invalid === $configuration->state ) {
				$has_invalid_slice = true;
				continue;
			}

			if ( EffectiveFieldState::Unresolved === $configuration->state ) {
				continue;
			}

			$has_unresolved_only = false;
			$mapped              = $this->map_slice( $product_id, $configuration, $input_target_type, $variation_id );

			if ( null === $mapped ) {
				continue;
			}

			$availability = $mapped['rule']->fulfilment_availability;
			$chosen[ $availability ]       = $mapped['rule'];
			$explanations[ $availability ] = $mapped['explanation'];
			$matched[]                     = $mapped['rule'];
			$dimensions[ $availability ]   = $mapped['dimensions'];
		}

		if ( [] === $chosen && ( $has_invalid_slice || $has_unresolved_only ) ) {
			$result = ProductRuleResolutionResult::failure(
				$input_target_type,
				$result_target_id,
				__(
					'Effective configuration is invalid or unresolved for this product.',
					'cetech-woocommerce-delivery-engine'
				)
			);

			return [
				'result'                            => $result,
				'configuration_fingerprint'         => $this->combine_fingerprints( $fingerprints ),
				'quote_dimensions_by_availability'  => [],
			];
		}

		$no_match = [] === $chosen
			? __(
				'No effective configuration slices produced applicable delivery rules for this product.',
				'cetech-woocommerce-delivery-engine'
			)
			: null;

		$hierarchy = [
			[
				'target_type' => ProductTargetType::Product->value,
				'target_id'   => $product_id,
				'label'       => null,
				'order'       => 1,
			],
		];

		if (
			ProductTargetType::Variation->value === $input_target_type
			&& null !== $variation_id
			&& $variation_id > 0
		) {
			$hierarchy[] = [
				'target_type' => ProductTargetType::Variation->value,
				'target_id'   => $variation_id,
				'label'       => null,
				'order'       => 2,
			];
		}

		$explanation = ProductTargetType::Variation->value === $input_target_type
			? __(
				'Effective configuration resolver (GLOBAL → PRODUCT → VARIATION) supplied slice-aware runtime configuration.',
				'cetech-woocommerce-delivery-engine'
			)
			: __(
				'Effective configuration resolver (GLOBAL → PRODUCT) supplied slice-aware runtime configuration.',
				'cetech-woocommerce-delivery-engine'
			);

		$result = new ProductRuleResolutionResult(
			true,
			null,
			$input_target_type,
			$result_target_id,
			null,
			$hierarchy,
			$explanation,
			$matched,
			$chosen,
			$explanations,
			[],
			[],
			$no_match
		);

		return [
			'result'                           => $result,
			'configuration_fingerprint'        => $this->combine_fingerprints( $fingerprints ),
			'quote_dimensions_by_availability' => $dimensions,
		];
	}

	/**
	 * @return array{
	 *     rule: ResolvedProductDeliveryRule,
	 *     explanation: string,
	 *     dimensions: array{
	 *         logistics_profile_id: int|null,
	 *         supplier_id: int|null,
	 *         origin_id: int|null
	 *     }
	 * }|null
	 */
	private function map_slice(
		int $product_id,
		EffectiveConfiguration $configuration,
		string $input_target_type = ProductTargetType::Product->value,
		?int $variation_id = null
	): ?array {
		$availability = $this->resolve_availability( $configuration );

		if ( null === $availability ) {
			return null;
		}

		$choice_field = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE );

		if ( null === $choice_field || EffectiveFieldState::Valid !== $choice_field->state ) {
			return null;
		}

		$choice = (string) $choice_field->value;

		if ( ! $this->is_valid_choice( $choice ) ) {
			return null;
		}

		$offer_ids = [];
		$offers    = $configuration->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );

		if ( null !== $offers && EffectiveFieldState::Valid === $offers->state ) {
			$offer_ids = array_values(
				array_filter(
					$offers->members,
					static fn ( int $id ): bool => $id > 0
				)
			);
		} elseif ( null !== $offers && EffectiveFieldState::Disabled === $offers->state ) {
			$offer_ids = [];
		} elseif ( FulfilmentChoice::Delivery->value === $choice ) {
			// Delivery without a resolvable offer list cannot produce offers.
			return null;
		}

		$priority_field = $configuration->scalar( ConfigurationFieldKey::PRIORITY );
		$priority       = ( null !== $priority_field && EffectiveFieldState::Valid === $priority_field->state )
			? (int) $priority_field->value
			: 100;

		$logistics = $this->nullable_reference( $configuration, ConfigurationFieldKey::LOGISTICS_PROFILE_ID );
		$supplier  = $this->nullable_reference( $configuration, ConfigurationFieldKey::SUPPLIER_ID );
		$origin    = $this->nullable_reference( $configuration, ConfigurationFieldKey::ORIGIN_ID );
		$pickup    = $this->nullable_reference( $configuration, ConfigurationFieldKey::PICKUP_LOCATION_ID );

		if ( FulfilmentChoice::Delivery->value === $choice && [] === $offer_ids && null === $pickup ) {
			return null;
		}

		$rule_target_type = ProductTargetType::Variation->value === $input_target_type
			&& null !== $variation_id
			&& $variation_id > 0
			? ProductTargetType::Variation->value
			: ProductTargetType::Product->value;
		$rule_target_id   = ProductTargetType::Variation->value === $rule_target_type
			? (int) $variation_id
			: $product_id;
		$specificity      = ProductTargetType::Variation->value === $rule_target_type ? 3 : 2;

		$rule = new ResolvedProductDeliveryRule(
			0,
			$rule_target_type,
			$rule_target_id,
			null,
			$specificity,
			$availability,
			$choice,
			$offer_ids,
			$logistics,
			$supplier,
			$origin,
			$priority,
			$pickup
		);

		return [
			'rule'        => $rule,
			'explanation' => sprintf(
				/* translators: %s: fulfilment availability slug */
				__( 'Effective configuration slice "%s" mapped for runtime.', 'cetech-woocommerce-delivery-engine' ),
				$availability
			),
			'dimensions'  => [
				'logistics_profile_id' => $logistics,
				'supplier_id'          => $supplier,
				'origin_id'            => $origin,
			],
		];
	}

	private function resolve_availability( EffectiveConfiguration $configuration ): ?string {
		$slice = $configuration->slice_key;

		if ( ConfigurationScope::DEFAULT_SLICE_KEY !== $slice && $this->is_valid_availability( $slice ) ) {
			return $slice;
		}

		$field = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );

		if ( null === $field || EffectiveFieldState::Valid !== $field->state ) {
			return null;
		}

		$value = (string) $field->value;

		return $this->is_valid_availability( $value ) ? $value : null;
	}

	private function nullable_reference( EffectiveConfiguration $configuration, string $field_key ): ?int {
		$field = $configuration->scalar( $field_key );

		if ( null === $field ) {
			return null;
		}

		if ( EffectiveFieldState::Disabled === $field->state ) {
			return null;
		}

		if ( EffectiveFieldState::Valid !== $field->state ) {
			return null;
		}

		$value = (int) $field->value;

		return $value > 0 ? $value : null;
	}

	private function is_valid_availability( string $value ): bool {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $value ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_choice( string $value ): bool {
		foreach ( FulfilmentChoice::cases() as $case ) {
			if ( $case->value === $value ) {
				return true;
			}
		}

		return false;
	}

	private function result_target_id( string $input_target_type, int $product_id, ?int $variation_id ): int {
		if (
			ProductTargetType::Variation->value === $input_target_type
			&& null !== $variation_id
			&& $variation_id > 0
		) {
			return $variation_id;
		}

		return $product_id;
	}

	/**
	 * @param list<string> $fingerprints
	 */
	private function combine_fingerprints( array $fingerprints ): ?string {
		$fingerprints = array_values( array_filter( $fingerprints, static fn ( string $fp ): bool => '' !== $fp ) );

		if ( [] === $fingerprints ) {
			return null;
		}

		sort( $fingerprints, SORT_STRING );

		return hash( 'sha256', implode( '|', $fingerprints ) );
	}
}
