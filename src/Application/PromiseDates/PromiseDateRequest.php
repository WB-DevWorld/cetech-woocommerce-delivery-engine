<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * What a surface knows when it asks for a promised delivery date.
 *
 * Built by the server from the product, the chosen Delivery Option and the committed destination,
 * never from browser-supplied dates. Unknown facts stay null; a provider that needs one of them
 * returns no promise rather than guessing.
 */
final readonly class PromiseDateRequest {

	public const CHOICE_DELIVERY     = 'delivery';
	public const CHOICE_STORE_PICKUP = 'store_pickup';

	/**
	 * @param int                     $product_id              Parent product id (positive).
	 * @param int                     $variation_id            Variation id, or 0 for a simple product.
	 * @param string                  $fulfilment_choice       `delivery` or `store_pickup`.
	 * @param int|null                $delivery_offer_id       The chosen Delivery Option, when known.
	 * @param int|null                $destination_zone_id     The matched Delivery Area, when known.
	 * @param int|null                $logistics_profile_id    The product's logistics profile, when known.
	 * @param string|null             $fulfilment_availability For example `in_store` or `in_warehouse`, when known.
	 * @param \DateTimeImmutable      $at                      The moment the promise is evaluated for (an order time or "now").
	 * @param int                     $quantity                Line quantity (positive).
	 */
	public function __construct(
		public int $product_id,
		public int $variation_id,
		public string $fulfilment_choice,
		public ?int $delivery_offer_id,
		public ?int $destination_zone_id,
		public ?int $logistics_profile_id,
		public ?string $fulfilment_availability,
		public \DateTimeImmutable $at,
		public int $quantity = 1
	) {
		if ( $product_id < 1 || $variation_id < 0 || $quantity < 1 ) {
			throw new \InvalidArgumentException( 'A promise request needs a product and a positive quantity.' );
		}
		if ( ! in_array( $fulfilment_choice, [ self::CHOICE_DELIVERY, self::CHOICE_STORE_PICKUP ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown fulfilment choice for a promise request.' );
		}
		foreach ( [ $delivery_offer_id, $destination_zone_id, $logistics_profile_id ] as $id ) {
			if ( null !== $id && $id < 1 ) {
				throw new \InvalidArgumentException( 'Promise request ids must be positive when present.' );
			}
		}
		if ( null !== $fulfilment_availability && 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $fulfilment_availability ) ) {
			throw new \InvalidArgumentException( 'Invalid fulfilment availability for a promise request.' );
		}
	}

	public function is_pickup(): bool {
		return self::CHOICE_STORE_PICKUP === $this->fulfilment_choice;
	}
}
