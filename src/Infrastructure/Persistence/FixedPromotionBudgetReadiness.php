<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Exact structural checks on an existing owner. No installation or schema-marker inference. */
final class FixedPromotionBudgetReadiness {
	public function assert_ready( OperationSession $session ): void {
		$site = $session->site_id(); $prefix = $session->table_prefix(); $charset = $session->charset_collate();
		try {
			$this->owned( $session, $site, $prefix, $charset );
			// The caller's transaction must retain participant metadata locks until completion.
			if ( ! $session->validate_tables( FixedPromotionBudgetSchema::tables( $prefix ) ) || 0 !== $session->errno() ) { self::refuse(); }
			$this->owned( $session, $site, $prefix, $charset ); $details = FixedPromotionBudgetSchema::charset_details( $charset ); $collation = $details['collation'];
			if ( null === $collation ) { $row = $this->one( $session, $session->prepare( 'SHOW CHARACTER SET LIKE %s', $details['charset'] ), $site, $prefix, $charset ); $collation = $row['Default collation'] ?? null; if ( ! is_string( $collation ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $collation ) ) { self::refuse(); } $collation = strtolower( $collation ); }
			foreach ( FixedPromotionBudgetSchema::SUFFIXES as $suffix ) {
				$table = FixedPromotionBudgetSchema::table( $prefix, $suffix ); $status = $this->one( $session, $session->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), $site, $prefix, $charset );
				if ( null === $status || ( $status['Name'] ?? null ) !== $table || 'InnoDB' !== ( $status['Engine'] ?? null ) || $collation !== ( $status['Collation'] ?? null ) ) { self::refuse(); }
				$expected = FixedPromotionBudgetSchema::columns( $suffix ); $seen = [];
				foreach ( $this->many( $session, "SHOW FULL COLUMNS FROM `{$table}`", 16, $site, $prefix, $charset ) as $column ) {
					foreach ( [ 'Field', 'Type', 'Null', 'Default', 'Extra', 'Collation' ] as $required ) { if ( ! array_key_exists( $required, $column ) ) { self::refuse(); } }
					$name = $column['Field'] ?? null; if ( ! is_string( $name ) || ! isset( $expected[$name] ) || isset( $seen[$name] ) ) { self::refuse(); } $seen[$name] = true;
					[ $type, $nullable, $default, $extra, $purpose ] = $expected[$name]; $actual = strtolower( (string) ( $column['Type'] ?? '' ) ); $actual = preg_replace( '/\b(bigint|smallint|int)\([0-9]+\)/', '$1', $actual );
					if ( $actual !== $type || ( $nullable ? 'YES' : 'NO' ) !== ( $column['Null'] ?? null ) || $default !== ( null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'] ) || $extra !== strtolower( (string) ( $column['Extra'] ?? '' ) ) || ( 'site' === $purpose ? $collation : $purpose ) !== ( $column['Collation'] ?? null ) ) { self::refuse(); }
				}
				if ( count( $seen ) !== count( $expected ) ) { self::refuse(); }
				$indexes = FixedPromotionBudgetSchema::indexes( $suffix ); $parts = [];
				foreach ( $this->many( $session, "SHOW INDEX FROM `{$table}`", 16, $site, $prefix, $charset ) as $index ) {
					foreach ( [ 'Key_name', 'Seq_in_index', 'Non_unique', 'Sub_part', 'Index_type', 'Collation', 'Column_name' ] as $required ) { if ( ! array_key_exists( $required, $index ) ) { self::refuse(); } }
					$name = $index['Key_name'] ?? null; if ( ! is_string( $name ) || ! isset( $indexes[$name] ) ) { self::refuse(); } $position = QuoteStorageCodec::integer( $index['Seq_in_index'] ?? null, 1, 16 ); $definition = $indexes[$name];
					if ( isset( $parts[$name][$position] ) || ! isset( $definition['columns'][$position - 1] ) || $definition['columns'][$position - 1] !== ( $index['Column_name'] ?? null ) || ( $definition['unique'] ? 0 : 1 ) !== QuoteStorageCodec::integer( $index['Non_unique'], 0, 1 ) || null !== $index['Sub_part'] || 'BTREE' !== strtoupper( (string) ( $index['Index_type'] ?? '' ) ) || 'A' !== ( $index['Collation'] ?? null ) || null !== ( $index['Expression'] ?? null ) || ( array_key_exists( 'Visible', $index ) && 'YES' !== strtoupper( (string) $index['Visible'] ) ) || ( array_key_exists( 'Ignored', $index ) && 'NO' !== strtoupper( (string) $index['Ignored'] ) ) ) { self::refuse(); } $parts[$name][$position] = true;
				}
				foreach ( $indexes as $name => $definition ) { if ( count( $parts[$name] ?? [] ) !== count( $definition['columns'] ) ) { self::refuse(); } }
			}
			$this->owned( $session, $site, $prefix, $charset );
		} catch ( \Throwable ) { self::refuse(); }
	}
	private function one( OperationSession $session, string $sql, int $site, string $prefix, string $charset ): ?array { $this->owned( $session, $site, $prefix, $charset ); $row = $session->get_row( $sql ); $this->owned( $session, $site, $prefix, $charset ); if ( false === $row || 0 !== $session->errno() ) { self::refuse(); } return $row; }
	private function many( OperationSession $session, string $sql, int $limit, int $site, string $prefix, string $charset ): array { $this->owned( $session, $site, $prefix, $charset ); $rows = $session->get_results( $sql ); $this->owned( $session, $site, $prefix, $charset ); if ( ! is_array( $rows ) || ! array_is_list( $rows ) || [] === $rows || count( $rows ) > $limit || 0 !== $session->errno() ) { self::refuse(); } foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { self::refuse(); } } return $rows; }
	private function owned( OperationSession $session, int $site, string $prefix, string $charset ): void { if ( $site < 1 || $session->site_id() !== $site || $session->table_prefix() !== $prefix || $session->charset_collate() !== $charset || $session->is_retired() || ! $session->in_transaction() ) { self::refuse(); } FixedPromotionBudgetSchema::tables( $prefix ); }
	private static function refuse(): never { throw new \RuntimeException( 'Promotion budget storage could not be verified.' ); }
}
