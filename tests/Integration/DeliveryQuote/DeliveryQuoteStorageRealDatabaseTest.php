<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryQuote;

use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures as Facts;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageProofFactory;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageProofWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Real native DDL/rows/transactions. Runner options are test doubles; native WP is a separate proof. */
#[Group( 'quote-storage-real-db' )]
final class DeliveryQuoteStorageRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private QuoteStorageProofWpdb $wpdb;
	private QuoteStorageProofFactory $factory;
	private array $old_tables;
	private array $saved_globals;

	protected function setUp(): void {
		$this->saved_globals = [];
		foreach ( [ 'wpdb', 'blog_id', 'cetech_de_test_options' ] as $key ) { $this->saved_globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
		$this->wpdb = new QuoteStorageProofWpdb( $this->database, $this->prefix ); $GLOBALS['wpdb'] = $this->wpdb; $GLOBALS['blog_id'] = 1;
		// C06's current-source fixture installs schema 9; remove only Q02's additive units.
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) { DB::execute( $this->database, 'DROP TABLE `' . $this->table( $suffix ) . '`' ); }
		DB::execute( $this->database, 'ALTER TABLE `' . $this->table( 'rate_cards' ) . '` DROP INDEX ' . DeliveryQuoteSchema::RATE_INDEX );
		$this->option( SchemaVersion::OPTION_NAME, '8' ); $this->option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) );
		$this->old_tables = $this->tables();
		$GLOBALS['cetech_de_test_options'] = [ SchemaVersion::OPTION_NAME => '8', MigrationStatus::OPTION_NAME => [ 'status' => 'success', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ];
		$this->factory = new QuoteStorageProofFactory( $this->prefix );
		if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/stubs/' ); }
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	protected function tearDown(): void {
		if ( isset( $this->factory ) ) { $this->factory->close_all(); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
		foreach ( $this->saved_globals as $key => [ $existed, $value ] ) { if ( $existed ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
	}
	private function migrate(): void {
		$runner = new MigrationRunner( new Logger() );
		$runner->set_migrations( [ require dirname( __DIR__, 3 ) . '/database/migrations/20261007055100_create_delivery_quote_tables.php' ] ); $runner->run();
	}
	/** Publish fixture readiness only after the actual migration runner has accepted all units. */
	private function ready(): void {
		$this->migrate(); self::assertSame( '9', SchemaVersion::get(), (string) ( MigrationStatus::get()['error'] ?? '' ) ); self::assertSame( 'success', MigrationStatus::get()['status'] );
		$this->option( SchemaVersion::OPTION_NAME, '9' ); $this->option( MigrationStatus::OPTION_NAME, serialize( MigrationStatus::get() ) );
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new DeliveryQuoteReadiness( $this->wpdb ) )->get_status() );
	}
	private function option( string $name, string $value ): void {
		$sql = $this->wpdb->prepare( "UPDATE `{$this->prefix}options` SET option_value=%s WHERE option_name=%s", $value, $name ); self::assertNotFalse( $this->wpdb->query( $sql ) );
	}
	private function table( string $suffix ): string { return $this->prefix . 'delivery_engine_' . $suffix; }
	private function tables(): array {
		$p = $this->database->real_escape_string( $this->prefix . 'delivery_engine_' ); $r = $this->database->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix . 'delivery_engine_' ) . ")='{$p}' ORDER BY TABLE_NAME" );
		if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Quote fixture inventory refused.' ); } return array_column( $r->fetch_all( MYSQLI_ASSOC ), 'TABLE_NAME' );
	}
	private function bytes( array $tables ): array {
		$result = []; foreach ( $tables as $table ) { $r = $this->database->query( "SELECT * FROM `{$table}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Quote fixture preservation read refused.' ); } $result[$table] = $r->fetch_all( MYSQLI_ASSOC ); } return $result;
	}
	private function definition( string $table ): array { return DB::row( $this->database, "SHOW CREATE TABLE `{$table}`" ); }
	private function sentinel( string $table ): void {
		$fields = $this->database->query( "SHOW COLUMNS FROM `{$table}`" )->fetch_all( MYSQLI_ASSOC ); $columns = []; $values = [];
		foreach ( $fields as $f ) {
			if ( str_contains( $f['Extra'], 'auto_increment' ) || 'id' === $f['Field'] ) { continue; } $columns[] = '`' . $f['Field'] . '`'; $type = strtolower( $f['Type'] );
			if ( 'YES' === $f['Null'] ) { $values[] = 'NULL'; } elseif ( preg_match( '/int|decimal|double|float/', $type ) ) { $values[] = '1'; }
			elseif ( str_starts_with( $type, 'datetime' ) || str_starts_with( $type, 'timestamp' ) ) { $values[] = "'2026-10-06 00:00:00'"; }
			else { $values[] = preg_match( '/(?:var)?char\(([0-9]+)\)/', $type, $m ) && (int) $m[1] < 32 ? "'P'" : "'PRIVATE_PRESERVATION_SENTINEL'"; }
		}
		DB::execute( $this->database, "INSERT INTO `{$table}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
	}
	private function insert( string $suffix, array $row, bool $omit_id = true ): int|false {
		if ( $omit_id ) { unset( $row['id'] ); } $columns = []; $values = [];
		foreach ( $row as $name => $value ) { $columns[] = '`' . $name . '`'; $values[] = null === $value ? 'NULL' : $this->wpdb->prepare( '%s', (string) $value ); }
		$result = $this->wpdb->query( 'INSERT INTO `' . $this->table( $suffix ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
		return false === $result ? false : $this->wpdb->insert_id;
	}
	private function row( string $suffix, int $id ): array { return DB::row( $this->database, 'SELECT * FROM `' . $this->table( $suffix ) . '` WHERE id=' . $id ); }
	private function refuses( callable $work ): void {
		try { $work(); } catch ( \RuntimeException|\DomainException|\InvalidArgumentException $error ) { self::assertStringNotContainsString( 'PRIVATE_PRESERVATION', $error->getMessage() ); return; }
		self::fail( 'The incompatible quote store was accepted.' );
	}
	private function write_refused( ?DeliveryQuoteReadiness $readiness = null ): void {
		$s = $this->factory->open(); self::assertTrue( $s->begin() ); $repository = new DeliveryQuoteRepository( $s, $readiness );
		$this->refuses( static fn() => $repository->insert_quote( Facts::quote() ) ); self::assertTrue( $s->rollback() ); $s->retire();
	}

	public function test_schema8_upgrade_preserves_every_row_in_all32_existing_domain_tables(): void {
		self::assertCount( 32, $this->old_tables ); foreach ( $this->old_tables as $table ) { $this->sentinel( $table ); } $before = $this->bytes( $this->old_tables );
		$this->ready(); self::assertCount( 35, $this->tables() ); self::assertSame( $before, $this->bytes( $this->old_tables ) );
		$inspection = new DeliveryQuoteReadiness( $this->wpdb ); $inspection->verify(); $inspection->verify_stored_records();
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) {
			$table = $this->table( $suffix ); self::assertSame( 'InnoDB', DB::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$table}'" )['Engine'] );
			self::assertSame( 'utf8mb4_unicode_ci', DB::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$table}'" )['Collation'] );
			$columns = $this->wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`" ); self::assertSame( array_keys( DeliveryQuoteSchema::columns( $suffix ) ), array_column( $columns, 'Field' ) );
		}
		$indexes = $this->wpdb->get_results( "SHOW INDEX FROM `{$this->prefix}delivery_engine_rate_cards` WHERE Key_name='quote_candidate_range'" );
		self::assertSame( [ 'delivery_offer_id', 'destination_zone_id', 'base_currency', 'id' ], array_column( $indexes, 'Column_name' ) );
	}
	public static function interruptions(): array { $cases = []; for ( $unit = 0; $unit < 4; ++$unit ) { foreach ( [ 'before', 'after' ] as $side ) { $cases["{$side}_unit_{$unit}"] = [ $unit, $side ]; } } return $cases; }
	#[DataProvider( 'interruptions' )]
	public function test_each_ddl_unit_failure_keeps_schema8_and_exact_partial_retry( int $unit, string $side ): void {
		$this->wpdb->fault_unit = $unit; $this->wpdb->fault_side = $side; $before = $this->bytes( $this->old_tables ); $this->migrate();
		self::assertSame( '8', SchemaVersion::get() ); self::assertSame( 'failed', MigrationStatus::get()['status'] ); self::assertSame( '9', MigrationStatus::get()['to_version'] );
		self::assertFalse( ( new DeliveryQuoteReadiness( $this->wpdb ) )->get_status()['ready'] ); $this->write_refused();
		$completed = min( 3, $unit + ( 'after' === $side ? 1 : 0 ) ); self::assertCount( 32 + $completed, $this->tables() );
		$rate = $this->wpdb->get_results( 'SHOW INDEX FROM `' . $this->table( 'rate_cards' ) . "` WHERE Key_name='quote_candidate_range'" );
		self::assertCount( 3 === $unit && 'after' === $side ? 4 : 0, $rate );
		$definitions = []; foreach ( array_diff( $this->tables(), $this->old_tables ) as $table ) { $definitions[$table] = $this->definition( $table ); }
		$this->wpdb->fault_unit = null; $this->ready();
		foreach ( $definitions as $table => $definition ) { self::assertSame( $definition, $this->definition( $table ) ); }
		self::assertSame( $before, $this->bytes( $this->old_tables ) ); self::assertCount( 35, $this->tables() );
	}
	public static function conflicts(): array {
		return [
			'wrong_type' => [ 'ALTER TABLE %s MODIFY revision int unsigned NOT NULL DEFAULT 1' ],
			'wrong_default' => [ 'ALTER TABLE %s ALTER revision SET DEFAULT 2' ],
			'wrong_engine' => [ 'ALTER TABLE %s ENGINE=MyISAM' ],
			'wrong_site_collation' => [ 'ALTER TABLE %s DEFAULT COLLATE utf8mb4_bin' ],
			'wrong_digest_collation' => [ 'ALTER TABLE %s MODIFY owner_digest char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL' ],
			'shortened_unique_index' => [ 'ALTER TABLE %s DROP INDEX site_uuid, ADD UNIQUE KEY site_uuid (site_id,quote_uuid(12))' ],
			'wrong_rate_tuple' => [ 'ALTER TABLE %s DROP INDEX quote_candidate_range, ADD KEY quote_candidate_range (delivery_offer_id,destination_zone_id,id,base_currency)', 'rate_cards' ],
			'shortened_rate_index' => [ 'ALTER TABLE %s DROP INDEX quote_candidate_range, ADD KEY quote_candidate_range (delivery_offer_id,destination_zone_id,base_currency(1),id)', 'rate_cards' ],
		];
	}
	#[DataProvider( 'conflicts' )]
	public function test_incompatible_native_shape_refuses_without_repair( string $alter, string $suffix = 'delivery_quotes' ): void {
		$this->ready(); $table = $this->table( $suffix ); DB::execute( $this->database, sprintf( $alter, "`{$table}`" ) ); $before = $this->definition( $table );
		$inspection = new DeliveryQuoteReadiness( $this->wpdb ); $this->refuses( fn() => $inspection->preflight() ); self::assertFalse( $inspection->get_status()['ready'] ); $this->write_refused();
		$GLOBALS['cetech_de_test_options'][SchemaVersion::OPTION_NAME] = '8'; $this->option( SchemaVersion::OPTION_NAME, '8' ); $this->migrate();
		self::assertSame( '8', SchemaVersion::get() ); self::assertSame( 'failed', MigrationStatus::get()['status'] );
		self::assertSame( $before, $this->definition( $table ) );
	}
	public function test_wrong_prefix_cannot_claim_another_site_tables_or_write(): void {
		$this->ready(); $this->wpdb->prefix = 'foreign_'; $wrong = new DeliveryQuoteReadiness( $this->wpdb ); self::assertFalse( $wrong->get_status()['ready'] ); $this->write_refused( $wrong );
		$this->wpdb->prefix = str_repeat( 'x', 40 ); self::assertFalse( ( new DeliveryQuoteReadiness( $this->wpdb ) )->get_status()['ready'] );
		$this->wpdb->prefix = $this->prefix; self::assertSame( 0, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quotes' ) . '`' ) );
	}
	public function test_uuid_and_each_original_quote_namespace_are_physically_unique(): void {
		$this->ready(); $first = Facts::quote()->row(); self::assertNotFalse( $this->insert( 'delivery_quotes', $first ) );
		foreach ( [ 'quote_uuid', 'issue_namespace_hash', 'accept_namespace_hash', 'invalidate_namespace_hash' ] as $field ) { $next = Facts::quote()->row(); $next[$field] = $first[$field]; self::assertFalse( $this->insert( 'delivery_quotes', $next ), $field ); self::assertSame( 1062, $this->database->errno ); }
		self::assertSame( 1, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quotes' ) . '`' ) );
	}
	public function test_binding_uniqueness_refuses_distinct_competing_intents(): void {
		$this->ready(); $parent = Facts::quote( state: 'accepted' ); self::assertNotFalse( $this->insert( 'delivery_quotes', $parent->row() ) ); $first = Facts::binding( $parent )->row(); self::assertNotFalse( $this->insert( 'delivery_quote_bindings', $first ) );
		foreach ( [ 'placement_uuid', 'quote_uuid', 'bind_namespace_hash', 'seal_namespace_hash', 'order_manifest' ] as $field ) {
			$other = Facts::quote( state: 'accepted' ); $next = Facts::binding( $other, order: 101 )->row();
			if ( 'order_manifest' === $field ) { $next['order_id'] = $first['order_id']; $next['managed_group_manifest_digest'] = $first['managed_group_manifest_digest']; }
			else { $next[$field] = $first[$field]; }
			self::assertFalse( $this->insert( 'delivery_quote_bindings', $next ), $field ); self::assertSame( 1062, $this->database->errno );
		}
		self::assertSame( 1, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quote_bindings' ) . '`' ) );
	}
	public function test_admission_namespace_cannot_be_reused_in_a_later_window_or_new_slot(): void {
		$this->ready(); $first = Facts::budget()->row(); self::assertNotFalse( $this->insert( 'delivery_quote_budget_windows', $first ) );
		$next = Facts::budget( namespace: 'different', window: '2026-10-07 05:01:00.000000' )->row(); $next['admission_namespace_hash'] = $first['admission_namespace_hash']; $next['slot_key'] = QuoteBudgetSlot::admission_slot_key( $first['admission_namespace_hash'] );
		self::assertSame( 'admission', QuoteBudgetSlot::from_row( $next )->kind() ); self::assertNotSame( $first['admission_intent_digest'], $next['admission_intent_digest'] );
		self::assertFalse( $this->insert( 'delivery_quote_budget_windows', $next ) ); self::assertSame( 1062, $this->database->errno );
		$counter = Facts::budget( namespace: 'counter', admission: false )->row(); self::assertNotFalse( $this->insert( 'delivery_quote_budget_windows', $counter ) ); self::assertFalse( $this->insert( 'delivery_quote_budget_windows', $counter ) );
	}
	public function test_persisted_malformed_quote_rows_refuse_without_rewrite(): void {
		$this->ready();
		foreach ( [ 'header_json' => '{"format_version":1,"format_version":1}', 'private_body_json' => '{"private":"PRIVATE_PRESERVATION"}', 'body_digest' => str_repeat( 'a', 64 ), 'profile_code' => 'unknown', 'revision' => '0', 'private_body_null' => null, 'header_oversized' => str_repeat( ' ', 4097 ), 'body_oversized' => str_repeat( ' ', 65537 ) ] as $field => $value ) {
			$row = Facts::quote()->row(); $actual = match ( $field ) { 'private_body_null', 'body_oversized' => 'private_body_json', 'header_oversized' => 'header_json', default => $field }; $row[$actual] = $value; $id = $this->insert( 'delivery_quotes', $row ); self::assertNotFalse( $id ); $saved = $this->row( 'delivery_quotes', $id );
			$this->refuses( fn() => QuoteStoredRow::from_row( $saved ) ); $this->refuses( fn() => ( new DeliveryQuoteReadiness( $this->wpdb ) )->verify_stored_records() );
			$s = $this->factory->open(); self::assertTrue( $s->begin() ); $this->refuses( fn() => ( new DeliveryQuoteRepository( $s ) )->find_quote( QuoteId::from_string( $row['quote_uuid'] ) ) ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
			self::assertSame( $saved, $this->row( 'delivery_quotes', $id ) ); DB::execute( $this->database, 'DELETE FROM `' . $this->table( 'delivery_quotes' ) . '` WHERE id=' . $id );
		}
	}
	public function test_binding_parent_and_budget_intent_corruption_are_refused_without_repair(): void {
		$this->ready(); $quote = Facts::quote( state: 'accepted' ); $qid = $this->insert( 'delivery_quotes', $quote->row() ); self::assertNotFalse( $qid ); $quote = QuoteStoredRow::from_row( $this->row( 'delivery_quotes', $qid ) );
		$binding = Facts::binding( $quote )->row(); $binding['accepted_body_digest'] = str_repeat( 'a', 64 ); $id = $this->insert( 'delivery_quote_bindings', $binding ); self::assertNotFalse( $id );
		$before = $this->row( 'delivery_quote_bindings', $id ); $this->refuses( fn() => QuoteBinding::from_row( $before, $quote ) ); $this->refuses( fn() => ( new DeliveryQuoteReadiness( $this->wpdb ) )->verify_stored_records() ); self::assertSame( $before, $this->row( 'delivery_quote_bindings', $id ) );
		DB::execute( $this->database, 'DELETE FROM `' . $this->table( 'delivery_quote_bindings' ) . '`' );
		$budget = Facts::budget()->row(); $budget['admission_intent_digest'] = null; $bid = $this->insert( 'delivery_quote_budget_windows', $budget ); self::assertNotFalse( $bid ); $before = $this->row( 'delivery_quote_budget_windows', $bid );
		$this->refuses( fn() => QuoteBudgetSlot::from_row( $before ) ); $this->refuses( fn() => ( new DeliveryQuoteReadiness( $this->wpdb ) )->verify_stored_records() ); self::assertSame( $before, $this->row( 'delivery_quote_budget_windows', $bid ) );
	}
	public function test_repository_native_rollback_and_fresh_connection_commit_read_are_owned(): void {
		$this->ready(); $quote = Facts::quote(); $s = $this->factory->open(); self::assertTrue( $s->begin() ); $r = new DeliveryQuoteRepository( $s ); $rolled = $r->insert_quote( $quote ); self::assertGreaterThan( 0, $rolled ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
		self::assertSame( 0, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quotes' ) . '`' ) );
		$writer = $this->factory->open(); self::assertTrue( $writer->begin() ); $writer_id = $writer->get_row( 'SELECT CONNECTION_ID() AS id' )['id']; $written = ( new DeliveryQuoteRepository( $writer ) )->insert_quote( $quote ); self::assertSame( OperationCommitResult::Acknowledged, $writer->commit() ); self::assertTrue( $writer->retire() );
		$fresh = $this->factory->open(); self::assertTrue( $fresh->begin() ); self::assertNotSame( $writer_id, $fresh->get_row( 'SELECT CONNECTION_ID() AS id' )['id'] ); $read = ( new DeliveryQuoteRepository( $fresh ) )->find_quote( $quote->header()->id() ); self::assertNotNull( $read ); self::assertSame( $written, $read->id() ); self::assertSame( $quote->header()->to_private_json(), $read->header()->to_private_json() ); self::assertTrue( $fresh->rollback() ); self::assertTrue( $fresh->retire() );
		$foreign = $this->factory->open( 2 ); self::assertTrue( $foreign->begin() ); self::assertNull( ( new DeliveryQuoteRepository( $foreign ) )->find_quote( $quote->header()->id() ) );
		$this->refuses( fn() => ( new DeliveryQuoteRepository( $foreign ) )->insert_quote( $quote ) ); self::assertTrue( $foreign->rollback() ); self::assertTrue( $foreign->retire() );
	}
	public function test_schema8_and_failed_schema9_status_refuse_new_storage_writes(): void {
		$this->write_refused(); $this->ready(); $this->option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'failed', 'to_version' => '9', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID ] ) ); $this->write_refused();
		self::assertSame( 0, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quotes' ) . '`' ) );
	}
	public function test_reader_rollback_refuses_quote_storage_and_preserves_accepted_history_and_tombstone(): void {
		$this->ready(); $accepted = Facts::quote( state: 'accepted' ); $stripped = Facts::quote( state: 'stripped' ); self::assertNotFalse( $this->insert( 'delivery_quotes', $accepted->row() ) ); self::assertNotFalse( $this->insert( 'delivery_quotes', $stripped->row() ) );
		self::assertNotFalse( $this->insert( 'delivery_quote_bindings', Facts::binding( $accepted )->row() ) ); self::assertNotFalse( $this->insert( 'delivery_quote_budget_windows', Facts::budget()->row() ) );
		$tables = DeliveryQuoteSchema::tables( $this->prefix ); $before = $this->bytes( $tables ); $this->option( SchemaVersion::OPTION_NAME, '8' ); $this->option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) );
		$this->write_refused(); self::assertSame( $before, $this->bytes( $tables ) );
	}
	public function test_retry_inspects_and_preserves_existing_accepted_quote_binding_budget_and_tombstone(): void {
		$this->ready(); $accepted = Facts::quote( state: 'accepted' );
		self::assertNotFalse( $this->insert( 'delivery_quotes', $accepted->row() ) ); self::assertNotFalse( $this->insert( 'delivery_quotes', Facts::quote( state: 'stripped' )->row() ) );
		self::assertNotFalse( $this->insert( 'delivery_quote_bindings', Facts::binding( $accepted )->row() ) ); self::assertNotFalse( $this->insert( 'delivery_quote_budget_windows', Facts::budget()->row() ) );
		$tables = DeliveryQuoteSchema::tables( $this->prefix ); $before = $this->bytes( $tables );
		$GLOBALS['cetech_de_test_options'][SchemaVersion::OPTION_NAME] = '8'; $this->option( SchemaVersion::OPTION_NAME, '8' ); $this->ready(); self::assertSame( $before, $this->bytes( $tables ) );
	}
	public function test_native_three_store_primitives_commit_and_stale_quote_cas_preserves_newer_state(): void {
		$this->ready(); $s = $this->factory->open(); self::assertTrue( $s->begin() ); $r = new DeliveryQuoteRepository( $s ); $issued = Facts::quote(); $id = $r->insert_quote( $issued );
		$opened = $r->find_quote( $issued->header()->id(), true ); self::assertNotNull( $opened ); self::assertSame( $id, $opened->id() );
		$row = $opened->row(); $row['state'] = 'accepted'; $row['revision'] = 2; $row['accepted_at'] = $row['transition_at'] = $opened->header()->created_at()->plus_seconds( 1 )->sql(); $accepted = QuoteStoredRow::from_row( $row );
		self::assertTrue( $r->replace_quote( $opened, $accepted ) ); $binding_id = $r->insert_binding( Facts::binding( $accepted ) ); $budget = Facts::budget(); $budget_id = $r->insert_budget( $budget );
		self::assertGreaterThan( 0, $binding_id ); self::assertGreaterThan( 0, $budget_id ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
		$fresh = $this->factory->open(); self::assertTrue( $fresh->begin() ); $r = new DeliveryQuoteRepository( $fresh ); self::assertSame( 'accepted', $r->find_quote( $issued->header()->id() )->state() ); self::assertSame( $binding_id, $r->find_binding( $accepted )->id() ); self::assertSame( $budget_id, $r->find_admission( $budget->row()['admission_namespace_hash'] )->id() );
		$row = $accepted->row(); $row['state'] = 'invalidated'; $row['revision'] = 3; $row['transition_at'] = $accepted->header()->created_at()->plus_seconds( 2 )->sql(); $next = QuoteStoredRow::from_row( $row ); self::assertTrue( $r->replace_quote( $accepted, $next ) ); self::assertSame( OperationCommitResult::Acknowledged, $fresh->commit() ); self::assertTrue( $fresh->retire() );
		$stale = $this->factory->open(); self::assertTrue( $stale->begin() ); self::assertFalse( ( new DeliveryQuoteRepository( $stale ) )->replace_quote( $accepted, $next ) ); self::assertTrue( $stale->rollback() ); self::assertTrue( $stale->retire() ); self::assertSame( 'invalidated', $this->row( 'delivery_quotes', $id )['state'] );
		self::assertSame( $accepted->row()['header_json'], $this->row( 'delivery_quotes', $id )['header_json'] );
	}
	public function test_binding_insert_rejects_an_accepted_dto_without_an_actual_accepted_parent(): void {
		$this->ready(); $fake = Facts::quote( state: 'accepted' ); $s = $this->factory->open(); self::assertTrue( $s->begin() ); $r = new DeliveryQuoteRepository( $s );
		$this->refuses( fn() => $r->insert_binding( Facts::binding( $fake ) ) ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
		self::assertSame( 0, (int) DB::scalar( $this->database, 'SELECT COUNT(*) FROM `' . $this->table( 'delivery_quote_bindings' ) . '`' ) );
		$s = $this->factory->open(); self::assertTrue( $s->begin() ); $r = new DeliveryQuoteRepository( $s ); $issued = Facts::quote(); $id = $r->insert_quote( $issued );
		$fake = Facts::quote( $id, quote_id: $issued->header()->id(), state: 'accepted' );
		$this->refuses( fn() => $r->insert_binding( Facts::binding( $fake ) ) ); self::assertSame( 'issued', $r->find_quote( $issued->header()->id() )->state() ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
	}
	public function test_consumed_budget_cas_rejects_a_quote_dto_without_the_actual_quote_row(): void {
		$this->ready(); $parent = Facts::quote(); $row = Facts::budget()->row(); $row['admission_namespace_hash'] = $parent->row()['issue_namespace_hash']; $row['slot_key'] = QuoteBudgetSlot::admission_slot_key( $row['admission_namespace_hash'] ); $granted = QuoteBudgetSlot::from_row( $row );
		$s = $this->factory->open(); self::assertTrue( $s->begin() ); $r = new DeliveryQuoteRepository( $s ); $id = $r->insert_budget( $granted ); $row['id'] = $id; $granted = QuoteBudgetSlot::from_row( $row );
		$row['lease_state'] = 'consumed'; $row['revision'] = 2; $row['consumed_quote_uuid'] = $parent->header()->id()->value(); $row['consumed_at'] = $row['last_seen_at'] = $parent->header()->created_at()->plus_seconds( 1 )->sql(); $consumed = QuoteBudgetSlot::from_row( $row, $parent );
		$this->refuses( fn() => $r->replace_budget( $granted, $consumed ) ); self::assertSame( 'granted', $r->find_admission( $granted->row()['admission_namespace_hash'] )->row()['lease_state'] );
		self::assertGreaterThan( 0, $r->insert_quote( $parent ) ); self::assertTrue( $r->replace_budget( $granted, $consumed ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
		$fresh = $this->factory->open(); self::assertTrue( $fresh->begin() ); $loaded = ( new DeliveryQuoteRepository( $fresh ) )->find_admission( $granted->row()['admission_namespace_hash'] ); self::assertSame( 'consumed', $loaded->row()['lease_state'] ); self::assertSame( $parent->header()->id()->value(), $loaded->row()['consumed_quote_uuid'] ); self::assertTrue( $fresh->rollback() ); self::assertTrue( $fresh->retire() );
	}
}
