<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Schema 5 bulk-job persistence.
 *
 * Durable job/item storage for large administrative operations.
 * Not autoloaded wp_options. Not a second configuration resolver.
 */
final class BulkJobSchema {

	public const JOBS_SUFFIX    = 'bulk_jobs';
	public const ITEMS_SUFFIX   = 'bulk_job_items';
	public const RECIPES_SUFFIX = 'bulk_recipes';

	/** @var list<string> */
	public const SUFFIXES = [
		self::JOBS_SUFFIX,
		self::ITEMS_SUFFIX,
		self::RECIPES_SUFFIX,
	];

	/**
	 * @return array<string, string> suffix => CREATE TABLE SQL
	 */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		$prefix  = $qualified_prefix ?? self::resolve_qualified_prefix();
		$jobs    = $prefix . self::JOBS_SUFFIX;
		$items   = $prefix . self::ITEMS_SUFFIX;
		$recipes = $prefix . self::RECIPES_SUFFIX;

		return [
			self::JOBS_SUFFIX => "CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_uuid char(36) NOT NULL,
				job_code varchar(32) NOT NULL,
				operation_type varchar(64) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL,
				dry_run tinyint(1) unsigned NOT NULL DEFAULT 1,
				cancel_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
				target_hash char(64) NOT NULL DEFAULT '',
				action_hash char(64) NOT NULL DEFAULT '',
				target_definition_json longtext NOT NULL,
				action_manifest_json longtext NOT NULL,
				summary_json longtext NOT NULL,
				total_count int(10) unsigned NOT NULL DEFAULT 0,
				enumerated_count int(10) unsigned NOT NULL DEFAULT 0,
				processed_count int(10) unsigned NOT NULL DEFAULT 0,
				changed_count int(10) unsigned NOT NULL DEFAULT 0,
				skipped_count int(10) unsigned NOT NULL DEFAULT 0,
				failed_count int(10) unsigned NOT NULL DEFAULT 0,
				warning_count int(10) unsigned NOT NULL DEFAULT 0,
				enumeration_complete tinyint(1) unsigned NOT NULL DEFAULT 0,
				checkpoint_cursor varchar(64) NOT NULL DEFAULT '0',
				batch_size smallint(5) unsigned NOT NULL DEFAULT 25,
				claim_token varchar(64) DEFAULT NULL,
				claimed_at datetime DEFAULT NULL,
				retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
				error_code varchar(64) DEFAULT NULL,
				error_summary varchar(500) DEFAULT NULL,
				parent_job_id bigint(20) unsigned DEFAULT NULL,
				format_version smallint(5) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				started_at datetime DEFAULT NULL,
				completed_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY job_uuid (job_uuid),
				UNIQUE KEY job_code (job_code),
				KEY status (status),
				KEY status_updated (status, updated_at),
				KEY actor_user_id (actor_user_id),
				KEY operation_type (operation_type),
				KEY parent_job_id (parent_job_id)
			) ENGINE=InnoDB {$charset_collate};",
			self::ITEMS_SUFFIX => "CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				target_type varchar(32) NOT NULL,
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				external_key varchar(191) NOT NULL DEFAULT '',
				parent_target_id bigint(20) unsigned DEFAULT NULL,
				status varchar(32) NOT NULL,
				attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
				precondition_fingerprint char(64) NOT NULL DEFAULT '',
				after_fingerprint char(64) NOT NULL DEFAULT '',
				before_snapshot_json longtext NOT NULL,
				result_json longtext NOT NULL,
				error_code varchar(64) DEFAULT NULL,
				error_summary varchar(500) DEFAULT NULL,
				claim_token varchar(64) DEFAULT NULL,
				claimed_at datetime DEFAULT NULL,
				completed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY job_target (job_id, target_type, target_id, external_key),
				KEY job_status_id (job_id, status, id),
				KEY job_id (job_id),
				KEY claim_token (claim_token),
				KEY target_lookup (target_type, target_id)
			) ENGINE=InnoDB {$charset_collate};",
			self::RECIPES_SUFFIX => "CREATE TABLE {$recipes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				recipe_code varchar(64) NOT NULL,
				name varchar(191) NOT NULL,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_definition_json longtext NOT NULL,
				action_manifest_json longtext NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY recipe_code (recipe_code),
				KEY owner_user_id (owner_user_id)
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
	 * @return array<string, list<string>>
	 */
	public static function required_markers(): array {
		return [
			self::JOBS_SUFFIX => [
				'job_uuid',
				'job_code',
				'operation_type',
				'actor_user_id',
				'status',
				'dry_run',
				'target_definition_json',
				'action_manifest_json',
				'checkpoint_cursor',
				'batch_size',
				'UNIQUE KEY job_uuid (job_uuid)',
				'UNIQUE KEY job_code (job_code)',
				'KEY status (status)',
				'KEY status_updated (status, updated_at)',
				'ENGINE=InnoDB',
			],
			self::ITEMS_SUFFIX => [
				'job_id',
				'target_type',
				'target_id',
				'external_key',
				'status',
				'precondition_fingerprint',
				'after_fingerprint',
				'before_snapshot_json',
				'UNIQUE KEY job_target (job_id, target_type, target_id, external_key)',
				'KEY job_status_id (job_id, status, id)',
				'ENGINE=InnoDB',
			],
			self::RECIPES_SUFFIX => [
				'recipe_code',
				'name',
				'target_definition_json',
				'action_manifest_json',
				'UNIQUE KEY recipe_code (recipe_code)',
				'ENGINE=InnoDB',
			],
		];
	}
}
