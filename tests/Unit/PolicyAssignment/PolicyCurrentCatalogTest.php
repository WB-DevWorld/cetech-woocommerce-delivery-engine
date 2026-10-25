<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\PolicyAssignment\PolicyCurrentCatalog;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentMap;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleStartMode, RuleSnapshot};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/PolicyAssignment/PolicyAssignmentFixture.php';

/** Real C01 values on an owned SQLite transport; no physical-lock acceptance. */
final class PolicyCurrentCatalogTest extends TestCase {

	private PolicyAssignmentFixture $fixture;
	protected function setUp(): void { $this->fixture = new PolicyAssignmentFixture(); }
	protected function tearDown(): void { $this->fixture->close(); }

	public function test_locks_refund_before_return_and_reads_only_the_selected_logical_policy(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $f->publish( 'return_policy_v1', 102, 2 ); $f->publish( 'refund_policy_v1', 201, 3 );
		// Corrupt an unrelated policy. Selected capture must neither load nor disclose it.
		$f->factory->inspection()->exec( 'UPDATE ' . $f->table( 'rule_versions' ) . " SET payload_json='broken' WHERE version_uuid='" . $f::uuid( 2 ) . "'" );
		$f->factory->sql = []; $session = $f->factory->open(); self::assertTrue( $session->begin() );
		$catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		$snapshot = $catalog->snapshot( $session, 'return_policy_v1', $f::uuid( 101 ) );
		self::assertTrue( $snapshot->complete ); self::assertTrue( $snapshot->history_complete );
		self::assertCount( 1, $snapshot->logicals ); self::assertSame( $f::uuid( 101 ), $snapshot->logicals[0]->logical_uuid );
		self::assertCount( 1, $snapshot->versions );
		$sql = implode( "\n", $f->factory->sql ); self::assertLessThan( strpos( $sql, "family_code='return_policy_v1'" ), strpos( $sql, "family_code='refund_policy_v1'" ) );
		self::assertStringNotContainsString( 'LEFT JOIN', $sql ); self::assertStringContainsString( 'logical_rule_id=', $sql );
		self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
	}

	public function test_reviewed_original_head_is_exact_and_read_only(): void {
		$f = $this->fixture; $return = $f->publish( 'return_policy_v1', 101, 1 ); $refund = $f->publish( 'refund_policy_v1', 201, 2 );
		$before = [ $f->rows( 'rule_versions' ), $f->rows( 'logical_rules' ), $f->rows( 'rule_family_guards' ) ];
		$session = $f->factory->open(); self::assertTrue( $session->begin() ); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		$catalog->assert_reviewed( $session, [ 'return_policy_v1' => $return, 'refund_policy_v1' => $refund ], RuleTime::parse( $f->factory->clock ) );
		$session->rollback(); $session->retire();
		self::assertSame( $before, [ $f->rows( 'rule_versions' ), $f->rows( 'logical_rules' ), $f->rows( 'rule_family_guards' ) ] );
	}

	#[DataProvider( 'witness_changes' )]
	public function test_changed_review_witness_never_matches_the_current_head( string $field, mixed $value ): void {
		$f = $this->fixture; $review = $f->publish( 'return_policy_v1', 101, 1 ); $review[$field] = $value;
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		try { $catalog->assert_reviewed( $session, [ 'return_policy_v1' => $review, 'refund_policy_v1' => null ], RuleTime::parse( $f->factory->clock ) ); self::fail( 'A forged or stale witness matched.' ); }
		catch ( \UnexpectedValueException ) { self::assertTrue( true ); }
		finally { $session->rollback(); $session->retire(); }
	}
	public static function witness_changes(): array {
		return [ 'logical' => [ 'policy_uuid', PolicyAssignmentFixture::uuid( 999 ) ], 'version' => [ 'version_uuid', PolicyAssignmentFixture::uuid( 999 ) ], 'logical revision' => [ 'logical_revision', 99 ], 'version revision' => [ 'version_revision', 99 ], 'guard' => [ 'guard_revision', 99 ], 'hash' => [ 'content_hash', str_repeat( 'f', 64 ) ], 'numeric coercion' => [ 'logical_revision', '2' ], 'extra' => [ 'extra', true ] ];
	}

