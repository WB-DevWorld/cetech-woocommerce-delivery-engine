<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Additive, preserved operation storage. Not part of the legacy uninstall list. */
final class OperationStoreSchema {

	public const RECORDS_SUFFIX = 'operation_records';
	public const CHANGES_SUFFIX = 'operation_changes';
	public const SUFFIXES = [ self::RECORDS_SUFFIX, self::CHANGES_SUFFIX ];

	/** @return array<string,string> */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		self::charset_details( $charset_collate );
		$prefix = $qualified_prefix ?? (string) ( $GLOBALS['wpdb']->prefix ?? 'wp_' ) . TableNames::PREFIX;
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) {
			throw new \InvalidArgumentException( 'Operation store table prefix is invalid.' );
		}
		foreach ( self::SUFFIXES as $suffix ) {
			if ( strlen( $prefix . $suffix ) > 64 ) {
				throw new \InvalidArgumentException( 'Operation store table prefix is invalid.' );
			}
		}
		$records = $prefix . self::RECORDS_SUFFIX;
		$changes = $prefix . self::CHANGES_SUFFIX;
		return [
			self::RECORDS_SUFFIX => "CREATE TABLE {$records} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint unsigned NOT NULL,
				namespace_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				intent_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				namespace_format smallint unsigned NOT NULL DEFAULT 1,
				intent_format smallint unsigned NOT NULL DEFAULT 1,
				record_format smallint unsigned NOT NULL DEFAULT 1,
				operation varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				operation_version bigint unsigned NOT NULL,
				target_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				publication_state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				completion_json longtext NULL,
				audit_id bigint unsigned NULL,
				row_version bigint unsigned NOT NULL DEFAULT 1,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				completed_at datetime(6) NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY site_namespace (site_id, namespace_hash),
				KEY site_state_id (site_id, state, id)
			) ENGINE=InnoDB {$charset_collate};",
			self::CHANGES_SUFFIX => "CREATE TABLE {$changes} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint unsigned NOT NULL,
				operation_id bigint unsigned NOT NULL,
				event_format smallint unsigned NOT NULL DEFAULT 1,
				event_json longtext NOT NULL,
				created_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY operation_event (site_id, operation_id)
			) ENGINE=InnoDB {$charset_collate};",
		];
	}

	/** @return array{charset:string,collation:?string} */
	public static function charset_details( string $declaration ): array {
		if ( 1 !== preg_match( '/\A(?:DEFAULT\s+)?CHARACTER\s+SET\s+([a-zA-Z0-9_]+)(?:\s+COLLATE\s+([a-zA-Z0-9_]+))?\z/Di', trim( $declaration ), $parts ) ) {
			throw new \InvalidArgumentException( 'Operation store charset declaration is invalid.' );
		}
		return [ 'charset' => strtolower( $parts[1] ), 'collation' => isset( $parts[2] ) ? strtolower( $parts[2] ) : null ];
	}

	/** type, nullable, default, extra, collation purpose; used for exact inspection. */
	public static function columns( string $suffix ): array {
		$number = static fn ( string $type = 'bigint unsigned', ?string $default = null, bool $nullable = false ): array => [ $type, $nullable, $default, '', null ];
		$ascii = static fn ( string $type ): array => [ $type, false, null, '', 'ascii_bin' ];
		$time = static fn ( bool $nullable = false ): array => [ 'datetime(6)', $nullable, null, '', null ];
		$shared = [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'site_id' => $number() ];
		return match ( $suffix ) {
			self::RECORDS_SUFFIX => $shared + [
				'namespace_hash' => $ascii( 'char(64)' ), 'intent_hash' => $ascii( 'char(64)' ),
				'namespace_format' => $number( 'smallint unsigned', '1' ), 'intent_format' => $number( 'smallint unsigned', '1' ),
				'record_format' => $number( 'smallint unsigned', '1' ), 'operation' => $ascii( 'varchar(96)' ),
				'operation_version' => $number(), 'target_hash' => $ascii( 'char(64)' ), 'state' => $ascii( 'varchar(24)' ),
				'publication_state' => $ascii( 'varchar(16)' ), 'completion_json' => [ 'longtext', true, null, '', 'site' ],
				'audit_id' => $number( 'bigint unsigned', null, true ), 'row_version' => $number( 'bigint unsigned', '1' ),
				'created_at' => $time(), 'updated_at' => $time(), 'completed_at' => $time( true ),
			],
			self::CHANGES_SUFFIX => $shared + [
				'operation_id' => $number(), 'event_format' => $number( 'smallint unsigned', '1' ),
				'event_json' => [ 'longtext', false, null, '', 'site' ], 'created_at' => $time(),
			],
			default => throw new \InvalidArgumentException( 'Operation store suffix is invalid.' ),
		};
	}

	/** @return array<string,array{unique:bool,columns:list<string>}> */
	public static function indexes( string $suffix ): array {
		return match ( $suffix ) {
			self::RECORDS_SUFFIX => [
				'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ],
				'site_namespace' => [ 'unique' => true, 'columns' => [ 'site_id', 'namespace_hash' ] ],
				'site_state_id' => [ 'unique' => false, 'columns' => [ 'site_id', 'state', 'id' ] ],
			],
			self::CHANGES_SUFFIX => [
				'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ],
				'operation_event' => [ 'unique' => true, 'columns' => [ 'site_id', 'operation_id' ] ],
			],
			default => throw new \InvalidArgumentException( 'Operation store suffix is invalid.' ),
		};
	}
}
