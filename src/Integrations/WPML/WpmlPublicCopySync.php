<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Idempotent existing-content registration for WPML String Translation.
 *
 * Not a schema migration. Uses a lightweight option marker. Safe when WPML/ST
 * is absent. Does not scan on frontend requests — callers must invoke from admin.
 */
final class WpmlPublicCopySync {

	public const OPTION_NAME = 'cetech_de_wpml_string_sync_version';

	public const VERSION = 1;

	public function __construct(
		private WpmlPublicCopyCatalog $catalog,
		private DeliveryOfferRepositoryInterface $offers,
		private PickupLocationRepositoryInterface $pickups,
		private DestinationZoneRepositoryInterface $zones
	) {
	}

	public function maybe_sync(): void {
		if ( ! $this->catalog->translator()->is_string_translation_available() ) {
			return;
		}

		$stored = get_option( self::OPTION_NAME, 0 );

		if ( (int) $stored === self::VERSION ) {
			return;
		}

		$this->sync_all();
		update_option( self::OPTION_NAME, self::VERSION, false );
	}

	public function sync_all(): int {
		$count = 0;

		foreach ( $this->iterate( $this->offers ) as $row ) {
			$this->catalog->register_delivery_offer( $row );
			++$count;
		}

		foreach ( $this->iterate( $this->pickups ) as $row ) {
			$this->catalog->register_pickup_location( $row );
			++$count;
		}

		foreach ( $this->iterate( $this->zones ) as $row ) {
			$this->catalog->register_destination_zone( $row );
			++$count;
		}

		return $count;
	}

	/**
	 * @param object $repository
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function iterate( object $repository ): \Generator {
		if ( method_exists( $repository, 'page_after' ) ) {
			$after = 0;

			do {
				$page = $repository->page_after( $after, 100 );

				if ( ! is_array( $page ) || [] === $page ) {
					break;
				}

				foreach ( $page as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$after = (int) ( $row['id'] ?? $after );
					yield $row;
				}
			} while ( true );

			return;
		}

		if ( ! method_exists( $repository, 'list' ) ) {
			return;
		}

		$rows = $repository->list( [ 'limit' => 500 ] );

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				yield $row;
			}
		}
	}
}
