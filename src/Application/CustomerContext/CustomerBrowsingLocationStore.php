<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;

/**
 * Session convenience default for PDP matching location.
 *
 * Not authoritative cart state. Changing this value must not rewrite cart lines.
 * Matching-level geography only — never a street address.
 */
final class CustomerBrowsingLocationStore {

	public const SESSION_KEY = 'cetech_de_browsing_matching_location';

	public function __construct(
		private ?CanonicalLocationResolver $resolver = null
	) {
	}

	public function get(): ?MatchingLocation {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) || ! method_exists( $wc->session, 'get' ) ) {
			return null;
		}

		$raw = $wc->session->get( self::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$location = MatchingLocation::fromArray( $raw );
		if ( ! $location->isPresent() ) {
			return null;
		}

		return $this->sanitize_restored( $location );
	}

	public function save( MatchingLocation $location ): void {
		if ( ! $location->isPresent() || ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) || ! method_exists( $wc->session, 'set' ) ) {
			return;
		}

		$location = $this->sanitize_restored( $location );
		$wc->session->set(
			self::SESSION_KEY,
			[
				'country'                => $location->country,
				'state'                  => $location->state,
				'city'                   => $location->city,
				'postcode'               => $location->postcode,
				'canonical_location_key' => $location->canonical_location_key,
			]
		);
	}

	private function sanitize_restored( MatchingLocation $location ): MatchingLocation {
		if ( ! $this->resolver instanceof CanonicalLocationResolver ) {
			return $location;
		}
		$country = $location->country_identity;
		if ( ! $this->resolver->country_has_usable_pack( $country ) ) {
			return $location;
		}
		$key = $location->canonical_location_key;
		if ( '' !== $key && null !== $this->resolver->require_valid_key( $key, $country ) ) {
			return $location;
		}
		if ( '' === trim( $location->city ) ) {
			return $location;
		}

		return MatchingLocation::fromInput(
			[
				'country'                => $location->country,
				'state'                  => $location->state,
				'city'                   => '',
				'postcode'               => $location->postcode,
				'canonical_location_key' => '',
			]
		);
	}
}
