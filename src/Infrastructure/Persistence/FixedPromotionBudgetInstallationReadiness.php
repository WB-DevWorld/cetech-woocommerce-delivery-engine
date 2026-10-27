<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteStorageCodec};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Forward-install inspection only. Successful metadata is not quote or payment authority. */
final class FixedPromotionBudgetInstallationReadiness {
	public const MIGRATION_ID = '20261021213000_create_promotion_budget_tables';
	public const MAX_RECORDS = 1000;
	private ?object $connection;
	private ?array $binding = null;
	private ?string $collation = null;

	public function __construct( ?object $connection = null ) { $this->connection = $connection ?? ( $GLOBALS['wpdb'] ?? null ); }

	/** Both structures and retained rows are checked before the first dbDelta call. */
	public function preflight(): void {
		$this->probe( function (): void {
			$present = [];
			foreach ( FixedPromotionBudgetSchema::SUFFIXES as $suffix ) { if ( $this->inspect( $suffix, true ) ) { $present[] = $suffix; } }
			if ( 1 === count( $present ) ) {
				$table = $this->table( $present[0] );
				if ( null !== $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) { self::refuse(); }
			} elseif ( 2 === count( $present ) ) { $this->check_records(); }
		} );
	}
	public function verify_table( string $suffix ): void { $this->probe( fn() => $this->inspect( $suffix, false ) ); }
	public function verify(): void { $this->probe( function (): void { foreach ( FixedPromotionBudgetSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); } } ); }
	public function verify_stored_records(): void { $this->probe( function (): void { foreach ( FixedPromotionBudgetSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); } $this->check_records(); } ); }

	/** Publication and exact structure only; ordinary diagnostics never walk history. */
	public function get_status(): array {
		try {
			return $this->probe( function (): array {
				$version = $this->read_option( SchemaVersion::OPTION_NAME );
				if ( null === $version || 1 !== preg_match( '/\A[1-9][0-9]{0,8}\z/D', $version ) || version_compare( $version, '12', '<' ) ) { return [ 'ready' => false, 'code' => 'schema_unavailable' ]; }
				$encoded = $this->read_option( MigrationStatus::OPTION_NAME );
				$status = null === $encoded ? null : @unserialize( $encoded, [ 'allowed_classes' => false, 'max_depth' => 8 ] );
				if ( ! is_array( $status ) || 'success' !== ( $status['status'] ?? null ) || $version !== ( $status['to_version'] ?? null ) || ! is_string( $status['migration_id'] ?? null ) || '' === $status['migration_id'] || ( '12' === $version && self::MIGRATION_ID !== $status['migration_id'] ) ) { return [ 'ready' => false, 'code' => 'migration_unconfirmed' ]; }
				foreach ( FixedPromotionBudgetSchema::SUFFIXES as $suffix ) { $this->inspect( $suffix, false ); }
				return [ 'ready' => true, 'code' => 'ready' ];
			} );
		} catch ( \Throwable ) { return [ 'ready' => false, 'code' => 'store_unverified' ]; }
	}
	public function assert_ready(): void { if ( ! $this->get_status()['ready'] ) { self::refuse(); } }

	private function inspect( string $suffix, bool $missing_allowed ): bool {
		$expected = FixedPromotionBudgetSchema::columns( $suffix ); $table = $this->table( $suffix );
		$status = $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( null === $status ) { if ( $missing_allowed ) { return false; } self::refuse(); }
		$connection = $this->require_connection();
		if ( $connection instanceof OperationSession ) {
			if ( ! $connection->validate_tables( [ $table ] ) || 0 !== $connection->errno() ) { self::refuse(); } $this->assert_binding();
			$status = $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		}
		if ( ( $status['Name'] ?? null ) !== $table || 'InnoDB' !== ( $status['Engine'] ?? null ) || $this->site_collation() !== ( $status['Collation'] ?? null ) ) { self::refuse(); }
		$seen = [];
		foreach ( $this->rows( "SHOW FULL COLUMNS FROM `{$table}`", 16 ) as $column ) {
			foreach ( [ 'Field', 'Type', 'Null', 'Default', 'Extra', 'Collation' ] as $required ) { if ( ! array_key_exists( $required, $column ) ) { self::refuse(); } }
			$name = $column['Field']; if ( ! is_string( $name ) || ! isset( $expected[$name] ) || isset( $seen[$name] ) ) { self::refuse(); } $seen[$name] = true;
			[ $type, $nullable, $default, $extra, $purpose ] = $expected[$name];
			$actual = preg_replace( '/\b(bigint|smallint|int)\([0-9]+\)/', '$1', strtolower( (string) $column['Type'] ) );
			if ( $actual !== $type || ( $nullable ? 'YES' : 'NO' ) !== $column['Null'] || $default !== ( null === $column['Default'] ? null : (string) $column['Default'] ) || $extra !== strtolower( (string) $column['Extra'] ) || ( 'site' === $purpose ? $this->site_collation() : $purpose ) !== $column['Collation'] ) { self::refuse(); }
		}
		if ( count( $seen ) !== count( $expected ) ) { self::refuse(); }
		$indexes = FixedPromotionBudgetSchema::indexes( $suffix ); $parts = [];
		foreach ( $this->rows( "SHOW INDEX FROM `{$table}`", 16 ) as $index ) {
			foreach ( [ 'Key_name', 'Seq_in_index', 'Non_unique', 'Sub_part', 'Index_type', 'Collation', 'Column_name' ] as $required ) { if ( ! array_key_exists( $required, $index ) ) { self::refuse(); } }
			$name = $index['Key_name']; if ( ! is_string( $name ) || ! isset( $indexes[$name] ) ) { self::refuse(); }
			$position = QuoteStorageCodec::integer( $index['Seq_in_index'], 1, 16 ); $definition = $indexes[$name];
			if ( isset( $parts[$name][$position] ) || ! isset( $definition['columns'][$position - 1] ) || $definition['columns'][$position - 1] !== $index['Column_name'] || ( $definition['unique'] ? 0 : 1 ) !== QuoteStorageCodec::integer( $index['Non_unique'], 0, 1 ) || null !== $index['Sub_part'] || 'BTREE' !== strtoupper( (string) $index['Index_type'] ) || 'A' !== $index['Collation'] || null !== ( $index['Expression'] ?? null ) || ( array_key_exists( 'Visible', $index ) && 'YES' !== strtoupper( (string) $index['Visible'] ) ) || ( array_key_exists( 'Ignored', $index ) && 'NO' !== strtoupper( (string) $index['Ignored'] ) ) ) { self::refuse(); }
			$parts[$name][$position] = true;
		}
		foreach ( $indexes as $name => $definition ) { if ( count( $parts[$name] ?? [] ) !== count( $definition['columns'] ) ) { self::refuse(); } }
		return true;
	}

	/** Bounded complete migration census; an incomplete or corrupt ledger is never repaired. */
	private function check_records(): void {
		$accounts = []; $totals = []; $seen_claims = [];
		foreach ( $this->records( FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX ) as $row ) {
			$account = FixedPromotionBudgetRecordCodec::account_from_row( $row ); $this->assert_site( $account->site_id() ); $key = $account->account_key();
			if ( isset( $accounts[$key] ) ) { self::refuse(); } $accounts[$key] = $account;
			$zero = QuoteMoney::from_array( [ 'amount' => '0', 'currency' => $account->limit()->currency(), 'precision' => $account->limit()->precision() ] ); $totals[$key] = [ 'held' => $zero, 'committed' => $zero ];
		}
		foreach ( $this->records( FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX ) as $row ) {
			$claim = FixedPromotionBudgetRecordCodec::reservation_from_row( $row ); $request = $claim->request(); $this->assert_site( $request->site_id() ); $key = $request->account_key(); $claim_key = $request->claim_key();
			if ( isset( $seen_claims[$claim_key] ) || ! isset( $accounts[$key] ) ) { self::refuse(); } $seen_claims[$claim_key] = true; $account = $accounts[$key];
			if ( $account->funding_source() !== $request->funding_source() || $account->limit()->currency() !== $request->amount()->currency() || $account->limit()->precision() !== $request->amount()->precision() ) { self::refuse(); }
			if ( in_array( $claim->state(), [ 'held', 'committed' ], true ) ) { $totals[$key][$claim->state()] = $totals[$key][$claim->state()]->add( $request->amount() ); }
		}
		foreach ( $accounts as $key => $account ) { if ( 0 !== $account->held()->compare( $totals[$key]['held'] ) || 0 !== $account->spent()->compare( $totals[$key]['committed'] ) ) { self::refuse(); } }
	}
	private function records( string $suffix ): array {
		$table = $this->table( $suffix );
		$rows = $this->rows( 'SELECT ' . FixedPromotionBudgetRecordCodec::projection( $suffix ) . " FROM `{$table}` ORDER BY id ASC LIMIT 1001", self::MAX_RECORDS ); $after = 0; $result = [];
		foreach ( $rows as $raw ) { $row = FixedPromotionBudgetRecordCodec::bounded_row( $suffix, $raw ); $id = QuoteStorageCodec::integer( $row['id'] ); if ( $id <= $after ) { self::refuse(); } $after = $id; $result[] = $row; }
		return $result;
	}
	private function assert_site( int $site ): void { if ( $site !== $this->binding['site'] ) { self::refuse(); } }
	private function table( string $suffix ): string { return FixedPromotionBudgetSchema::table( $this->binding['prefix'], $suffix ); }
	private function site_collation(): string {
		if ( null !== $this->collation ) { return $this->collation; }
		$details = FixedPromotionBudgetSchema::charset_details( $this->binding['charset'] );
		if ( null !== $details['collation'] ) { return $this->collation = $details['collation']; }
		$row = $this->row( $this->prepare( 'SHOW CHARACTER SET LIKE %s', $details['charset'] ) ); $collation = $row['Default collation'] ?? null;
		if ( ! is_string( $collation ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $collation ) ) { self::refuse(); } return $this->collation = strtolower( $collation );
	}
	private function read_option( string $name ): ?string {
		$table = $this->binding['prefix'] . 'options'; $connection = $this->require_connection();
		if ( isset( $connection->options ) && $connection->options !== $table ) { self::refuse(); }
		$row = $this->row( $this->prepare( "SELECT CASE WHEN OCTET_LENGTH(option_value) <= 16384 THEN option_value ELSE NULL END AS option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name ) );
		return null === $row || ! is_string( $row['option_value'] ?? null ) ? null : $row['option_value'];
	}
	private function probe( callable $work ): mixed {
		if ( null !== $this->binding ) { self::refuse(); }
		try { $this->binding = $this->capture_binding(); $result = $work(); $this->assert_binding(); return $result; }
		catch ( \Throwable ) { self::refuse(); }
		finally { $this->binding = null; $this->collation = null; }
	}
	private function capture_binding(): array {
		$connection = $this->require_connection();
		if ( $connection instanceof OperationSession ) {
			if ( $connection->is_retired() || ! $connection->in_transaction() ) { self::refuse(); }
			$site = $connection->site_id(); $prefix = $connection->table_prefix(); $charset = $connection->charset_collate();
			if ( $connection->is_retired() || ! $connection->in_transaction() || $connection->site_id() !== $site || $connection->table_prefix() !== $prefix ) { self::refuse(); }
		} else {
			$site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1; $prefix = $connection->prefix ?? null;
			if ( ! is_string( $prefix ) || ! is_callable( [ $connection, 'get_charset_collate' ] ) ) { self::refuse(); } $charset = $connection->get_charset_collate();
			if ( ( $connection->prefix ?? null ) !== $prefix || ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) !== $site ) { self::refuse(); }
		}
		if ( ! is_int( $site ) || $site < 1 || ! is_string( $prefix ) || ! is_string( $charset ) ) { self::refuse(); }
		FixedPromotionBudgetSchema::tables( $prefix ); FixedPromotionBudgetSchema::charset_details( $charset ); return [ 'site' => $site, 'prefix' => $prefix, 'charset' => $charset ];
	}
	private function assert_binding(): void { if ( null === $this->binding || $this->capture_binding() !== $this->binding ) { self::refuse(); } }
	private function row( string $sql ): ?array {
		$this->assert_binding(); $connection = $this->require_connection(); $row = $connection instanceof OperationSession ? $connection->get_row( $sql ) : $connection->get_row( $sql, ARRAY_A ); $this->assert_binding();
		if ( false === $row || ( null !== $row && ! is_array( $row ) ) || $this->query_failed() ) { self::refuse(); } return $row;
	}
	private function rows( string $sql, int $limit ): array {
		$this->assert_binding(); $connection = $this->require_connection(); $rows = $connection instanceof OperationSession ? $connection->get_results( $sql ) : $connection->get_results( $sql, ARRAY_A ); $this->assert_binding();
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > $limit || $this->query_failed() ) { self::refuse(); } foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { self::refuse(); } } return $rows;
	}
	private function query_failed(): bool { return $this->connection instanceof OperationSession ? 0 !== $this->connection->errno() : '' !== trim( (string) ( $this->connection->last_error ?? '' ) ); }
	private function prepare( string $sql, mixed ...$args ): string { $this->assert_binding(); $prepared = $this->require_connection()->prepare( $sql, ...$args ); $this->assert_binding(); if ( ! is_string( $prepared ) || '' === $prepared ) { self::refuse(); } return $prepared; }
	private function require_connection(): object { if ( ! is_object( $this->connection ) ) { self::refuse(); } return $this->connection; }
	private static function refuse(): never { throw new \RuntimeException( 'Promotion budget installation could not be verified.' ); }
}
