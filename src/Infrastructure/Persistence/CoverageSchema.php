<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Schema 6 Delivery Area coverage groups, members and postcode constraints.
 *
 * Additive to destination_zones / destination_rules. Zone IDs remain the
 * business identity used by Rate Cards.
 */
final class CoverageSchema {

	public const GROUPS_SUFFIX    = 'destination_coverage_groups';
	public const MEMBERS_SUFFIX   = 'destination_coverage_members';
	public const POSTCODES_SUFFIX = 'destination_coverage_postcodes';

	/** @var list<string> */
	public const SUFFIXES = [
		self::GROUPS_SUFFIX,
		self::MEMBERS_SUFFIX,
		self::POSTCODES_SUFFIX,
	];

	/**
	 * @return array<string, string> suffix => CREATE TABLE SQL
	 */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		$prefix    = $qualified_prefix ?? self::resolve_qualified_prefix();
		$groups    = $prefix . self::GROUPS_SUFFIX;
		$members   = $prefix . self::MEMBERS_SUFFIX;
		$postcodes = $prefix . self::POSTCODES_SUFFIX;

		return [
			self::GROUPS_SUFFIX => "CREATE TABLE {$groups} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				zone_id bigint(20) unsigned NOT NULL,
				root_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				coverage_mode varchar(32) NOT NULL,
				sort_order int(11) NOT NULL DEFAULT 100,
				status varchar(16) NOT NULL,
				review_required tinyint(1) unsigned NOT NULL DEFAULT 0,
				legacy_migration_json longtext NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY zone_id (zone_id),
				KEY zone_status_order (zone_id, status, sort_order),
				KEY root_location_id (root_location_id),
				KEY review_required (review_required)
			) ENGINE=InnoDB {$charset_collate};",
			self::MEMBERS_SUFFIX => "CREATE TABLE {$members} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				coverage_group_id bigint(20) unsigned NOT NULL,
				location_id bigint(20) unsigned NOT NULL,
				membership varchar(16) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY group_location_membership (coverage_group_id, location_id, membership),
				KEY coverage_group_id (coverage_group_id),
				KEY location_id (location_id)
			) ENGINE=InnoDB {$charset_collate};",
			self::POSTCODES_SUFFIX => "CREATE TABLE {$postcodes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				coverage_group_id bigint(20) unsigned NOT NULL,
				postcode_value varchar(64) NOT NULL,
				match_mode varchar(16) NOT NULL,
				priority int(11) NOT NULL DEFAULT 100,
				status varchar(16) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY group_postcode_mode (coverage_group_id, postcode_value, match_mode),
				KEY coverage_group_id (coverage_group_id),
				KEY postcode_value (postcode_value)
			) ENGINE=InnoDB {$charset_collate};",
		];
	}

	/**
	 * @return array<string, list<string>>
	 */
	public static function required_markers(): array {
		return [
			self::GROUPS_SUFFIX => [
				'zone_id',
				'root_location_id',
				'coverage_mode',
				'review_required',
				'legacy_migration_json',
				'KEY zone_status_order (zone_id, status, sort_order)',
				'ENGINE=InnoDB',
			],
			self::MEMBERS_SUFFIX => [
				'coverage_group_id',
				'location_id',
				'membership',
				'UNIQUE KEY group_location_membership (coverage_group_id, location_id, membership)',
				'ENGINE=InnoDB',
			],
			self::POSTCODES_SUFFIX => [
				'coverage_group_id',
				'postcode_value',
				'match_mode',
				'UNIQUE KEY group_postcode_mode (coverage_group_id, postcode_value, match_mode)',
				'ENGINE=InnoDB',
			],
		];
	}

	private static function resolve_qualified_prefix(): string {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
			return (string) $GLOBALS['wpdb']->prefix . TableNames::PREFIX;
		}

		return 'wp_' . TableNames::PREFIX;
	}
}
