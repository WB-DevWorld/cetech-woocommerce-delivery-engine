<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Forward-only immutable promise history. No lifecycle transition owns deletion. */
final class PromiseStorageSchema {
	public const OBJECTS_SUFFIX = 'promise_objects';
	public const VERSIONS_SUFFIX = 'promise_versions';
	public const ASSIGNMENTS_SUFFIX = 'promise_assignments';
	public const SUFFIXES = [ self::OBJECTS_SUFFIX, self::VERSIONS_SUFFIX, self::ASSIGNMENTS_SUFFIX ];
	public const BODY_BYTES = 32768;
	public const RECEIPT_BYTES = 16384;
	public const REFERENCE_BYTES = 4096;
	public const ROW_BYTES = 98304;

	/** @return list<string> */
	public static function tables( string $table_prefix ): array {
		$prefix = self::qualified_prefix( $table_prefix );
		return array_map( static fn( string $suffix ): string => $prefix . $suffix, self::SUFFIXES );
	}
	/** @return array<string,string> */
	public static function create_table_statements( string $charset_collate, ?string $qualified_prefix = null ): array {
		self::charset_details( $charset_collate );
		$prefix = $qualified_prefix ?? self::qualified_prefix( (string) ( $GLOBALS['wpdb']->prefix ?? 'wp_' ) ); self::assert_prefix( $prefix );
		$statements = [];
		foreach ( self::SUFFIXES as $suffix ) {
			$definitions = [];
			foreach ( self::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $collation ] ) {
				$sql = "  {$name} {$type}";
				if ( 'ascii_bin' === $collation ) { $sql .= ' CHARACTER SET ascii COLLATE ascii_bin'; }
				$sql .= $nullable ? ' NULL' : ' NOT NULL';
				if ( null !== $default ) { $sql .= ' DEFAULT ' . $default; }
				if ( '' !== $extra ) { $sql .= ' ' . strtoupper( $extra ); }
				$definitions[] = $sql;
			}
			foreach ( self::indexes( $suffix ) as $name => $definition ) {
				$columns = implode( ', ', $definition['columns'] );
				$definitions[] = 'PRIMARY' === $name ? "  PRIMARY KEY  ({$columns})" : '  ' . ( $definition['unique'] ? 'UNIQUE KEY' : 'KEY' ) . " {$name} ({$columns})";
			}
			$statements[$suffix] = "CREATE TABLE {$prefix}{$suffix} (\n" . implode( ",\n", $definitions ) . "\n) ENGINE=InnoDB {$charset_collate};";
		}
		return $statements;
	}
	public static function charset_details( string $declaration ): array {
		try { return OperationStoreSchema::charset_details( $declaration ); }
		catch ( \InvalidArgumentException ) { throw new \InvalidArgumentException( 'Promise storage charset declaration is invalid.' ); }
	}
	/** Exact SQL types, nullability, defaults, extra and collation purpose. */
	public static function columns( string $suffix ): array {
		$number = static fn( string $type = 'bigint unsigned', ?string $default = null, bool $nullable = false ): array => [ $type, $nullable, $default, '', null ];
		$ascii = static fn( string $type = 'char(64)', bool $nullable = false ): array => [ $type, $nullable, null, '', 'ascii_bin' ];
		$time = static fn( bool $nullable = false ): array => [ 'datetime(6)', $nullable, null, '', null ];
		$json = static fn( bool $nullable = false ): array => [ 'longtext', $nullable, null, '', 'site' ];
		$shared = [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'site_id' => $number(), 'site_key' => $ascii( 'varchar(64)' ) ];
		return match ( $suffix ) {
			self::OBJECTS_SUFFIX => $shared + [
				'kind' => $ascii( 'varchar(16)' ), 'logical_id' => $ascii( 'varchar(64)' ), 'revision' => $number( 'bigint unsigned', '1' ), 'last_sequence' => $number( 'bigint unsigned', '0' ),
				'draft_version_id' => $number( 'bigint unsigned', null, true ), 'scheduled_version_id' => $number( 'bigint unsigned', null, true ), 'published_version_id' => $number( 'bigint unsigned', null, true ),
				'latest_version_id' => $number( 'bigint unsigned', null, true ), 'latest_source_receipt_digest' => $ascii( 'char(64)', true ),
				'created_at' => $time(), 'updated_at' => $time(),
			],
			self::VERSIONS_SUFFIX => $shared + [
				'object_id' => $number(), 'kind' => $ascii( 'varchar(16)' ), 'logical_id' => $ascii( 'varchar(64)' ), 'version_uuid' => $ascii( 'char(36)' ), 'format_version' => $number( 'smallint unsigned', '1' ), 'domain_version' => $number(), 'row_revision' => $number( 'bigint unsigned', '1' ), 'state' => $ascii( 'varchar(16)' ),
				'body_json' => $json(), 'body_digest' => $ascii(), 'declared_from' => $time(), 'declared_until' => $time( true ), 'created_at' => $time(), 'sealed_at' => $time( true ), 'scheduled_at' => $time( true ), 'published_at' => $time( true ), 'retired_at' => $time( true ),
				'author_user_id' => $number(), 'reason' => [ 'varchar(256)', false, null, '', 'site' ], 'predecessor_version_id' => $number( 'bigint unsigned', null, true ), 'schedule_expected_object_revision' => $number( 'bigint unsigned', null, true ), 'schedule_expected_published_version_id' => $number( 'bigint unsigned', null, true ),
				'create_receipt_json' => $json(), 'create_receipt_digest' => $ascii(), 'publication_receipt_json' => $json( true ), 'publication_receipt_digest' => $ascii( 'char(64)', true ), 'source_receipt_json' => $json(), 'source_receipt_digest' => $ascii(),
			],
			self::ASSIGNMENTS_SUFFIX => $shared + [
				'scope_kind' => $ascii( 'varchar(16)' ), 'scope_id' => $number(), 'service_kind' => $ascii( 'varchar(16)' ), 'service_code' => $ascii( 'varchar(64)' ), 'endpoint' => $ascii( 'varchar(64)' ), 'endpoint_kind' => $ascii( 'varchar(16)' ), 'revision' => $number( 'bigint unsigned', '1' ), 'generation' => $number( 'bigint unsigned', '0' ), 'state' => $ascii( 'varchar(16)' ),
				'policy_object_id' => $number( 'bigint unsigned', null, true ), 'policy_version_id' => $number( 'bigint unsigned', null, true ), 'policy_reference_json' => $json( true ), 'created_at' => $time(), 'updated_at' => $time(), 'source_receipt_json' => $json( true ), 'source_receipt_digest' => $ascii( 'char(64)', true ),
			],
			default => throw new \InvalidArgumentException( 'Promise storage table suffix is invalid.' ),
		};
	}
	/** @return array<string,array{unique:bool,columns:list<string>}> */
	public static function indexes( string $suffix ): array {
		$primary = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ] ];
		$index = static fn( array $columns, bool $unique = false ): array => [ 'unique' => $unique, 'columns' => $columns ];
		return match ( $suffix ) {
			self::OBJECTS_SUFFIX => $primary + [ 'site_object' => $index( [ 'site_id', 'site_key', 'kind', 'logical_id' ], true ) ],
			self::VERSIONS_SUFFIX => $primary + [ 'site_version_uuid' => $index( [ 'site_id', 'site_key', 'version_uuid' ], true ), 'object_sequence' => $index( [ 'site_id', 'site_key', 'object_id', 'domain_version' ], true ), 'site_exact_version' => $index( [ 'site_id', 'site_key', 'kind', 'logical_id', 'domain_version' ], true ), 'version_lifecycle' => $index( [ 'site_id', 'site_key', 'state', 'declared_from', 'id' ] ) ],
			self::ASSIGNMENTS_SUFFIX => $primary + [ 'scope_service_endpoint' => $index( [ 'site_id', 'site_key', 'scope_kind', 'scope_id', 'service_kind', 'service_code', 'endpoint', 'endpoint_kind' ], true ), 'assignment_policy' => $index( [ 'site_id', 'site_key', 'policy_version_id', 'id' ] ) ],
			default => throw new \InvalidArgumentException( 'Promise storage table suffix is invalid.' ),
		};
	}
	/** Bounded SQL projections must apply these before transporting stored private bytes. */
	public static function payload_limits( string $suffix ): array {
		return match ( $suffix ) {
			self::VERSIONS_SUFFIX => [ 'body_json' => self::BODY_BYTES, 'create_receipt_json' => self::RECEIPT_BYTES, 'publication_receipt_json' => self::RECEIPT_BYTES, 'source_receipt_json' => self::RECEIPT_BYTES, 'reason' => 256 ],
			self::ASSIGNMENTS_SUFFIX => [ 'policy_reference_json' => self::REFERENCE_BYTES, 'source_receipt_json' => self::RECEIPT_BYTES ],
			self::OBJECTS_SUFFIX => [],
			default => throw new \InvalidArgumentException( 'Promise storage table suffix is invalid.' ),
		};
	}
	private static function qualified_prefix( string $raw ): string {
		if ( '' === $raw || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $raw ) ) { self::invalid_prefix(); }
		$prefix = $raw . TableNames::PREFIX; self::assert_prefix( $prefix ); return $prefix;
	}
	private static function assert_prefix( string $prefix ): void {
		if ( '' === $prefix || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::invalid_prefix(); }
		foreach ( self::SUFFIXES as $suffix ) { if ( strlen( $prefix . $suffix ) > 64 ) { self::invalid_prefix(); } }
	}
	private static function invalid_prefix(): never { throw new \InvalidArgumentException( 'Promise storage table prefix is invalid.' ); }
}
