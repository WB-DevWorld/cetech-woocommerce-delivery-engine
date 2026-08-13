<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

/**
 * Products whose effective delivery setup is incomplete for staff follow-up.
 */
final class NeedsAttentionQuery {

	public function __construct(
		private readonly CatalogIndexInterface $catalog,
		private readonly EffectiveConfigurationResolver $resolver
	) {
	}

	/**
	 * @return list<array{id: int, label: string, url: string, reason: string}>
	 */
	public function list( int $limit = 100 ): array {
		$items = [];

		foreach ( $this->catalog->published_product_ids( 0, 5000 ) as $product_id ) {
			$reason = $this->reason_for( $product_id );
			if ( null === $reason ) {
				continue;
			}

			$items[] = [
				'id'     => $product_id,
				'label'  => $this->catalog->product_label( $product_id ),
				'url'    => $this->catalog->product_edit_url( $product_id ),
				'reason' => $reason,
			];

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	public function count(): int {
		return count( $this->list( 5000 ) );
	}

	public function reason_for( int $product_id ): ?string {
		$set = $this->resolver->resolveAll( $product_id, null );

		foreach ( $set->ordered_slice_keys as $slice_key ) {
			$configuration = $set->for_slice( $slice_key );
			if ( null === $configuration ) {
				continue;
			}

			if ( EffectiveFieldState::Invalid === $configuration->state ) {
				return 'This product has a fulfilment combination that is not allowed.';
			}

			$availability = $configuration->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
			if ( null === $availability || EffectiveFieldState::Valid !== $availability->state ) {
				return 'Fulfilment type is incomplete.';
			}

			$offers = $configuration->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
			if ( null === $offers || EffectiveFieldState::Valid !== $offers->state || [] === $offers->members ) {
				return 'No usable delivery option is configured.';
			}

			if ( EffectiveFieldState::Unresolved === $configuration->state ) {
				return 'Required delivery settings are still missing.';
			}
		}

		return null;
	}
}