	public function test_foreign_family_same_uuid_is_unresolved_and_never_reinterpreted(): void {
		$f = $this->fixture; $f->publish( 'refund_policy_v1', 201, 1 ); $session = $f->factory->open(); $session->begin();
		$catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		$selection = $catalog->selection( $session, 'return_policy_v1', [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 201 ) ], [], str_repeat( 'a', 64 ), RuleTime::parse( $f->factory->clock ) );
		self::assertSame( 'unresolved', $selection->disposition() ); self::assertNull( $selection->version() ); $session->rollback(); $session->retire();
	}

	public function test_no_assignment_and_disable_do_not_read_a_policy_or_create_a_family(): void {
		$f = $this->fixture; $session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session ); $f->factory->sql = [];
		foreach ( [ 'inherit' => 'not_assigned', 'disabled' => 'disabled' ] as $mode => $expected ) {
			$selection = $catalog->selection( $session, 'return_policy_v1', [ 'mode' => $mode ], [], str_repeat( 'a', 64 ), RuleTime::parse( $f->factory->clock ) );
			self::assertSame( $expected, $selection->disposition() ); self::assertNull( $selection->version() );
		}
		self::assertSame( [], $f->factory->sql ); $session->rollback(); $session->retire(); self::assertSame( 0, $f->count( 'rule_family_guards' ) );
	}

	public function test_target_only_grant_does_not_need_permission_for_another_policy(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $f->publish( 'return_policy_v1', 102, 2 );
		$id = $this->identity(); $scopes = [];
		$profile = new ReturnPolicyFamily( static function( OperationIdentity $candidate, array $scope ) use ( $id, &$scopes ): bool { $scopes[] = $scope; return $candidate === $id && [ 'policy_uuid' => PolicyAssignmentFixture::uuid( 101 ) ] === $scope; }, static fn(): bool => false, static fn(): bool => false );
		$catalog = new PolicyCurrentCatalog( $profile, $f->refund_family, $id ); $session = $f->factory->open(); $session->begin(); $catalog->lock_families( $session );
		self::assertCount( 1, $catalog->snapshot( $session, 'return_policy_v1', $f::uuid( 101 ) )->logicals );
		self::assertNotEmpty( $scopes ); foreach ( $scopes as $scope ) { self::assertSame( [ 'policy_uuid' => $f::uuid( 101 ) ], $scope ); }
		$session->rollback(); $session->retire();
	}

	public function test_revocation_while_capturing_never_discloses_content(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $allowed = true;
		// Capture by reference is deliberate fault injection; production native grants are immutable.
		$profile = new ReturnPolicyFamily( static function() use ( &$allowed ): bool { return $allowed; }, static fn(): bool => false, static fn(): bool => false );
		$catalog = new PolicyCurrentCatalog( $profile, $f->refund_family, $this->identity() ); $session = $f->factory->open(); $session->begin(); $catalog->lock_families( $session );
		$f->factory->on_query = static function( string $sql ) use ( &$allowed ): void { if ( str_contains( $sql, 'logical_rule_id=' ) ) { $allowed = false; } };
		try { $catalog->snapshot( $session, 'return_policy_v1', $f::uuid( 101 ) ); self::fail( 'Revoked content escaped.' ); }
		catch ( \UnexpectedValueException ) { self::assertFalse( $allowed ); }
		finally { $session->rollback(); $session->retire(); }
	}

	public function test_captured_owner_cannot_be_rebound_while_its_unit_is_alive(): void {
		$f = $this->fixture; $first = $f->factory->open(); $first->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $first );
		$other = $f->factory->open();
		try { $catalog->snapshot( $other, 'return_policy_v1', $f::uuid( 101 ) ); self::fail( 'Another owner was accepted.' ); }
		catch ( OperationStorageException ) { self::assertTrue( $first->in_transaction() ); }
		finally { $first->rollback(); $first->retire(); $other->retire(); }
	}

	public function test_final_clock_check_is_pure_and_rejects_the_exact_expiry_boundary(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 );
		$row = $f->rows( 'rule_versions' )[0]; $until = RuleTime::parse( '2026-10-24 10:00:01.000000' );
		$hash = RuleContent::hash( $f->return_family, $f->return_family->payload_schema()->decode( $row['payload_json'] ), RuleStartMode::from( $row['start_mode'] ), null === $row['effective_from'] ? null : RuleTime::parse( $row['effective_from'] ), $until, 0, null );
		$statement = $f->factory->inspection()->prepare( 'UPDATE ' . $f->table( 'rule_versions' ) . ' SET effective_until=?,content_hash=? WHERE id=?' ); $statement->execute( [ $until->sql(), $hash, $row['id'] ] );
		$review = [ 'return_policy_v1' => $f->witness( 'return_policy_v1', $f::uuid( 101 ) ), 'refund_policy_v1' => null ];
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		$catalog->assert_reviewed( $session, $review, RuleTime::parse( $f->factory->clock ) ); $queries = count( $f->factory->sql );
		$catalog->assert_reviewed_at( $session, $review, RuleTime::parse( '2026-10-24 10:00:00.999999' ) ); self::assertSame( $queries, count( $f->factory->sql ) );
		try { $catalog->assert_reviewed_at( $session, $review, $until ); self::fail( 'An expired review remained current.' ); }
		catch ( \UnexpectedValueException ) { self::assertSame( $queries, count( $f->factory->sql ) ); }
		finally { $session->rollback(); $session->retire(); }
	}

	public function test_future_source_generation_refuses_an_older_still_eligible_published_version(): void {
		$f = $this->fixture; $review = [ 'return_policy_v1' => $f->publish( 'return_policy_v1', 101, 1 ), 'refund_policy_v1' => null ];
		$f->factory->inspection()->exec( 'UPDATE ' . $f->table( 'rule_family_guards' ) . " SET updated_at='2026-10-24 10:00:00.000010'" );
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session ); $at = RuleTime::parse( $f->factory->clock );
		$snapshot = $catalog->snapshot( $session, 'return_policy_v1', $f::uuid( 101 ) ); self::assertTrue( $snapshot->versions[0]->eligible_at( $at ) );
		try { $catalog->assert_reviewed( $session, $review, $at ); self::fail( 'A future source generation was accepted as current.' ); }
		catch ( \UnexpectedValueException ) { self::assertTrue( true ); }
		$catalog->assert_reviewed( $session, $review, RuleTime::parse( '2026-10-24 10:00:00.000010' ) );
		$session->rollback(); $session->retire();
	}

	#[DataProvider( 'pure_check_faults' )]
	public function test_final_clock_proof_cannot_change_owner_witness_or_rewind_time( string $fault ): void {
		$f = $this->fixture; $review = [ 'return_policy_v1' => $f->publish( 'return_policy_v1', 101, 1 ), 'refund_policy_v1' => null ];
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		$catalog->assert_reviewed( $session, $review, RuleTime::parse( $f->factory->clock ) ); $at = RuleTime::parse( $f->factory->clock ); $other = null;
		if ( 'witness' === $fault ) { ++$review['return_policy_v1']['version_revision']; }
		elseif ( 'owner' === $fault ) { $other = $f->factory->open(); }
		elseif ( 'rewind' === $fault ) { $at = RuleTime::parse( '2026-10-24 09:59:59.000000' ); }
		elseif ( 'reset' === $fault ) { $catalog->lock_families( $session ); }
		elseif ( 'failed_assert' === $fault ) {
			$changed = $review; ++$changed['return_policy_v1']['guard_revision'];
			try { $catalog->assert_reviewed( $session, $changed, $at ); self::fail( 'Changed source review matched.' ); } catch ( \UnexpectedValueException ) {}
		}
		$queries = count( $f->factory->sql );
		try { $catalog->assert_reviewed_at( $other ?? $session, $review, $at ); self::fail( 'A mismatched final proof escaped.' ); }
		catch ( \UnexpectedValueException ) { self::assertSame( $queries, count( $f->factory->sql ) ); }
		finally { $session->rollback(); $session->retire(); $other?->retire(); }
	}
	public static function pure_check_faults(): array { return [ 'witness' => [ 'witness' ], 'owner' => [ 'owner' ], 'rewind' => [ 'rewind' ], 'reset' => [ 'reset' ], 'failed assertion' => [ 'failed_assert' ] ]; }

	#[DataProvider( 'released_review_owners' )]
	public function test_last_clock_review_refuses_a_released_owner_without_new_sql( string $release ): void {
		$f = $this->fixture; $review = [ 'return_policy_v1' => $f->publish( 'return_policy_v1', 101, 1 ), 'refund_policy_v1' => null ]; $at = RuleTime::parse( $f->factory->clock );
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session ); $catalog->assert_reviewed( $session, $review, $at );
		if ( 'rollback' === $release ) { $session->rollback(); } else { $session->retire(); }
		$queries = count( $f->factory->sql );
		try { $catalog->assert_reviewed_at( $session, $review, $at ); self::fail( 'A released owner retained current proof.' ); }
		catch ( OperationStorageException ) { self::assertSame( $queries, count( $f->factory->sql ) ); }
		finally { $session->retire(); }
	}
	public static function released_review_owners(): array { return [ 'rolled back' => [ 'rollback' ], 'retired' => [ 'retire' ] ]; }

	public function test_selected_history_overflow_is_refused_even_when_the_complete_values_are_valid(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $row = $f->rows( 'rule_versions' )[0]; unset( $row['id'] );
		$row['state'] = 'retired'; $row['row_revision'] = 3; $row['retired_at'] = $row['updated_at'];
		$columns = array_keys( $row ); $sql = 'INSERT INTO ' . $f->table( 'rule_versions' ) . ' (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')'; $statement = $f->factory->inspection()->prepare( $sql );
		for ( $i = 2; $i <= 1001; ++$i ) { $row['version_uuid'] = $f::uuid( 10000 + $i ); $row['version_sequence'] = $i; $statement->execute( array_values( $row ) ); }
		$f->factory->inspection()->exec( 'UPDATE ' . $f->table( 'logical_rules' ) . ' SET last_version_sequence=1001' );
		// Establish that the refusal is the reader's bound, rather than a malformed test row.
		$full = RuleSnapshot::from_history_rows( $f->return_family, $f->rows( 'rule_family_guards' )[0], $f->rows( 'logical_rules' ), $f->rows( 'rule_versions' ) ); self::assertCount( 1001, $full->versions );
		$session = $f->factory->open(); $session->begin(); $catalog = $f->catalog( $this->identity() ); $catalog->lock_families( $session );
		try { $catalog->snapshot( $session, 'return_policy_v1', $f::uuid( 101 ) ); self::fail( 'An overflowing history was silently truncated.' ); }
		catch ( \UnexpectedValueException ) { self::assertStringContainsString( 'LIMIT 1001', implode( "\n", $f->factory->sql ) ); }
		finally { $session->rollback(); $session->retire(); }
	}

	private function identity(): OperationIdentity { return $this->fixture->identity( $this->fixture->payload(), 'read-current', 'policy.assignment.read' ); }
}
