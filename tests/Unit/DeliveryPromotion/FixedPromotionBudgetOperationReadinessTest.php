<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetInstallationReadiness as Installation, FixedPromotionBudgetOperationReadiness as Readiness, FixedPromotionBudgetSchema as Schema};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\FixedPromotionBudgetMetadataSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/FixedPromotionBudgetMetadataSession.php';

/** Current publication protocol only; real RR/concurrency is qualified separately in owned SQL. */
final class FixedPromotionBudgetOperationReadinessTest extends TestCase {
	private function session(): PromotionOperationReadinessSession { return new PromotionOperationReadinessSession(); }
	private function refuses( PromotionOperationReadinessSession $session ): void {
		try { ( new Readiness() )->assert_ready( $session ); self::fail( 'Current published installation must be verified.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Promotion budget operation readiness is unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $session->controls );
	}
	public function test_exact_published_schema_uses_current_bounded_locks_and_no_ledger_walk_or_transaction_control(): void {
		$s = $this->session(); ( new Readiness() )->assert_ready( $s );
		self::assertSame( 0, $s->controls ); self::assertTrue( $s->in_transaction() ); self::assertFalse( $s->is_retired() );
		self::assertSame( [ 'wp_options', ...Schema::tables( 'wp_' ) ], $s->validated[0] );
		$publication = array_values( array_filter( $s->reads, static fn( string $sql ): bool => str_contains( $sql, 'option_name IN (' ) ) ); self::assertCount( 1, $publication );
		self::assertStringContainsString( 'CASE WHEN OCTET_LENGTH(option_value) <= 16384', $publication[0] ); self::assertStringContainsString( 'ORDER BY option_name ASC LIMIT 3 FOR UPDATE', $publication[0] );
		self::assertStringContainsString( "'" . SchemaVersion::OPTION_NAME . "'", $publication[0] ); self::assertStringContainsString( "'" . MigrationStatus::OPTION_NAME . "'", $publication[0] );
		self::assertSame( [], array_values( array_filter( $s->reads, static fn( string $sql ): bool => str_contains( $sql, 'ORDER BY id' ) || ( str_contains( $sql, 'AS option_value FROM' ) && ! str_contains( $sql, 'FOR UPDATE' ) ) ) ) );
	}
	public function test_physical_structure_alone_cannot_replace_current_publication(): void {
		$s = $this->session(); ( new Installation( $s ) )->verify(); $s->publication = []; $this->refuses( $s );
	}
	public static function invalid_publications(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'missing schema', 'missing status', 'missing both', 'schema11', 'noncanonical version', 'wrong migration', 'failed status', 'wrong to version', 'integer to version', 'malformed status', 'object status', 'oversize status', 'duplicate name', 'extra row', 'unknown option' ] ); }
	#[DataProvider( 'invalid_publications' )]
	public function test_current_publication_gaps_or_unconfirmed_values_refuse( string $kind ): void {
		$s = $this->session(); $values = $s->publication; $status = unserialize( $values[MigrationStatus::OPTION_NAME], [ 'allowed_classes' => false ] );
		if ( 'missing schema' === $kind ) { unset( $values[SchemaVersion::OPTION_NAME] ); } elseif ( 'missing status' === $kind ) { unset( $values[MigrationStatus::OPTION_NAME] ); } elseif ( 'missing both' === $kind ) { $values = []; }
		elseif ( 'schema11' === $kind ) { $values[SchemaVersion::OPTION_NAME] = '11'; $status['to_version'] = '11'; } elseif ( 'noncanonical version' === $kind ) { $values[SchemaVersion::OPTION_NAME] = '012'; }
		elseif ( 'wrong migration' === $kind ) { $status['migration_id'] = 'unrelated_migration'; } elseif ( 'failed status' === $kind ) { $status['status'] = 'failed'; } elseif ( 'wrong to version' === $kind ) { $status['to_version'] = '11'; } elseif ( 'integer to version' === $kind ) { $status['to_version'] = 12; }
		elseif ( 'malformed status' === $kind ) { $values[MigrationStatus::OPTION_NAME] = 'invalid serialized publication'; } elseif ( 'object status' === $kind ) { $values[MigrationStatus::OPTION_NAME] = serialize( (object) $status ); } elseif ( 'oversize status' === $kind ) { $values[MigrationStatus::OPTION_NAME] = str_repeat( 'x', 16385 ); }
		if ( in_array( $kind, [ 'schema11', 'wrong migration', 'failed status', 'wrong to version', 'integer to version' ], true ) ) { $values[MigrationStatus::OPTION_NAME] = serialize( $status ); }
		$s->publication = $values;
		if ( 'duplicate name' === $kind ) { $s->publication_rows = array_fill( 0, 2, [ 'option_name' => SchemaVersion::OPTION_NAME, 'option_value' => '12' ] ); }
		elseif ( 'extra row' === $kind ) { $s->publication_rows = array_fill( 0, 3, [ 'option_name' => SchemaVersion::OPTION_NAME, 'option_value' => '12' ] ); }
		elseif ( 'unknown option' === $kind ) { $s->publication_rows = [ [ 'option_name' => 'foreign_option', 'option_value' => '12' ], [ 'option_name' => MigrationStatus::OPTION_NAME, 'option_value' => serialize( $status ) ] ]; }
		$this->refuses( $s );
	}
	public function test_matching_later_successful_schema_remains_compatible(): void {
		$s = $this->session(); $s->publication = [ SchemaVersion::OPTION_NAME => '13', MigrationStatus::OPTION_NAME => serialize( [ 'status' => 'success', 'to_version' => '13', 'migration_id' => 'later_forward_migration' ] ) ];
		( new Readiness() )->assert_ready( $s ); self::assertSame( 0, $s->controls );
	}
	public function test_current_failed_publication_is_rechecked_instead_of_reusing_a_prior_success(): void {
		$s = $this->session(); $r = new Readiness(); $r->assert_ready( $s ); $s->publication[MigrationStatus::OPTION_NAME] = serialize( [ 'status' => 'failed', 'to_version' => '12', 'migration_id' => Installation::MIGRATION_ID ] ); $this->refuses( $s );
		self::assertCount( 2, array_filter( $s->reads, static fn( string $sql ): bool => str_contains( $sql, 'FOR UPDATE' ) ) );
	}
	public static function inactive_bindings(): array { return [ [ 'retired', true ], [ 'transaction', false ], [ 'site', 0 ], [ 'prefix', '../foreign_' ], [ 'charset', 'unsupported charset' ] ]; }
	#[DataProvider( 'inactive_bindings' )]
	public function test_invalid_or_inactive_owner_refuses_before_reads_without_replacing_it( string $field, mixed $value ): void { $s = $this->session(); $s->$field = $value; $this->refuses( $s ); self::assertSame( [], $s->reads ); }
	public static function binding_changes(): array { return [ [ 'retired', true ], [ 'transaction', false ], [ 'site', 2 ], [ 'prefix', 'wp_foreign_' ], [ 'charset', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci' ] ]; }
	#[DataProvider( 'binding_changes' )]
	public function test_owner_changes_during_current_publication_cannot_admit( string $field, mixed $value ): void {
		$s = $this->session(); $s->on_query = static function( string $sql, PromotionOperationReadinessSession $session ) use ( $field, $value ): void { if ( str_contains( $sql, 'FOR UPDATE' ) ) { $session->$field = $value; } }; $this->refuses( $s );
		self::assertSame( [], array_values( array_filter( $s->reads, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) ) );
	}
	public function test_owner_change_in_validation_stops_before_publication_read(): void {
		$s = $this->session(); $s->on_query = static function( string $sql, PromotionOperationReadinessSession $session ): void { if ( 'VALIDATE TABLES' === $sql ) { $session->prefix = 'wp_foreign_'; } }; $this->refuses( $s ); self::assertSame( [ 'VALIDATE TABLES' ], $s->reads );
	}
	public function test_owner_change_in_prepare_stops_before_current_publication_read(): void {
		$s = $this->session(); $s->on_prepare = static function( PromotionOperationReadinessSession $session ): void { $session->site = 2; }; $this->refuses( $s ); self::assertSame( [ 'VALIDATE TABLES' ], $s->reads );
	}
	public function test_owner_change_in_error_read_stops_before_structure_probe(): void {
		$s = $this->session(); $s->on_errno = static function( PromotionOperationReadinessSession $session ): void { $session->charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'; }; $this->refuses( $s );
		self::assertSame( [], array_values( array_filter( $s->reads, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) ) );
	}
	public function test_owner_change_during_exact_structure_probe_stops_readiness(): void {
		$s = $this->session(); $s->on_query = static function( string $sql, PromotionOperationReadinessSession $session ): void { if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { $session->prefix = 'wp_foreign_'; } }; $this->refuses( $s );
		self::assertSame( [], array_values( array_filter( $s->reads, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW INDEX' ) ) ) );
	}
	public function test_structure_or_participant_validation_failure_never_claims_ready(): void {
		$s = $this->session(); $s->valid_tables = false; $this->refuses( $s );
		$s = $this->session(); $s->metadata = static function( string $sql, array $rows ): array { if ( str_starts_with( $sql, 'SHOW INDEX' ) ) { array_pop( $rows ); } return $rows; }; $this->refuses( $s );
	}
	public function test_current_query_failure_and_foreign_global_connection_are_not_fallbacks(): void {
		$s = $this->session(); $s->publication_failed = true; $had = array_key_exists( 'wpdb', $GLOBALS ); $prior = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \stdClass(); try { $this->refuses( $s ); } finally { if ( $had ) { $GLOBALS['wpdb'] = $prior; } else { unset( $GLOBALS['wpdb'] ); } }
	}
}

final class PromotionOperationReadinessSession extends FixedPromotionBudgetMetadataSession {
	public array $publication;
	public ?array $publication_rows = null;
	public bool $publication_failed = false;
	public ?\Closure $on_prepare = null;
	public ?\Closure $on_errno = null;
	public function __construct() { $this->publication = [ SchemaVersion::OPTION_NAME => '12', MigrationStatus::OPTION_NAME => serialize( [ 'status' => 'success', 'to_version' => '12', 'migration_id' => Installation::MIGRATION_ID ] ) ]; }
	public function get_results( string $sql ): array|false {
		if ( str_contains( $sql, 'option_name IN (' ) ) {
			$this->touch( $sql ); if ( $this->publication_failed ) { return false; } if ( null !== $this->publication_rows ) { return $this->publication_rows; }
			$rows = []; foreach ( $this->publication as $name => $value ) { $rows[] = [ 'option_name' => $name, 'option_value' => strlen( $value ) <= 16384 ? $value : null ]; } return $rows;
		}
		return parent::get_results( $sql );
	}
	public function prepare( string $sql, mixed ...$args ): string { $prepared = parent::prepare( $sql, ...$args ); if ( null !== $this->on_prepare ) { ( $this->on_prepare )( $this ); } return $prepared; }
	public function errno(): int { if ( null !== $this->on_errno ) { ( $this->on_errno )( $this ); } return parent::errno(); }
}
