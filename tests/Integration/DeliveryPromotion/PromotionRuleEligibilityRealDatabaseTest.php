<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetRequest;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleFamilyRegistry, RuleLifecycleCommand, RuleSnapshot, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\{RuleLifecycleSchema, SqlFixedPromotionRuleEligibilityGuard};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionEligibilityFixture as E;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofFactory;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\{RuleProofDatabase, RuleProofEnvelope, RuleProofFactory};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Owned real InnoDB/current-read proofs; explicit CI selection never silently skips. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionRuleEligibilityRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private SqlFixedPromotionRuleEligibilityGuard $guard;
	protected function setUp(): void { $this->database = RuleProofDatabase::connect(); $this->prefix = RuleProofDatabase::prefix(); RuleProofDatabase::install( $this->database, $this->prefix ); $this->guard = new SqlFixedPromotionRuleEligibilityGuard( E::family() ); }
	protected function tearDown(): void { foreach ( $this->factories as $f ) { $f->close_all(); } if ( isset( $this->database, $this->prefix ) ) { RuleProofDatabase::cleanup( $this->database, $this->prefix ); self::assertSame( '0', (string) RuleProofDatabase::scalar( $this->database, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='" . $this->database->real_escape_string( $this->prefix ) . "'" ) ); $this->database->close(); } }
	private function session( int $site = 1 ): OperationSession { $factory = new OperationProofFactory( $this->prefix, $site ); $this->factories[] = $factory; return $factory->open(); }
	private function seed( ?array $rows = null ): array { $rows ??= E::rows(); foreach ( RuleLifecycleSchema::SUFFIXES as $i => $suffix ) { foreach ( 0 === $i ? [ $rows[0] ] : $rows[$i] as $row ) { $this->insert( $suffix, $row ); } } return $rows; }
	private function insert( string $suffix, array $row ): void { $values = []; foreach ( $row as $v ) { $values[] = null === $v ? 'NULL' : "'" . $this->database->real_escape_string( (string) $v ) . "'"; } RuleProofDatabase::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_{$suffix}` (`" . implode( '`,`', array_keys( $row ) ) . '`) VALUES (' . implode( ',', $values ) . ')' ); }
	private function replace_rows( array $rows ): void { RuleSnapshot::from_history_rows( E::family(), ...$rows ); foreach ( array_reverse( RuleLifecycleSchema::SUFFIXES ) as $s ) { RuleProofDatabase::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_{$s}`" ); } $this->seed( $rows ); }
	private function planning( OperationSession $session ): ?array { return $this->guard->planning_evidence( $session, F::owner(), F::original_context(), F::original_terms(), F::key(), F::rule(), E::at() ); }
	private function compiled(): array { $s = $this->session(); self::assertTrue( $s->begin() ); $e = $this->planning( $s ); self::assertNotNull( $e ); self::assertTrue( $s->rollback() ); return E::compiled( $e['eligibility'] ); }
	private function validity( OperationSession $s, array $compiled, ?string $pool = null, ?object $at = null ): mixed { [ $h, $c, $t ] = $compiled; return $this->guard->validity_for_requests_at( $s, F::owner(), $h, $c, $t, [ FixedPromotionBudgetRequest::from_captured( $h, $c, $t, F::key(), $pool ?? E::account() ) ], $at ?? E::at() ); }
	private function facts(): array { $out = []; foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { $out[$suffix] = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" )->fetch_all( MYSQLI_ASSOC ); } return $out; }
	private function attempt( RuleLifecycleService $service, string $operation, array $payload, string $token ): object { $family = E::family(); $identity = new OperationIdentity( 1, 'wordpress', 'staff:9', $operation, 1, RuleLifecycleCommand::make_target_key( $family->family(), RuleContent::scope_hash( $family, $payload['scope'] ), $payload['logical_uuid'] ), $token ); return $service->attempt( $identity, $payload, RequestContext::create() ); }

	public function test_native_versioned_policy_publish_drives_planning_capture_and_exact_new_quote_guard(): void {
		$f = E::family(); $factory = new RuleProofFactory( $this->prefix, clock: RuleTime::parse( E::PUBLISHED ) ); $this->factories[] = $factory; $service = new RuleLifecycleService( new RuleFamilyRegistry( [ $f ] ), $factory );
		$payload = RuleProofEnvelope::create( E::uuid( 1 ), E::uuid( 101 ) ); $payload['family'] = $f->family(); $payload['scope'] = E::scope(); $payload['payload'] = [ 'rule' => F::rule()->private_facts(), 'budget_account_key' => E::account() ]; $payload['priority'] = 10;
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload, 'eligibility-create' )->outcome->state );
		$opened = RuleProofEnvelope::opened( $this->database, $this->prefix, E::uuid( 1 ), E::uuid( 101 ) ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $opened, 'eligibility-publish' )->outcome->state );
		$compiled = $this->compiled(); $before = $this->facts(); $s = $this->session(); self::assertTrue( $s->begin() ); $valid = $this->validity( $s, $compiled ); self::assertNotNull( $valid ); self::assertTrue( $valid->valid_at( E::at() ) ); self::assertTrue( $s->rollback() ); self::assertSame( $before, $this->facts() );
	}
	public function test_preopened_repeatable_read_stays_old_but_current_policy_guard_observes_committed_retirement(): void {
		$rows = $this->seed(); $compiled = $this->compiled(); $s = $this->session(); self::assertTrue( $s->begin() );
		$sql = "SELECT state FROM `{$this->prefix}delivery_engine_rule_versions` WHERE id=1"; self::assertSame( 'published', $s->get_row( $sql )['state'] );
		$this->replace_rows( E::retired_rows() );
		self::assertSame( 'published', $s->get_row( $sql )['state'] ); self::assertNull( $this->validity( $s, $compiled ) ); self::assertTrue( $s->rollback() ); self::assertSame( 'retired', RuleProofDatabase::row( $this->database, $sql )['state'] );
	}
	public function test_same_price_same_funding_successor_has_a_new_reference_and_refuses_original_plan(): void {
		$this->seed(); $compiled = $this->compiled(); $this->replace_rows( E::successor_rows() );
		$s = $this->session(); self::assertTrue( $s->begin() ); self::assertNull( $this->validity( $s, $compiled ) ); $new = $this->planning( $s ); self::assertNotNull( $new ); self::assertTrue( $s->rollback() );
		$fresh = E::compiled( $new['eligibility'] ); self::assertTrue( $s->begin() ); self::assertNotNull( $this->validity( $s, $fresh ) ); self::assertTrue( $s->rollback() );
	}
	public function test_matching_scheduled_change_caps_interval_and_due_unactivated_successor_refuses(): void {
		$this->seed(); $compiled = $this->compiled(); $boundary = E::at()->plus_seconds( 30 ); $this->replace_rows( E::scheduled_rows() );
		$s = $this->session(); self::assertTrue( $s->begin() ); $valid = $this->validity( $s, $compiled ); self::assertNotNull( $valid ); self::assertFalse( $valid->valid_at( $boundary ) ); self::assertNull( $this->validity( $s, $compiled, at: $boundary ) ); self::assertTrue( $s->rollback() );
	}
	public function test_wrong_pool_and_cross_site_refuse_without_any_rule_write(): void {
		$this->seed(); $compiled = $this->compiled(); $before = $this->facts(); $s = $this->session(); self::assertTrue( $s->begin() ); self::assertNull( $this->validity( $s, $compiled, Q::digest( 'wrong same-funder pool' ) ) ); self::assertTrue( $s->rollback() );
		$foreign = $this->session( 2 ); self::assertTrue( $foreign->begin() ); self::assertNull( $this->validity( $foreign, $compiled ) ); self::assertTrue( $foreign->rollback() ); self::assertSame( $before, $this->facts() );
	}
	public function test_oversized_persisted_policy_and_broken_version_pointer_are_retained_and_refused(): void {
		$this->seed(); $compiled = $this->compiled(); RuleProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_rule_versions` SET payload_json=REPEAT('x',16385) WHERE id=1" ); $before = $this->facts();
		$s = $this->session(); self::assertTrue( $s->begin() ); self::assertNull( $this->validity( $s, $compiled ) ); self::assertTrue( $s->rollback() ); self::assertSame( $before, $this->facts() );
		$this->replace_rows( E::rows() ); RuleProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_logical_rules` SET current_published_version_id=999 WHERE id=1" ); self::assertTrue( $s->begin() ); self::assertNull( $this->validity( $s, $compiled ) ); self::assertTrue( $s->rollback() ); self::assertSame( '999', (string) RuleProofDatabase::row( $this->database, "SELECT current_published_version_id FROM `{$this->prefix}delivery_engine_logical_rules` WHERE id=1" )['current_published_version_id'] );
	}
}
