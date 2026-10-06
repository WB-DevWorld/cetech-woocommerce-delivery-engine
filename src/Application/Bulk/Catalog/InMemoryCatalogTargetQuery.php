<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;

final class InMemoryCatalogTargetQuery implements CatalogTargetQueryInterface {

	/**
	 * @param list<CatalogTarget>              $targets
	 * @param array<int, string>               $skus_by_id keyed by target id
	 * @param array<int, int>                  $variation_parents variation id => parent id
	 * @param array<int, array<string, mixed>> $attributes keyed by target id
	 */
	public function __construct(
		private array $targets = [],
		private array $skus_by_id = [],
		private array $variation_parents = [],
		private array $attributes = []
	) {
		foreach ( $this->targets as $target ) {
			$this->by_key[ $target->type . ':' . $target->id ] = $target;
		}
	}

	/** @var array<string, CatalogTarget> */
	private array $by_key = [];

	public function add( CatalogTarget $target, array $attributes = [] ): void {
		$this->targets[] = $target;
		if ( '' !== $target->external_key ) {
			$this->skus_by_id[ $target->id ] = $target->external_key;
		}
		if ( CatalogTargetDefinition::TARGET_VARIATION === $target->type && null !== $target->parent_id ) {
			$this->variation_parents[ $target->id ] = $target->parent_id;
		}
		if ( [] !== $attributes ) {
			$this->attributes[ $target->id ] = $attributes;
		}
		$this->by_key[ $target->type . ':' . $target->id ] = $target;
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function set_attributes( int $id, array $attributes ): void {
		$this->attributes[ $id ] = $attributes;
	}

	public function count( CatalogTargetDefinition $definition ): int {
		CatalogTargetFilters::assert_supported( $definition->filters );

		return count( $this->matching( $definition ) );
	}

	public function page_after( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		CatalogTargetFilters::assert_supported( $definition->filters );
		$matches = $this->matching( $definition );
		$page    = [];
		foreach ( $matches as $target ) {
			if ( $target->id <= $after_id ) {
				continue;
			}
			$page[] = $target;
			if ( count( $page ) >= $limit ) {
				break;
			}
		}

		return $page;
	}

	public function sku_for( string $target_type, int $target_id ): string {
		return $this->skus_by_id[ $target_id ] ?? '';
	}

	public function parent_product_id( int $variation_id ): ?int {
		return $this->variation_parents[ $variation_id ] ?? null;
	}

	public function find_id_by_sku( string $sku, string $target_type ): ?int {
		foreach ( $this->targets as $target ) {
			if ( $target->type === $target_type && $target->external_key === $sku ) {
				return $target->id;
			}
		}

		return null;
	}

	public function catalog_ceiling( CatalogTargetDefinition $definition ): int {
		CatalogTargetFilters::assert_supported( $definition->filters );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return [] === $definition->selected_ids ? 0 : max( $definition->selected_ids );
		}
		$max = 0;
		foreach ( $this->targets as $target ) {
			if ( $target->type === $definition->target_type ) {
				$max = max( $max, $target->id );
			}
		}

		return $max;
	}

	public function scan_page( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array {
		CatalogTargetFilters::assert_supported( $definition->filters );
		$limit = max( 1, $limit );
		$ids   = $this->candidate_ids( $definition, $after_id, $high_water );
		$slice = array_slice( $ids, 0, $limit );
		$accepted = [];
		$cursor   = $after_id;
		foreach ( $slice as $id ) {
			$cursor = $id;
			if ( 'accepted' !== $this->membership( $definition, $id ) ) {
				continue;
			}
			$target = $this->target_by_id( $definition->target_type, $id );
			if ( $target instanceof CatalogTarget ) {
				$accepted[] = $target;
			}
		}

		return CatalogTargetDefinition::candidate_page( $accepted, $cursor, count( $slice ), count( $ids ) <= $limit );
	}

	public function membership( CatalogTargetDefinition $definition, int $target_id ): string {
		CatalogTargetFilters::assert_supported( $definition->filters );
		$target = $this->target_by_id( $definition->target_type, $target_id );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			if ( ! $definition->selected_ids_materialized && ! in_array( $target_id, $definition->selected_ids, true ) ) {
				return 'rejected';
			}

			return $target instanceof CatalogTarget ? 'accepted' : 'unavailable';
		}
		if ( ! $target instanceof CatalogTarget ) {
			return 'unavailable';
		}

		return $this->passes_definition( $target, $definition ) ? 'accepted' : 'rejected';
	}

	private function target_by_id( string $type, int $id ): ?CatalogTarget {
		return $this->by_key[ $type . ':' . $id ] ?? null;
	}

	/**
	 * @return list<int>
	 */
	private function candidate_ids( CatalogTargetDefinition $definition, int $after_id, int $high_water ): array {
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			$ids = [];
			foreach ( $definition->selected_ids as $id ) {
				$id = (int) $id;
				if ( $id > $after_id && $id <= $high_water ) {
					$ids[] = $id;
				}
			}
			sort( $ids, SORT_NUMERIC );

			return $ids;
		}

		$ids = [];
		foreach ( $this->targets as $target ) {
			if ( $target->type !== $definition->target_type ) {
				continue;
			}
			if ( $target->id <= $after_id || $target->id > $high_water ) {
				continue;
			}
			$ids[] = $target->id;
		}
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * @return list<CatalogTarget>
	 */
	private function matching( CatalogTargetDefinition $definition ): array {
		$wanted_type = $definition->target_type;
		$matches     = [];
		foreach ( $this->targets as $target ) {
			if ( $target->type !== $wanted_type ) {
				continue;
			}
			if ( ! $this->passes_definition( $target, $definition ) ) {
				continue;
			}
			$matches[] = $target;
		}

		usort( $matches, static fn ( CatalogTarget $a, CatalogTarget $b ): int => $a->id <=> $b->id );

		return $matches;
	}

	private function passes_definition( CatalogTarget $target, CatalogTargetDefinition $definition ): bool {
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return in_array( $target->id, $definition->selected_ids, true );
		}

		if ( BulkTargetScope::MatchingFilters === $definition->scope && ! $definition->has_matching_criteria() ) {
			return false;
		}

		if ( [] !== $definition->skus && ! in_array( $target->external_key, $definition->skus, true ) ) {
			return false;
		}

		$attributes = $this->attributes[ $target->id ] ?? [];
		$attributes['sku']    = $target->external_key;
		$attributes['label']  = $target->label;
		if ( ! isset( $attributes['sku'] ) || '' === $attributes['sku'] ) {
			$attributes['sku'] = $target->external_key;
		}

		return CatalogFilterMatcher::matches( $attributes, $definition->filters );
	}
}
