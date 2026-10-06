<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Additive rule identities and sealed history, outside the legacy delete list. */
final class RuleLifecycleSchema {

	public const GUARDS_SUFFIX = 'rule_family_guards';
	public const RULES_SUFFIX = 'logical_rules';
	public const VERSIONS_SUFFIX = 'rule_versions';
	public const SUFFIXES = [ self::GUARDS_SUFFIX, self::RULES_SUFFIX, self::VERSIONS_SUFFIX ];

	/** @return list<string> Names on an explicitly selected WordPress/site prefix. */
	public static function tables( string $table_prefix ): array {
		if ( '' === $table_prefix ) { self::invalid_prefix(); }
		return array_map( static fn ( string $suffix ): string => $table_prefix . TableNames::PREFIX . $suffix,
			array_keys( self::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4', $table_prefix . TableNames::PREFIX ) ) );
	}

	/** @return array<string,string> */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		self::charset_details( $charset_collate );
		$prefix = $qualified_prefix ?? (string) ( $GLOBALS['wpdb']->prefix ?? 'wp_' ) . TableNames::PREFIX;
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::invalid_prefix(); }
		foreach ( self::SUFFIXES as $suffix ) {
			if ( strlen( $prefix . $suffix ) > 64 ) { self::invalid_prefix(); }
		}
		return [
			self::GUARDS_SUFFIX => "CREATE TABLE {$prefix}rule_family_guards (
			  id bigint unsigned NOT NULL AUTO_INCREMENT,
			  site_id bigint unsigned NOT NULL,
			  family_code varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  family_format smallint unsigned NOT NULL DEFAULT 1,
			  policy_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  revision bigint unsigned NOT NULL DEFAULT 1,
			  created_at datetime(6) NOT NULL,
			  updated_at datetime(6) NOT NULL,
			  PRIMARY KEY  (id),
			  UNIQUE KEY site_family (site_id, family_code)
			) ENGINE=InnoDB {$charset_collate};",
			self::RULES_SUFFIX => "CREATE TABLE {$prefix}logical_rules (
			  id bigint unsigned NOT NULL AUTO_INCREMENT,
			  site_id bigint unsigned NOT NULL,
			  family_guard_id bigint unsigned NOT NULL,
			  logical_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  scope_format smallint unsigned NOT NULL DEFAULT 1,
			  scope_json longtext NOT NULL,
			  scope_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  revision bigint unsigned NOT NULL DEFAULT 1,
			  last_version_sequence bigint unsigned NOT NULL DEFAULT 0,
			  current_published_version_id bigint unsigned NULL,
			  draft_version_id bigint unsigned NULL,
			  scheduled_version_id bigint unsigned NULL,
			  created_at datetime(6) NOT NULL,
			  updated_at datetime(6) NOT NULL,
			  PRIMARY KEY  (id),
			  UNIQUE KEY site_uuid (site_id, logical_uuid),
			  KEY family_rules (site_id, family_guard_id, id)
			) ENGINE=InnoDB {$charset_collate};",
			self::VERSIONS_SUFFIX => "CREATE TABLE {$prefix}rule_versions (
			  id bigint unsigned NOT NULL AUTO_INCREMENT,
			  site_id bigint unsigned NOT NULL,
			  logical_rule_id bigint unsigned NOT NULL,
			  version_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  version_sequence bigint unsigned NOT NULL,
			  row_revision bigint unsigned NOT NULL DEFAULT 1,
			  state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  payload_format smallint unsigned NOT NULL DEFAULT 1,
			  payload_json longtext NOT NULL,
			  content_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  priority int NOT NULL,
			  start_mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			  effective_from datetime(6) NULL,
			  effective_until datetime(6) NULL,
			  author_user_id bigint unsigned NOT NULL,
			  change_reason text NOT NULL,
			  supersedes_version_id bigint unsigned NULL,
			  scheduled_revision bigint unsigned NULL,
			  scheduled_logical_revision bigint unsigned NULL,
			  scheduled_predecessor_row_revision bigint unsigned NULL,
			  sealed_at datetime(6) NULL,
			  scheduled_at datetime(6) NULL,
			  published_at datetime(6) NULL,
			  retired_at datetime(6) NULL,
			  created_at datetime(6) NOT NULL,
			  updated_at datetime(6) NOT NULL,
			  PRIMARY KEY  (id),
			  UNIQUE KEY site_version_uuid (site_id, version_uuid),
			  UNIQUE KEY logical_sequence (site_id, logical_rule_id, version_sequence),
			  KEY activation_due (site_id, state, effective_from, id),
			  KEY logical_eligibility (site_id, logical_rule_id, state, effective_from, id)
			) ENGINE=InnoDB {$charset_collate};",
		];
	}

	/** @return array{charset:string,collation:?string} */
	public static function charset_details( string $declaration ): array {
		try { return OperationStoreSchema::charset_details( $declaration ); }
		catch ( \InvalidArgumentException ) { throw new \InvalidArgumentException( 'Rule lifecycle charset declaration is invalid.' ); }
	}

	/** type, nullable, default, extra, collation purpose; exact inspection facts. */
	public static function columns( string $suffix ): array {
		$number = static fn ( string $type = 'bigint unsigned', ?string $default = null, bool $nullable = false ): array => [ $type, $nullable, $default, '', null ];
		$ascii = static fn ( string $type ): array => [ $type, false, null, '', 'ascii_bin' ];
		$time = static fn ( bool $nullable = false ): array => [ 'datetime(6)', $nullable, null, '', null ];
		$shared = [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'site_id' => $number() ];
		return match ( $suffix ) {
			self::GUARDS_SUFFIX => $shared + [
				'family_code' => $ascii( 'varchar(96)' ),
				'family_format' => $number( 'smallint unsigned', '1' ),
				'policy_hash' => $ascii( 'char(64)' ),
				'revision' => $number( 'bigint unsigned', '1', false ),
				'created_at' => $time(),
				'updated_at' => $time(),
			],
			self::RULES_SUFFIX => $shared + [
				'family_guard_id' => $number( 'bigint unsigned', null, false ),
				'logical_uuid' => $ascii( 'char(36)' ),
				'scope_format' => $number( 'smallint unsigned', '1' ),
				'scope_json' => [ 'longtext', false, null, '', 'site' ],
				'scope_hash' => $ascii( 'char(64)' ),
				'revision' => $number( 'bigint unsigned', '1', false ),
				'last_version_sequence' => $number( 'bigint unsigned', '0', false ),
				'current_published_version_id' => $number( 'bigint unsigned', null, true ),
				'draft_version_id' => $number( 'bigint unsigned', null, true ),
				'scheduled_version_id' => $number( 'bigint unsigned', null, true ),
				'created_at' => $time(),
				'updated_at' => $time(),
			],
			self::VERSIONS_SUFFIX => $shared + [
				'logical_rule_id' => $number( 'bigint unsigned', null, false ),
				'version_uuid' => $ascii( 'char(36)' ),
				'version_sequence' => $number( 'bigint unsigned', null, false ),
				'row_revision' => $number( 'bigint unsigned', '1', false ),
				'state' => $ascii( 'varchar(16)' ),
				'payload_format' => $number( 'smallint unsigned', '1' ),
				'payload_json' => [ 'longtext', false, null, '', 'site' ],
				'content_hash' => $ascii( 'char(64)' ),
				'priority' => $number( 'int' ),
				'start_mode' => $ascii( 'varchar(16)' ),
				'effective_from' => $time( true ),
				'effective_until' => $time( true ),
				'author_user_id' => $number( 'bigint unsigned', null, false ),
				'change_reason' => [ 'text', false, null, '', 'site' ],
				'supersedes_version_id' => $number( 'bigint unsigned', null, true ),
				'scheduled_revision' => $number( 'bigint unsigned', null, true ),
				'scheduled_logical_revision' => $number( 'bigint unsigned', null, true ),
				'scheduled_predecessor_row_revision' => $number( 'bigint unsigned', null, true ),
				'sealed_at' => $time( true ),
				'scheduled_at' => $time( true ),
				'published_at' => $time( true ),
				'retired_at' => $time( true ),
				'created_at' => $time(),
				'updated_at' => $time(),
			],
			default => throw new \InvalidArgumentException( 'Rule lifecycle suffix is invalid.' ),
		};
	}

	/** @return array<string,array{unique:bool,columns:list<string>}> */
	public static function indexes( string $suffix ): array {
		return match ( $suffix ) {
			self::GUARDS_SUFFIX => [
				'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ],
				'site_family' => [ 'unique' => true, 'columns' => [ 'site_id', 'family_code' ] ],
			],
			self::RULES_SUFFIX => [
				'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ],
				'site_uuid' => [ 'unique' => true, 'columns' => [ 'site_id', 'logical_uuid' ] ],
				'family_rules' => [ 'unique' => false, 'columns' => [ 'site_id', 'family_guard_id', 'id' ] ],
			],
			self::VERSIONS_SUFFIX => [
				'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ],
				'site_version_uuid' => [ 'unique' => true, 'columns' => [ 'site_id', 'version_uuid' ] ],
				'logical_sequence' => [ 'unique' => true, 'columns' => [ 'site_id', 'logical_rule_id', 'version_sequence' ] ],
				'activation_due' => [ 'unique' => false, 'columns' => [ 'site_id', 'state', 'effective_from', 'id' ] ],
				'logical_eligibility' => [ 'unique' => false, 'columns' => [ 'site_id', 'logical_rule_id', 'state', 'effective_from', 'id' ] ],
			],
			default => throw new \InvalidArgumentException( 'Rule lifecycle suffix is invalid.' ),
		};
	}

	private static function invalid_prefix(): never {
		throw new \InvalidArgumentException( 'Rule lifecycle table prefix is invalid.' );
	}
}
