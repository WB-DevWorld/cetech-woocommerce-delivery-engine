<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * Re-resolves live pre-order public copy from canonical DE rows + current WPML language.
 *
 * Cart session may store source or previously presented text. Overlay always uses
 * canonical stored values as WPML original_value so language switches stay consistent
 * across PDP, cart, Classic checkout, and Blocks/Store API.
 *
 * Does not mutate cart identity, hashes, quotes, or canonical DE tables.
 * Historical order snapshots must not use this overlay when reading persisted text.
 */
final class WpmlLivePublicCopyPresenter {

	public function __construct(
		private WpmlPublicCopyCatalog $catalog,
		private DeliveryOfferRepositoryInterface $offers,
		private PickupLocationRepositoryInterface $pickups
	) {
	}

	/**
	 * @param array<string, string|null> $summary
	 * @param array<string, mixed>|null  $intent
	 *
	 * @return array<string, string|null>
	 */
	public function localize_summary( array $summary, ?array $intent ): array {
		$choice   = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) : '';
		$offer_id = is_array( $intent ) && isset( $intent['delivery_offer_id'] )
			? (int) $intent['delivery_offer_id']
			: 0;

		if ( $offer_id > 0 && FulfilmentChoice::StorePickup->value !== $choice ) {
			$offer = $this->offers->findById( $offer_id );

			if ( is_array( $offer ) ) {
				$source_label = trim( (string) ( $offer['public_label'] ?? '' ) );

				if ( '' !== $source_label ) {
					$summary['delivery_offer_public_label'] = $this->catalog->translate_delivery_offer_label(
						$offer_id,
						$source_label
					);
				}
			}
		}

		$pickup_id = isset( $summary['pickup_location_id'] ) ? (int) $summary['pickup_location_id'] : 0;

		if ( $pickup_id > 0 ) {
			$location = $this->pickups->findById( $pickup_id );

			if ( is_array( $location ) ) {
				$name = trim( (string) ( $location['location_name'] ?? '' ) );

				if ( '' !== $name ) {
					$summary['pickup_location_label'] = $this->catalog->translate_pickup_location_name(
						$pickup_id,
						$name
					);
				}

				$instructions = trim( (string) ( $location['public_pickup_instructions'] ?? '' ) );
				$summary['pickup_instructions'] = '' !== $instructions
					? $this->catalog->translate_pickup_instructions( $pickup_id, $instructions )
					: null;

				$readiness = trim( (string) ( $location['readiness_estimate'] ?? '' ) );

				if ( '' !== $readiness ) {
					$summary['estimate_text'] = $this->catalog->translate_pickup_readiness(
						$pickup_id,
						$readiness
					);
				}
			}
		}

		return $summary;
	}

	public function translate_offer_description( int $offer_id, string $source ): string {
		return $this->catalog->translate_delivery_offer_description( $offer_id, $source );
	}
}
