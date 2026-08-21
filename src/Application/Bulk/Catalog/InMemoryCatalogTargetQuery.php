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
	}

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
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function set_attributes( int $id, array $attributes ): void {
		$this->attributes[ $id ] = $attributes;
	}

	public function count( CatalogTargetDefinition $definition ): int {
		return count( $this->matching( $definition ) );
	}

	public function page_after( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
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
