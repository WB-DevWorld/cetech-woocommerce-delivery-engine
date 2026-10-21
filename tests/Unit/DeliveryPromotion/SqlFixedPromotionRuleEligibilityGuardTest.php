<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetRequest;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\SqlFixedPromotionRuleEligibilityGuard;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionEligibilityFixture as E, PromotionEligibilitySession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlFixedPromotionRuleEligibilityGuardTest extends TestCase {
	private PromotionEligibilitySession $session;
	private SqlFixedPromotionRuleEligibilityGuard $guard;
	protected function setUp(): void { $this->session = new PromotionEligibilitySession(); $this->guard = new SqlFixedPromotionRuleEligibilityGuard( E::family() ); }
	private function planning(): ?array { return $this->guard->planning_evidence( $this->session, F::owner(), F::original_context(), F::original_terms(), F::key(), F::rule(), E::at() ); }
	private function compiled(): array { $planning = $this->planning(); self::assertNotNull( $planning ); return E::compiled( $planning['eligibility'] ); }
	private function validity( array $compiled, ?string $account = null, ?QuoteTime $at = null ): mixed { [ $h, $c, $t ] = $compiled; return $this->guard->validity_for_requests_at( $this->session, F::owner(), $h, $c, $t, [ FixedPromotionBudgetRequest::from_captured( $h, $c, $t, F::key(), $account ?? E::account() ) ], $at ?? E::at() ); }
	public function test_sql_planning_provenance_revalidates_the_actual_compiled_quote_and_selected_pool(): void {
		$planning = $this->planning(); self::assertSame( E::account(), $planning['account_key'] ); self::assertSame( 'eligible', $planning['eligibility']['state'] );
		$compiled = E::compiled( $planning['eligibility'] ); $valid = $this->validity( $compiled ); self::assertNotNull( $valid ); self::assertTrue( $valid->valid_at( E::at() ) );
		self::assertNull( $this->validity( $compiled, Q::digest( 'same funding wrong pool' ) ) );
		self::assertNull( $this->guard->validity_at( $this->session, F::owner(), ...[ ...$compiled, E::at() ] ) );
		$locks = array_values( array_filter( $this->session->queries, static fn( string $q ): bool => str_contains( $q, 'FOR UPDATE' ) ) ); self::assertCount( 9, $locks );
		self::assertStringContainsString( 'rule_family_guards', $locks[0] ); self::assertStringContainsString( 'LIMIT 1001', $locks[1] ); self::assertStringContainsString( 'LIMIT 2001', $locks[2] ); self::assertStringContainsString( 'CASE WHEN OCTET_LENGTH', $locks[2] );
	}
	public function test_a_frozen_arbitrary_eligibility_digest_is_not_current_policy_authority(): void { self::assertNull( $this->validity( [ F::header( $p = F::provider() ), $p->context(), $p->terms() ] ) ); }
	public function test_unchanged_active_policy_survives_unrelated_draft_revision(): void {
		$compiled = $this->compiled(); ++$this->session->rows[0]['revision']; ++$this->session->rows[1][0]['revision'];
		self::assertNotNull( $this->validity( $compiled ) );
	}
	#[DataProvider('policy_changes')]
	public function test_missing_retired_changed_and_wrong_scope_policy_refuses( string $change ): void {
		$compiled = $this->compiled(); $f = E::family(); $row =& $this->session->rows[2][0];
		if ( 'missing' === $change ) { $this->session->rows[0] = []; }
		elseif ( 'retired' === $change ) { $row['state'] = 'retired'; $row['retired_at'] = E::UPDATED; $this->session->rows[1][0]['current_published_version_id'] = null; }
		elseif ( 'scope' === $change ) { $scope = E::scope(); $scope['product_ids'] = [ 999 ]; $this->session->rows[1][0]['scope_json'] = $f->scope_schema()->encode( $scope ); $this->session->rows[1][0]['scope_hash'] = RuleContent::scope_hash( $f, $scope ); }
		else { $payload = json_decode( $row['payload_json'], true, 16, JSON_THROW_ON_ERROR ); $payload['budget_account_key'] = Q::digest( 'changed policy pool' ); $row['payload_json'] = $f->payload_schema()->encode( $payload ); $row['content_hash'] = RuleContent::hash( $f, $payload, RuleStartMode::Immediate, RuleTime::parse( E::PUBLISHED ), null, 10, null ); }
		self::assertNull( $this->validity( $compiled ) );
	}
	public static function policy_changes(): array { return [ [ 'missing' ], [ 'retired' ], [ 'scope' ], [ 'account' ] ]; }
	#[DataProvider('owner_losses')]
	public function test_owner_loss_after_each_current_read_refuses( string $stage ): void {
		$compiled = $this->compiled(); $this->session->after_read = static function( PromotionEligibilitySession $s, string $q ) use ( $stage ): void { if ( str_contains( $q, $stage ) ) { $s->active = false; } };
		self::assertNull( $this->validity( $compiled ) );
	}
	public static function owner_losses(): array { return [ [ 'rule_family_guards' ], [ 'LIMIT 1001' ], [ 'LIMIT 2001' ] ]; }
	public function test_policy_exclusive_end_caps_quote_validity_at_exact_microsecond(): void {
		$end = E::at()->plus_seconds( 30 ); $row =& $this->session->rows[2][0]; $row['effective_until'] = $end->sql();
		$row['content_hash'] = RuleContent::hash( E::family(), json_decode( $row['payload_json'], true ), RuleStartMode::Immediate, RuleTime::parse( E::PUBLISHED ), RuleTime::parse( $end->sql() ), 10, null );
		$compiled = $this->compiled(); $valid = $this->validity( $compiled ); self::assertNotNull( $valid ); self::assertFalse( $valid->valid_at( $end ) ); self::assertNull( $this->validity( $compiled, at: $end ) );
	}
	public function test_query_failure_and_nontransactional_owner_fail_closed_without_writes(): void {
		$this->session->failure = true; self::assertNull( $this->planning() ); $this->session->failure = false; $this->session->active = false; self::assertNull( $this->planning() );
	}
	public function test_bounded_capture_refuses_partial_population_and_oversized_payload(): void {
		$compiled = $this->compiled(); $saved = $this->session->rows; $this->session->rows[1] = array_fill( 0, 1001, $saved[1][0] ); self::assertNull( $this->validity( $compiled ) );
		$this->session->rows = $saved; $this->session->rows[2][0]['payload_json'] = str_repeat( 'x', 16385 ); self::assertNull( $this->validity( $compiled ) );
	}
	public function test_the_physical_fixture_mutations_are_strict_history_and_current_guard_proofs(): void {
		$compiled = $this->compiled(); $this->session->rows = E::scheduled_rows(); $boundary = E::at()->plus_seconds( 30 );
		$valid = $this->validity( $compiled ); self::assertNotNull( $valid ); self::assertFalse( $valid->valid_at( $boundary ) ); self::assertNull( $this->validity( $compiled, at: $boundary ) );
		$this->session->rows = E::successor_rows(); self::assertNull( $this->validity( $compiled ) ); $planning = $this->planning(); self::assertNotNull( $planning ); self::assertNotNull( $this->validity( E::compiled( $planning['eligibility'] ) ) );
		$this->session->rows = E::retired_rows(); self::assertNull( $this->validity( $compiled ) );
	}
}
