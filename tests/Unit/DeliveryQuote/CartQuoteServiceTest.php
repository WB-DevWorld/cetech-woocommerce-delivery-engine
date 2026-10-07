<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/CartQuoteFixtures.php';
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,CartQuoteFixtures,QuoteDurableFixtureFactory,QuoteFixturePublication};
use PHPUnit\Framework\TestCase;

final class CartQuoteServiceTest extends TestCase {
	private QuoteDurableFixtureFactory $factory; private CartQuoteFixtureEnvironment $environment; private CartQuoteFixtureSessions $sessions;
	private const TOKEN = '12345678-1234-4abc-8abc-123456789abc';
	protected function setUp(): void { $this->factory = new QuoteDurableFixtureFactory(); $this->environment = new CartQuoteFixtureEnvironment( $this->factory ); $this->sessions = new CartQuoteFixtureSessions(); }
	private function service( ?QuoteFixturePublication $publication = null ) { return CartQuoteFixtures::service( $this->factory, $this->environment, $this->sessions, $publication ); }
	private function refresh() { return $this->service()->refresh( self::TOKEN, 0, RequestContext::create() ); }
	public function test_explicit_refresh_charges_before_preparation_and_stages_original_before_effect(): void {
		$this->environment->before_prepare = function(): void { self::assertSame( 3, $this->factory->count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->factory->count( 'operation_records' ) ); self::assertFalse( $this->factory->pdo->inTransaction() ); foreach ( $this->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); } };
		$result = $this->refresh()->shopper_facts(); self::assertSame( 'review_required', $result['status'] ); self::assertTrue( $result['can_confirm'] ); self::assertSame( 1, $result['generation'] ); self::assertSame( 'issued', $this->sessions->current->phase() ); self::assertNotNull( $this->sessions->current->reference() ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) );
	}
	public function test_current_and_network_retry_never_prepare_accept_or_extend_original_quote(): void {
		$first = $this->refresh()->shopper_facts(); $header = $this->sessions->current->header()->to_private_json(); $writes = $this->sessions->writes;
		foreach ( [ $this->service()->current( RequestContext::create() ), $this->service()->refresh( self::TOKEN, 0, RequestContext::create() ), $this->service()->retry( 1, RequestContext::create() ) ] as $result ) { self::assertSame( 'review_required', $result->shopper_facts()['status'] ); self::assertSame( $first['quote']['quote_id'], $result->shopper_facts()['quote']['quote_id'] ); }
		self::assertSame( $header, $this->sessions->current->header()->to_private_json() ); self::assertSame( $writes, $this->sessions->writes ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) ); self::assertSame( 'issued', $this->factory->pdo->query( 'SELECT state FROM durable_delivery_engine_delivery_quotes' )->fetchColumn() );
	}
	public function test_confirm_is_explicit_and_repeated_confirmation_is_read_only(): void {
		$this->refresh(); $result = $this->service()->confirm( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'confirmed', $result['status'] ); self::assertFalse( $result['can_confirm'] ); self::assertSame( 'accepted', $result['quote']['status'] ); $writes = $this->sessions->writes;
		self::assertSame( 'confirmed', $this->service()->confirm( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $writes, $this->sessions->writes ); self::assertSame( 2, $this->factory->count( 'operation_changes' ) ); self::assertSame( 1, $this->environment->preparations );
	}
	public function test_lost_effect_ack_reconciles_original_staged_handle_without_second_preparation(): void {
		$this->factory->effect_fault = 'lost_ack'; $first = $this->refresh()->shopper_facts(); self::assertSame( 'unconfirmed', $first['status'] ); self::assertFalse( $first['can_refresh'] ); self::assertNull( $first['quote'] ); $reference = $this->sessions->current->reference()->public_fields(); $header = $this->sessions->current->header()->to_private_json(); self::assertSame( 1, $this->factory->count( 'delivery_quotes' ) );
		$result = $this->service()->retry( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'review_required', $result['status'] ); self::assertSame( $reference, $this->sessions->current->reference()->public_fields() ); self::assertSame( $header, $this->sessions->current->header()->to_private_json() ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) );
	}
	public function test_staged_ack_loss_closes_only_after_original_lease_is_authoritatively_terminated(): void {
		$this->sessions->lose_stage_ack = true; self::assertSame( 'unconfirmed', $this->refresh()->shopper_facts()['status'] ); self::assertSame( 'staged', $this->sessions->current->phase() ); $reference = $this->sessions->current->reference()->public_fields(); self::assertSame( 0, $this->factory->count( 'operation_records' ) ); self::assertSame( 0, $this->factory->count( 'delivery_quotes' ) );
		self::assertSame( 'unconfirmed', $this->service()->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $reference, $this->sessions->current->reference()->public_fields() );
		$this->factory->utc = '2026-10-07 05:01:00.000000'; $closed = $this->service()->retry( 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'unavailable', $closed['status'] ); self::assertTrue( $closed['can_refresh'] ); self::assertSame( 'failed', $this->sessions->current->phase() ); self::assertSame( 'terminated', $this->factory->pdo->query( "SELECT lease_state FROM durable_delivery_engine_delivery_quote_budget_windows WHERE slot_kind='admission'" )->fetchColumn() ); self::assertSame( 1, $this->environment->preparations );
		$new = $this->service()->refresh( '12345678-1234-4abc-8abc-123456789abd', 1, RequestContext::create() )->shopper_facts(); self::assertSame( 'review_required', $new['status'] ); self::assertSame( 2, $new['generation'] ); self::assertSame( 1, $this->factory->count( 'delivery_quotes' ) );
	}
	public function test_failed_private_publication_retries_completion_and_blocks_replacement(): void {
		$this->sessions->refuse_publication = true; self::assertSame( 'unconfirmed', $this->refresh()->shopper_facts()['status'] ); $reference = $this->sessions->current->reference()->public_fields();
		self::assertSame( 'unconfirmed', $this->service()->refresh( '12345678-1234-4abc-8abc-123456789abd', 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 1, $this->environment->preparations );
		$this->sessions->refuse_publication = false; self::assertSame( 'review_required', $this->service()->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $reference, $this->sessions->current->reference()->public_fields() ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) );
	}
	public function test_accept_commit_unknown_replays_without_second_audit_or_new_quote(): void {
		$this->refresh(); $this->factory->effect_fault = 'lost_ack'; self::assertSame( 'unconfirmed', $this->service()->confirm( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'accepting', $this->sessions->current->phase() );
		self::assertSame( 'confirmed', $this->service()->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 2, $this->factory->count( 'operation_changes' ) ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 1, $this->factory->count( 'delivery_quotes' ) );
	}
	public function test_lost_accepting_stage_ack_explicit_retry_sends_only_original_accept_once(): void {
		$this->refresh(); $header = $this->sessions->current->header()->to_private_json(); $reference = $this->sessions->current->reference()->public_fields(); $this->sessions->lose_accepting_ack = true;
		self::assertSame( 'unconfirmed', $this->service()->confirm( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'accepting', $this->sessions->current->phase() ); self::assertSame( 1, $this->factory->count( 'operation_records' ) ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) );
		self::assertSame( 'unconfirmed', $this->service()->current( RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 1, $this->factory->count( 'operation_records' ) );
		self::assertSame( 'confirmed', $this->service()->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $header, $this->sessions->current->header()->to_private_json() ); self::assertSame( $reference, $this->sessions->current->reference()->public_fields() ); self::assertSame( 2, $this->factory->count( 'operation_changes' ) ); self::assertSame( 1, $this->environment->preparations );
		self::assertSame( 'confirmed', $this->service()->retry( 1, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 2, $this->factory->count( 'operation_changes' ) );
	}
	public function test_authoritative_expiry_controls_outer_and_nested_projection(): void {
		$this->refresh(); $header = $this->sessions->current->header()->to_private_json(); $this->factory->utc = '2026-10-07 05:05:00.000000'; $result = $this->service()->current( RequestContext::create() )->shopper_facts(); self::assertSame( 'expired', $result['status'] ); self::assertSame( 'expired', $result['quote']['status'] ); self::assertFalse( $result['quote']['currently_applicable'] ); self::assertFalse( $result['can_confirm'] ); self::assertTrue( $result['can_refresh'] ); self::assertSame( $header, $this->sessions->current->header()->to_private_json() ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) );
	}
	public function test_changed_draft_and_unknown_current_evidence_preserve_choices_and_history(): void {
		$this->refresh(); $header = $this->sessions->current->header()->to_private_json(); $this->environment->current = CartQuoteFixtures::draft( 3 ); self::assertSame( 'changed', $this->service()->current( RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'changed', $this->service()->confirm( 1, RequestContext::create() )->shopper_facts()['status'] );
		$this->environment->current = CartQuoteFixtures::draft(); $this->environment->evidence_available = false; self::assertSame( 'unavailable', $this->service()->current( RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $header, $this->sessions->current->header()->to_private_json() ); self::assertSame( 1, $this->factory->count( 'operation_changes' ) ); self::assertSame( 1, $this->environment->preparations );
	}
	public function test_pause_denies_before_preparation_and_known_preparation_failure_terminates(): void {
		$this->factory->paused = true; self::assertSame( 'unavailable', $this->refresh()->shopper_facts()['status'] ); self::assertSame( 0, $this->environment->preparations ); self::assertSame( 0, $this->factory->count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->factory->count( 'operation_records' ) );
		$this->setUp(); $this->environment->fail_preparation = true; $result = $this->refresh()->shopper_facts(); self::assertSame( 'unavailable', $result['status'] ); self::assertTrue( $result['can_refresh'] ); self::assertSame( 'terminated', $this->factory->pdo->query( "SELECT lease_state FROM durable_delivery_engine_delivery_quote_budget_windows WHERE slot_kind='admission'" )->fetchColumn() ); self::assertSame( 0, $this->factory->count( 'operation_records' ) );
	}
	public function test_current_authority_before_load_and_private_result_projection(): void {
		$this->refresh(); $this->environment->read_authorized = false; $result = $this->service()->current( RequestContext::create() ); self::assertSame( 'unavailable', $result->shopper_facts()['status'] ); self::assertNull( $result->shopper_facts()['quote'] );
		$this->environment->read_authorized = true; $json = json_encode( $this->service()->current( RequestContext::create() ), JSON_THROW_ON_ERROR ); foreach ( [ 'PRIVATE-Q05', 'acceptance_handle', 'owner_digest', 'source', 'header_json', 'original_token', 'supplier', 'origin', 'body_digest', 'principal_hash' ] as $private ) { self::assertStringNotContainsString( $private, $json ); }
	}
}
