<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
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
		private readonly SiteWideDefaultsPolicyInterface $policy,
		private readonly OperationalReadinessAssessor $readiness
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
	 *     customized: list<string>,
	 *     status: string,
	 *     type_label: string
	 * }>
	 */
	public function list( int $limit = 100, array $filters = [] ): array {
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

			if ( count( $items ) >= max( $limit * 5, 500 ) ) {
				break;
			}
		}

		$search     = strtolower( trim( (string) ( $filters['search'] ?? '' ) ) );
		$fulfilment = sanitize_key( (string) ( $filters['fulfilment'] ?? '' ) );
		$type       = sanitize_key( (string) ( $filters['type'] ?? '' ) );
		$status     = sanitize_key( (string) ( $filters['status'] ?? '' ) );

		$filtered = [];
		foreach ( $items as $item ) {
			if ( '' !== $search && ! str_contains( strtolower( (string) $item['label'] ), $search ) ) {
				continue;
			}
			if ( '' !== $fulfilment && sanitize_key( (string) $item['fulfilment_key'] ) !== $fulfilment ) {
				continue;
			}
			if ( '' !== $type && (string) $item['type'] !== $type ) {
				continue;
			}
			if ( 'ready' === $status && 'Ready' !== $item['status'] ) {
				continue;
			}
			if ( 'needs_attention' === $status && 'Needs Attention' !== $item['status'] ) {
				continue;
			}
			$filtered[] = $item;
			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		return $filtered;
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
			: $this->variation_display_label( $id, $parent_id );
		$readiness = 'variation' === $type
			? $this->readiness->assess( (int) $parent_id, $id, $scope->scope->slice_key )
			: $this->readiness->assess( $id, null, $scope->scope->slice_key );

		return [
			'id'              => $id,
			'type'            => $type,
			'parent_id'       => $parent_id,
			'label'           => $label,
			'url'             => $this->catalog->product_edit_url( $parent_id ?? $id ),
			'fulfilment'      => $profile?->label ?? 'Site-wide default',
			'fulfilment_key'  => $profile_key,
			'type_label'      => 'variation' === $type ? 'Variation-specific' : 'Product-specific',
			'currently_using' => 'variation' === $type
				? __( 'Product Settings', 'cetech-woocommerce-delivery-engine' )
				: __( 'Product-specific delivery settings', 'cetech-woocommerce-delivery-engine' ),
			'customized'      => $customized,
			'status'          => $readiness->status_label,
		];
	}

	private function variation_display_label( int $variation_id, ?int $parent_id ): string {
		$variation = $this->catalog->variation_label( $variation_id );
		$parent    = null !== $parent_id ? $this->catalog->product_label( $parent_id ) : '';
		if ( '' === $parent ) {
			return $variation . ' variation';
		}

		if ( str_contains( strtolower( $variation ), strtolower( $parent ) ) ) {
			return rtrim( $variation ) . ' variation';
		}

		return $parent . ' — ' . $variation . ' variation';
	}

	/**
	 * @return list<string>
	 */
	private function customized_labels( \CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration $scope ): array {
		$labels      = [];
		$has_private = false;
		$business    = ConfigurationFieldCatalog::business_field_keys();
		$private     = ConfigurationFieldCatalog::private_field_keys();

		foreach ( $scope->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			if ( in_array( $field_key, $private, true ) ) {
				$has_private = true;
				continue;
			}
			if ( in_array( $field_key, $business, true ) ) {
				$labels[] = ConfigurationFieldCatalog::label( $field_key );
			}
		}

		foreach ( $scope->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			if ( in_array( $field_key, $private, true ) ) {
				$has_private = true;
				continue;
			}
			if ( in_array( $field_key, $business, true ) ) {
				$labels[] = ConfigurationFieldCatalog::label( $field_key );
			}
		}

		if ( $has_private ) {
			$labels[] = ConfigurationFieldCatalog::technical_delivery_details_label();
		}

		return $labels;
	}
}
