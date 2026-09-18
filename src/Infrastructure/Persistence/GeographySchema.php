<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Schema 6 canonical geography pack and location tables.
 *
 * Provider IDs are mappings, never permanent business identity.
 * Destination Area configuration is stored separately in CoverageSchema.
 */
final class GeographySchema {

	public const PACKS_SUFFIX     = 'geography_packs';
	public const LOCATIONS_SUFFIX = 'geography_locations';
	public const ALIASES_SUFFIX   = 'geography_location_aliases';
	public const MAPPINGS_SUFFIX  = 'geography_provider_mappings';

	/** @var list<string> */
	public const SUFFIXES = [
		self::PACKS_SUFFIX,
		self::LOCATIONS_SUFFIX,
		self::ALIASES_SUFFIX,
		self::MAPPINGS_SUFFIX,
	];

	/**
	 * @return array<string, string> suffix => CREATE TABLE SQL
	 */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		$prefix    = $qualified_prefix ?? self::resolve_qualified_prefix();
		$packs     = $prefix . self::PACKS_SUFFIX;
		$locations = $prefix . self::LOCATIONS_SUFFIX;
		$aliases   = $prefix . self::ALIASES_SUFFIX;
		$mappings  = $prefix . self::MAPPINGS_SUFFIX;

		return [
			self::PACKS_SUFFIX => "CREATE TABLE {$packs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				country_code char(2) NOT NULL,
				provider varchar(32) NOT NULL,
				dataset_name varchar(64) NOT NULL,
				dataset_version varchar(64) NOT NULL DEFAULT '',
				source_url varchar(500) NOT NULL DEFAULT '',
				source_reference varchar(191) NOT NULL DEFAULT '',
				checksum varchar(64) NOT NULL DEFAULT '',
				license_name varchar(191) NOT NULL DEFAULT '',
				license_url varchar(500) NOT NULL DEFAULT '',
				attribution_text text NULL,
				status varchar(32) NOT NULL,
				import_cursor varchar(64) NOT NULL DEFAULT '0',
				progress_json longtext NOT NULL,
				last_error varchar(500) DEFAULT NULL,
				installed_at datetime DEFAULT NULL,
				target_token varchar(64) NOT NULL DEFAULT '',
				lease_owner varchar(64) NOT NULL DEFAULT '',
				lease_role varchar(32) NOT NULL DEFAULT '',
				lease_acquired_at bigint(20) unsigned NOT NULL DEFAULT 0,
				lease_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY country_provider_dataset (country_code, provider, dataset_name),
				KEY status (status),
				KEY country_status (country_code, status),
				KEY target_token (target_token),
				KEY lease_owner (lease_owner)
			) ENGINE=InnoDB {$charset_collate};",
			self::LOCATIONS_SUFFIX => "CREATE TABLE {$locations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_key char(36) NOT NULL,
				country_code char(2) NOT NULL,
				parent_location_id bigint(20) unsigned DEFAULT NULL,
				location_type varchar(32) NOT NULL,
				administrative_level tinyint(3) unsigned DEFAULT NULL,
				canonical_name varchar(191) NOT NULL,
				normalized_name varchar(191) NOT NULL,
				ascii_name varchar(191) NOT NULL DEFAULT '',
				latitude decimal(10,7) DEFAULT NULL,
				longitude decimal(10,7) DEFAULT NULL,
				status varchar(16) NOT NULL,
				ancestry_path varchar(191) NOT NULL DEFAULT '',
				generation int unsigned NOT NULL DEFAULT 0,
				generation_token varchar(64) NOT NULL DEFAULT '',
				draft_generation_token varchar(64) NOT NULL DEFAULT '',
				prepared_generation_token varchar(64) NOT NULL DEFAULT '',
				prepared_ancestry_path varchar(191) NOT NULL DEFAULT '',
				draft_json longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY location_key (location_key),
				KEY country_parent_type_status (country_code, parent_location_id, location_type, status),
				KEY country_normalized (country_code, normalized_name, parent_location_id),
				KEY parent_id (parent_location_id),
				KEY type_level (location_type, administrative_level),
				KEY ancestry_path (ancestry_path),
				KEY generation_status (generation, status),
				KEY generation_token (generation_token),
				KEY draft_generation_token (draft_generation_token),
				KEY prepared_generation_token (prepared_generation_token),
				KEY status (status)
			) ENGINE=InnoDB {$charset_collate};",
			self::ALIASES_SUFFIX => "CREATE TABLE {$aliases} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_id bigint(20) unsigned NOT NULL,
				alias varchar(191) NOT NULL,
				normalized_alias varchar(191) NOT NULL,
				language_code varchar(16) NOT NULL DEFAULT '',
				alias_type varchar(32) NOT NULL DEFAULT 'alternate',
				is_preferred tinyint(1) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL,
				generation_token varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY location_normalized_generation (location_id, normalized_alias, generation_token),
				KEY normalized_alias (normalized_alias),
				KEY location_id (location_id),
				KEY generation_token (generation_token),
				KEY status (status)
			) ENGINE=InnoDB {$charset_collate};",
			self::MAPPINGS_SUFFIX => "CREATE TABLE {$mappings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_id bigint(20) unsigned NOT NULL,
				provider varchar(32) NOT NULL,
				external_id varchar(64) NOT NULL,
				pack_id bigint(20) unsigned DEFAULT NULL,
				dataset_version varchar(64) NOT NULL DEFAULT '',
				provider_parent_reference varchar(64) NOT NULL DEFAULT '',
				feature_class char(1) NOT NULL DEFAULT '',
				feature_code varchar(16) NOT NULL DEFAULT '',
				provider_metadata_json longtext NOT NULL,
				generation_token varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY provider_external_generation (provider, external_id, generation_token),
				KEY location_id (location_id),
				KEY pack_id (pack_id),
				KEY provider_parent (provider, provider_parent_reference)
			) ENGINE=InnoDB {$charset_collate};",
		];
	}

	/**
	 * @return array<string, list<string>>
	 */
	public static function required_markers(): array {
		return [
			self::PACKS_SUFFIX => [
				'country_code',
				'provider',
				'dataset_name',
				'dataset_version',
				'license_name',
				'attribution_text',
				'status',
				'import_cursor',
				'progress_json',
				'target_token',
				'lease_owner',
				'lease_role',
				'lease_acquired_at',
				'lease_expires_at',
				'UNIQUE KEY country_provider_dataset (country_code, provider, dataset_name)',
				'KEY country_status (country_code, status)',
				'KEY target_token (target_token)',
				'KEY lease_owner (lease_owner)',
				'ENGINE=InnoDB',
			],
			self::LOCATIONS_SUFFIX => [
				'location_key',
				'country_code',
				'parent_location_id',
				'location_type',
				'administrative_level',
				'canonical_name',
				'normalized_name',
				'UNIQUE KEY location_key (location_key)',
				'KEY country_parent_type_status (country_code, parent_location_id, location_type, status)',
				'KEY country_normalized (country_code, normalized_name, parent_location_id)',
				'KEY ancestry_path (ancestry_path)',
				'generation',
				'generation_token',
				'draft_generation_token',
				'prepared_generation_token',
				'prepared_ancestry_path',
				'draft_json',
				'KEY generation_status (generation, status)',
				'KEY generation_token (generation_token)',
				'KEY draft_generation_token (draft_generation_token)',
				'KEY prepared_generation_token (prepared_generation_token)',
				'ENGINE=InnoDB',
			],
			self::ALIASES_SUFFIX => [
				'location_id',
				'alias',
				'normalized_alias',
				'generation_token',
				'UNIQUE KEY location_normalized_generation (location_id, normalized_alias, generation_token)',
				'KEY generation_token (generation_token)',
				'KEY normalized_alias (normalized_alias)',
				'ENGINE=InnoDB',
			],
			self::MAPPINGS_SUFFIX => [
				'location_id',
				'provider',
				'external_id',
				'generation_token',
				'UNIQUE KEY provider_external_generation (provider, external_id, generation_token)',
				'KEY provider_parent (provider, provider_parent_reference)',
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
