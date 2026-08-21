<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;

/**
 * Frozen target definition. Products created after approval are excluded
 * unless entire_catalog was explicitly confirmed.
 *
 * Selected IDs are compact immutable metadata used only while enumerating
 * durable job items. After materialization the ID array is dropped from the
 * job row so later worker ticks stay batch-bounded.
 *
 * @phpstan-type FilterMap array<string, mixed>
 */
final class CatalogTargetDefinition {

	public const TARGET_PRODUCT = 'product';

	public const TARGET_VARIATION = 'variation';

	/**
	 * @param list<int>            $selected_ids
	 * @param array<string, mixed> $filters
	 * @param list<string>         $skus
	 */
	public function __construct(
		public readonly BulkTargetScope $scope,
		public readonly array $selected_ids = [],
		public readonly array $filters = [],
		public readonly array $skus = [],
		public readonly BulkVariationPolicy $variation_policy = BulkVariationPolicy::PreserveOverrides,
		public readonly bool $entire_catalog_confirmed = false,
		public readonly string $target_type = self::TARGET_PRODUCT,
		public readonly int $selected_id_count = 0,
		public readonly bool $selected_ids_materialized = false
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$scope = BulkTargetScope::tryFrom( (string) ( $data['scope'] ?? '' ) ) ?? BulkTargetScope::SelectedIds;
		$policy = BulkVariationPolicy::tryFrom( (string) ( $data['variation_policy'] ?? '' ) ) ?? BulkVariationPolicy::PreserveOverrides;
		$ids    = [];
		foreach ( (array) ( $data['selected_ids'] ?? [] ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		$skus = [];
		foreach ( (array) ( $data['skus'] ?? [] ) as $sku ) {
			$sku = trim( (string) $sku );
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		$filters = is_array( $data['filters'] ?? null ) ? $data['filters'] : [];
		$count   = (int) ( $data['selected_id_count'] ?? count( $ids ) );

		return new self(
			$scope,
			$ids,
			CatalogTargetFilters::sanitize( $filters ),
			$skus,
			$policy,
			(bool) ( $data['entire_catalog_confirmed'] ?? false ),
			(string) ( $data['target_type'] ?? self::TARGET_PRODUCT ),
			max( 0, $count ),
			(bool) ( $data['selected_ids_materialized'] ?? false )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'scope'                      => $this->scope->value,
			'selected_ids'               => $this->selected_ids,
			'filters'                    => $this->filters,
			'skus'                       => $this->skus,
			'variation_policy'           => $this->variation_policy->value,
			'entire_catalog_confirmed'   => $this->entire_catalog_confirmed,
			'target_type'                => $this->target_type,
			'selected_id_count'          => $this->selected_count(),
			'selected_ids_materialized'  => $this->selected_ids_materialized,
		];
	}

	public function selected_count(): int {
		if ( $this->selected_id_count > 0 ) {
			return $this->selected_id_count;
		}

		return count( $this->selected_ids );
	}

	/**
	 * Drop the ID array after durable job items exist. Worker ticks then load
	 * only compact metadata plus a claimed item page.
	 */
	public function after_materialization(): self {
		return new self(
			$this->scope,
			[],
			$this->filters,
			$this->skus,
			$this->variation_policy,
			$this->entire_catalog_confirmed,
			$this->target_type,
			$this->selected_count(),
			true
		);
	}

	/**
	 * Binary-search a sorted unique ID list. Cost is O(log n + page), not O(n).
	 *
	 * @param list<int> $sorted_ids
	 * @return list<int>
	 */
	public static function page_sorted_ids( array $sorted_ids, int $after_id, int $limit ): array {
		$limit = max( 1, $limit );
		$count = count( $sorted_ids );
		if ( 0 === $count ) {
			return [];
		}

		$low  = 0;
		$high = $count;
		while ( $low < $high ) {
			$mid = intdiv( $low + $high, 2 );
			if ( $sorted_ids[ $mid ] <= $after_id ) {
				$low = $mid + 1;
			} else {
				$high = $mid;
			}
		}

		return array_values( array_slice( $sorted_ids, $low, $limit ) );
	}

	public function requires_entire_catalog_confirmation(): bool {
		return BulkTargetScope::EntireCatalog === $this->scope && ! $this->entire_catalog_confirmed;
	}

	public function has_matching_criteria(): bool {
		if ( [] !== $this->skus ) {
			return true;
		}

		return [] !== $this->filters;
	}
}
