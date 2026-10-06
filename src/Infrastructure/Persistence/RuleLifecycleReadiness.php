<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use RuntimeException;
use Throwable;

/** Feature-local inspection. No writes, cache truth, conversion or legacy gate. */
final class RuleLifecycleReadiness {

	public const MIGRATION_ID = '20261006214731_create_rule_lifecycle_tables';
	private ?object $connection;

	public function __construct( ?object $connection = null, private ?RuleFamilyRegistry $families = null ) {
		$this->connection = $connection ?? ( $GLOBALS['wpdb'] ?? null );
	}

	/** Refuse incompatibility before dbDelta can issue any ALTER. */
	public function preflight(): void {
		$this->probe( function (): void {
			foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
				$this->inspect( $suffix, true );
			}
			$this->check_stored_records();
		} );
	}

	/** Exact structure; ordinary readiness deliberately does not scan history. */
	public function verify(): void {
		$this->probe( function (): void {
			foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
				$this->inspect( $suffix, false );
			}
		} );
	}

	/** Fixed ceilings and bounded pages. Unknown families/rows are never migrated. */
	public function verify_stored_records(): void {
		$this->probe( fn () => $this->check_stored_records() );
	}

	/** @return array{ready:bool,code:string} */
	public function get_status(): array {
		try {
			return $this->probe( function (): array {
				$version = $this->read_option( SchemaVersion::OPTION_NAME );
				if ( ! is_string( $version ) || 1 !== preg_match( '/\A[1-9][0-9]{0,8}\z/D', $version ) || version_compare( $version, '8', '<' ) ) {
					return [ 'ready' => false, 'code' => 'schema_unavailable' ];
				}
				$encoded = $this->read_option( MigrationStatus::OPTION_NAME );
				$status = is_string( $encoded ) && strlen( $encoded ) <= 16384
					? @unserialize( $encoded, [ 'allowed_classes' => false, 'max_depth' => 8 ] ) : null;
				if ( ! is_array( $status ) || 'success' !== ( $status['status'] ?? null )
					|| $version !== (string) ( $status['to_version'] ?? '' )
					|| ! is_string( $status['migration_id'] ?? null )
					|| ( '8' === $version && self::MIGRATION_ID !== $status['migration_id'] )
				) {
					return [ 'ready' => false, 'code' => 'migration_unconfirmed' ];
				}
				foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
					$this->inspect( $suffix, false );
				}
				( new OperationStoreReadiness( $this->require_connection() ) )->verify();
				return [ 'ready' => true, 'code' => 'ready' ];
			} );
		} catch ( Throwable ) {
			return [ 'ready' => false, 'code' => 'store_unverified' ];
		}
	}

	public function assert_ready(): void {
		if ( ! $this->get_status()['ready'] ) {
			self::refuse();
		}
	}

	private function inspect( string $suffix, bool $partial ): void {
		$table = $this->table( $suffix );
		$status = $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		if ( null === $status ) {
			if ( $partial ) { return; }
			self::refuse();
		}
		$collation = $this->site_collation();
		if ( 'InnoDB' !== ( $status['Engine'] ?? null ) || $collation !== ( $status['Collation'] ?? null ) ) {
			self::refuse();
		}
		$columns = $this->rows( "SHOW FULL COLUMNS FROM `{$table}`" );
		$expected = RuleLifecycleSchema::columns( $suffix );
		$seen = [];
		foreach ( $columns as $column ) {
			$name = $column['Field'] ?? null;
			if ( ! is_string( $name ) || ! isset( $expected[ $name ] ) || isset( $seen[ $name ] ) ) { self::refuse(); }
			$seen[ $name ] = true;
			[ $type, $nullable, $default, $extra, $purpose ] = $expected[ $name ];
			$actual_type = strtolower( (string) ( $column['Type'] ?? '' ) );
			$actual_type = preg_replace( '/\b(bigint|smallint|int)\([0-9]+\)/', '$1', $actual_type );
			if ( $type !== $actual_type || ( $nullable ? 'YES' : 'NO' ) !== ( $column['Null'] ?? null )
				|| $default !== ( null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'] )
				|| $extra !== strtolower( (string) ( $column['Extra'] ?? '' ) )
				|| ( 'site' === $purpose ? $collation : $purpose ) !== ( $column['Collation'] ?? null )
			) { self::refuse(); }
		}
		$indexes = $this->rows( "SHOW INDEX FROM `{$table}`" );
		$expected_indexes = RuleLifecycleSchema::indexes( $suffix );
		$seen_indexes = [];
		foreach ( $indexes as $index ) {
			$name = $index['Key_name'] ?? null;
			$position = self::positive_integer( $index['Seq_in_index'] ?? null );
			if ( ! is_string( $name ) || ! isset( $expected_indexes[ $name ] )
				|| isset( $seen_indexes[ $name ][ $position ] )
				|| ( $expected_indexes[ $name ]['unique'] ? '0' : '1' ) !== (string) ( $index['Non_unique'] ?? '' )
				|| null !== ( $index['Sub_part'] ?? null ) || 'BTREE' !== strtoupper( (string) ( $index['Index_type'] ?? '' ) )
				|| 'A' !== ( $index['Collation'] ?? null )
				|| ! isset( $expected_indexes[ $name ]['columns'][ $position - 1 ] )
				|| $expected_indexes[ $name ]['columns'][ $position - 1 ] !== ( $index['Column_name'] ?? null )
			) { self::refuse(); }
			$seen_indexes[ $name ][ $position ] = true;
		}
		$missing = count( $seen ) !== count( $expected );
		foreach ( $expected_indexes as $name => $definition ) {
			if ( isset( $seen_indexes[ $name ] ) && count( $seen_indexes[ $name ] ) !== count( $definition['columns'] ) ) { self::refuse(); }
			$missing = $missing || ! isset( $seen_indexes[ $name ] );
		}
		if ( $missing ) {
			// A partial first install can have only approved compatible structures
			// and no rows. Populated/incompatible partial stores are not repaired.
			if ( ! $partial || null !== $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) { self::refuse(); }
		}
	}

	private function check_stored_records(): void {
		$tables = [];
		$any_rows = false;
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
			$table = $this->table( $suffix );
			$exists = null !== $this->row( $this->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
			$tables[ $suffix ] = $exists;
			if ( $exists && null !== $this->row( "SELECT 1 AS present FROM `{$table}` LIMIT 1" ) ) { $any_rows = true; }
		}
		if ( ! $any_rows ) { return; }
		if ( in_array( false, $tables, true ) ) { self::refuse(); }
		$rules = $this->table( RuleLifecycleSchema::RULES_SUFFIX );
		$versions = $this->table( RuleLifecycleSchema::VERSIONS_SUFFIX );
		if ( null !== $this->row( "SELECT id FROM `{$rules}` WHERE OCTET_LENGTH(`scope_json`) > 4096 LIMIT 1" )
			|| null !== $this->row( "SELECT id FROM `{$versions}` WHERE OCTET_LENGTH(`payload_json`) > 16384 OR OCTET_LENGTH(`change_reason`) > 512 LIMIT 1" )
		) { self::refuse(); }
		$this->validate_population();
	}

	/** Stream exact codecs, then validate authoritative relationships in both directions. */
	private function validate_population(): void {
		$families = $this->families ?? new RuleFamilyRegistry();
		$guards = $this->table( RuleLifecycleSchema::GUARDS_SUFFIX );
		$rules = $this->table( RuleLifecycleSchema::RULES_SUFFIX );
		$versions = $this->table( RuleLifecycleSchema::VERSIONS_SUFFIX );
		// Freeze every identity ceiling before walking any table.
		$ceilings = [];
		foreach ( [ $guards, $rules, $versions ] as $table ) {
			$ceiling = $this->row( "SELECT MAX(id) AS ceiling FROM `{$table}`" );
			$ceilings[ $table ] = null === ( $ceiling['ceiling'] ?? null ) ? 0 : self::positive_integer( $ceiling['ceiling'] );
		}
		$hydrate_rule = function ( array $row ) use ( $families, $guards, $ceilings ): array {
			$this->assert_site( $row['site_id'] ?? null );
			$guard_id = self::positive_integer( $row['family_guard_id'] ?? null );
			$guard_row = $this->reference( $guards, $guard_id, $ceilings[ $guards ] );
			$this->assert_site( $guard_row['site_id'] ?? null );
			$profile = $families->get( (string) ( $guard_row['family_code'] ?? '' ) );
			$guard = RuleFamilyGuard::from_row( $guard_row, $profile );
			return [ LogicalRule::from_row( $row, $guard, $profile ), $profile ];
		};
		$this->walk( $guards, $ceilings[ $guards ], function ( array $row ) use ( $families ): void {
			$this->assert_site( $row['site_id'] ?? null );
			RuleFamilyGuard::from_row( $row, $families->get( (string) ( $row['family_code'] ?? '' ) ) );
		} );
		$this->walk( $rules, $ceilings[ $rules ], function ( array $row ) use ( $hydrate_rule, $versions, $ceilings ): void {
			[ $logical, $profile ] = $hydrate_rule( $row );
			$id = self::positive_integer( $row['id'] ?? null );
			$max = $this->row( $this->prepare( "SELECT MAX(version_sequence) AS last_sequence FROM `{$versions}` WHERE site_id = %d AND logical_rule_id = %d AND id <= %d", self::positive_integer( $row['site_id'] ), $id, $ceilings[ $versions ] ) );
			$last = null === ( $max['last_sequence'] ?? null ) ? 0 : self::positive_integer( $max['last_sequence'] );
			if ( (string) $last !== (string) ( $row['last_version_sequence'] ?? '' ) ) { self::refuse(); }
			foreach ( [ 'draft_version_id' => 'draft', 'scheduled_version_id' => 'scheduled', 'current_published_version_id' => 'published' ] as $pointer => $state ) {
				if ( null === $row[ $pointer ] ) { continue; }
				$version_row = $this->reference( $versions, self::positive_integer( $row[ $pointer ] ), $ceilings[ $versions ] );
				RuleVersion::from_row( $version_row, $logical, $profile );
				if ( $state !== ( $version_row['state'] ?? null ) ) { self::refuse(); }
			}
		} );
		$this->walk( $versions, $ceilings[ $versions ], function ( array $row ) use ( $hydrate_rule, $rules, $versions, $ceilings ): void {
			$this->assert_site( $row['site_id'] ?? null );
			$rule_row = $this->reference( $rules, self::positive_integer( $row['logical_rule_id'] ?? null ), $ceilings[ $rules ] );
			[ $logical, $profile ] = $hydrate_rule( $rule_row );
			RuleVersion::from_row( $row, $logical, $profile );
			$id = self::positive_integer( $row['id'] );
			$pointer = match ( $row['state'] ) { 'draft' => 'draft_version_id', 'scheduled' => 'scheduled_version_id', 'published' => 'current_published_version_id', 'retired' => null, default => null };
			if ( null !== $pointer && (string) $id !== (string) $rule_row[ $pointer ] ) { self::refuse(); }
			if ( 'retired' === $row['state'] && in_array( (string) $id, array_map( static fn ( mixed $value ): string => (string) $value, [ $rule_row['draft_version_id'], $rule_row['scheduled_version_id'], $rule_row['current_published_version_id'] ] ), true ) ) { self::refuse(); }
			if ( null !== $row['supersedes_version_id'] ) {
				$previous = $this->reference( $versions, self::positive_integer( $row['supersedes_version_id'] ), $ceilings[ $versions ] );
				RuleVersion::from_row( $previous, $logical, $profile );
				if ( self::positive_integer( $previous['version_sequence'] ) >= self::positive_integer( $row['version_sequence'] ) || null === $previous['published_at'] ) { self::refuse(); }
				if ( null !== $row['scheduled_predecessor_row_revision'] && self::positive_integer( $row['scheduled_predecessor_row_revision'] ) > self::positive_integer( $previous['row_revision'] ) ) { self::refuse(); }
				if ( null !== $row['published_at'] && $previous['retired_at'] !== $row['published_at'] ) { self::refuse(); }
			}
		} );
	}

	private function reference( string $table, int $id, int $ceiling ): array {
		if ( $id > $ceiling ) { self::refuse(); }
		$row = $this->row( $this->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ) );
		if ( null === $row || self::positive_integer( $row['id'] ?? null ) !== $id ) { self::refuse(); }
		return $row;
	}

	private function walk( string $table, int $ceiling, callable $validate ): void {
		$after = 0;
		while ( $after < $ceiling ) {
			$page = $this->rows( $this->prepare( "SELECT * FROM `{$table}` WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 100", $after, $ceiling ) );
			if ( [] === $page ) { break; }
			if ( count( $page ) > 100 ) { self::refuse(); }
			foreach ( $page as $record ) {
				$id = self::positive_integer( $record['id'] ?? null );
				if ( $id <= $after || $id > $ceiling ) { self::refuse(); }
				$validate( $record );
				$after = $id;
			}
		}
	}

	private function assert_site( mixed $site ): void {
		$expected = $this->connection instanceof OperationSession ? $this->connection->site_id()
			: ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
		if ( self::positive_integer( $site ) !== $expected ) { self::refuse(); }
	}

	private function site_collation(): string {
		$connection = $this->require_connection();
		$declaration = $connection instanceof OperationSession ? $connection->charset_collate() : $connection->get_charset_collate();
		$details = RuleLifecycleSchema::charset_details( $declaration );
		if ( null !== $details['collation'] ) { return $details['collation']; }
		$row = $this->row( $this->prepare( 'SHOW CHARACTER SET LIKE %s', $details['charset'] ) );
		$value = $row['Default collation'] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $value ) ) { self::refuse(); }
		return strtolower( $value );
	}

	private function table( string $suffix ): string {
		$connection = $this->require_connection();
		$prefix = $connection instanceof OperationSession ? $connection->table_prefix() : (string) ( $connection->prefix ?? '' );
		$table = $prefix . TableNames::PREFIX . $suffix;
		if ( '' === $prefix || strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); }
		return $table;
	}

	private function read_option( string $name ): ?string {
		$connection = $this->require_connection();
		$prefix = $connection instanceof OperationSession ? $connection->table_prefix() : (string) ( $connection->prefix ?? '' );
		$table = $prefix . 'options';
		if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { self::refuse(); }
		$row = $this->row( $this->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name ) );
		return null === $row ? null : ( is_string( $row['option_value'] ?? null ) ? $row['option_value'] : null );
	}

	private function probe( callable $work ): mixed {
		$connection = $this->require_connection();
		$owned = $connection instanceof OperationSession && ! $connection->in_transaction();
		if ( $owned && ! $connection->begin() ) { self::refuse(); }
		try {
			$result = $work();
		} catch ( Throwable ) {
			if ( $owned && ! $connection->rollback() ) { $connection->retire(); }
			self::refuse();
		}
		if ( $owned && ! $connection->rollback() ) {
			$connection->retire();
			self::refuse();
		}
		return $result;
	}

	private function rows( string $sql ): array {
		$connection = $this->require_connection();
		$result = $connection instanceof OperationSession ? $connection->get_results( $sql ) : $connection->get_results( $sql, ARRAY_A );
		if ( ! is_array( $result ) || $this->failed_query() ) { self::refuse(); }
		foreach ( $result as $row ) { if ( ! is_array( $row ) ) { self::refuse(); } }
		return $result;
	}

	private function row( string $sql ): ?array {
		$connection = $this->require_connection();
		$result = $connection instanceof OperationSession ? $connection->get_row( $sql ) : $connection->get_row( $sql, ARRAY_A );
		if ( false === $result || ( null !== $result && ! is_array( $result ) ) || $this->failed_query() ) { self::refuse(); }
		return $result;
	}

	private function failed_query(): bool {
		return $this->connection instanceof OperationSession ? 0 !== $this->connection->errno()
			: '' !== trim( (string) ( $this->connection->last_error ?? '' ) );
	}

	private function prepare( string $sql, mixed ...$args ): string {
		return $this->require_connection()->prepare( $sql, ...$args );
	}

	private function require_connection(): object {
		if ( ! is_object( $this->connection ) ) { self::refuse(); }
		return $this->connection;
	}

	private static function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) { return $value; }
		if ( is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value )
			&& ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) )
		) { return (int) $value; }
		self::refuse();
	}

	private static function refuse(): never {
		throw new RuntimeException( 'Rule lifecycle store could not be verified.' );
	}
}
