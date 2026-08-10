<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ProductRule;

use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;

/**
 * Resolves applicable active product delivery rules for admin/test use only.
 *
 * Does not modify cart, checkout, orders, product metadata, or calculate prices.
 */
final class ProductDeliveryRuleResolver {

	public function __construct(
		private ProductDeliveryRuleRepositoryInterface $rule_repository,
		private ProductTargetResolver $target_resolver
	) {
	}

	public function resolve( string $target_type, int $target_id ): ProductRuleResolutionResult {
		$target_type = sanitize_key( $target_type );

		if ( ! $this->is_valid_input_target_type( $target_type ) ) {
			return ProductRuleResolutionResult::failure(
				$target_type,
				$target_id,
				__( 'Invalid target type.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( $target_id <= 0 ) {
			return ProductRuleResolutionResult::failure(
				$target_type,
				$target_id,
				__( 'Target ID must be a positive integer.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( ! $this->target_resolver->is_woocommerce_available() ) {
			return ProductRuleResolutionResult::failure(
				$target_type,
				$target_id,
				__(
					'WooCommerce is not available. Product rule resolution requires WooCommerce.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}

		$target_error = $this->target_resolver->validate_target( $target_type, $target_id );

		if ( null !== $target_error ) {
			return ProductRuleResolutionResult::failure( $target_type, $target_id, $target_error );
		}

		$hierarchy     = $this->build_candidate_hierarchy( $target_type, $target_id );
		$specificity_map = $this->specificity_map_from_hierarchy( $hierarchy );
		$hierarchy_explanation = $this->build_hierarchy_explanation( $target_type, $hierarchy );
		$raw_rules       = $this->rule_repository->findActiveByTargets(
			array_map(
				static fn ( array $entry ): array => [
					'target_type' => (string) $entry['target_type'],
					'target_id'   => (int) $entry['target_id'],
				],
				$hierarchy
			)
		);

		$skipped_rules = [];
		$eligible      = [];
		$matched_safe  = [];
		$warnings      = [];

		foreach ( $raw_rules as $row ) {
			$rule_id         = (int) ( $row['id'] ?? 0 );
			$rule_target_type = (string) ( $row['target_type'] ?? '' );
			$rule_target_id   = (int) ( $row['target_id'] ?? 0 );
			$key              = $this->target_key( $rule_target_type, $rule_target_id );

			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				$skipped_rules[] = [
					'rule_id' => $rule_id,
					'reason'  => __( 'Rule is not active.', 'cetech-woocommerce-delivery-engine' ),
					'code'    => 'inactive',
				];
				continue;
			}

			if ( ! $this->target_resolver->target_exists( $rule_target_type, $rule_target_id ) ) {
				$skipped_rules[] = [
					'rule_id' => $rule_id,
					'reason'  => __( 'Rule target does not exist in WooCommerce.', 'cetech-woocommerce-delivery-engine' ),
					'code'    => 'missing_target',
				];
				continue;
			}

			$specificity = $specificity_map[ $key ] ?? $this->specificity_for_target_type( $rule_target_type );
			$offer_ids   = $this->decode_offer_ids( $row['delivery_offer_ids'] ?? null );
			$choice      = (string) ( $row['fulfilment_choice'] ?? '' );

			if ( FulfilmentChoice::Delivery->value === $choice && [] === $offer_ids ) {
				$warnings[] = sprintf(
					/* translators: %d: product rule ID */
					__( 'Rule #%d has delivery fulfilment choice but no delivery offers.', 'cetech-woocommerce-delivery-engine' ),
					$rule_id
				);
			}

			$resolved = ResolvedProductDeliveryRule::from_row( $row, $offer_ids, $specificity );
			$matched_safe[] = $resolved;
			$eligible[]     = [
				'row'         => $row,
				'specificity' => $specificity,
				'resolved'    => $resolved,
			];
		}

		$chosen_rules = [];
		$chosen_explanations = [];
		$by_availability = [];

		foreach ( $eligible as $candidate ) {
			$availability = (string) ( $candidate['row']['fulfilment_availability'] ?? '' );
			$by_availability[ $availability ]   = $by_availability[ $availability ] ?? [];
			$by_availability[ $availability ][] = $candidate;
		}

		foreach ( FulfilmentAvailability::cases() as $availability_case ) {
			$availability = $availability_case->value;
			$pool         = $by_availability[ $availability ] ?? [];

			if ( [] === $pool ) {
				continue;
			}

			usort(
				$pool,
				static function ( array $left, array $right ): int {
					$left_spec  = (int) ( $left['specificity'] ?? 0 );
					$right_spec = (int) ( $right['specificity'] ?? 0 );

					if ( $left_spec !== $right_spec ) {
						return $right_spec <=> $left_spec;
					}

					$left_prio  = (int) ( $left['row']['priority'] ?? 100 );
					$right_prio = (int) ( $right['row']['priority'] ?? 100 );

					if ( $left_prio !== $right_prio ) {
						return $left_prio <=> $right_prio;
					}

					return (int) ( $left['row']['id'] ?? 0 ) <=> (int) ( $right['row']['id'] ?? 0 );
				}
			);

			$winner = $pool[0];
			$chosen_rules[ $availability ] = $winner['resolved'];
			$chosen_explanations[ $availability ] = $this->build_chosen_explanation( $winner, $pool );

			for ( $index = 1, $count = count( $pool ); $index < $count; ++$index ) {
				$loser   = $pool[ $index ];
				$rule_id = (int) ( $loser['row']['id'] ?? 0 );

				$skipped_rules[] = [
					'rule_id' => $rule_id,
					'reason'  => $this->build_superseded_reason( $winner, $loser, $availability ),
					'code'    => 'superseded',
				];
			}

			if ( count( $pool ) > 1 ) {
				$first_prio = (int) ( $pool[0]['row']['priority'] ?? 100 );
				$same_prio  = array_filter(
					$pool,
					static fn ( array $item ): bool => (int) ( $item['row']['priority'] ?? 100 ) === $first_prio
						&& (int) ( $item['specificity'] ?? 0 ) === (int) ( $pool[0]['specificity'] ?? 0 )
				);

				if ( count( $same_prio ) > 1 ) {
					$warnings[] = sprintf(
						/* translators: 1: fulfilment availability, 2: priority number */
						__( 'Multiple rules compete for %1$s at the same target specificity and priority %2$d.', 'cetech-woocommerce-delivery-engine' ),
						$availability,
						$first_prio
					);
				}
			}
		}

		$no_match = [] === $chosen_rules
			? __(
				'No active product delivery rules matched the candidate hierarchy for this target.',
				'cetech-woocommerce-delivery-engine'
			)
			: null;

		return new ProductRuleResolutionResult(
			true,
			null,
			$target_type,
			$target_id,
			$this->target_resolver->resolve_label( $target_type, $target_id ),
			$hierarchy,
			$hierarchy_explanation,
			$matched_safe,
			$chosen_rules,
			$chosen_explanations,
			$skipped_rules,
			$warnings,
			$no_match
		);
	}

	/**
	 * @param list<array{target_type: string, target_id: int, label: string|null, order: int}> $hierarchy
	 */
	private function build_hierarchy_explanation( string $input_target_type, array $hierarchy ): string {
		if ( [] === $hierarchy ) {
			return __( 'No candidate targets were resolved.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ProductTargetType::Category->value === $input_target_type ) {
			return __(
				'Category input searches category rules for the selected term only.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		if ( ProductTargetType::Variation->value === $input_target_type ) {
			return __(
				'Variation input searches variation rules first, then parent product rules, then parent product category rules in term ID order.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		return __(
			'Product input searches product rules first, then product category rules in term ID order.',
			'cetech-woocommerce-delivery-engine'
		);
	}

	/**
	 * @param array{row: array<string, mixed>, specificity: int, resolved: ResolvedProductDeliveryRule} $winner
	 * @param list<array{row: array<string, mixed>, specificity: int, resolved: ResolvedProductDeliveryRule}> $pool
	 */
	private function build_chosen_explanation( array $winner, array $pool ): string {
		$winner_id   = (int) ( $winner['row']['id'] ?? 0 );
		$specificity = (int) ( $winner['specificity'] ?? 0 );
		$priority    = (int) ( $winner['row']['priority'] ?? 100 );
		$parts       = [
			sprintf(
				/* translators: 1: rule ID, 2: specificity label */
				__( 'Rule #%1$d selected with %2$s target specificity.', 'cetech-woocommerce-delivery-engine' ),
				$winner_id,
				$this->specificity_label( $specificity )
			),
			sprintf(
				/* translators: %d: priority number */
				__( 'Priority %d (lower wins).', 'cetech-woocommerce-delivery-engine' ),
				$priority
			),
		];

		if ( count( $pool ) > 1 ) {
			$same_spec_prio = array_filter(
				$pool,
				static fn ( array $item ): bool => (int) ( $item['specificity'] ?? 0 ) === $specificity
					&& (int) ( $item['row']['priority'] ?? 100 ) === $priority
			);

			if ( count( $same_spec_prio ) > 1 ) {
				$parts[] = sprintf(
					/* translators: %d: rule ID */
					__( 'Rule ID %d used as stable tie-breaker.', 'cetech-woocommerce-delivery-engine' ),
					$winner_id
				);
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param array{row: array<string, mixed>, specificity: int, resolved: ResolvedProductDeliveryRule} $winner
	 * @param array{row: array<string, mixed>, specificity: int, resolved: ResolvedProductDeliveryRule} $loser
	 */
	private function build_superseded_reason( array $winner, array $loser, string $availability ): string {
		$winner_id      = (int) ( $winner['row']['id'] ?? 0 );
		$loser_id       = (int) ( $loser['row']['id'] ?? 0 );
		$winner_spec    = (int) ( $winner['specificity'] ?? 0 );
		$loser_spec     = (int) ( $loser['specificity'] ?? 0 );
		$winner_prio    = (int) ( $winner['row']['priority'] ?? 100 );
		$loser_prio     = (int) ( $loser['row']['priority'] ?? 100 );

		if ( $winner_spec > $loser_spec ) {
			return sprintf(
				/* translators: 1: winning rule ID, 2: availability, 3: winner specificity, 4: loser specificity */
				__( 'Superseded by rule #%1$d for %2$s: higher target specificity (%3$s vs %4$s).', 'cetech-woocommerce-delivery-engine' ),
				$winner_id,
				$availability,
				$this->specificity_label( $winner_spec ),
				$this->specificity_label( $loser_spec )
			);
		}

		if ( $winner_prio < $loser_prio ) {
			return sprintf(
				/* translators: 1: winning rule ID, 2: availability, 3: winner priority, 4: loser priority */
				__( 'Superseded by rule #%1$d for %2$s: lower priority (%3$d vs %4$d).', 'cetech-woocommerce-delivery-engine' ),
				$winner_id,
				$availability,
				$winner_prio,
				$loser_prio
			);
		}

		return sprintf(
			/* translators: 1: winning rule ID, 2: availability, 3: winning rule ID used as tie-break */
			__( 'Superseded by rule #%1$d for %2$s: same specificity and priority; rule ID %3$d wins tie-break.', 'cetech-woocommerce-delivery-engine' ),
			$winner_id,
			$availability,
			$winner_id
		);
	}

	private function specificity_label( int $specificity ): string {
		return match ( $specificity ) {
			3 => __( 'variation', 'cetech-woocommerce-delivery-engine' ),
			2 => __( 'product', 'cetech-woocommerce-delivery-engine' ),
			1 => __( 'category', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'unknown', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/**
	 * @return list<array{target_type: string, target_id: int, label: string|null, order: int}>
	 */
	private function build_candidate_hierarchy( string $input_target_type, int $input_target_id ): array {
		$hierarchy = [];
		$order     = 1;

		if ( ProductTargetType::Category->value === $input_target_type ) {
			$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Category->value, $input_target_id, $order++ );

			return $hierarchy;
		}

		if ( ProductTargetType::Variation->value === $input_target_type ) {
			$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Variation->value, $input_target_id, $order++ );

			$parent_id = $this->resolve_parent_product_id( $input_target_id );

			if ( $parent_id > 0 ) {
				$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Product->value, $parent_id, $order++ );

				foreach ( $this->resolve_product_category_ids( $parent_id ) as $category_id ) {
					$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Category->value, $category_id, $order++ );
				}
			}

			return $hierarchy;
		}

		$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Product->value, $input_target_id, $order++ );

		foreach ( $this->resolve_product_category_ids( $input_target_id ) as $category_id ) {
			$hierarchy[] = $this->hierarchy_entry( ProductTargetType::Category->value, $category_id, $order++ );
		}

		return $hierarchy;
	}

	/**
	 * @return array{target_type: string, target_id: int, label: string|null, order: int}
	 */
	private function hierarchy_entry( string $target_type, int $target_id, int $order ): array {
		return [
			'target_type' => $target_type,
			'target_id'   => $target_id,
			'label'       => $this->target_resolver->resolve_label( $target_type, $target_id ),
			'order'       => $order,
		];
	}

	/**
	 * @param list<array{target_type: string, target_id: int, label: string|null, order: int}> $hierarchy
	 *
	 * @return array<string, int>
	 */
	private function specificity_map_from_hierarchy( array $hierarchy ): array {
		$map = [];

		foreach ( $hierarchy as $entry ) {
			$key       = $this->target_key( (string) $entry['target_type'], (int) $entry['target_id'] );
			$map[ $key ] = $this->specificity_for_target_type( (string) $entry['target_type'] );
		}

		return $map;
	}

	private function target_key( string $target_type, int $target_id ): string {
		return $target_type . ':' . (string) $target_id;
	}

	private function specificity_for_target_type( string $target_type ): int {
		return match ( $target_type ) {
			ProductTargetType::Variation->value => 3,
			ProductTargetType::Product->value => 2,
			ProductTargetType::Category->value => 1,
			default => 0,
		};
	}

	private function is_valid_input_target_type( string $target_type ): bool {
		foreach ( ProductTargetType::cases() as $case ) {
			if ( $case->value === $target_type ) {
				return true;
			}
		}

		return false;
	}

	private function resolve_parent_product_id( int $variation_id ): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = wc_get_product( $variation_id );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variation' ) ) {
			return 0;
		}

		return (int) $product->get_parent_id();
	}

	/**
	 * @return list<int>
	 */
	private function resolve_product_category_ids( int $product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return [];
		}

		$ids = array_map( 'intval', $product->get_category_ids() );
		$ids = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * @return list<int>
	 */
	private function decode_offer_ids( mixed $stored ): array {
		if ( $this->rule_repository instanceof WpdbProductDeliveryRuleRepository ) {
			return $this->rule_repository->decode_offer_ids( $stored );
		}

		if ( null === $stored || '' === $stored ) {
			return [];
		}

		$decoded = json_decode( (string) $stored, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids = [];

		foreach ( $decoded as $value ) {
			$int = (int) $value;

			if ( $int > 0 ) {
				$ids[] = $int;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
