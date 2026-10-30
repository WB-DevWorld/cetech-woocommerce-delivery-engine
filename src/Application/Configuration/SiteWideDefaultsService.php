<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogIndexInterface;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritancePreview;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Saves/activates site-wide fulfilment defaults without copying values onto products.
 */
final class SiteWideDefaultsService {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly SiteWideDefaultsSettings $settings,
		private readonly CatalogInheritanceClassifier $classifier,
		private readonly EffectiveConfigurationResolver $resolver,
		private readonly CatalogIndexInterface $catalog
	) {
	}

	public function ensure_profile_scope( string $profile_key ): ScopedConfiguration {
		$profile = $this->require_profile( $profile_key );
		$existing = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile->key
		);

		if ( $existing instanceof ScopedConfiguration ) {
			return $existing;
		}

		return $this->scopes->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::profileDefault( $profile->key ) )
		);
	}

	/**
	 * @param array<string, array{mode?: string, value?: mixed, members?: list<mixed>}> $raw_fields
	 * @param bool $allow_incomplete True only for an intermediate save inside the setup wizard, whose own step submission completes the profile.
	 */
	public function save_profile_defaults( string $profile_key, array $raw_fields, bool $allow_incomplete = false ): ScopedConfiguration {
		$profile = $this->require_profile( $profile_key );
		$existing = $this->ensure_profile_scope( $profile->key );

		$scalars     = $existing->scalars;
		$collections = $existing->collections;

		foreach ( ConfigurationFieldRegistry::all() as $field_key => $definition ) {
			$payload = $raw_fields[ $field_key ] ?? null;
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$mode = sanitize_key( (string) ( $payload['mode'] ?? 'override' ) );

			if ( $definition->is_collection ) {
				$members = isset( $payload['members'] ) && is_array( $payload['members'] ) ? $payload['members'] : [];
				$collections[ $field_key ] = CollectionFieldInstruction::create(
					$field_key,
					CollectionConfigurationMode::from( $mode ),
					$members
				);
				continue;
			}

			if ( 'disable' === $mode && $definition->allows_disable ) {
				$scalars[ $field_key ] = ScalarFieldInstruction::disable( $field_key );
				continue;
			}

			$value = $payload['value'] ?? null;
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			if ( null === $value || '' === $value ) {
				if ( $definition->is_optional ) {
					unset( $scalars[ $field_key ] );
					continue;
				}

				// Same rule as the product form and the CSV import: a blank override is not inherit.
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %s: configuration field key */
						__( 'Override for %s requires a value. Blank does not mean inherit.', 'cetech-woocommerce-delivery-engine' ),
						$field_key
					)
				);
			}

			$scalars[ $field_key ] = ScalarFieldInstruction::override( $field_key, $value );
		}

		$scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ] = ScalarFieldInstruction::override(
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			$profile->availability
		);

		if ( ! $profile->pickup_allowed ) {
			$scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ] = ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_CHOICE,
				FulfilmentChoice::Delivery->value
			);
			if ( isset( $scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] ) ) {
				$scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = ScalarFieldInstruction::disable(
					ConfigurationFieldKey::PICKUP_LOCATION_ID
				);
			}
		}

		if ( ! $allow_incomplete ) {
			$this->assert_delivery_defaults_complete( $profile, $scalars, $collections );
		}

		$saved = $this->scopes->saveScopedConfiguration(
			new ScopedConfiguration(
				$existing->scope,
				$scalars,
				$collections
			)
		);

		$this->resolver->clearMemoization();

		return $saved;
	}

	/**
	 * Site-wide defaults are the root of inheritance, so a blank here is not
	 * "inherit": a profile that offers Delivery must keep at least one Delivery
	 * Option and an estimate, otherwise every inheriting product loses its
	 * delivery option the moment the defaults save. A pickup-only store profile
	 * (no option, a pickup location) is complete without either.
	 *
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function assert_delivery_defaults_complete( FulfilmentProfile $profile, array $scalars, array $collections ): void {
		$offers       = $collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;
		$no_options   = $offers instanceof CollectionFieldInstruction && CollectionConfigurationMode::Replace === $offers->mode && [] === $offers->members;
		$pickup       = $scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] ?? null;
		$pickup_only  = $profile->pickup_allowed && $no_options && $pickup instanceof ScalarFieldInstruction && ScalarConfigurationMode::Override === $pickup->mode;
		$estimate     = $scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ] ?? null;
		$has_estimate = $estimate instanceof ScalarFieldInstruction && ScalarConfigurationMode::Override === $estimate->mode;

		if ( $pickup_only ) {
			return;
		}

		$errors = [];
		if ( $no_options ) {
			$errors[] = __( 'Select at least one Delivery Option. Products inheriting these defaults would otherwise have no delivery option.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( ! $has_estimate ) {
			$errors[] = sprintf(
				/* translators: %s: configuration field key */
				__( 'Override for %s requires a value. Blank does not mean inherit.', 'cetech-woocommerce-delivery-engine' ),
				ConfigurationFieldKey::ESTIMATED_DELIVERY
			);
		}

		if ( [] !== $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) );
		}
	}

	/**
	 * Activates the site-wide policy. Does not copy defaults onto products.
	 *
	 * Optionally converts migrated OVERRIDE fields that already match the
	 * profile default into INHERIT so later global edits flow through.
	 *
	 * @param list<string> $active_profiles
	 *
	 * @return array{preview: CatalogInheritancePreview, converted_products: int, skipped_exceptions: int, skipped_legacy: int, skipped_review: int}
	 */
	public function apply_site_wide(
		array $active_profiles,
		string $primary_profile,
		bool $convert_matching_overrides = true
	): array {
		$active = [];
		foreach ( $active_profiles as $key ) {
			$key = sanitize_key( (string) $key );
			if ( FulfilmentProfileRegistry::has( $key ) ) {
				$active[] = $key;
				$this->ensure_profile_scope( $key );
			}
		}

		$primary = sanitize_key( $primary_profile );
		if ( ! in_array( $primary, $active, true ) ) {
			throw new \InvalidArgumentException( 'Primary default fulfilment must be one of the active fulfilment types.' );
		}

		$this->settings->save(
			[
				'setup_completed' => true,
				'active_profiles' => $active,
				'primary_profile' => $primary,
				'applied_at'      => gmdate( 'c' ),
			]
		);

		$preview = $this->classifier->preview();

		$converted          = 0;
		$skipped_exceptions = $preview->product_exceptions;
		$skipped_legacy     = $preview->legacy_dependent;
		$skipped_review     = $preview->needs_review;

		if ( $convert_matching_overrides ) {
			$converted = $this->convert_matching_from_catalog_scan();
			$preview   = $this->classifier->preview();
		}

		$this->resolver->clearMemoization();

		return [
			'preview'            => $preview,
			'converted_products' => $converted,
			'skipped_exceptions' => $skipped_exceptions,
			'skipped_legacy'     => $skipped_legacy,
			'skipped_review'     => $skipped_review,
		];
	}

	public function preview(): CatalogInheritancePreview {
		return $this->classifier->preview();
	}

	public function reset_product_to_site_wide( int $product_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		$deleted = $this->scopes->deleteScope( ConfigurationScopeType::Product, $product_id, $slice_key );
		$this->resolver->clearMemoization();

		return $deleted;
	}

	public function reset_variation_to_product( int $variation_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		if ( $variation_id <= 0 ) {
			return false;
		}

		$deleted = $this->scopes->deleteScope( ConfigurationScopeType::Variation, $variation_id, $slice_key );
		$this->resolver->clearMemoization();

		return $deleted;
	}

	/**
	 * Classify a product to a fulfilment profile without copying default values.
	 */
	public function classify_product( int $product_id, ?string $profile_key ): ScopedConfiguration|bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( null === $profile_key || '' === $profile_key || $profile_key === $this->settings->primary_profile_key() ) {
			foreach ( $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id ) as $scope ) {
				if ( ! $this->classifier->scope_has_custom_fields( $scope ) ) {
					$this->scopes->deleteScope( ConfigurationScopeType::Product, $product_id, $scope->scope->slice_key );
				}
			}
			$this->resolver->clearMemoization();

			return true;
		}

		$profile = $this->require_profile( $profile_key );
		$existing = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Product,
			$product_id,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);

		$scalars = $existing instanceof ScopedConfiguration ? $existing->scalars : [];
		$collections = $existing instanceof ScopedConfiguration ? $existing->collections : [];
		$scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ] = ScalarFieldInstruction::override(
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			$profile->availability
		);

		$scope = $existing instanceof ScopedConfiguration
			? $existing->scope
			: new ConfigurationScope(
				null,
				ConfigurationScopeType::Product,
				$product_id,
				ConfigurationScope::DEFAULT_SLICE_KEY,
				null,
				\CetechDeliveryEngine\Domain\Enum\RecordStatus::Active,
				1,
				\CetechDeliveryEngine\Domain\Enum\ConfigurationSource::Native,
				null
			);

		$saved = $this->scopes->saveScopedConfiguration(
			new ScopedConfiguration( $scope, $scalars, $collections )
		);
		$this->resolver->clearMemoization();

		return $saved;
	}

	public function profile( string $profile_key ): FulfilmentProfile {
		return $this->require_profile( $profile_key );
	}

	private function convert_matching_from_catalog_scan(): int {
		$converted = 0;

		foreach ( $this->catalog->published_product_ids( 0, 5000 ) as $product_id ) {
			$scopes = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
			$bucket = $this->classifier->classify_product( $product_id, $scopes, [] );
			if ( 'inherit' !== $bucket['bucket'] || 1 !== count( $scopes ) ) {
				continue;
			}

			$scope    = $scopes[0];
			$matching = $this->classifier->matching_field_keys( $scope );
			if ( [] === $matching ) {
				continue;
			}

			$scalars     = $scope->scalars;
			$collections = $scope->collections;

			foreach ( $matching as $field_key ) {
				if ( isset( $scalars[ $field_key ] ) ) {
					$scalars[ $field_key ] = ScalarFieldInstruction::inherit( $field_key );
				}
				if ( isset( $collections[ $field_key ] ) ) {
					$collections[ $field_key ] = CollectionFieldInstruction::inherit( $field_key );
				}
			}

			$this->scopes->saveScopedConfiguration(
				new ScopedConfiguration( $scope->scope, $scalars, $collections )
			);
			++$converted;
		}

		return $converted;
	}

	private function require_profile( string $profile_key ): FulfilmentProfile {
		$profile = FulfilmentProfileRegistry::get( sanitize_key( $profile_key ) );
		if ( ! $profile instanceof FulfilmentProfile ) {
			throw new \InvalidArgumentException( 'Unknown fulfilment type.' );
		}

		return $profile;
	}
}
