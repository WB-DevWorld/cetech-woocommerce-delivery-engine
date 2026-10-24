<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPolicyAssignmentAdminIntent;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentAdminIntentDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;

/** Exact byte/fence protocol tests only, without a WordPress or database service. */
final class WpdbPolicyAssignmentAdminIntentTest extends TestCase {
	private PolicyAssignmentAdminIntentDatabase $database;
	private WpdbPolicyAssignmentAdminIntent $store;
	private string $name;
	private mixed $previous_blog;
	private mixed $previous_database;
	private mixed $previous_deletes;

	protected function setUp(): void {
		$this->previous_blog = $GLOBALS['blog_id'] ?? null;
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->previous_deletes = $GLOBALS['cetech_de_test_cache_deletes'] ?? null;
		$GLOBALS['blog_id'] = 1;
		$this->database = new PolicyAssignmentAdminIntentDatabase();
		$this->store = new WpdbPolicyAssignmentAdminIntent( $this->database );
		$this->name = WpdbPolicyAssignmentAdminIntent::name( 1, 9 );
	}
	protected function tearDown(): void {
		foreach ( [ 'blog_id' => $this->previous_blog, 'wpdb' => $this->previous_database, 'cetech_de_test_cache_deletes' => $this->previous_deletes ] as $key => $value ) {
			if ( null === $value ) { unset( $GLOBALS[$key] ); } else { $GLOBALS[$key] = $value; }
		}
	}
	private function locked( callable $work ): mixed { return $this->store->locked( $this->name, $work ); }
	private function bytes(): string { return '{"state":"ready","text":"PRIVATE refund café Ω\\nline","original_token":"PRIVATE_ORIGINAL_TOKEN"}'; }
	private function asserted_refusal( callable $work ): void {
		try { $work(); self::fail( 'An unacknowledged editor operation must refuse.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Policy assignment editor storage could not be confirmed.', $error->getMessage() ); }
	}
	private function writes(): array { return array_values( array_filter( $this->database->queries, static fn( string $query ): bool => 1 === preg_match( '/\A(?:INSERT|UPDATE|DELETE) /', $query ) ) ); }

	public function test_names_are_bounded_and_distinguish_the_native_owner_and_site(): void {
		self::assertSame( $this->name, WpdbPolicyAssignmentAdminIntent::name( 1, 9 ) );
		self::assertStringStartsWith( WpdbPolicyAssignmentAdminIntent::PREFIX, $this->name );
		self::assertLessThanOrEqual( 191, strlen( $this->name ) );
		self::assertNotSame( $this->name, WpdbPolicyAssignmentAdminIntent::name( 2, 9 ) );
		self::assertNotSame( $this->name, WpdbPolicyAssignmentAdminIntent::name( 1, 8 ) );
	}

	public static function invalid_owners(): array { return [ 'site-zero' => [ 0, 9 ], 'site-negative' => [ -1, 9 ], 'owner-zero' => [ 1, 0 ], 'owner-negative' => [ 1, -1 ] ]; }
	#[DataProvider( 'invalid_owners' )]
	public function test_nonpositive_native_identifiers_never_name_a_record( int $site, int $owner ): void {
		$this->asserted_refusal( static fn(): string => WpdbPolicyAssignmentAdminIntent::name( $site, $owner ) );
		self::assertSame( [], $this->database->queries );
	}

	public function test_no_read_or_write_is_permitted_outside_the_original_name_fence(): void {
		foreach ( [ fn() => $this->store->read( $this->name ), fn() => $this->store->insert( $this->name, $this->bytes() ), fn() => $this->store->replace( $this->name, 'old', 'new' ), fn() => $this->store->remove( $this->name, 'old' ) ] as $work ) { $this->asserted_refusal( $work ); }
		self::assertSame( [], $this->database->queries );
	}

