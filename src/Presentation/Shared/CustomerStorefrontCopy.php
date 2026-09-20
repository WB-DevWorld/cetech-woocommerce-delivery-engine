<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Shared;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Customer-facing storefront copy shared by Classic and Blocks.
 *
 * Presentation only. Does not change quoting, identity, or address authority.
 */
final class CustomerStorefrontCopy {

	private function __construct() {
	}

	public static function delivery_and_pickup(): string {
		return __( 'Delivery & pickup', 'cetech-woocommerce-delivery-engine' );
	}

	public static function where_do_you_want_this_item(): string {
		return __( 'Where do you want this item?', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery(): string {
		return __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
	}

	public static function store_pickup(): string {
		return __( 'Store Pickup', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_fee(): string {
		return __( 'Delivery fee', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_fee_line( string $price_text ): string {
		$price_text = trim( $price_text );
		if ( '' === $price_text ) {
			return '';
		}
		if ( 0 === strcasecmp( $price_text, __( 'Free', 'cetech-woocommerce-delivery-engine' ) ) ) {
			return $price_text;
		}
		if ( str_starts_with( strtolower( $price_text ), strtolower( self::delivery_fee() ) ) ) {
			return $price_text;
		}

		return self::delivery_fee() . ': ' . $price_text;
	}

	public static function change(): string {
		return __( 'Change', 'cetech-woocommerce-delivery-engine' );
	}

	public static function your_deliveries(): string {
		return __( 'Your deliveries', 'cetech-woocommerce-delivery-engine' );
	}

	public static function add_delivery_address(): string {
		return __( 'Add delivery address', 'cetech-woocommerce-delivery-engine' );
	}

	public static function use_my_checkout_address(): string {
		return __( 'Use my checkout address', 'cetech-woocommerce-delivery-engine' );
	}

	public static function items_keep_own_address(): string {
		return __( 'Items with their own delivery address will keep that address.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function multi_destination(): string {
		return __( 'Your items will be delivered to different locations.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function mixed_fulfilment(): string {
		return __( 'This order includes Store Pickup and Delivery.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function use_for_all_delivery_items(): string {
		return __( 'Use this address for all delivery items', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_choices(): string {
		return __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
	}

	public static function delivery_to( string $locality ): string {
		$locality = trim( $locality );

		if ( '' === $locality ) {
			return self::delivery();
		}

		return sprintf(
			/* translators: %s: city or locality */
			__( 'Delivery to %s', 'cetech-woocommerce-delivery-engine' ),
			$locality
		);
	}

	public static function pickup_at( string $location ): string {
		$location = trim( $location );

		if ( '' === $location ) {
			return self::store_pickup();
		}

		return sprintf(
			/* translators: %s: pickup location name */
			__( 'Store Pickup — %s', 'cetech-woocommerce-delivery-engine' ),
			$location
		);
	}

	public static function incomplete_address( int $count ): string {
		$count = max( 1, $count );

		return sprintf(
			/* translators: %d: number of items missing a delivery address */
			_n(
				'Complete the delivery address for %d item before placing your order.',
				'Complete the delivery address for %d items before placing your order.',
				$count,
				'cetech-woocommerce-delivery-engine'
			),
			$count
		);
	}

	public static function compact_estimate( string $estimate_text ): string {
		return DeliveryPresentationLabels::strip_estimated_prefix( $estimate_text );
	}

	public static function locality_estimate_line( string $locality, string $estimate_text ): string {
		$locality = trim( $locality );
		$estimate = self::compact_estimate( $estimate_text );

		if ( '' !== $locality && '' !== $estimate ) {
			return $locality . ' · ' . $estimate;
		}

		if ( '' !== $locality ) {
			return $locality;
		}

		return $estimate;
	}

	/**
	 * Compact closed cart-line summary.
	 *
	 * @param array<string, string|null> $summary
	 *
	 * @return array{kicker: string, title: string, meta: string, is_pickup: bool}
	 */
	public static function cart_line_summary( string $choice, array $summary, string $locality ): array {
		$is_pickup = FulfilmentChoice::StorePickup->value === $choice;
		$estimate  = self::compact_estimate( (string) ( $summary['estimate_text'] ?? '' ) );

		if ( $is_pickup ) {
			$title = trim( (string) ( $summary['pickup_location_label'] ?? $summary['delivery_offer_public_label'] ?? '' ) );
			if ( '' === $title ) {
				$title = self::store_pickup();
			}

			return [
				'kicker'    => self::store_pickup(),
				'title'     => $title,
				'meta'      => '' !== $estimate ? $estimate : '',
				'is_pickup' => true,
			];
		}

		$offer = trim( (string) ( $summary['delivery_offer_public_label'] ?? '' ) );

		return [
			'kicker'    => '',
			'title'     => '' !== $offer ? $offer : self::delivery(),
			'meta'      => self::locality_estimate_line( $locality, (string) ( $summary['estimate_text'] ?? '' ) ),
			'is_pickup' => false,
		];
	}

	/**
	 * Checkout confirmation groups. Does not expose IDs or internal grouping keys.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return list<array{heading: string, is_pickup: bool, lines: list<array{product: string, offer: string, estimate: string}>}>
	 */
	public static function delivery_plan( array $contents ): array {
		$groups = [];

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);
			if ( null === $intent ) {
				continue;
			}

			$summary = CartDeliverySelectionSessionData::normalizeSummary(
				$item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
			) ?? [];
			$choice    = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
			$context   = CustomerCartContext::fromCartItem( $item );
			$is_pickup = FulfilmentChoice::StorePickup->value === $choice;
			$product   = self::product_name( $item );
			$estimate  = self::compact_estimate( (string) ( $summary['estimate_text'] ?? '' ) );

			if ( $is_pickup ) {
				$heading = trim( (string) ( $summary['pickup_location_label'] ?? '' ) );
				$heading = '' !== $heading ? $heading : self::store_pickup();
				$offer   = self::store_pickup();
			} else {
				$heading = $context instanceof CustomerCartContext ? $context->publicLocalityLabel() : '';
				$heading = '' !== $heading ? $heading : self::delivery();
				$offer   = trim( (string) ( $summary['delivery_offer_public_label'] ?? '' ) );
				$offer   = '' !== $offer ? $offer : self::delivery();
			}

			$key = ( $is_pickup ? 'p:' : 'd:' ) . strtolower( $heading );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'heading'   => $heading,
					'is_pickup' => $is_pickup,
					'lines'     => [],
				];
			}

			$offer_line = $offer;
			if ( '' !== $estimate ) {
				$offer_line = $is_pickup ? $estimate : ( $offer . ' · ' . $estimate );
			}

			$groups[ $key ]['lines'][] = [
				'product'  => $product,
				'offer'    => $offer,
				'estimate' => $offer_line,
			];
		}

		return array_values( $groups );
	}

	public static function pickup_address( string $raw ): string {
		return PickupLocationAddressFormatter::format( $raw );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private static function product_name( array $cart_item ): string {
		$data = $cart_item['data'] ?? null;
		if ( is_object( $data ) && method_exists( $data, 'get_name' ) ) {
			return trim( (string) $data->get_name() );
		}

		return '';
	}
}
