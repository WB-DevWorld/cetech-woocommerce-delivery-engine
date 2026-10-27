<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Explicit provisioning contract only. Normal activation does not install these stores. */
final class FixedPromotionBudgetSchema {
	public const ACCOUNTS_SUFFIX = 'promotion_budget_accounts';
	public const RESERVATIONS_SUFFIX = 'promotion_budget_reservations';
	public const SUFFIXES = [ self::ACCOUNTS_SUFFIX, self::RESERVATIONS_SUFFIX ];
	public const ACCOUNT_JSON_BYTES = 4096;
	public const RESERVATION_JSON_BYTES = 16384;

	public static function tables( string $site_prefix ): array { $qualified = self::qualified_prefix( $site_prefix ); return array_map( static fn( string $suffix ): string => $qualified . $suffix, self::SUFFIXES ); }
	public static function table( string $site_prefix, string $suffix ): string { self::columns( $suffix ); return self::qualified_prefix( $site_prefix ) . $suffix; }
	public static function charset_details( string $declaration ): array { return PromiseStorageSchema::charset_details( $declaration ); }
	/** Both arguments are explicit; no global connection, schema option or migration discovery. */
	public static function create_table_statements( string $charset_collate, string $qualified_prefix ): array {
		self::charset_details( $charset_collate ); self::assert_prefix( $qualified_prefix ); $out = [];
		foreach ( self::SUFFIXES as $suffix ) {
			$lines = [];
			foreach ( self::columns( $suffix ) as $field => [ $type, $nullable, $default, $extra, $collation ] ) {
				$line = "  {$field} {$type}";
				if ( 'ascii_bin' === $collation ) { $line .= ' CHARACTER SET ascii COLLATE ascii_bin'; }
				$line .= $nullable ? ' NULL' : ' NOT NULL';
				if ( null !== $default ) { $line .= ' DEFAULT ' . $default; }
				if ( '' !== $extra ) { $line .= ' ' . strtoupper( $extra ); }
				$lines[] = $line;
			}
			foreach ( self::indexes( $suffix ) as $name => $index ) { $fields = implode( ', ', $index['columns'] ); $lines[] = 'PRIMARY' === $name ? "  PRIMARY KEY  ({$fields})" : '  ' . ( $index['unique'] ? 'UNIQUE KEY' : 'KEY' ) . " {$name} ({$fields})"; }
			$out[$suffix] = "CREATE TABLE {$qualified_prefix}{$suffix} (\n" . implode( ",\n", $lines ) . "\n) ENGINE=InnoDB {$charset_collate};";
		}
		return $out;
	}
	/** Exact type, nullability, default, extra and collation purpose. */
	public static function columns( string $suffix ): array {
		$number = [ 'bigint unsigned', false, null, '', null ]; $ascii = [ 'char(64)', false, null, '', 'ascii_bin' ]; $json = [ 'longtext', false, null, '', 'site' ];
		$base = [ 'id' => [ 'bigint unsigned', false, null, 'auto_increment', null ], 'site_id' => $number ];
		return match ( $suffix ) {
			self::ACCOUNTS_SUFFIX => $base + [ 'account_key' => $ascii, 'format_version' => [ 'smallint unsigned', false, null, '', null ], 'revision' => $number, 'state_json' => $json, 'state_digest' => $ascii ],
			self::RESERVATIONS_SUFFIX => $base + [ 'claim_key' => $ascii, 'account_key' => $ascii, 'format_version' => [ 'smallint unsigned', false, null, '', null ], 'state' => [ 'varchar(16)', false, null, '', 'ascii_bin' ], 'revision' => $number, 'reservation_json' => $json, 'reservation_digest' => $ascii ],
			default => throw new \InvalidArgumentException( 'Unknown promotion budget table.' ),
		};
	}
	public static function indexes( string $suffix ): array {
		$primary = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ] ];
		return match ( $suffix ) {
			self::ACCOUNTS_SUFFIX => $primary + [ 'site_account' => [ 'unique' => true, 'columns' => [ 'site_id', 'account_key' ] ] ],
			self::RESERVATIONS_SUFFIX => $primary + [ 'site_claim' => [ 'unique' => true, 'columns' => [ 'site_id', 'claim_key' ] ], 'account_claims' => [ 'unique' => false, 'columns' => [ 'site_id', 'account_key', 'id' ] ] ],
			default => throw new \InvalidArgumentException( 'Unknown promotion budget table.' ),
		};
	}
	public static function payload_limits( string $suffix ): array { return match ( $suffix ) { self::ACCOUNTS_SUFFIX => [ 'state_json' => self::ACCOUNT_JSON_BYTES ], self::RESERVATIONS_SUFFIX => [ 'reservation_json' => self::RESERVATION_JSON_BYTES ], default => throw new \InvalidArgumentException( 'Unknown promotion budget table.' ) }; }
	private static function qualified_prefix( string $raw ): string { if ( '' === $raw || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $raw ) ) { self::invalid_prefix(); } $qualified = $raw . TableNames::PREFIX; self::assert_prefix( $qualified ); return $qualified; }
	private static function assert_prefix( string $prefix ): void { if ( '' === $prefix || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) || ! str_ends_with( $prefix, TableNames::PREFIX ) ) { self::invalid_prefix(); } foreach ( self::SUFFIXES as $suffix ) { if ( strlen( $prefix . $suffix ) > 64 ) { self::invalid_prefix(); } } }
	private static function invalid_prefix(): never { throw new \InvalidArgumentException( 'Invalid promotion budget table prefix.' ); }
}
