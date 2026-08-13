<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\FulfilmentProfile;

use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;

/**
 * Smallest extensible registry of supported fulfilment profiles.
 *
 * Built-ins boot on first read. Additional supported profiles call register().
 */
final class FulfilmentProfileRegistry {

	/** @var array<string, FulfilmentProfile> */
	private static array $profiles = [];

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::register(
			new FulfilmentProfile(
				FulfilmentAvailability::InWarehouse->value,
				'In Warehouse',
				'Warehouse',
				FulfilmentAvailability::InWarehouse->value,
				true,
				false,
				false,
				'In Warehouse',
				'Local warehouse stock. Delivery only. No store pickup and no air or sea shipping.',
				[ DeliveryRoute::LocalDelivery->value ]
			)
		);
		self::register(
			new FulfilmentProfile(
				FulfilmentAvailability::InStore->value,
				'In Store',
				'Store',
				FulfilmentAvailability::InStore->value,
				true,
				true,
				false,
				'In Store',
				'Local store stock. Delivery and/or store pickup. No air or sea shipping.',
				[ DeliveryRoute::LocalDelivery->value ]
			)
		);
		self::register(
			new FulfilmentProfile(
				FulfilmentAvailability::InternationalFulfilment->value,
				'International',
				'International',
				FulfilmentAvailability::InternationalFulfilment->value,
				true,
				false,
				true,
				'Delivery Only',
				'International fulfilment. Delivery only, using air and/or sea. Store pickup is not available.',
				[ DeliveryRoute::Air->value, DeliveryRoute::Sea->value ]
			)
		);
	}

	public static function register( FulfilmentProfile $profile ): void {
		self::boot();
		self::$profiles[ $profile->key ] = $profile;
	}

	public static function has( string $key ): bool {
		self::boot();

		return isset( self::$profiles[ $key ] );
	}

	public static function get( string $key ): ?FulfilmentProfile {
		self::boot();

		return self::$profiles[ $key ] ?? null;
	}

	/**
	 * @return list<FulfilmentProfile>
	 */
	public static function all(): array {
		self::boot();

		return array_values( self::$profiles );
	}

	/**
	 * @return list<string>
	 */
	public static function keys(): array {
		self::boot();

		return array_keys( self::$profiles );
	}

	public static function reset_for_tests(): void {
		self::$profiles = [];
		self::$booted   = false;
	}
}
