<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion, VerifiableMigrationInterface};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetInstallationReadiness as Inspection, FixedPromotionBudgetRecordCodec as Codec, FixedPromotionBudgetSchema as Schema};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\FixedPromotionBudgetMetadataSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/FixedPromotionBudgetMetadataSession.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Installation-only probes, separate from real dbDelta and independent SQL-owner proofs. */
final class FixedPromotionBudgetInstallationReadinessTest extends TestCase {
	private InstallationBudgetSession $session;
	private Inspection $inspection;
	private static ?FixedPromotionBudgetRequest $request = null;
	private bool $had_blog;
	private mixed $prior_blog;
	protected function setUp(): void { $this->had_blog = array_key_exists( 'blog_id', $GLOBALS ); $this->prior_blog = $GLOBALS['blog_id'] ?? null; $GLOBALS['blog_id'] = 1; $this->session = new InstallationBudgetSession(); $this->inspection = new Inspection( $this->session ); }
	protected function tearDown(): void { if ( $this->had_blog ) { $GLOBALS['blog_id'] = $this->prior_blog; } else { unset( $GLOBALS['blog_id'] ); } }
	private function request(): FixedPromotionBudgetRequest { if ( null === self::$request ) { $p = F::provider(); self::$request = FixedPromotionBudgetRequest::from_captured( F::header( $p ), $p->context(), $p->terms(), F::key(), Q::digest( 'installation account' ) ); } return self::$request; }
	private function account(): FixedPromotionBudgetState { $r = $this->request(); return FixedPromotionBudgetState::create( 1, $r->account_key(), $r->funding_source(), QuoteMoney::from_array( [ 'amount' => '9.00', 'currency' => 'GHS', 'precision' => 2 ] ) ); }
	private function ledger( string $state = 'held' ): void {
		$r = $this->request(); $at = $r->quote_created_at(); $account = $this->account()->reserve( $r->amount() ); $claim = FixedPromotionBudgetReservation::hold( $r, $at, $at->plus_seconds( 60 ) );
		if ( 'committed' === $state ) { $account = $account->commit( $r->amount() ); $claim = $claim->commit( $at->plus_seconds( 1 ) ); }
		elseif ( 'released' === $state ) { $account = $account->release( $r->amount() ); $claim = $claim->release( $at->plus_seconds( 1 ) ); }
		$this->session->ledger = [ Schema::ACCOUNTS_SUFFIX => [ [ 'id' => 1 ] + Codec::account_values( $account ) ], Schema::RESERVATIONS_SUFFIX => [ [ 'id' => 1 ] + Codec::reservation_values( $claim ) ] ];
	}
	private function refuse( callable $run ): void { try { $run(); self::fail( 'Installation inspection should refuse.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promotion budget installation could not be verified.', $e->getMessage() ); } self::assertSame( 0, $this->session->controls ); }

