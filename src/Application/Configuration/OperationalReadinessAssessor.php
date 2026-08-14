<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

/**
 * Single authoritative assessment of whether a product/variation can offer delivery.
 *
 * Needs Attention and Delivery Preview must both use this result. Do not
 * reimplement readiness in presentation classes.
 */
final class OperationalReadinessAssessor {

	public function __construct(
		private readonly EffectiveConfigurationResolver $resolver
	) {
	}

	public function assess( int $product_id, ?int $variation_id = null, ?string $slice_key = null ): OperationalReadiness {
		$set = $this->resolver->resolveAll( $product_id, $variation_id );

		// null = catalog scan across discovered slices (ready if any operational slice is ready).
		// Explicit slice key — including DEFAULT_SLICE_KEY '' — assesses that slice, with
		// resolver bridging so Stage 6 profile scopes remain visible on the Stage 13 root.
		if ( null !== $slice_key ) {
			$configuration = $set->for_slice( $slice_key );
			if ( null === $configuration ) {
				$configuration = $this->resolver->resolve(
					new EffectiveConfigurationRequest(
						$product_id,
						$variation_id,
						$slice_key,
						null !== $variation_id ? $product_id : null
					)
				);
			}

			$reason = $this->reason_for_configuration( $configuration );

			return null === $reason
				? OperationalReadiness::ready()
				: OperationalReadiness::needs_attention( $reason );
		}

		$first_reason = null;
		$any_ready    = false;

		foreach ( $set->ordered_slice_keys as $key ) {
			$configuration = $set->for_slice( $key );
			if ( null === $configuration ) {
				continue;
			}

			$reason = $this->reason_for_configuration( $configuration );
			if ( null === $reason ) {
				$any_ready = true;
				break;
			}
			$first_reason ??= $reason;
		}

		if ( $any_ready ) {
			return OperationalReadiness::ready();
		}

		if ( null !== $first_reason ) {
			return OperationalReadiness::needs_attention( $first_reason );
		}

		// No discovered slices resolved — resolve the native root with Stage 6 bridging.
		$fallback = $this->resolver->resolve(
			new EffectiveConfigurationRequest( $product_id, $variation_id, '' )
		);
		$reason = $this->reason_for_configuration( $fallback );

		return null === $reason
			? OperationalReadiness::ready()
			: OperationalReadiness::needs_attention( $reason );
	}

	public function reason_for( int $product_id, ?int $variation_id = null, ?string $slice_key = null ): ?string {
		return $this->assess( $product_id, $variation_id, $slice_key )->reason;
	}

	public function assess_field_for( int $product_id, ?int $variation_id, string $field_key, ?string $slice_key = null ): OperationalReadiness {
		$resolved = $this->resolver->resolve(
			new EffectiveConfigurationRequest(
				$product_id,
				$variation_id,
				$slice_key ?? '',
				null !== $variation_id ? $product_id : null
			)
		);

		return $this->assess_field( $resolved, $field_key );
	}

	/**
	 * Field-level readiness used by Preview. Shares the same cause as Needs Attention.
	 */
	public function assess_field(
		\CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration $configuration,
		string $field_key
	): OperationalReadiness {
		if ( ConfigurationFieldKey::DELIVERY_OFFER_IDS === $field_key ) {
			return $this->offer_field_readiness( $configuration );
		}

		if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ) {
			$availability = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
			if ( null === $availability || EffectiveFieldState::Valid !== $availability->state ) {
				return OperationalReadiness::needs_attention( 'Fulfilment type is incomplete.' );
			}

			return OperationalReadiness::ready();
		}

		$overall = $this->reason_for_configuration( $configuration );
		if ( null !== $overall && ConfigurationFieldKey::FULFILMENT_CHOICE === $field_key && str_contains( $overall, 'not allowed' ) ) {
			return OperationalReadiness::needs_attention( $overall );
		}

		$field = $configuration->scalar( $field_key ) ?? $configuration->collection( $field_key );
		if ( null === $field ) {
			return OperationalReadiness::ready();
		}
		if ( EffectiveFieldState::Invalid === $field->state ) {
			return OperationalReadiness::needs_attention( 'This product has a fulfilment combination that is not allowed.' );
		}
		if ( EffectiveFieldState::Unresolved === $field->state ) {
			return OperationalReadiness::needs_attention( 'Required delivery settings are still missing.' );
		}

		return OperationalReadiness::ready();
	}

	private function reason_for_configuration( \CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration $configuration ): ?string {
		if ( EffectiveFieldState::Invalid === $configuration->state ) {
			return 'This product has a fulfilment combination that is not allowed.';
		}

		$availability = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		if ( null === $availability || EffectiveFieldState::Valid !== $availability->state ) {
			return 'Fulfilment type is incomplete.';
		}

		$offer_reason = $this->offer_field_readiness( $configuration )->reason;
		if ( null !== $offer_reason ) {
			return $offer_reason;
		}

		$choice = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE );
		if ( null !== $choice && EffectiveFieldState::Invalid === $choice->state ) {
			return 'This product has a fulfilment combination that is not allowed.';
		}
		if ( null === $choice || EffectiveFieldState::Unresolved === $choice->state ) {
			return 'Required delivery settings are still missing.';
		}

		// Private/technical unresolved fields (supplier, origin, logistics, priority)
		// must not create a false "no usable delivery option" finding when business
		// delivery fields are already valid.

		return null;
	}

	private function offer_field_readiness(
		\CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration $configuration
	): OperationalReadiness {
		$offers = $configuration->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		if ( null === $offers || EffectiveFieldState::Unresolved === $offers->state ) {
			return OperationalReadiness::needs_attention( 'Delivery options are still missing.' );
		}
		if ( EffectiveFieldState::Invalid === $offers->state ) {
			return OperationalReadiness::needs_attention( 'Delivery options could not be applied with the current fulfilment settings.' );
		}
		if ( EffectiveFieldState::Valid !== $offers->state || [] === $offers->members ) {
			return OperationalReadiness::needs_attention( 'No usable delivery option is configured.' );
		}

		return OperationalReadiness::ready();
	}
}
