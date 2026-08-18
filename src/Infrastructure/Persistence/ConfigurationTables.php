<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Plugin configuration, product-rule, scoped-configuration, and shipment tables.
 */
final class ConfigurationTables {

	/** @var list<string> Phase 2A configuration-domain table suffixes. */
	public const SUFFIXES = [
		'delivery_offers',
		'destination_zones',
		'destination_rules',
		'logistics_profiles',
		'suppliers',
		'origins',
		'pickup_locations',
		'rate_cards',
		'rate_card_rules',
		'audit_log',
	];

	/** @var list<string> Phase 2C1 product-rule table suffixes. */
	public const PRODUCT_RULE_SUFFIXES = [
		'product_delivery_rules',
	];

	/** @var list<string> Stage 2 scoped configuration table suffixes. */
	public const SCOPED_CONFIGURATION_SUFFIXES = ScopedConfigurationSchema::SUFFIXES;

	/** @var list<string> Stage 14 shipment persistence table suffixes. */
	public const SHIPMENT_SUFFIXES = ShipmentSchema::SUFFIXES;

	/**
	 * @return list<string>
	 */
	public static function all_suffixes(): array {
		return array_merge(
			self::SUFFIXES,
			self::PRODUCT_RULE_SUFFIXES,
			self::SCOPED_CONFIGURATION_SUFFIXES,
			self::SHIPMENT_SUFFIXES
		);
	}

	/**
	 * @return list<string> Fully qualified table names for the current site.
	 */
	public static function all(): array {
		$tables = [];

		foreach ( self::all_suffixes() as $suffix ) {
			$tables[] = TableNames::for( $suffix );
		}

		return $tables;
	}

	public static function exists( string $suffix ): bool {
		global $wpdb;

		$table = TableNames::for( $suffix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return $result === $table;
	}

	public static function all_exist(): bool {
		return [] === self::missing();
	}

	/**
	 * @return list<string> Missing table suffixes across all plugin tables.
	 */
	public static function missing(): array {
		$missing = [];

		foreach ( self::all_suffixes() as $suffix ) {
			if ( ! self::exists( $suffix ) ) {
				$missing[] = $suffix;
			}
		}

		return $missing;
	}

	/**
	 * @return list<string> Missing Phase 2A configuration-domain table suffixes.
	 */
	public static function missing_configuration_domain(): array {
		$missing = [];

		foreach ( self::SUFFIXES as $suffix ) {
			if ( ! self::exists( $suffix ) ) {
				$missing[] = $suffix;
			}
		}

		return $missing;
	}
}
