<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Forward-only quote stores. Neither quote expiry nor uninstall owns history. */
final class DeliveryQuoteSchema {
	public const QUOTES_SUFFIX = 'delivery_quotes';
	public const BINDINGS_SUFFIX = 'delivery_quote_bindings';
	public const BUDGET_SUFFIX = 'delivery_quote_budget_windows';
	public const SUFFIXES = [ self::QUOTES_SUFFIX, self::BINDINGS_SUFFIX, self::BUDGET_SUFFIX ];
	public const RATE_INDEX = 'quote_candidate_range';
	public const RATE_SUFFIX = 'rate_cards';

	/** @return list<string> Explicit raw WordPress/site prefix, not a global route. */
	public static function tables( string $table_prefix ): array {
		$prefix = self::qualified_prefix( $table_prefix );
		return array_map( static fn( string $suffix ): string => $prefix . $suffix, self::SUFFIXES );
	}
	/** @return array<string,string> dbDelta-compatible exact additive definitions. */
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
	/** Existing legacy schema spells the approved currency dimension base_currency. */
	public static function rate_index_statement( string $table_prefix ): string {
		$table = self::qualified_prefix( $table_prefix ) . self::RATE_SUFFIX;
		return "ALTER TABLE `{$table}` ADD KEY " . self::RATE_INDEX . ' (delivery_offer_id, destination_zone_id, base_currency, id)';
	}
	public static function charset_details( string $declaration ): array {
		try { return OperationStoreSchema::charset_details( $declaration ); }
		catch ( \InvalidArgumentException ) { throw new \InvalidArgumentException( 'Delivery quote charset declaration is invalid.' ); }
	}
	/** Exact type, nullability, default, extra, and collation purpose. */
	public static function columns( string $suffix ): array {
		$number = static fn( string $type = 'bigint unsigned', ?string $default = null, bool $nullable = false ): array => [ $type, $nullable, $default, '', null ];
		$ascii = static fn( string $type = 'char(64)', bool $nullable = false ): array => [ $type, $nullable, null, '', 'ascii_bin' ];
		$time = static fn( bool $nullable = false ): array => [ 'datetime(6)', $nullable, null, '', null ];
		$shared = [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'site_id' => $number() ];
		return match ( $suffix ) {
			self::QUOTES_SUFFIX => $shared + [
				'quote_uuid' => $ascii( 'char(36)' ), 'format_version' => $number( 'smallint unsigned', '1' ),
				'profile_code' => $ascii( 'varchar(64)' ), 'profile_version' => $number( 'smallint unsigned', '1' ), 'purpose' => $ascii( 'varchar(16)' ),
				'principal_hash' => $ascii(), 'owner_digest' => $ascii(), 'material_digest' => $ascii(), 'body_digest' => $ascii(),
				'header_json' => [ 'longtext', false, null, '', 'site' ], 'private_body_json' => [ 'longtext', true, null, '', 'site' ],
				'issue_namespace_hash' => $ascii(), 'accept_namespace_hash' => $ascii(), 'invalidate_namespace_hash' => $ascii(),
				'state' => $ascii( 'varchar(16)' ), 'revision' => $number( 'bigint unsigned', '1' ), 'retention_revision' => $number( 'bigint unsigned', '1' ),
				'created_at' => $time(), 'expires_at' => $time(), 'accepted_at' => $time( true ), 'transition_at' => $time( true ),
			],
			self::BINDINGS_SUFFIX => $shared + [
				'format_version' => $number( 'smallint unsigned', '1' ), 'quote_uuid' => $ascii( 'char(36)' ), 'order_id' => $number(), 'placement_uuid' => $ascii( 'char(36)' ),
				'managed_group_manifest_digest' => $ascii(), 'mapping_json' => [ 'longtext', false, null, '', 'site' ],
				'native_money_digest' => $ascii(), 'accepted_body_digest' => $ascii(), 'snapshot_digest' => $ascii( 'char(64)', true ), 'context_digest' => $ascii( 'char(64)', true ),
				'bind_namespace_hash' => $ascii(), 'seal_namespace_hash' => $ascii(), 'state' => $ascii( 'varchar(16)' ), 'revision' => $number( 'bigint unsigned', '1' ),
				'created_at' => $time(), 'verified_at' => $time( true ), 'sealed_at' => $time( true ),
			],
			self::BUDGET_SUFFIX => $shared + [
				'format_version' => $number( 'smallint unsigned', '1' ), 'purpose' => $ascii( 'varchar(32)' ), 'slot_kind' => $ascii( 'varchar(16)' ), 'slot_key' => $ascii(),
				'window_start' => $time(), 'principal_hash' => $ascii( 'char(64)', true ), 'attempt_count' => $number( 'int unsigned', '0' ), 'revision' => $number( 'bigint unsigned', '1' ),
				'created_at' => $time(), 'last_seen_at' => $time(), 'admission_namespace_hash' => $ascii( 'char(64)', true ), 'admission_intent_digest' => $ascii( 'char(64)', true ),
				'server_attempt_digest' => $ascii( 'char(64)', true ), 'lease_expires_at' => $time( true ), 'lease_state' => $ascii( 'varchar(16)', true ), 'consumed_quote_uuid' => $ascii( 'char(36)', true ), 'consumed_at' => $time( true ),
			],
			default => throw new \InvalidArgumentException( 'Delivery quote table suffix is invalid.' ),
		};
	}
	/** @return array<string,array{unique:bool,columns:list<string>}> */
	public static function indexes( string $suffix ): array {
		$primary = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ] ];
		$index = static fn( array $columns, bool $unique = false ): array => [ 'unique' => $unique, 'columns' => $columns ];
		return match ( $suffix ) {
			self::QUOTES_SUFFIX => $primary + [
				'site_uuid' => $index( [ 'site_id', 'quote_uuid' ], true ), 'site_issue' => $index( [ 'site_id', 'issue_namespace_hash' ], true ),
				'site_accept' => $index( [ 'site_id', 'accept_namespace_hash' ], true ), 'site_invalidate' => $index( [ 'site_id', 'invalidate_namespace_hash' ], true ),
				'quote_expiry' => $index( [ 'site_id', 'state', 'expires_at', 'id' ] ), 'principal_quotes' => $index( [ 'site_id', 'principal_hash', 'id' ] ), 'owner_quotes' => $index( [ 'site_id', 'owner_digest', 'id' ] ),
			],
			self::BINDINGS_SUFFIX => $primary + [
				'site_placement' => $index( [ 'site_id', 'placement_uuid' ], true ), 'site_order_manifest' => $index( [ 'site_id', 'order_id', 'managed_group_manifest_digest' ], true ),
				'site_quote' => $index( [ 'site_id', 'quote_uuid' ], true ), 'site_bind' => $index( [ 'site_id', 'bind_namespace_hash' ], true ), 'site_seal' => $index( [ 'site_id', 'seal_namespace_hash' ], true ), 'binding_order' => $index( [ 'site_id', 'order_id', 'id' ] ),
			],
			self::BUDGET_SUFFIX => $primary + [
				'budget_slot' => $index( [ 'site_id', 'purpose', 'slot_kind', 'slot_key', 'window_start' ], true ), 'site_admission_namespace' => $index( [ 'site_id', 'admission_namespace_hash' ], true ), 'budget_expiry' => $index( [ 'site_id', 'slot_kind', 'last_seen_at', 'id' ] ),
			],
			default => throw new \InvalidArgumentException( 'Delivery quote table suffix is invalid.' ),
		};
	}
	/** Only these legacy prerequisites are inspected; other price columns/indexes survive. */
	public static function rate_columns(): array {
		return [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'delivery_offer_id' => [ 'bigint unsigned', false, null, '', null ], 'destination_zone_id' => [ 'bigint unsigned', false, null, '', null ], 'base_currency' => [ 'char(3)', false, '', '', 'site' ] ];
	}
	public static function rate_index(): array { return [ 'unique' => false, 'columns' => [ 'delivery_offer_id', 'destination_zone_id', 'base_currency', 'id' ] ]; }
	private static function qualified_prefix( string $raw ): string {
		if ( '' === $raw || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $raw ) ) { self::invalid_prefix(); }
		$prefix = $raw . TableNames::PREFIX; self::assert_prefix( $prefix ); return $prefix;
	}
	private static function assert_prefix( string $prefix ): void {
		if ( '' === $prefix || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::invalid_prefix(); }
		foreach ( self::SUFFIXES as $suffix ) { if ( strlen( $prefix . $suffix ) > 64 ) { self::invalid_prefix(); } }
	}
	private static function invalid_prefix(): never { throw new \InvalidArgumentException( 'Delivery quote table prefix is invalid.' ); }
}