	public function test_insert_reads_exact_private_utf8_bytes_and_invalidates_only_owned_cache_keys(): void {
		$GLOBALS['cetech_de_test_cache_deletes'] = [];
		$bytes = $this->bytes();
		$this->locked( function () use ( $bytes ): void {
			self::assertNull( $this->store->read( $this->name ) );
			self::assertTrue( $this->store->insert( $this->name, $bytes ) );
			self::assertSame( $bytes, $this->store->read( $this->name ) );
		} );
		self::assertSame( [ [ $this->name, 'options' ], [ 'alloptions', 'options' ], [ 'notoptions', 'options' ] ], $GLOBALS['cetech_de_test_cache_deletes'] );
		self::assertSame( [], $this->database->locks );
		self::assertCount( 1, $this->writes() );
		self::assertStringContainsString( "autoload) VALUES (%s, %s, 'off')", $this->writes()[0] );
		self::assertStringContainsString( 'CASE WHEN OCTET_LENGTH(option_value) <= %d', implode( "\n", $this->database->queries ) );
	}

	public function test_second_tab_cannot_replace_the_first_original_request(): void {
		$first = $this->bytes();
		$this->locked( function () use ( $first ): void {
			self::assertTrue( $this->store->insert( $this->name, $first ) );
			$this->asserted_refusal( fn() => $this->store->insert( $this->name, '{"different_original":true}' ) );
			self::assertSame( $first, $this->store->read( $this->name ) );
		} );
		self::assertSame( $first, $this->database->rows[$this->name] );
		self::assertSame( [], $this->database->locks );
	}

	public function test_update_and_delete_compare_case_sensitive_bytes_and_confirm_the_result(): void {
		$before = '{"body":"Original"}'; $after = '{"body":"Next"}'; $this->database->rows[$this->name] = $before;
		$this->locked( function () use ( $before, $after ): void {
			self::assertFalse( $this->store->replace( $this->name, strtolower( $before ), $after ) );
			self::assertSame( $before, $this->store->read( $this->name ) );
			self::assertTrue( $this->store->replace( $this->name, $before, $after ) );
			self::assertFalse( $this->store->remove( $this->name, strtolower( $after ) ) );
			self::assertSame( $after, $this->store->read( $this->name ) );
			self::assertTrue( $this->store->remove( $this->name, $after ) );
		} );
		self::assertArrayNotHasKey( $this->name, $this->database->rows );
		foreach ( $this->writes() as $query ) { self::assertStringContainsString( 'BINARY option_name = %s AND BINARY option_value = %s', $query ); }
	}

	public static function write_kinds(): array { return [ 'insert' => [ 'insert' ], 'replace' => [ 'replace' ], 'remove' => [ 'remove' ] ]; }
	#[DataProvider( 'write_kinds' )]
	public function test_positive_write_count_without_durable_effect_is_not_acknowledged( string $kind ): void {
		$bytes = $this->bytes(); if ( 'insert' !== $kind ) { $this->database->rows[$this->name] = $bytes; }
		$this->database->ack_without_write = true;
		$this->locked( function () use ( $kind, $bytes ): void {
			$result = match ( $kind ) { 'insert' => $this->store->insert( $this->name, $bytes ), 'replace' => $this->store->replace( $this->name, $bytes, '{"new":true}' ), 'remove' => $this->store->remove( $this->name, $bytes ) };
			self::assertFalse( $result );
		} );
		self::assertSame( 'insert' === $kind ? [] : [ $this->name => $bytes ], $this->database->rows );
	}

	#[DataProvider( 'write_kinds' )]
	public function test_committed_write_with_lost_readback_remains_unacknowledged( string $kind ): void {
		$bytes = $this->bytes(); $new = '{"state":"inflight","same_original":true}';
		if ( 'insert' !== $kind ) { $this->database->rows[$this->name] = $bytes; }
		$this->database->after_write = function (): void { $this->database->fail_next_read = true; };
		$this->asserted_refusal( fn() => $this->locked( fn() => match ( $kind ) { 'insert' => $this->store->insert( $this->name, $bytes ), 'replace' => $this->store->replace( $this->name, $bytes, $new ), 'remove' => $this->store->remove( $this->name, $bytes ) } ) );
		self::assertSame( [], $this->database->locks );
		$actual = $this->locked( fn() => $this->store->read( $this->name ) );
		self::assertSame( match ( $kind ) { 'insert' => $bytes, 'replace' => $new, 'remove' => null }, $actual );
	}

