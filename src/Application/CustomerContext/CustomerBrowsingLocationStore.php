<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;

/**
 * Session convenience default for PDP matching location.
 *
 * Not authoritative cart state. Changing this value must not rewrite cart lines.
 * Matching-level geography only — never a street address.
 */
final class CustomerBrowsingLocationStore {

	public const SESSION_KEY = 'cetech_de_browsing_matching_location';

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

		return $location->isPresent() ? $location : null;
	}

	public function save( MatchingLocation $location ): void {
		if ( ! $location->isPresent() || ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) || ! method_exists( $wc->session, 'set' ) ) {
			return;
		}

		$wc->session->set(
			self::SESSION_KEY,
			[
				'country'  => $location->country,
				'state'    => $location->state,
				'city'     => $location->city,
				'postcode' => $location->postcode,
			]
		);
	}
}
