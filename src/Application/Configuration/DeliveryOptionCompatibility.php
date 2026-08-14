<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Single staff-facing compatibility source for Wizard, Site-wide Defaults,
 * product customization, and variation customization.
 *
 * Domain hard constraints remain authoritative at runtime. This class only
 * decides which delivery options are offered in normal admin choice lists.
 */
final class DeliveryOptionCompatibility {

	/**
	 * @return list<string>
	 */
	public static function allowed_routes( FulfilmentProfile $profile ): array {
		$allowed = $profile->allowed_routes;
		if ( $profile->pickup_allowed ) {
			$allowed[] = DeliveryRoute::StorePickup->value;
		}

		return array_values( array_unique( $allowed ) );
	}

	public static function profile_for_key( string $profile_key ): ?FulfilmentProfile {
		return FulfilmentProfileRegistry::get( $profile_key );
	}

	public static function offer_is_compatible( array $offer, FulfilmentProfile $profile ): bool {
		$allowed = self::allowed_routes( $profile );
		$route   = (string) ( $offer['route'] ?? '' );

		return [] === $allowed || in_array( $route, $allowed, true );
	}

	/**
	 * @param list<array<string, mixed>> $offers
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function filter_offers( array $offers, FulfilmentProfile $profile ): array {
		$out = [];
		foreach ( $offers as $offer ) {
			if ( self::offer_is_compatible( $offer, $profile ) ) {
				$out[] = $offer;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, string>         $id_to_label
	 * @param list<array<string, mixed>> $offers
	 *
	 * @return array<int, string>
	 */
	public static function filter_option_labels( array $id_to_label, array $offers, FulfilmentProfile $profile ): array {
		$allowed_ids = [];
		foreach ( self::filter_offers( $offers, $profile ) as $offer ) {
			$allowed_ids[ (int) ( $offer['id'] ?? 0 ) ] = true;
		}

		$filtered = [];
		foreach ( $id_to_label as $id => $label ) {
			if ( isset( $allowed_ids[ (int) $id ] ) ) {
				$filtered[ (int) $id ] = $label;
			}
		}

		return $filtered;
	}

	/**
	 * @param list<int|string>           $member_ids
	 * @param list<array<string, mixed>> $offers
	 *
	 * @return list<int>
	 */
	public static function filter_member_ids( array $member_ids, array $offers, FulfilmentProfile $profile ): array {
		$compatible = [];
		foreach ( self::filter_offers( $offers, $profile ) as $offer ) {
			$compatible[ (int) ( $offer['id'] ?? 0 ) ] = true;
		}

		$out = [];
		foreach ( $member_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $compatible[ $id ] ) ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param list<array<string, mixed>> $offers
	 */
	public static function route_for_offer_id( array $offers, int $offer_id ): string {
		foreach ( $offers as $offer ) {
			if ( (int) ( $offer['id'] ?? 0 ) === $offer_id ) {
				return (string) ( $offer['route'] ?? '' );
			}
		}

		return '';
	}
}
