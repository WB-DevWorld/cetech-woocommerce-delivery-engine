<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;

/**
 * Developer/test-only comparator for legacy vs ECR runtime meaning.
 *
 * Never used for customer behavior decisions.
 */
final class RuntimeConfigurationParityComparator {

	public const MATCH = 'MATCH';

	public const MISMATCH = 'MISMATCH';

	/**
	 * @return array{
	 *     status: string,
	 *     differences: list<string>,
	 *     legacy: array<string, mixed>,
	 *     ecr: array<string, mixed>
	 * }
	 */
	public function compare_resolutions(
		ProductRuleResolutionResult $legacy,
		ProductRuleResolutionResult $ecr
	): array {
		$left  = $this->normalize_resolution( $legacy );
		$right = $this->normalize_resolution( $ecr );
		$diffs = $this->diff_maps( $left, $right, 'resolution' );

		return [
			'status'      => [] === $diffs ? self::MATCH : self::MISMATCH,
			'differences' => $diffs,
			'legacy'      => $left,
			'ecr'         => $right,
		];
	}

	/**
	 * @param list<ProductDeliveryOption> $legacy_options
	 * @param list<ProductDeliveryOption> $ecr_options
	 *
	 * @return array{
	 *     status: string,
	 *     differences: list<string>,
	 *     legacy: list<array<string, mixed>>,
	 *     ecr: list<array<string, mixed>>
	 * }
	 */
	public function compare_options( array $legacy_options, array $ecr_options ): array {
		$left  = array_map( [ $this, 'normalize_option' ], $legacy_options );
		$right = array_map( [ $this, 'normalize_option' ], $ecr_options );
		$diffs = [];

		if ( count( $left ) !== count( $right ) ) {
			$diffs[] = sprintf( 'option_count: %d !== %d', count( $left ), count( $right ) );
		}

		$max = max( count( $left ), count( $right ) );

		for ( $i = 0; $i < $max; ++$i ) {
			$l = $left[ $i ] ?? null;
			$r = $right[ $i ] ?? null;

			if ( $l === $r ) {
				continue;
			}

			$diffs[] = sprintf( 'option[%d]: %s !== %s', $i, wp_json_encode( $l ), wp_json_encode( $r ) );
		}

		return [
			'status'      => [] === $diffs ? self::MATCH : self::MISMATCH,
			'differences' => $diffs,
			'legacy'      => $left,
			'ecr'         => $right,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function normalize_resolution( ProductRuleResolutionResult $result ): array {
		$slices = [];

		foreach ( $result->chosen_rules as $availability => $rule ) {
			if ( ! $rule instanceof ResolvedProductDeliveryRule ) {
				continue;
			}

			$slices[ (string) $availability ] = [
				'fulfilment_availability' => $rule->fulfilment_availability,
				'fulfilment_choice'       => $rule->fulfilment_choice,
				'delivery_offer_ids'      => array_values( $rule->delivery_offer_ids ),
				'logistics_profile_id'    => $rule->logistics_profile_id,
				'supplier_id'             => $rule->supplier_id,
				'origin_id'               => $rule->origin_id,
				'priority'                => $rule->priority,
				'enabled'                 => true,
			];
		}

		ksort( $slices );

		return [
			'success'          => $result->success,
			'has_error'        => null !== $result->error && '' !== $result->error,
			'chosen_slice_keys'=> array_keys( $slices ),
			'slices'           => $slices,
			'no_match'         => null !== $result->no_match_message,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalize_option( ProductDeliveryOption $option ): array {
		return [
			'display_key'             => ProductDeliveryOptionsBuilder::normalizeDisplayKey( $option->display_key ),
			'fulfilment_availability' => $option->fulfilment_availability,
			'fulfilment_choice'       => $option->fulfilment_choice,
			'delivery_offer_id'       => $option->delivery_offer_id,
			'public_label'            => $option->delivery_offer_public_label,
			'public_description'      => $option->delivery_offer_public_description,
			'estimate_text'           => $option->estimate_text,
			'is_available'            => $option->is_available,
			'unavailable_reason'      => $option->unavailable_reason,
		];
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 *
	 * @return list<string>
	 */
	private function diff_maps( array $left, array $right, string $prefix ): array {
		$diffs = [];
		$keys  = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );

		foreach ( $keys as $key ) {
			$l = $left[ $key ] ?? null;
			$r = $right[ $key ] ?? null;

			if ( is_array( $l ) && is_array( $r ) ) {
				foreach ( $this->diff_maps( $l, $r, $prefix . '.' . $key ) as $diff ) {
					$diffs[] = $diff;
				}
				continue;
			}

			if ( $l !== $r ) {
				$diffs[] = sprintf( '%s.%s: %s !== %s', $prefix, $key, $this->stringify( $l ), $this->stringify( $r ) );
			}
		}

		return $diffs;
	}

	private function stringify( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );

		return false === $encoded ? 'unencodable' : $encoded;
	}
}
