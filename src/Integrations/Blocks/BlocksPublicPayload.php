<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Integrations\WPML\WpmlLivePublicCopyPresenter;
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
		string $cart_item_key = '',
		?WpmlLivePublicCopyPresenter $wpml_presenter = null
	): array {
		$intent  = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);
		$summary = CartDeliverySelectionSessionData::normalizeSummary(
			$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
		) ?? [];

		if ( null !== $wpml_presenter ) {
			$summary = $wpml_presenter->localize_summary( $summary, $intent );
		}

		$choice = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) : '';
		$availability = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) ) : '';
		$is_pickup = FulfilmentChoice::StorePickup->value === $choice;

		$selection_valid = null;
		$requires_selection = false;

		if ( null !== $capture ) {
			$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			$requires_selection = $capture->should_apply_capture_to_line( $product_id, $variation_id )
				&& 'required' === ( $capture->assess_product_selection( $product_id, $variation_id )['requirement'] ?? 'none' );
		}

		if ( null !== $revalidator && '' !== $cart_item_key && is_array( $intent ) ) {
			$result = $revalidator->revalidate_cart_item( $cart_item_key, $cart_item );
			$selection_valid = CartDeliverySelectionRevalidationResult::STATUS_VALID === $result->status;
		} elseif ( is_array( $intent ) ) {
			$selection_valid = true;
		}

		$payload = [
			'fulfilment_choice'       => '' !== $choice ? $choice : null,
			'fulfilment_availability' => '' !== $availability ? $availability : null,
			'delivery_option_label'   => self::nullable_string( $summary['delivery_offer_public_label'] ?? null ),
			'estimate_text'           => self::nullable_string( $summary['estimate_text'] ?? null ),
			'pickup_location_label'   => self::nullable_string( $summary['pickup_location_label'] ?? null ),
			'pickup_address'          => self::nullable_string( $summary['pickup_address'] ?? null ),
			'pickup_instructions'     => self::nullable_string( $summary['pickup_instructions'] ?? null ),
			'is_pickup'               => $is_pickup,
			'requires_selection'      => $requires_selection,
			'selection_valid'         => $selection_valid,
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
