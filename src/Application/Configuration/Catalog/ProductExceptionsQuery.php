<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsPolicyInterface;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Products and variations that differ from site-wide defaults.
 */
final class ProductExceptionsQuery {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly CatalogIndexInterface $catalog,
		private readonly CatalogInheritanceClassifier $classifier,
		private readonly SiteWideDefaultsPolicyInterface $policy
	) {
	}

	/**
	 * @return list<array{
	 *     id: int,
	 *     type: string,
	 *     parent_id: int|null,
	 *     label: string,
	 *     url: string,
	 *     fulfilment: string,
	 *     currently_using: string,
	 *     customized: list<string>
	 * }>
	 */
	public function list( int $limit = 100 ): array {
		$items = [];

		foreach ( $this->catalog->published_product_ids( 0, 5000 ) as $product_id ) {
			$product_scopes = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
			if ( $this->classifier->has_custom_fields( $product_scopes ) && ! $this->all_match_defaults( $product_scopes ) ) {
				$scope    = $product_scopes[0];
				$items[]  = $this->map_item(
					$product_id,
					'product',
					null,
					$scope,
					$this->customized_labels( $scope )
				);
			}

			foreach ( $this->catalog->variation_ids( $product_id ) as $variation_id ) {
				$variation_scopes = $this->scopes->findByScope( ConfigurationScopeType::Variation, $variation_id );
				if ( ! $this->classifier->has_custom_fields( $variation_scopes ) ) {
					continue;
				}

				$scope   = $variation_scopes[0];
				$items[] = $this->map_item(
					$variation_id,
					'variation',
					$product_id,
					$scope,
					$this->customized_labels( $scope )
				);
			}

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * @param list<\CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration> $scopes
	 */
	private function all_match_defaults( array $scopes ): bool {
		if ( 1 !== count( $scopes ) ) {
			return false;
		}

		$matching = $this->classifier->matching_field_keys( $scopes[0] );
		$custom   = 0;

		foreach ( $scopes[0]->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode || ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
				continue;
			}
			++$custom;
			if ( ! in_array( $field_key, $matching, true ) ) {
				return false;
			}
		}

		foreach ( $scopes[0]->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			++$custom;
			if ( ! in_array( $field_key, $matching, true ) ) {
				return false;
			}
		}

		return $custom > 0;
	}

	/**
	 * @param list<string> $customized
	 *
	 * @return array<string, mixed>
	 */
	private function map_item(
		int $id,
		string $type,
		?int $parent_id,
		\CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration $scope,
		array $customized
	): array {
		$profile_key = $scope->scope->slice_key;
		$instruction = $scope->scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ] ?? null;
		if ( null !== $instruction && ScalarConfigurationMode::Override === $instruction->mode && is_string( $instruction->value ) ) {
			$profile_key = $instruction->value;
		}
		if ( ! FulfilmentProfileRegistry::has( $profile_key ) ) {
			$profile_key = (string) $this->policy->primary_profile_key();
		}

		$profile = FulfilmentProfileRegistry::get( $profile_key );
		$label   = 'product' === $type
			? $this->catalog->product_label( $id )
			: $this->catalog->variation_label( $id );

		return [
			'id'              => $id,
			'type'            => $type,
			'parent_id'       => $parent_id,
			'label'           => $label,
			'url'             => $this->catalog->product_edit_url( $parent_id ?? $id ),
			'fulfilment'      => $profile?->label ?? 'Site-wide default',
			'currently_using' => $profile instanceof \CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile
				? $profile->label . ' with product-specific settings'
				: 'Product-specific settings',
			'customized'      => $customized,
		];
	}

	/**
	 * @return list<string>
	 */
	private function customized_labels( \CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration $scope ): array {
		$labels = [];

		foreach ( $scope->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
				continue;
			}
			$labels[] = ConfigurationFieldCatalog::label( $field_key );
		}

		foreach ( $scope->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			$labels[] = ConfigurationFieldCatalog::label( $field_key );
		}

		return $labels;
	}
}