	public function test_changed_row_during_update_readback_cannot_be_reported_as_confirmed(): void {
		$this->database->rows[$this->name] = 'Original';
		$this->database->after_write = function (): void { $this->database->rows[$this->name] = 'Concurrent'; };
		$this->locked( function (): void { self::assertFalse( $this->store->replace( $this->name, 'Original', 'Expected' ) ); } );
		self::assertSame( 'Concurrent', $this->database->rows[$this->name] );
	}

	public function test_write_error_is_not_an_absent_record_or_a_successful_claim(): void {
		$this->database->fail_writes = true;
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->insert( $this->name, $this->bytes() ) ) );
		self::assertSame( [], $this->database->rows ); self::assertSame( [], $this->database->locks );
	}

	public function test_nonwaiting_foreign_connection_lock_stops_work_before_any_read_or_write(): void {
		$lock = 'cetech_policy_' . substr( hash( 'sha256', $this->database->database_name . ':' . $this->database->options . ':' . $this->name ), 0, 48 );
		$this->database->locks[$lock] = 999; $called = false;
		$this->asserted_refusal( fn() => $this->locked( function () use ( &$called ): void { $called = true; } ) );
		self::assertFalse( $called ); self::assertSame( [], $this->writes() );
		self::assertSame( [ $lock => 999 ], $this->database->locks );
		self::assertStringNotContainsString( 'GET_LOCK(%s, 0)', implode( "\n", $this->database->queries ) );
	}

	public function test_wrong_record_and_nested_requests_cannot_reuse_another_fence(): void {
		$other = WpdbPolicyAssignmentAdminIntent::name( 1, 10 );
		$this->locked( function () use ( $other ): void {
			$this->asserted_refusal( fn() => $this->store->read( $other ) );
			$this->asserted_refusal( fn() => $this->store->insert( $other, 'wrong owner' ) );
			$this->asserted_refusal( fn() => $this->store->locked( $other, static fn(): bool => true ) );
			self::assertTrue( $this->store->insert( $this->name, 'original' ) );
		} );
		self::assertSame( [ $this->name => 'original' ], $this->database->rows ); self::assertSame( [], $this->database->locks );
	}

	public static function fence_mutations(): array { return [ 'connection' => [ 'connection' ], 'database' => [ 'database' ], 'table' => [ 'table' ], 'prefix' => [ 'prefix' ], 'blog' => [ 'blog' ], 'owner-lock' => [ 'owner-lock' ] ]; }
	#[DataProvider( 'fence_mutations' )]
	public function test_changed_native_fence_cannot_acknowledge_a_claim( string $kind ): void {
		$this->asserted_refusal( fn() => $this->locked( function () use ( $kind ): bool {
			self::assertTrue( $this->store->insert( $this->name, 'Original' ) );
			match ( $kind ) {
				'connection' => ++$this->database->connection,
				'database' => $this->database->database_name = 'foreign_database',
				'table' => $this->database->options = 'foreign_options',
				'prefix' => $this->database->prefix = 'foreign_',
				'blog' => $GLOBALS['blog_id'] = 2,
				'owner-lock' => $this->database->locks = array_map( static fn(): int => 999, $this->database->locks ),
			};
			return $this->store->replace( $this->name, 'Original', 'Unauthorized next' );
		} ) );
		self::assertSame( 'Original', $this->database->rows[$this->name] ); self::assertCount( 1, $this->writes() );
		if ( ! in_array( $kind, [ 'connection', 'owner-lock' ], true ) ) { self::assertSame( [], $this->database->locks ); }
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_replaced_global_database_cannot_reuse_the_old_native_transport(): void {
		eval( 'class wpdb extends \\CetechDeliveryEngine\\Tests\\Support\\PolicyAssignment\\PolicyAssignmentAdminIntentDatabase {}' );
		$this->database = new \wpdb();
		$GLOBALS['wpdb'] = $this->database; $native = new WpdbPolicyAssignmentAdminIntent();
		$this->asserted_refusal( fn() => $native->locked( $this->name, function () use ( $native ): bool {
			$GLOBALS['wpdb'] = new PolicyAssignmentAdminIntentDatabase();
			return $native->insert( $this->name, 'wrong native owner' );
		} ) );
		self::assertSame( [], $this->writes() ); self::assertSame( [], $this->database->locks );
	}

	public static function malformed_reads(): array { return [ 'oversized' => [ 'oversized' ], 'bad-length' => [ 'bad-length' ], 'wrong-name' => [ 'wrong-name' ], 'invalid-utf8' => [ 'invalid-utf8' ], 'empty' => [ 'empty' ] ]; }
	#[DataProvider( 'malformed_reads' )]
	public function test_malformed_or_oversized_record_never_looks_like_no_pending_request( string $kind ): void {
		$this->database->rows[$this->name] = match ( $kind ) { 'oversized' => str_repeat( 'a', WpdbPolicyAssignmentAdminIntent::MAX_BYTES + 1 ), 'invalid-utf8' => "\xff", 'empty' => '', default => $this->bytes() };
		$this->database->bad_length = 'bad-length' === $kind; $this->database->wrong_read_name = 'wrong-name' === $kind;
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertArrayHasKey( $this->name, $this->database->rows ); self::assertSame( [], $this->writes() );
	}

	public function test_collation_equivalent_noncanonical_name_is_corruption_not_absence(): void {
		$this->database->rows[strtoupper( $this->name )] = $this->bytes();
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertSame( [ strtoupper( $this->name ) => $this->bytes() ], $this->database->rows );
		self::assertSame( [], $this->writes() );
	}

	public static function invalid_unique_indexes(): array {
		$part = [ 'Table' => 'wp_options', 'Key_name' => 'option_name', 'Column_name' => 'option_name', 'Non_unique' => '0', 'Seq_in_index' => '1', 'Sub_part' => null ];
		return [
			'absent' => [ [] ], 'duplicate-parts' => [ [ $part, $part ] ],
			'nonunique' => [ [ array_replace( $part, [ 'Non_unique' => '1' ] ) ] ],
			'prefix-only' => [ [ array_replace( $part, [ 'Sub_part' => '20' ] ) ] ],
			'wrong-column' => [ [ array_replace( $part, [ 'Column_name' => 'option_value' ] ) ] ],
			'wrong-table' => [ [ array_replace( $part, [ 'Table' => 'other_options' ] ) ] ],
			'functional' => [ [ array_replace( $part, [ 'Expression' => 'lower(option_name)' ] ) ] ],
		];
	}
	#[DataProvider( 'invalid_unique_indexes' )]
	public function test_missing_or_incomplete_unique_name_key_cannot_acknowledge_an_intent( array $parts ): void {
		$this->database->index_parts = $parts; $called = false;
		$this->asserted_refusal( fn() => $this->locked( function () use ( &$called ): void { $called = true; } ) );
		self::assertFalse( $called ); self::assertSame( [], $this->writes() ); self::assertSame( [], $this->database->locks );
	}

	public function test_null_read_with_sql_error_is_uncertainty_rather_than_absence(): void {
		$this->database->fail_next_read = true;
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertSame( [], $this->database->locks );
	}

	public function test_read_permission_callback_losing_connection_before_disclosure_cannot_return_bytes(): void {
		$this->database->rows[$this->name] = $this->bytes();
		$this->database->after_read = function (): void { ++$this->database->connection; };
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertSame( $this->bytes(), $this->database->rows[$this->name] );
	}

	public function test_exact_maximum_bytes_survive_and_larger_values_never_reach_sql(): void {
		$exact = str_repeat( 'a', WpdbPolicyAssignmentAdminIntent::MAX_BYTES );
		$this->locked( function () use ( $exact ): void {
			self::assertTrue( $this->store->insert( $this->name, $exact ) );
			$this->asserted_refusal( fn() => $this->store->replace( $this->name, $exact, $exact . 'a' ) );
		} );
		self::assertSame( $exact, $this->database->rows[$this->name] ); self::assertCount( 1, $this->writes() );
	}

	public function test_unacknowledged_release_cannot_return_a_successful_storage_result(): void {
		$this->database->fail_release = true;
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->insert( $this->name, $this->bytes() ) ) );
		self::assertSame( $this->bytes(), $this->database->rows[$this->name] );
		$this->database->fail_release = false;
		// Recursive reacquisition would leak a named-lock reference. A retained
		// same-connection lock cannot authorize another dispatcher.
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertCount( 1, $this->database->locks );
		// Model connection teardown, which releases native session locks.
		$this->database->locks = []; ++$this->database->connection;
		self::assertSame( $this->bytes(), $this->locked( fn() => $this->store->read( $this->name ) ) );
		self::assertSame( [], $this->database->locks );
	}

	public static function foreign_transaction_states(): array { return [ 'autocommit-disabled' => [ 0, 0 ], 'explicit-transaction' => [ 1, 1 ], 'both' => [ 0, 1 ] ]; }
	#[DataProvider( 'foreign_transaction_states' )]
	public function test_an_existing_foreign_transaction_cannot_claim_or_acknowledge_intent( int $autocommit, int $transaction ): void {
		$this->database->autocommit = $autocommit; $this->database->in_transaction = $transaction; $called = false;
		$this->asserted_refusal( fn() => $this->locked( function () use ( &$called ): void { $called = true; } ) );
		self::assertFalse( $called ); self::assertSame( [], $this->writes() ); self::assertSame( [], $this->database->locks );
		self::assertSame( $autocommit, $this->database->autocommit ); self::assertSame( $transaction, $this->database->in_transaction );
	}

	#[DataProvider( 'foreign_transaction_states' )]
	public function test_transaction_opened_after_write_cannot_make_same_connection_readback_durable( int $autocommit, int $transaction ): void {
		$this->database->after_write = function () use ( $autocommit, $transaction ): void { $this->database->autocommit = $autocommit; $this->database->in_transaction = $transaction; };
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->insert( $this->name, $this->bytes() ) ) );
		self::assertSame( $this->bytes(), $this->database->rows[$this->name] ); self::assertSame( [], $this->database->locks );
		self::assertSame( $autocommit, $this->database->autocommit ); self::assertSame( $transaction, $this->database->in_transaction );
		self::assertStringNotContainsString( 'COMMIT', implode( "\n", $this->database->queries ) );
		self::assertStringNotContainsString( 'ROLLBACK', implode( "\n", $this->database->queries ) );
	}

	public function test_original_exception_releases_the_owned_fence_and_does_not_expose_journal_debug_bytes(): void {
		try { $this->locked( static function (): never { throw new \LogicException( 'synthetic caller fault' ); } ); self::fail( 'Caller error must remain visible to its owner.' ); }
		catch ( \LogicException $error ) { self::assertSame( 'synthetic caller fault', $error->getMessage() ); }
		self::assertSame( [], $this->database->locks );
		$this->database->rows[$this->name] = $this->bytes();
		self::assertSame( [ 'policy_assignment_editor_journal' => 'redacted' ], $this->store->__debugInfo() );
	}

	public function test_default_native_composition_refuses_custom_database_routing_objects(): void {
		$GLOBALS['wpdb'] = $this->database;
		$native = new WpdbPolicyAssignmentAdminIntent();
		self::assertSame( [], $this->database->queries );
		$this->asserted_refusal( fn() => $native->locked( $this->name, static fn(): bool => true ) );
		self::assertSame( [], $this->database->queries );
		self::assertNull( $this->locked( fn() => $this->store->read( $this->name ) ) );
	}

	public function test_composition_without_a_global_database_is_lazy_but_operations_refuse(): void {
		unset( $GLOBALS['wpdb'] );
		$native = new WpdbPolicyAssignmentAdminIntent();
		self::assertSame( [], $this->database->queries );
		$this->asserted_refusal( fn() => $native->locked( $this->name, static fn(): bool => true ) );
		$this->asserted_refusal( fn() => $native->read( $this->name ) );
		self::assertSame( [], $this->database->queries );
	}

	public function test_index_inspection_reconnect_or_transaction_change_is_caught_by_final_atomic_fence(): void {
		$this->database->after_index = function (): void { $this->database->in_transaction = 1; };
		$this->asserted_refusal( fn() => $this->locked( fn() => $this->store->insert( $this->name, $this->bytes() ) ) );
		self::assertSame( [], $this->writes() ); self::assertSame( 1, $this->database->in_transaction );
		self::assertSame( [], $this->database->locks );
	}

	public function test_unowned_option_names_are_refused_before_any_database_query(): void {
		foreach ( [ 'arbitrary_option', WpdbPolicyAssignmentAdminIntent::PREFIX . str_repeat( 'A', 64 ), $this->name . 'x', '' ] as $name ) {
			$this->asserted_refusal( fn() => $this->store->locked( $name, static fn(): bool => true ) );
		}
		self::assertSame( [], $this->database->queries );
	}

	public function test_unacknowledged_prepare_and_lock_identity_stop_work_without_writes(): void {
		$this->database->bad_prepare = true; $called = false;
		$this->asserted_refusal( fn() => $this->locked( function () use ( &$called ): void { $called = true; } ) );
		self::assertFalse( $called ); self::assertSame( [], $this->writes() );
		$this->database->bad_prepare = false; $this->database->error_next_scalar = true;
		$this->asserted_refusal( fn() => $this->locked( static fn(): bool => true ) );
		self::assertSame( [], $this->writes() );
	}

	public function test_return_and_assignment_originals_coexist_under_independent_native_name_fences(): void {
		$return = new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent( $this->database );
		$return_name = \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent::name( 1, 9 );
		self::assertNotSame( $return_name, $this->name );
		$return->locked( $return_name, function () use ( $return, $return_name ): void {
			self::assertTrue( $return->insert( $return_name, 'private unresolved Return original' ) );
			self::assertCount( 1, $this->database->locks );
			$this->locked( function (): void {
				self::assertCount( 2, $this->database->locks );
				self::assertTrue( $this->store->insert( $this->name, 'private unresolved Policy assignment original' ) );
			} );
			self::assertCount( 1, $this->database->locks );
			self::assertSame( 'private unresolved Return original', $return->read( $return_name ) );
		} );
		self::assertSame( [ $return_name => 'private unresolved Return original', $this->name => 'private unresolved Policy assignment original' ], $this->database->rows );
		self::assertSame( [], $this->database->locks );
		$this->locked( function (): void { self::assertTrue( $this->store->remove( $this->name, 'private unresolved Policy assignment original' ) ); } );
		self::assertSame( [ $return_name => 'private unresolved Return original' ], $this->database->rows );
	}

	public function test_assignment_transport_cannot_read_replace_or_remove_an_original_return_record(): void {
		$return_name = \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent::name( 1, 9 );
		$this->database->rows[$return_name] = 'private Return original';
		$this->locked( function () use ( $return_name ): void {
			$this->asserted_refusal( fn() => $this->store->read( $return_name ) );
			$this->asserted_refusal( fn() => $this->store->replace( $return_name, 'private Return original', 'rebound as Policy assignment' ) );
			$this->asserted_refusal( fn() => $this->store->remove( $return_name, 'private Return original' ) );
			$this->asserted_refusal( fn() => $this->store->locked( $return_name, static fn(): bool => true ) );
		} );
		self::assertSame( [ $return_name => 'private Return original' ], $this->database->rows );
		self::assertSame( [], $this->writes() );
		self::assertSame( [], $this->database->locks );
	}

	public function test_both_catalog_originals_and_authoritative_assignment_storage_are_outside_this_journal(): void {
		$return_name = \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent::name( 1, 9 );
		$refund_name = \CetechDeliveryEngine\Infrastructure\Persistence\WpdbRefundPolicyAdminIntent::name( 1, 9 );
		$assignment_name = \CetechDeliveryEngine\Infrastructure\Persistence\PolicyAssignmentStore::PREFIX . str_repeat( 'a', 64 );
		$this->database->rows = [ $return_name => 'original Return', $refund_name => 'original Refund', $assignment_name => 'authoritative assignment body' ];
		$before = $this->database->rows;
		$this->locked( function () use ( $return_name, $refund_name, $assignment_name ): void {
			foreach ( [ $return_name, $refund_name, $assignment_name ] as $name ) {
				$this->asserted_refusal( fn() => $this->store->read( $name ) );
				$this->asserted_refusal( fn() => $this->store->replace( $name, $this->database->rows[$name], 'forged original' ) );
				$this->asserted_refusal( fn() => $this->store->remove( $name, $this->database->rows[$name] ) );
			}
		} );
		self::assertSame( $before, $this->database->rows ); self::assertSame( [], $this->writes() );
	}
}
