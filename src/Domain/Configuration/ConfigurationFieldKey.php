<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

/**
 * Canonical configuration field keys derived from product_delivery_rules + Stage 1 mapping.
 */
final class ConfigurationFieldKey {

	public const FULFILMENT_AVAILABILITY = 'fulfilment_availability';
	public const FULFILMENT_CHOICE       = 'fulfilment_choice';
	public const LOGISTICS_PROFILE_ID    = 'logistics_profile_id';
	public const SUPPLIER_ID             = 'supplier_id';
	public const ORIGIN_ID               = 'origin_id';
	public const PRIORITY                = 'priority';
	public const ESTIMATED_DELIVERY      = 'estimated_delivery';
	public const DELIVERY_OFFER_IDS      = 'delivery_offer_ids';

	private function __construct() {
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return [
			self::FULFILMENT_AVAILABILITY,
			self::FULFILMENT_CHOICE,
			self::LOGISTICS_PROFILE_ID,
			self::SUPPLIER_ID,
			self::ORIGIN_ID,
			self::PRIORITY,
			self::ESTIMATED_DELIVERY,
			self::DELIVERY_OFFER_IDS,
		];
	}
}