	public function test_new_install_allows_missing_pair_but_does_not_claim_structural_readiness(): void {
		$this->session->present = []; $this->inspection->preflight(); self::assertCount( 2, $this->session->reads ); $this->refuse( fn() => $this->inspection->verify() );
	}
	public function test_empty_exact_partial_pair_can_resume_and_each_table_is_verified_independently(): void {
		$this->session->present = [ Schema::ACCOUNTS_SUFFIX => true ]; $this->inspection->preflight(); $this->inspection->verify_table( Schema::ACCOUNTS_SUFFIX );
		$this->refuse( fn() => $this->inspection->verify_table( Schema::RESERVATIONS_SUFFIX ) ); self::assertSame( [], $this->session->ledger[Schema::ACCOUNTS_SUFFIX] );
	}
	public function test_populated_partial_pair_refuses_before_any_record_interpretation_or_ddl(): void {
		$this->ledger(); $this->session->present = [ Schema::ACCOUNTS_SUFFIX => true ]; $before = $this->session->ledger; $this->refuse( fn() => $this->inspection->preflight() ); self::assertSame( $before, $this->session->ledger ); self::assertSame( 0, $this->session->data_reads );
	}
	public function test_missing_column_in_an_empty_partial_table_is_incompatible_instead_of_repaired(): void {
		$this->session->present = [ Schema::ACCOUNTS_SUFFIX => true ]; $this->session->metadata = static function( string $sql, array $rows ): array { if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { array_pop( $rows ); } return $rows; };
		$this->refuse( fn() => $this->inspection->preflight() );
	}
	public function test_exact_empty_pair_is_verified_without_changing_the_owned_transaction(): void {
		$this->inspection->preflight(); $this->inspection->verify(); $this->inspection->verify_stored_records(); self::assertSame( 0, $this->session->controls ); self::assertTrue( $this->session->in_transaction() ); self::assertNotEmpty( $this->session->validated );
	}
	public static function allocation_states(): array { return [ [ 'held' ], [ 'committed' ], [ 'released' ] ]; }
	#[DataProvider( 'allocation_states' )]
	public function test_canonical_complete_account_and_claim_aggregate_is_preserved( string $state ): void {
		$this->ledger( $state ); $before = $this->session->ledger; $this->inspection->preflight(); $this->inspection->verify_stored_records(); self::assertSame( $before, $this->session->ledger ); self::assertSame( 0, $this->session->controls );
	}
	public function test_zero_precision_two_explicit_account_needs_no_claim(): void {
		$this->session->ledger[Schema::ACCOUNTS_SUFFIX] = [ [ 'id' => 1 ] + Codec::account_values( $this->account() ) ]; $this->inspection->verify_stored_records();
		self::assertSame( '0.00', $this->account()->held()->amount() ); self::assertSame( '0.00', $this->account()->spent()->amount() );
	}
	public static function bad_ledgers(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'orphan claim', 'held mismatch', 'spent mismatch', 'foreign site', 'foreign funding', 'foreign unit', 'unknown format', 'digest', 'noncanonical json', 'oversize', 'duplicate id', 'row ceiling' ] ); }
	#[DataProvider( 'bad_ledgers' )]
	public function test_malformed_incomplete_or_unknown_retained_records_refuse_without_rewrite( string $case ): void {
		$this->ledger(); $account =& $this->session->ledger[Schema::ACCOUNTS_SUFFIX][0];
		if ( 'orphan claim' === $case ) { $this->session->ledger[Schema::ACCOUNTS_SUFFIX] = []; }
		elseif ( in_array( $case, [ 'held mismatch', 'spent mismatch', 'foreign site', 'foreign funding', 'foreign unit' ], true ) ) {
			$facts = Codec::account_from_row( $account )->private_facts();
			if ( 'held mismatch' === $case ) { $facts['held']['amount'] = '2.00'; }
			elseif ( 'spent mismatch' === $case ) { $facts['spent']['amount'] = '1.00'; }
			elseif ( 'foreign site' === $case ) { $facts['site_id'] = 2; }
			elseif ( 'foreign funding' === $case ) { ++$facts['funding_source']['version']; }
			else { foreach ( [ 'limit', 'held', 'spent' ] as $field ) { $facts[$field]['currency'] = 'USD'; } }
			$account = [ 'id' => 1 ] + Codec::account_values( FixedPromotionBudgetState::from_array( $facts ) );
		} elseif ( 'unknown format' === $case ) { $account['format_version'] = 2; }
		elseif ( 'digest' === $case ) { $account['state_digest'] = str_repeat( 'a', 64 ); }
		elseif ( 'noncanonical json' === $case ) { $account['state_json'] .= ' '; }
		elseif ( 'oversize' === $case ) { $account['state_json'] = str_repeat( 'x', 4097 ); }
		elseif ( 'duplicate id' === $case ) { $this->session->ledger[Schema::ACCOUNTS_SUFFIX][] = $account; }
		else { $this->session->ledger[Schema::ACCOUNTS_SUFFIX] = array_fill( 0, 1001, $account ); }
		$before = $this->session->ledger; $this->refuse( fn() => $this->inspection->verify_stored_records() ); self::assertSame( $before, $this->session->ledger );
	}
	public function test_transport_projection_bounds_each_json_before_decoding(): void {
		$this->inspection->verify_stored_records(); $queries = array_values( array_filter( $this->session->reads, static fn( string $sql ): bool => str_contains( $sql, 'ORDER BY id ASC LIMIT 1001' ) ) ); self::assertCount( 2, $queries ); self::assertStringContainsString( 'OCTET_LENGTH(`state_json`) <= 4096', $queries[0] ); self::assertStringContainsString( 'OCTET_LENGTH(`reservation_json`) <= 16384', $queries[1] );
	}
	public static function publication_cases(): array { return [ 'before installation' => [ '11', [ 'status' => 'success', 'to_version' => '11', 'migration_id' => 'older' ], 'schema_unavailable' ], 'wrong id' => [ '12', [ 'status' => 'success', 'to_version' => '12', 'migration_id' => 'older' ], 'migration_unconfirmed' ], 'wrong to version' => [ '12', [ 'status' => 'success', 'to_version' => '11', 'migration_id' => Inspection::MIGRATION_ID ], 'migration_unconfirmed' ], 'numeric version not canonical' => [ '12', [ 'status' => 'success', 'to_version' => 12, 'migration_id' => Inspection::MIGRATION_ID ], 'migration_unconfirmed' ], 'failed publication' => [ '12', [ 'status' => 'failed', 'to_version' => '12', 'migration_id' => Inspection::MIGRATION_ID ], 'migration_unconfirmed' ], 'same version' => [ '12', [ 'status' => 'success', 'to_version' => '12', 'migration_id' => Inspection::MIGRATION_ID ], 'ready' ], 'later matching version' => [ '13', [ 'status' => 'success', 'to_version' => '13', 'migration_id' => 'a_later_migration' ], 'ready' ] ]; }
	#[DataProvider( 'publication_cases' )]
	public function test_diagnostics_require_published_schema_and_matching_success( string $version, array $status, string $code ): void {
		$this->session->option_values = [ SchemaVersion::OPTION_NAME => $version, MigrationStatus::OPTION_NAME => serialize( $status ) ]; self::assertSame( [ 'ready' => 'ready' === $code, 'code' => $code ], $this->inspection->get_status() ); self::assertSame( 0, $this->session->data_reads );
	}
	public function test_diagnostics_do_not_scan_or_rewrite_unknown_history(): void {
		$this->session->option_values = [ SchemaVersion::OPTION_NAME => '12', MigrationStatus::OPTION_NAME => serialize( [ 'status' => 'success', 'to_version' => '12', 'migration_id' => Inspection::MIGRATION_ID ] ) ];
		$this->session->ledger[Schema::ACCOUNTS_SUFFIX] = [ [ 'format_version' => 999, 'private_future_bytes' => 'opaque' ] ]; self::assertSame( [ 'ready' => true, 'code' => 'ready' ], $this->inspection->get_status() ); self::assertSame( 0, $this->session->data_reads );
	}
	public static function scope_changes(): array { return [ [ 'retired', true ], [ 'transaction', false ], [ 'site', 2 ], [ 'prefix', 'wp_2_' ], [ 'charset', 'DEFAULT CHARSET=utf8mb4' ] ]; }
	#[DataProvider( 'scope_changes' )]
	public function test_owner_loss_during_reads_stops_inspection_without_replacing_owner( string $field, mixed $value ): void {
		$this->session->on_query = static function( string $sql, InstallationBudgetSession $s ) use ( $field, $value ): void { if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { $s->$field = $value; } };
		$this->refuse( fn() => $this->inspection->verify() ); self::assertFalse( (bool) array_filter( $this->session->reads, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW INDEX' ) ) );
	}
	public function test_inactive_session_is_not_begun_or_retired_by_installation_probe(): void { $this->session->transaction = false; $this->refuse( fn() => $this->inspection->preflight() ); self::assertSame( [], $this->session->reads ); }
	public function test_query_failure_returns_generic_unverified_and_cannot_reuse_prior_success(): void {
		$this->inspection->verify(); $this->session->error = 2006; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], $this->inspection->get_status() ); $this->refuse( fn() => $this->inspection->verify() );
	}
	public function test_native_wpdb_and_explicit_global_fallback_use_only_read_methods(): void {
		$native = new InstallationBudgetWpdb( $this->session ); $had = array_key_exists( 'wpdb', $GLOBALS ); $prior = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = $native;
		try { ( new Inspection() )->preflight(); ( new Inspection( $native ) )->verify(); self::assertSame( 0, $this->session->controls ); } finally { if ( $had ) { $GLOBALS['wpdb'] = $prior; } else { unset( $GLOBALS['wpdb'] ); } }
	}
	public function test_native_prefix_switch_in_a_query_is_not_hidden_by_new_probe_binding(): void {
		$native = new InstallationBudgetWpdb( $this->session ); $this->session->on_query = static function( string $sql ) use ( $native ): void { if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { $native->prefix = 'wp_2_'; } }; $this->refuse( fn() => ( new Inspection( $native ) )->verify() );
	}
	public function test_migration_identity_and_ordered_inspection_contract_are_discoverable_without_execution(): void {
		$path = dirname( __DIR__, 3 ) . '/database/migrations/' . Inspection::MIGRATION_ID . '.php'; $migration = require $path; self::assertInstanceOf( VerifiableMigrationInterface::class, $migration ); self::assertSame( Inspection::MIGRATION_ID, $migration->get_id() ); self::assertSame( '12', $migration->get_version() );
		$source = file_get_contents( $path ); self::assertLessThan( strpos( $source, 'dbDelta( $sql )' ), strpos( $source, '$inspection->preflight()' ) ); self::assertStringContainsString( '$inspection->verify_table( $suffix )', $source ); self::assertStringNotContainsString( 'insert_account', $source ); self::assertStringNotContainsString( 'update_option', $source );
	}
	public function test_connection_substitution_after_preflight_refuses_before_require_or_ddl(): void {
		$had = array_key_exists( 'wpdb', $GLOBALS ); $prior = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = new InstallationBudgetWpdb( $this->session ); $this->session->present = []; $changed = false;
		$this->session->on_query = static function( string $sql, InstallationBudgetSession $session ) use ( &$changed ): void { if ( ! $changed ) { $changed = true; $GLOBALS['wpdb'] = new InstallationBudgetWpdb( $session ); } };
		try { $migration = require dirname( __DIR__, 3 ) . '/database/migrations/' . Inspection::MIGRATION_ID . '.php'; try { $migration->up(); self::fail( 'The migration cannot switch its inspected connection.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promotion budget migration binding is unavailable.', $e->getMessage() ); self::assertCount( 2, $this->session->reads ); } }
		finally { if ( $had ) { $GLOBALS['wpdb'] = $prior; } else { unset( $GLOBALS['wpdb'] ); } }
	}
	public static function incompatible_metadata(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'engine', 'collation', 'identity', 'missing default', 'missing index', 'prefix index', 'wrong unique', 'ignored index', 'invisible index', 'descending index' ] ); }
	#[DataProvider( 'incompatible_metadata' )]
	public function test_existing_empty_structure_is_never_silently_normalized( string $case ): void {
		$this->session->metadata = static function( string $sql, array $rows ) use ( $case ): array {
			if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { if ( 'engine' === $case ) { $rows['Engine'] = 'MyISAM'; } elseif ( 'collation' === $case ) { $rows['Collation'] = 'utf8mb4_general_ci'; } elseif ( 'identity' === $case ) { $rows['Name'] = 'another_table'; } }
			elseif ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) && 'missing default' === $case ) { unset( $rows[0]['Default'] ); }
			elseif ( str_starts_with( $sql, 'SHOW INDEX' ) ) { if ( 'missing index' === $case ) { array_pop( $rows ); } elseif ( 'prefix index' === $case ) { $rows[0]['Sub_part'] = 4; } elseif ( 'wrong unique' === $case ) { $rows[0]['Non_unique'] = 1; } elseif ( 'ignored index' === $case ) { $rows[0]['Ignored'] = 'YES'; } elseif ( 'invisible index' === $case ) { $rows[0]['Visible'] = 'NO'; } elseif ( 'descending index' === $case ) { $rows[0]['Collation'] = 'D'; } }
			return $rows;
		};
		$this->refuse( fn() => $this->inspection->preflight() );
	}
}

