<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Frontend\CartFulfilmentPackagePresentation;

/**
 * Customer-safe Store API payloads. Never includes supplier, origin, cost, or internal ids.
 */
final class BlocksPublicPayload {

	/** @var list<string> */
	public const FORBIDDEN_FRAGMENTS = [
		'supplier',
		'origin',
		'internal_cost',
		'private_cost',
		'rate_card',
		'group_id',
		'cetech_de_group_id',
		'rule_id',
		'logistics_profile',
		'internal_note',
		'staff_note',
	];

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_item(
		array $cart_item,
		?CartDeliverySelectionCapture $capture = null,
		?CartDeliverySelectionRevalidator $revalidator = null,
		string $cart_item_key = ''
	): array {
		$intent  = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);
		$summary = CartDeliverySelectionSessionData::normalizeSummary(
			$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
		) ?? [];

		$choice = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) : '';
		$availability = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) ) : '';
		$is_pickup = FulfilmentChoice::StorePickup->value === $choice;

		$selection_valid = null;
		$requires_selection = false;
		$needs_reselection = ! empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] );

		if ( null !== $capture ) {
			$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			$requires_selection = $capture->should_apply_capture_to_line( $product_id, $variation_id )
				&& 'required' === ( $capture->assess_product_selection( $product_id, $variation_id )['requirement'] ?? 'none' );
		}

		if ( $needs_reselection ) {
			$selection_valid = false;
		} elseif ( null !== $revalidator && '' !== $cart_item_key && is_array( $intent ) ) {
			$result = $revalidator->revalidate_cart_item( $cart_item_key, $cart_item );
			$selection_valid = CartDeliverySelectionRevalidationResult::STATUS_VALID === $result->status;
		} elseif ( is_array( $intent ) ) {
			$selection_valid = true;
		}

		$reselection_options = [];
		$product_name        = '';
		$data                = $cart_item['data'] ?? null;

		if ( is_object( $data ) && method_exists( $data, 'get_name' ) ) {
			$product_name = trim( (string) $data->get_name() );
		}

		if ( $needs_reselection && null !== $capture ) {
			$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			$assessment   = $capture->assess_product_selection( $product_id, $variation_id );

			foreach ( $assessment['options'] as $option ) {
				if ( ! $option instanceof ProductDeliveryOption || ! $option->is_available ) {
					continue;
				}

				$label = trim( (string) $option->delivery_offer_public_label );

				if ( '' === $label && FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
					$label = trim( (string) $option->pickup_location_label );
					$label = '' !== $label ? $label : __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );
				}

				$estimate = trim( (string) $option->estimate_text );

				$reselection_options[] = [
					'display_key'       => $option->display_key,
					'label'             => '' !== $label ? $label : __( 'Delivery option', 'cetech-woocommerce-delivery-engine' ),
					'fulfilment_choice' => $option->fulfilment_choice,
					'estimate_text'     => '' !== $estimate ? $estimate : null,
				];
			}
		}

		$payload = [
			'fulfilment_choice'       => $needs_reselection ? null : ( '' !== $choice ? $choice : null ),
			'fulfilment_availability' => $needs_reselection ? null : ( '' !== $availability ? $availability : null ),
			'delivery_option_label'   => $needs_reselection ? null : self::nullable_string( $summary['delivery_offer_public_label'] ?? null ),
			'estimate_text'           => $needs_reselection ? null : self::nullable_string( $summary['estimate_text'] ?? null ),
			'pickup_location_label'   => $needs_reselection ? null : self::nullable_string( $summary['pickup_location_label'] ?? null ),
			'pickup_address'          => $needs_reselection ? null : self::nullable_string( $summary['pickup_address'] ?? null ),
			'pickup_instructions'     => $needs_reselection ? null : self::nullable_string( $summary['pickup_instructions'] ?? null ),
			'is_pickup'               => $needs_reselection ? false : $is_pickup,
			'requires_selection'      => $requires_selection,
			'selection_valid'         => $selection_valid,
			'needs_reselection'       => $needs_reselection,
			'reselection_message'     => $needs_reselection
				? (
					'' !== $product_name
						? sprintf(
							/* translators: %s: product name */
							__( 'Delivery options for “%s” have changed. Please choose a delivery option. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' ),
							$product_name
						)
						: __( 'Delivery options for this item have changed. Please choose a delivery option. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' )
				)
				: null,
			'reselection_options'     => $reselection_options,
			'product_name'            => '' !== $product_name ? $product_name : null,
			'cart_item_key'           => '' !== $cart_item_key ? $cart_item_key : ( is_string( $cart_item['key'] ?? null ) ? (string) $cart_item['key'] : null ),
		];

		return self::strip_forbidden( $payload );
	}

	/**
	 * @param array<string, mixed> $package
	 *
	 * @return array<string, mixed>
	 */
	public static function package( array $package, int $index ): array {
		$meta      = DeliveryGroupIdentity::package_meta( $package ) ?? [];
		$managed   = DeliveryGroupIdentity::is_managed_package( $package );
		$is_pickup = CartFulfilmentPackagePresentation::is_pickup( $package );
		$heading   = CartFulfilmentPackagePresentation::heading( '', $package );
		$destination = CartFulfilmentPackagePresentation::destination( '', $package );

		$payload = [
			'package_index'                       => $index,
			'managed'                             => $managed,
			'is_pickup'                           => $is_pickup,
			'heading'                             => '' !== $heading ? $heading : null,
			'destination_label'                   => '' !== $destination ? $destination : null,
			'uses_customer_shipping_destination'  => CartFulfilmentPackagePresentation::uses_customer_shipping_destination( $package ),
			'offer_label'                         => self::nullable_string( $meta['offer_public_label'] ?? $meta['rate_label'] ?? null ),
			'estimate_text'                       => self::nullable_string( $meta['estimate_text'] ?? null ),
			'pickup_location_label'               => self::nullable_string( $meta['pickup_location_label'] ?? null ),
			'pickup_address'                      => self::nullable_string( $meta['pickup_address'] ?? null ),
			'pickup_instructions'                 => self::nullable_string( $meta['pickup_instructions'] ?? null ),
			'charge_is_zero'                      => $is_pickup,
		];

		return self::strip_forbidden( $payload );
	}

	/**
	 * True when any array key (nested) matches a private/internal fragment.
	 *
	 * @param mixed $value
	 */
	public static function contains_forbidden( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::key_is_forbidden( $key ) ) {
				return true;
			}

			if ( is_array( $item ) && self::contains_forbidden( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	public static function strip_forbidden( array $payload ): array {
		$clean = [];

		foreach ( $payload as $key => $value ) {
			if ( self::key_is_forbidden( (string) $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = self::strip_forbidden( $value );
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	private static function key_is_forbidden( string $key ): bool {
		$normalized = strtolower( $key );

		foreach ( self::FORBIDDEN_FRAGMENTS as $fragment ) {
			if ( $normalized === strtolower( $fragment ) || str_contains( $normalized, strtolower( $fragment ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );

		return '' !== $text ? $text : null;
	}
}
