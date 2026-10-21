<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetReservation;
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetInstallationReadiness as Installation, FixedPromotionBudgetRecordCodec as Codec, FixedPromotionBudgetSchema as Schema};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\PromotionBudgetSqlFixture as Budget;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionBudgetInstallationSqlFixture as F;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetInstallationSqlFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/PromotionBudgetSqlFixture.php';

/** Actual source migration/runner and MariaDB, with a labelled DDL shim in isolated OS children.
 * Actual WordPress dbDelta/fresh plugin activation remains a separate native CI proof.
 * Explicit selection requires SQL prerequisites; these cases never silently skip.
 */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionBudgetInstallationRealDatabaseTest extends TestCase {
	private \mysqli $db; private string $prefix; private array $processes = [];
	protected function setUp(): void { $this->db = DB::connect(); $this->prefix = DB::prefix(); }
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); }
		if ( isset( $this->db, $this->prefix ) ) {
			DB::cleanup( $this->db, $this->prefix );
			self::assertSame( 0, (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='{$this->prefix}'" ) );
			$this->db->close();
		}
	}
	private function child( string $fault = '', string $mode = 'run' ): array {
		$p = new OperationProofProcess( [ __DIR__ . '/promotion-budget-installation-child.php', json_encode( [ 'prefix' => $this->prefix, 'fault' => $fault, 'mode' => $mode ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; $out = $p->finish();
		self::assertNotSame( getmypid(), $out['process_id'] ); self::assertGreaterThan( 0, $out['connection_id'] ); self::assertNotSame( $this->db->thread_id, $out['connection_id'] ); return $out;
	}
	private function row_count( string $suffix ): int { $table = Schema::table( $this->prefix, $suffix ); return (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM `{$table}`" ); }
	private function assert_success( array $out ): void { self::assertSame( '12', $out['schema'] ); self::assertSame( 'success', $out['status']['status'] ); self::assertSame( Installation::MIGRATION_ID, $out['status']['migration_id'] ); self::assertSame( '12', $out['status']['to_version'] ); self::assertSame( [ 'ready' => true, 'code' => 'ready' ], $out['readiness'] ); }
	private function assert_no_controls_seeded(): void {
		$table = $this->prefix . 'options'; self::assertSame( 0, (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM `{$table}` WHERE option_name IN ('cetech_de_service_promise_adoption','cetech_de_quote_placement_adoption','cetech_de_delete_data_on_uninstall')" ) );
	}
	private function control(): array { return DB::row( $this->db, "SELECT option_value,autoload FROM `{$this->prefix}options` WHERE option_name='owned_installation_control'" ); }
	private function budget_snapshot(): array { return F::snapshot( $this->db, Schema::tables( $this->prefix ) ); }
	private function assert_before_ddl_refusal( array $out ): void { self::assertSame( '11', $out['schema'] ); self::assertSame( 'failed', $out['status']['status'] ); self::assertSame( [ 0, 0 ], [ $out['ddl_calls'], $out['ddl_creates'] ] ); self::assertFalse( $out['readiness']['ready'] ); }
	private function canonical_records(): void {
		$requests = [ Budget::request(), Budget::request(), Budget::request() ]; $first = $requests[0]; $at = $first->quote_created_at();
		self::assertCount( 3, array_unique( array_map( static fn( $request ): string => $request->claim_key(), $requests ) ) );
		$account = Budget::account( $first, '12.00' )->reserve( $first->amount() )->reserve( $requests[1]->amount() )->commit( $requests[1]->amount() )->reserve( $requests[2]->amount() )->release( $requests[2]->amount() );
		F::insert( $this->db, $this->prefix, Schema::ACCOUNTS_SUFFIX, Codec::account_values( $account ) );
		foreach ( $requests as $i => $request ) { $claim = FixedPromotionBudgetReservation::hold( $request, $request->quote_created_at(), $request->quote_created_at()->plus_seconds( 60 ) ); if ( 1 === $i ) { $claim = $claim->commit( $at->plus_seconds( 1 ) ); } elseif ( 2 === $i ) { $claim = $claim->release( $at->plus_seconds( 1 ) ); } F::insert( $this->db, $this->prefix, Schema::RESERVATIONS_SUFFIX, Codec::reservation_values( $claim ) ); }
	}

	public function test_fresh_missing_budget_pair_installs_empty_and_repeat_never_seeds_or_recreates(): void {
		F::base( $this->db, $this->prefix ); $control = $this->control(); $out = $this->child(); $this->assert_success( $out ); self::assertSame( [ 2, 2 ], [ $out['ddl_calls'], $out['ddl_creates'] ] ); self::assertCount( 2, F::domain_tables( $this->db, $this->prefix ) );
		self::assertSame( [ 0, 0 ], [ $this->row_count( Schema::ACCOUNTS_SUFFIX ), $this->row_count( Schema::RESERVATIONS_SUFFIX ) ] ); $before = $this->budget_snapshot();
		$repeat = $this->child(); $this->assert_success( $repeat ); self::assertSame( [ 0, 0 ], [ $repeat['ddl_calls'], $repeat['ddl_creates'] ] ); self::assertSame( $before, $this->budget_snapshot() ); self::assertSame( $control, $this->control() ); $this->assert_no_controls_seeded();
	}
	public function test_schema11_upgrade_preserves_all_retained39_ddl_checksums_and_raw_history(): void {
		F::base( $this->db, $this->prefix, true ); $retained = F::domain_tables( $this->db, $this->prefix ); self::assertCount( 39, $retained );
		self::assertSame( array_map( fn( string $suffix ): string => $this->prefix . 'delivery_engine_' . $suffix, ( function( array $suffixes ): array { sort( $suffixes ); return $suffixes; } )( DataLifecycleManifest::RETAINED_SHIPMENT_PROMISE_DOMAIN_TABLE_SUFFIXES ) ), $retained );
		$before = F::snapshot( $this->db, $retained ); $control = $this->control(); $this->assert_success( $this->child() );
		self::assertSame( $before, F::snapshot( $this->db, $retained ) ); self::assertSame( $control, $this->control() ); self::assertCount( 41, F::domain_tables( $this->db, $this->prefix ) ); self::assertSame( [ 0, 0 ], [ $this->row_count( Schema::ACCOUNTS_SUFFIX ), $this->row_count( Schema::RESERVATIONS_SUFFIX ) ] ); $this->assert_no_controls_seeded();
	}
	public function test_interruption_after_first_durable_ddl_resumes_without_rewriting_the_first_table(): void {
		F::base( $this->db, $this->prefix ); $out = $this->child( 'interrupt_after_first' ); self::assertSame( '11', $out['schema'] ); self::assertSame( 'failed', $out['status']['status'] ); self::assertSame( [ 1, 1 ], [ $out['ddl_calls'], $out['ddl_creates'] ] );
		$tables = F::domain_tables( $this->db, $this->prefix ); self::assertSame( [ Schema::table( $this->prefix, Schema::ACCOUNTS_SUFFIX ) ], $tables ); $before = F::snapshot( $this->db, $tables );
		$retry = $this->child(); $this->assert_success( $retry ); self::assertSame( [ 2, 1 ], [ $retry['ddl_calls'], $retry['ddl_creates'] ] ); self::assertSame( $before, F::snapshot( $this->db, $tables ) ); $this->assert_no_controls_seeded();
	}
	public function test_incompatible_first_table_refuses_before_any_ddl_and_is_not_repaired(): void {
		F::base( $this->db, $this->prefix ); $ddl = Schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->prefix . 'delivery_engine_' ); DB::execute( $this->db, str_replace( 'ENGINE=InnoDB', 'ENGINE=MyISAM', $ddl[Schema::ACCOUNTS_SUFFIX] ) );
		$tables = F::domain_tables( $this->db, $this->prefix ); $before = F::snapshot( $this->db, $tables ); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $tables, F::domain_tables( $this->db, $this->prefix ) ); self::assertSame( $before, F::snapshot( $this->db, $tables ) );
	}
	public function test_incompatible_second_table_is_detected_before_the_missing_first_is_created(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix, Schema::RESERVATIONS_SUFFIX ); $table = Schema::table( $this->prefix, Schema::RESERVATIONS_SUFFIX ); DB::execute( $this->db, "ALTER TABLE `{$table}` DROP INDEX site_claim" );
		$tables = [ $table ]; $before = F::snapshot( $this->db, $tables ); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $tables, F::domain_tables( $this->db, $this->prefix ) ); self::assertSame( $before, F::snapshot( $this->db, $tables ) );
	}
	public function test_populated_canonical_partial_pair_refuses_without_creating_or_altering_anything(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix, Schema::ACCOUNTS_SUFFIX ); $request = Budget::request(); F::insert( $this->db, $this->prefix, Schema::ACCOUNTS_SUFFIX, Codec::account_values( Budget::account( $request ) ) );
		$tables = F::domain_tables( $this->db, $this->prefix ); $before = F::snapshot( $this->db, $tables ); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $tables, F::domain_tables( $this->db, $this->prefix ) ); self::assertSame( $before, F::snapshot( $this->db, $tables ) );
	}
	public function test_version_publication_denial_stays_fenced_then_retries_without_new_table_creation(): void {
		F::base( $this->db, $this->prefix ); $out = $this->child( 'deny_version' ); self::assertSame( '11', $out['schema'] ); self::assertSame( 'failed', $out['status']['status'] ); self::assertFalse( $out['readiness']['ready'] ); self::assertSame( 2, $out['ddl_creates'] ); $before = $this->budget_snapshot();
		$retry = $this->child(); $this->assert_success( $retry ); self::assertSame( [ 2, 0 ], [ $retry['ddl_calls'], $retry['ddl_creates'] ] ); self::assertSame( $before, $this->budget_snapshot() );
	}
	public function test_success_status_denial_keeps_schema12_unready_then_reconciles_without_rerunning_up(): void {
		F::base( $this->db, $this->prefix ); $out = $this->child( 'deny_success' ); self::assertSame( '12', $out['schema'] ); self::assertSame( 'failed', $out['status']['status'] ); self::assertSame( [ 'ready' => false, 'code' => 'migration_unconfirmed' ], $out['readiness'] ); $before = $this->budget_snapshot();
		$retry = $this->child(); $this->assert_success( $retry ); self::assertSame( [ 0, 0 ], [ $retry['ddl_calls'], $retry['ddl_creates'] ] ); self::assertTrue( $retry['status']['reconciled'] ); self::assertSame( $before, $this->budget_snapshot() );
	}
	public function test_canonical_held_committed_and_released_history_survives_install_and_record_verification(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix ); $this->canonical_records(); $before = $this->budget_snapshot();
		$out = $this->child(); $this->assert_success( $out ); self::assertSame( [ 2, 0 ], [ $out['ddl_calls'], $out['ddl_creates'] ] ); self::assertSame( $before, $this->budget_snapshot() ); $verified = $this->child( mode: 'verify' ); self::assertTrue( $verified['records'] ); self::assertSame( $before, $this->budget_snapshot() ); $this->assert_no_controls_seeded();
	}
	public function test_unknown_persisted_format_is_retained_and_refused_before_any_ddl(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix ); $request = Budget::request(); $values = Codec::account_values( Budget::account( $request ) ); $values['format_version'] = 2; $values['state_json'] = '{"format_version":2,"future":"opaque owned fixture"}'; $values['state_digest'] = hash( 'sha256', $values['state_json'] ); F::insert( $this->db, $this->prefix, Schema::ACCOUNTS_SUFFIX, $values );
		$before = $this->budget_snapshot(); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $before, $this->budget_snapshot() ); $verified = $this->child( mode: 'verify' ); self::assertFalse( $verified['records'] ); self::assertSame( $before, $this->budget_snapshot() );
	}
	public function test_orphan_reservation_is_retained_and_refused_before_any_ddl(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix ); $request = Budget::request(); $claim = FixedPromotionBudgetReservation::hold( $request, $request->quote_created_at(), $request->quote_created_at()->plus_seconds( 60 ) ); F::insert( $this->db, $this->prefix, Schema::RESERVATIONS_SUFFIX, Codec::reservation_values( $claim ) );
		$before = $this->budget_snapshot(); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $before, $this->budget_snapshot() );
	}
	public function test_unexplained_held_total_is_not_normalized_or_published_as_installed(): void {
		F::base( $this->db, $this->prefix ); F::install_budgets( $this->db, $this->prefix ); $request = Budget::request(); F::insert( $this->db, $this->prefix, Schema::ACCOUNTS_SUFFIX, Codec::account_values( Budget::account( $request )->reserve( $request->amount() ) ) );
		$before = $this->budget_snapshot(); $this->assert_before_ddl_refusal( $this->child() ); self::assertSame( $before, $this->budget_snapshot() );
	}
}
