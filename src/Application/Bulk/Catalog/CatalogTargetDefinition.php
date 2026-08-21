<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;

/**
 * Frozen target definition. Products created after approval are excluded
 * unless entire_catalog was explicitly confirmed.
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
		public readonly string $target_type = self::TARGET_PRODUCT
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
		$skus = [];
		foreach ( (array) ( $data['skus'] ?? [] ) as $sku ) {
			$sku = trim( (string) $sku );
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}

		$filters = is_array( $data['filters'] ?? null ) ? $data['filters'] : [];

		return new self(
			$scope,
			array_values( array_unique( $ids ) ),
			CatalogTargetFilters::sanitize( $filters ),
			$skus,
			$policy,
			(bool) ( $data['entire_catalog_confirmed'] ?? false ),
			(string) ( $data['target_type'] ?? self::TARGET_PRODUCT )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'scope'                    => $this->scope->value,
			'selected_ids'             => $this->selected_ids,
			'filters'                  => $this->filters,
			'skus'                     => $this->skus,
			'variation_policy'         => $this->variation_policy->value,
			'entire_catalog_confirmed' => $this->entire_catalog_confirmed,
			'target_type'              => $this->target_type,
		];
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
