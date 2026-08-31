<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

/**
 * Normalizes and validates delivery selection data restored from WooCommerce session.
 *
 * Canonical intent fields that must survive capture → session → normalize → restore:
 * contract_version, product_id, variation_id, target_type, target_id, display_key,
 * fulfilment_availability, fulfilment_choice, delivery_offer_id, rule_id, issued_at,
 * configuration_fingerprint (additive ECR; omit when empty for legacy hash stability).
 */
final class CartDeliverySelectionSessionData {

	/** @var list<string> */
	public const CANONICAL_INTENT_KEYS = [
		'contract_version',
		'product_id',
		'variation_id',
		'target_type',
		'target_id',
		'display_key',
		'fulfilment_availability',
		'fulfilment_choice',
		'delivery_offer_id',
		'rule_id',
		'issued_at',
		'configuration_fingerprint',
	];

	private const SUMMARY_KEYS = [
		'fulfilment_availability_label',
		'fulfilment_choice_label',
		'delivery_offer_public_label',
		'estimate_text',
		'pickup_location_label',
		'pickup_address',
		'pickup_instructions',
	];

	/**
	 * @param array<string, mixed> $values Session values for a cart line.
	 *
	 * @return array{
	 *     intent: array<string, mixed>,
	 *     summary: array<string, string|null>,
	 *     hash: string
	 * }|null
	 */
	public static function restoreFromSession( array $values ): ?array {
		$has_any = isset( $values[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] )
			|| isset( $values[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] )
			|| isset( $values[ CartDeliverySelectionCapture::CART_HASH_KEY ] );

		if ( ! $has_any ) {
			return null;
		}

		$intent = self::normalizeIntent( $values[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null );

		if ( null === $intent ) {
			return null;
		}

		$summary = self::normalizeSummary( $values[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null );

		if ( null === $summary ) {
			return null;
		}

		$hash = self::normalizeHash( $values[ CartDeliverySelectionCapture::CART_HASH_KEY ] ?? null );

		if ( null === $hash ) {
			return null;
		}

		$expected = CartDeliverySelectionFingerprint::fromIntent( $intent );

		if ( ! hash_equals( $expected, $hash ) ) {
			return null;
		}

		return [
			'intent'  => $intent,
			'summary' => $summary,
			'hash'    => $hash,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function normalizeIntent( mixed $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		if ( ! CartDeliverySelectionFingerprint::isValidIntentShape( $raw ) ) {
			return null;
		}

		$fields = CartDeliverySelectionFingerprint::normalizeIntentFields( $raw );

		$intent = [
			'contract_version'        => (string) ( $raw['contract_version'] ?? '' ),
			'product_id'              => $fields['product_id'],
			'variation_id'            => '' !== $fields['variation_id'] ? (int) $fields['variation_id'] : null,
			'target_type'             => sanitize_key( (string) ( $raw['target_type'] ?? '' ) ),
			'target_id'               => max( 0, (int) ( $raw['target_id'] ?? 0 ) ),
			'display_key'             => $fields['display_key'],
			'fulfilment_availability' => $fields['fulfilment_availability'],
			'fulfilment_choice'       => $fields['fulfilment_choice'],
			'delivery_offer_id'       => '' !== $fields['delivery_offer_id'] ? (int) $fields['delivery_offer_id'] : null,
			'rule_id'                 => '' !== $fields['rule_id'] ? (int) $fields['rule_id'] : null,
			'issued_at'               => sanitize_text_field( (string) ( $raw['issued_at'] ?? '' ) ),
		];

		// Preserve additive ECR fingerprint so session restore / checkout revalidation
		// hash checks match the hash captured at add-to-cart (legacy intents omit this key).
		if ( '' !== (string) $fields['configuration_fingerprint'] ) {
			$intent['configuration_fingerprint'] = (string) $fields['configuration_fingerprint'];
		}

		return $intent;
	}

	/**
	 * @return array<string, string|null>|null
	 */
	public static function normalizeSummary( mixed $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$summary = [];

		foreach ( self::SUMMARY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				$summary[ $key ] = null;
				continue;
			}

			$value = $raw[ $key ];

			if ( null === $value || '' === $value ) {
				$summary[ $key ] = null;
				continue;
			}

			$summary[ $key ] = sanitize_text_field( (string) $value );
		}

		$has_content = false;

		foreach ( $summary as $value ) {
			if ( null !== $value && '' !== $value ) {
				$has_content = true;
				break;
			}
		}

		return $has_content ? $summary : null;
	}

	public static function normalizeHash( mixed $raw ): ?string {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return null;
		}

		$hash = strtolower( trim( (string) $raw ) );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}

		return $hash;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function stripSelectionKeys( array $cart_item ): array {
		unset(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ],
			$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ],
			$cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ]
		);

		return $cart_item;
	}
}
