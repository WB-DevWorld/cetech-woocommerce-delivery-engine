<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;

/**
 * Authoritative Classic PDP add-to-cart payload.
 *
 * Visible matching fields remain a convenience UI. This JSON is the
 * customer-context the server trusts for a new cart line. Browsing session
 * defaults are never read here.
 */
final class ClassicPdpContextPayload {

	public const POST_FIELD = 'cetech_de_pdp_context';

	/**
	 * @return array<string, mixed>
	 */
	public static function fromPost(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart form; validated server-side.
		if ( ! isset( $_POST[ self::POST_FIELD ] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( (string) $_POST[ self::POST_FIELD ] );

		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	public static function displayKeyFromPost(): string {
		$payload = self::fromPost();
		$raw     = isset( $payload['display_key'] ) ? (string) $payload['display_key'] : '';

		return ProductDeliveryOptionsBuilder::normalizeDisplayKey( $raw );
	}

	public static function matchingLocationFromPost(): ?MatchingLocation {
		$payload = self::fromPost();
		$raw     = $payload['matching_location'] ?? null;

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$location = MatchingLocation::fromInput(
			[
				'country'  => (string) ( $raw['country'] ?? '' ),
				'state'    => (string) ( $raw['state'] ?? '' ),
				'city'     => (string) ( $raw['city'] ?? '' ),
				'postcode' => (string) ( $raw['postcode'] ?? '' ),
			]
		);

		return $location->isPresent() ? $location : null;
	}
}
