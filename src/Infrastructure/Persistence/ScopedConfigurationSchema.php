<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Schema definitions for Stage 2 scoped configuration tables.
 */
final class ScopedConfigurationSchema {

	public const SCOPES_SUFFIX = 'configuration_scopes';
	public const FIELDS_SUFFIX = 'configuration_fields';
	public const COLLECTIONS_SUFFIX = 'configuration_collections';

	/** @var list<string> */
	public const SUFFIXES = [
		self::SCOPES_SUFFIX,
		self::FIELDS_SUFFIX,
		self::COLLECTIONS_SUFFIX,
	];

	public const GLOBAL_VERSION_OPTION = 'cetech_de_global_configuration_version';
	public const MIGRATION_REPORT_OPTION = 'cetech_de_v3_config_migration_report';

	/**
	 * @return array<string, string> suffix => CREATE TABLE SQL
	 */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		$prefix = $qualified_prefix ?? self::resolve_qualified_prefix();
		$scopes       = $prefix . self::SCOPES_SUFFIX;
		$fields       = $prefix . self::FIELDS_SUFFIX;
		$collections  = $prefix . self::COLLECTIONS_SUFFIX;

		return [
			self::SCOPES_SUFFIX => "CREATE TABLE {$scopes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_type varchar(32) NOT NULL,
				scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
				slice_key varchar(64) NOT NULL DEFAULT '',
				parent_product_id bigint(20) unsigned DEFAULT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				config_version bigint(20) unsigned NOT NULL DEFAULT 1,
				source varchar(32) NOT NULL DEFAULT 'native',
				legacy_rule_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_identity (scope_type, scope_id, slice_key),
				UNIQUE KEY legacy_rule_id (legacy_rule_id),
				KEY parent_product_id (parent_product_id),
				KEY status (status),
				KEY config_version (config_version)
			) {$charset_collate};",
			self::FIELDS_SUFFIX => "CREATE TABLE {$fields} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_row_id bigint(20) unsigned NOT NULL,
				field_key varchar(64) NOT NULL,
				mode varchar(32) NOT NULL,
				value_type varchar(32) NOT NULL,
				value_text longtext DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_field (scope_row_id, field_key),
				KEY field_key (field_key),
				KEY mode (mode)
			) {$charset_collate};",
			self::COLLECTIONS_SUFFIX => "CREATE TABLE {$collections} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_row_id bigint(20) unsigned NOT NULL,
				field_key varchar(64) NOT NULL,
				mode varchar(32) NOT NULL,
				members_json longtext NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_collection (scope_row_id, field_key),
				KEY field_key (field_key),
				KEY mode (mode)
			) {$charset_collate};",
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
			self::SCOPES_SUFFIX => [
				'scope_type',
				'scope_id',
				'slice_key',
				'parent_product_id',
				'config_version',
				'source',
				'legacy_rule_id',
				'UNIQUE KEY scope_identity',
				'UNIQUE KEY legacy_rule_id',
				'KEY parent_product_id',
				'created_at',
				'updated_at',
			],
			self::FIELDS_SUFFIX => [
				'scope_row_id',
				'field_key',
				'mode',
				'value_type',
				'value_text',
				'UNIQUE KEY scope_field',
			],
			self::COLLECTIONS_SUFFIX => [
				'scope_row_id',
				'field_key',
				'mode',
				'members_json',
				'UNIQUE KEY scope_collection',
			],
		];
	}
}
