<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsPolicyInterface;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;

/**
 * Classifies the live catalog for site-wide default application. Does not write.
 */
final class CatalogInheritanceClassifier {

	private const EXAMPLE_LIMIT = 8;

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly CatalogIndexInterface $catalog,
		private readonly EffectiveConfigurationResolver $resolver,
		private readonly SiteWideDefaultsPolicyInterface $policy,
		private readonly ?ProductDeliveryRuleRepositoryInterface $legacy_rules = null
	) {
	}

	public function preview(): CatalogInheritancePreview {
		$product_ids = $this->catalog->published_product_ids( 0, 5000 );
		$published   = $this->catalog->count_published_products();

		$can_inherit            = 0;
		$product_exceptions     = 0;
		$variation_exceptions   = 0;
		$legacy_dependent       = 0;
		$needs_review           = 0;
		$needs_attention        = 0;
		$inherit_examples       = [];
		$product_examples       = [];
		$variation_examples     = [];
		$legacy_examples        = [];
		$review_examples        = [];
		$seen_variation_parents = [];

		foreach ( $product_ids as $product_id ) {
			$product_scopes = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
			$legacy_rows    = $this->legacy_rows_for( ProductTargetType::Product->value, $product_id );
			$bucket         = $this->classify_product( $product_id, $product_scopes, $legacy_rows );

			if ( 'legacy' === $bucket['bucket'] ) {
				++$legacy_dependent;
				$this->push_example( $legacy_examples, $product_id, $bucket['reason'] );
			} elseif ( 'review' === $bucket['bucket'] ) {
				++$needs_review;
				$this->push_example( $review_examples, $product_id, $bucket['reason'] );
			} elseif ( 'exception' === $bucket['bucket'] ) {
				++$product_exceptions;
				$this->push_example( $product_examples, $product_id, $bucket['reason'] );
			} else {
				++$can_inherit;
				$this->push_example( $inherit_examples, $product_id, $bucket['reason'] );
			}

			if ( $this->product_needs_attention( $product_id ) ) {
				++$needs_attention;
			}

			if ( ! $this->catalog->is_variable( $product_id ) ) {
				continue;
			}

			foreach ( $this->catalog->variation_ids( $product_id ) as $variation_id ) {
				$variation_scopes = $this->scopes->findByScope( ConfigurationScopeType::Variation, $variation_id );
				if ( $this->has_custom_fields( $variation_scopes ) ) {
					++$variation_exceptions;
					if ( ! isset( $seen_variation_parents[ $product_id ] ) ) {
						$seen_variation_parents[ $product_id ] = true;
						$this->push_example(
							$variation_examples,
							$variation_id,
							'This variation has its own delivery settings.'
						);
					}
				}
			}
		}

		if ( 0 === $published && [] === $product_ids ) {
			$published = count( $product_ids );
		}

		return new CatalogInheritancePreview(
			$published > 0 ? $published : count( $product_ids ),
			$can_inherit,
			$product_exceptions,
			$variation_exceptions,
			$legacy_dependent,
			$needs_review,
			$needs_attention,
			$inherit_examples,
			$product_examples,
			$variation_examples,
			$legacy_examples,
			$review_examples
		);
	}

	/**
	 * @param list<ScopedConfiguration> $product_scopes
	 * @param list<array<string, mixed>> $legacy_rows
	 *
	 * @return array{bucket: string, reason: string}
	 */
	public function classify_product( int $product_id, array $product_scopes, array $legacy_rows = [] ): array {
		if ( count( $product_scopes ) > 1 ) {
			return [
				'bucket' => 'review',
				'reason' => 'This product has more than one saved delivery setup and needs review before site-wide defaults are applied.',
			];
		}

		$has_legacy_only = [] !== $legacy_rows && [] === $product_scopes;
		if ( $has_legacy_only ) {
			return [
				'bucket' => 'legacy',
				'reason' => 'This product still uses Legacy Delivery Rules.',
			];
		}

		if ( [] !== $legacy_rows && $this->has_custom_fields( $product_scopes ) ) {
			return [
				'bucket' => 'review',
				'reason' => 'This product has both Legacy Delivery Rules and newer delivery settings.',
			];
		}

		if ( [] !== $legacy_rows ) {
			return [
				'bucket' => 'legacy',
				'reason' => 'This product still uses Legacy Delivery Rules.',
			];
		}

		if ( $this->has_custom_fields( $product_scopes ) ) {
			$matching = $this->custom_fields_match_profile_defaults( $product_scopes );
			if ( $matching ) {
				return [
					'bucket' => 'inherit',
					'reason' => 'Saved values match the site-wide default and can inherit it.',
				];
			}

			return [
				'bucket' => 'exception',
				'reason' => 'This product has product-specific delivery settings.',
			];
		}

		return [
			'bucket' => 'inherit',
			'reason' => 'This product has no product-specific delivery settings.',
		];
	}

	/**
	 * @param list<ScopedConfiguration> $scopes
	 */
	public function has_custom_fields( array $scopes ): bool {
		foreach ( $scopes as $scope ) {
			if ( $this->scope_has_custom_fields( $scope ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fields that can be converted to INHERIT because they duplicate the profile default.
	 *
	 * @return list<string>
	 */
	public function matching_field_keys( ScopedConfiguration $product_scope ): array {
		$profile_key = $this->profile_key_for_scope( $product_scope );
		if ( null === $profile_key ) {
			return [];
		}

		$profile = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile_key
		);

		if ( ! $profile instanceof ScopedConfiguration ) {
			return [];
		}

		$matching = [];

		foreach ( $product_scope->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}

			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
				continue;
			}

			$profile_instruction = $profile->scalars[ $field_key ] ?? null;
			if ( $this->scalar_matches( $instruction, $profile_instruction ) ) {
				$matching[] = $field_key;
			}
		}

		foreach ( $product_scope->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}

			$profile_instruction = $profile->collections[ $field_key ] ?? null;
			if ( $this->collection_matches( $instruction, $profile_instruction ) ) {
				$matching[] = $field_key;
			}
		}

		return $matching;
	}

	public function scope_has_custom_fields( ScopedConfiguration $scope ): bool {
		foreach ( $scope->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}

			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
				continue;
			}

			return true;
		}

		foreach ( $scope->collections as $instruction ) {
			if ( CollectionConfigurationMode::Inherit !== $instruction->mode ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<ScopedConfiguration> $scopes
	 */
	private function custom_fields_match_profile_defaults( array $scopes ): bool {
		if ( 1 !== count( $scopes ) ) {
			return false;
		}

		$scope     = $scopes[0];
		$custom    = 0;
		$matching  = $this->matching_field_keys( $scope );

		foreach ( $scope->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}
			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
				continue;
			}
			++$custom;
			if ( ! in_array( $field_key, $matching, true ) ) {
				return false;
			}
		}

		foreach ( $scope->collections as $field_key => $instruction ) {
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

	private function scalar_matches( ScalarFieldInstruction $product, ?ScalarFieldInstruction $profile ): bool {
		if ( ! $profile instanceof ScalarFieldInstruction ) {
			return false;
		}

		return $product->mode === $profile->mode && $product->value === $profile->value;
	}

	private function collection_matches( CollectionFieldInstruction $product, ?CollectionFieldInstruction $profile ): bool {
		if ( ! $profile instanceof CollectionFieldInstruction ) {
			return false;
		}

		if ( CollectionConfigurationMode::Replace !== $product->mode || CollectionConfigurationMode::Replace !== $profile->mode ) {
			return false;
		}

		return $product->members === $profile->members;
	}

	private function profile_key_for_scope( ScopedConfiguration $scope ): ?string {
		$instruction = $scope->scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ] ?? null;
		if (
			$instruction instanceof ScalarFieldInstruction
			&& ScalarConfigurationMode::Override === $instruction->mode
			&& is_string( $instruction->value )
			&& FulfilmentProfileRegistry::has( $instruction->value )
		) {
			return $instruction->value;
		}

		if ( FulfilmentProfileRegistry::has( $scope->scope->slice_key ) ) {
			return $scope->scope->slice_key;
		}

		return $this->policy->primary_profile_key();
	}

	private function product_needs_attention( int $product_id ): bool {
		$set = $this->resolver->resolveAll( $product_id, null );

		foreach ( $set->ordered_slice_keys as $slice_key ) {
			$configuration = $set->for_slice( $slice_key );
			if ( null === $configuration ) {
				continue;
			}

			if ( in_array( $configuration->state, [ EffectiveFieldState::Invalid, EffectiveFieldState::Unresolved ], true ) ) {
				return true;
			}

			$offers = $configuration->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
			if ( null === $offers || EffectiveFieldState::Valid !== $offers->state || [] === $offers->members ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function legacy_rows_for( string $target_type, int $target_id ): array {
		if ( null === $this->legacy_rules ) {
			return [];
		}

		return $this->legacy_rules->findByTarget( $target_type, $target_id );
	}

	/**
	 * @param list<array{id: int, label: string, reason: string}> $examples
	 */
	private function push_example( array &$examples, int $id, string $reason ): void {
		if ( count( $examples ) >= self::EXAMPLE_LIMIT ) {
			return;
		}

		$examples[] = [
			'id'     => $id,
			'label'  => $this->catalog->product_label( $id ),
			'reason' => $reason,
		];
	}
}