class InstallationBudgetSession extends FixedPromotionBudgetMetadataSession {
	public array $present = [ Schema::ACCOUNTS_SUFFIX => true, Schema::RESERVATIONS_SUFFIX => true ];
	public array $ledger = [ Schema::ACCOUNTS_SUFFIX => [], Schema::RESERVATIONS_SUFFIX => [] ];
	public array $option_values = [];
	public int $data_reads = 0;
	public function get_row( string $sql ): array|null|false {
		foreach ( Schema::SUFFIXES as $suffix ) { if ( str_contains( $sql, $suffix ) && str_starts_with( $sql, 'SHOW TABLE STATUS' ) && ! isset( $this->present[$suffix] ) ) { $this->touch( $sql ); return null; } }
		if ( str_starts_with( $sql, 'SELECT 1 AS present' ) ) { $this->touch( $sql ); foreach ( Schema::SUFFIXES as $suffix ) { if ( str_contains( $sql, $suffix ) ) { return [] === $this->ledger[$suffix] ? null : [ 'present' => 1 ]; } } }
		if ( str_contains( $sql, 'AS option_value FROM' ) ) { $this->touch( $sql ); foreach ( $this->option_values as $name => $value ) { if ( str_contains( $sql, "'{$name}'" ) ) { return [ 'option_value' => is_string( $value ) && strlen( $value ) <= 16384 ? $value : null ]; } } return null; }
		return parent::get_row( $sql );
	}
	public function get_results( string $sql ): array|false {
		if ( str_contains( $sql, 'ORDER BY id ASC LIMIT 1001' ) ) {
			$this->touch( $sql ); ++$this->data_reads;
			foreach ( Schema::SUFFIXES as $suffix ) {
				if ( ! str_contains( $sql, $suffix . '`' ) ) { continue; } $rows = [];
				foreach ( array_slice( $this->ledger[$suffix], 0, 1001 ) as $row ) { foreach ( Schema::payload_limits( $suffix ) as $field => $limit ) { $bytes = isset( $row[$field] ) ? strlen( $row[$field] ) : null; $row['__' . $field . '_bytes'] = $bytes; if ( null !== $bytes && $bytes > $limit ) { $row[$field] = null; } } $rows[] = $row; } return $rows;
			}
		}
		return parent::get_results( $sql );
	}
}

final class InstallationBudgetWpdb {
	public string $prefix = 'wp_'; public string $options = 'wp_options'; public string $last_error = '';
	public function __construct( private InstallationBudgetSession $session ) {}
	public function get_charset_collate(): string { return $this->session->charset; }
	public function prepare( string $sql, mixed ...$args ): string { return $this->session->prepare( $sql, ...$args ); }
	public function get_row( string $sql, string $format ): array|null|false { if ( ARRAY_A !== $format ) { throw new \LogicException(); } return $this->session->get_row( $sql ); }
	public function get_results( string $sql, string $format ): array|false { if ( ARRAY_A !== $format ) { throw new \LogicException(); } return $this->session->get_results( $sql ); }
}
