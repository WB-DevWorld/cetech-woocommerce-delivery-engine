<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Schema definitions for Stage 14 shipment persistence tables.
 *
 * Tables store historical order-time truth, not current product settings.
 * Status columns store machine codes only.
 */
final class ShipmentSchema {

	public const SHIPMENTS_SUFFIX = 'shipments';
	public const ITEMS_SUFFIX     = 'shipment_items';
	public const EVENTS_SUFFIX    = 'shipment_events';

	/** @var list<string> */
	public const SUFFIXES = [
		self::SHIPMENTS_SUFFIX,
		self::ITEMS_SUFFIX,
		self::EVENTS_SUFFIX,
	];

	/**
	 * @return array<string, string> suffix => CREATE TABLE SQL
	 */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		$prefix    = $qualified_prefix ?? self::resolve_qualified_prefix();
		$shipments = $prefix . self::SHIPMENTS_SUFFIX;
		$items     = $prefix . self::ITEMS_SUFFIX;
		$events    = $prefix . self::EVENTS_SUFFIX;

		return [
			self::SHIPMENTS_SUFFIX => "CREATE TABLE {$shipments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				shipment_number varchar(64) NOT NULL,
				idempotency_key varchar(255) NOT NULL,
				delivery_group_id varchar(191) NOT NULL,
				status varchar(32) NOT NULL,
				fulfilment_availability varchar(64) NOT NULL DEFAULT '',
				fulfilment_choice varchar(32) NOT NULL DEFAULT '',
				delivery_offer_id bigint(20) unsigned DEFAULT NULL,
				delivery_offer_public_label varchar(255) DEFAULT NULL,
				route varchar(64) DEFAULT NULL,
				service_level varchar(64) DEFAULT NULL,
				carrier_visibility varchar(64) DEFAULT NULL,
				public_carrier_name varchar(255) DEFAULT NULL,
				destination_zone_id bigint(20) unsigned DEFAULT NULL,
				logistics_profile_id bigint(20) unsigned DEFAULT NULL,
				supplier_id bigint(20) unsigned DEFAULT NULL,
				origin_id bigint(20) unsigned DEFAULT NULL,
				currency_code char(3) NOT NULL DEFAULT '',
				customer_paid_shipping_amount decimal(18,6) DEFAULT NULL,
				rate_card_id bigint(20) unsigned DEFAULT NULL,
				rate_card_code varchar(64) DEFAULT NULL,
				internal_cost decimal(18,6) DEFAULT NULL,
				eta_original varchar(255) DEFAULT NULL,
				eta_current varchar(255) DEFAULT NULL,
				tracking_number varchar(191) DEFAULT NULL,
				tracking_url varchar(500) DEFAULT NULL,
				tracking_carrier_display varchar(255) DEFAULT NULL,
				dispatch_at datetime DEFAULT NULL,
				delivered_at datetime DEFAULT NULL,
				public_note text DEFAULT NULL,
				private_note text DEFAULT NULL,
				wc_fulfillment_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				UNIQUE KEY order_group (order_id, delivery_group_id),
				KEY order_id (order_id),
				KEY status (status),
				KEY status_updated (status, updated_at)
			) ENGINE=InnoDB {$charset_collate};",
			self::ITEMS_SUFFIX => "CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				shipment_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned DEFAULT NULL,
				variation_id bigint(20) unsigned DEFAULT NULL,
				quantity int(10) unsigned NOT NULL DEFAULT 1,
				product_name_snapshot varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY shipment_item (shipment_id, order_item_id),
				KEY shipment_id (shipment_id),
				KEY order_item_id (order_item_id)
			) ENGINE=InnoDB {$charset_collate};",
			self::EVENTS_SUFFIX => "CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				shipment_id bigint(20) unsigned NOT NULL,
				event_type varchar(64) NOT NULL,
				from_status varchar(32) DEFAULT NULL,
				to_status varchar(32) DEFAULT NULL,
				public_note text DEFAULT NULL,
				internal_note text DEFAULT NULL,
				actor_user_id bigint(20) unsigned DEFAULT NULL,
				source varchar(32) NOT NULL,
				event_at datetime NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY shipment_id (shipment_id),
				KEY shipment_time (shipment_id, event_at)
			) ENGINE=InnoDB {$charset_collate};",
		];
	}

	private static function resolve_qualified_prefix(): string {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
			return (string) $GLOBALS['wpdb']->prefix . TableNames::PREFIX;
		}

		return 'wp_' . TableNames::PREFIX;
	}

	/**
	 * Structural markers used by schema inspection tests (no DB required).
	 *
	 * @return array<string, list<string>>
	 */
	public static function required_markers(): array {
		return [
			self::SHIPMENTS_SUFFIX => [
				'order_id',
				'shipment_number',
				'idempotency_key',
				'delivery_group_id',
				'status',
				'fulfilment_availability',
				'fulfilment_choice',
				'delivery_offer_id',
				'delivery_offer_public_label',
				'eta_original',
				'eta_current',
				'customer_paid_shipping_amount',
				'tracking_number',
				'tracking_url',
				'public_note',
				'private_note',
				'internal_cost',
				'wc_fulfillment_id',
				'supplier_id',
				'origin_id',
				'logistics_profile_id',
				'UNIQUE KEY idempotency_key',
				'UNIQUE KEY order_group (order_id, delivery_group_id)',
				'KEY order_id (order_id)',
				'KEY status (status)',
				'KEY status_updated (status, updated_at)',
				'ENGINE=InnoDB',
				'created_at',
				'updated_at',
			],
			self::ITEMS_SUFFIX => [
				'shipment_id',
				'order_id',
				'order_item_id',
				'product_id',
				'variation_id',
				'quantity',
				'product_name_snapshot',
				'UNIQUE KEY shipment_item (shipment_id, order_item_id)',
				'KEY shipment_id (shipment_id)',
				'KEY order_item_id (order_item_id)',
				'ENGINE=InnoDB',
			],
			self::EVENTS_SUFFIX => [
				'shipment_id',
				'event_type',
				'from_status',
				'to_status',
				'public_note',
				'internal_note',
				'actor_user_id',
				'source',
				'event_at',
				'KEY shipment_id (shipment_id)',
				'KEY shipment_time (shipment_id, event_at)',
				'ENGINE=InnoDB',
			],
		];
	}
}
