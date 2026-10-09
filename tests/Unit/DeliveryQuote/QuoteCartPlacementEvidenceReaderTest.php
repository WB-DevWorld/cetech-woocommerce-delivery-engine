<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/CartQuoteFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteSessionEnvelope,CartQuoteSessionStore,QuoteCartPlacementEvidenceReader};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,CartQuoteFixtures,QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixturePublication};
use PHPUnit\Framework\TestCase;

final class QuoteCartPlacementEvidenceReaderTest extends TestCase {
	private QuoteDurableFixtureFactory $factory; private CartQuoteFixtureEnvironment $environment; private CartQuoteFixtureSessions $sessions;
	protected function setUp(): void { $this->factory = new QuoteDurableFixtureFactory(); $this->environment = new CartQuoteFixtureEnvironment( $this->factory ); $this->sessions = new CartQuoteFixtureSessions(); }
	private function accepted(): void { $service = CartQuoteFixtures::service( $this->factory, $this->environment, $this->sessions ); self::assertSame( 'review_required', $service->refresh( '12345678-1234-4abc-8abc-123456789abc', 0, RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( 'confirmed', $service->confirm( 1, RequestContext::create() )->shopper_facts()['status'] ); }
	private function reader( ?CartQuoteSessionStore $sessions = null ): QuoteCartPlacementEvidenceReader { $ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} }; return new QuoteCartPlacementEvidenceReader( $this->environment, $sessions ?? $this->sessions, $this->factory, $ready, new QuoteFixtureControl( $this->factory ), new QuoteFixturePublication() ); }
	public function test_confirmed_private_envelope_resolves_exact_accepted_body_without_new_effect_or_capture(): void {
		$this->accepted(); $original = $this->sessions->current->to_private_json(); $effects = $this->factory->count( 'operation_changes' ); $reads = $this->factory->count( 'operation_records' );
		$first = $this->reader()->current( RequestContext::create() ); $second = $this->reader()->current( RequestContext::create() );
		self::assertNotNull( $first ); self::assertNotNull( $second ); self::assertSame( 'accepted', $first->quote_record()->state() ); self::assertSame( 1, $first->header()->revision() ); self::assertSame( 2, $first->quote_record()->revision() ); self::assertSame( $first->header()->to_private_json(), $second->header()->to_private_json() ); self::assertSame( $original, $this->sessions->current->to_private_json() ); self::assertSame( $effects, $this->factory->count( 'operation_changes' ) ); self::assertSame( $reads, $this->factory->count( 'operation_records' ) ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( $this->environment->current->private_facts(), $first->draft_facts() );
	}
	public function test_issued_and_accepting_publication_are_not_private_confirmation(): void {
		$service = CartQuoteFixtures::service( $this->factory, $this->environment, $this->sessions ); $service->refresh( '12345678-1234-4abc-8abc-123456789abc', 0, RequestContext::create() ); self::assertNull( $this->reader()->current( RequestContext::create() ) );
		$this->factory->effect_fault = 'lost_ack'; $service->confirm( 1, RequestContext::create() ); self::assertSame( 'accepting', $this->sessions->current->phase() ); self::assertSame( 'accepted', $this->factory->pdo->query( 'SELECT state FROM durable_delivery_engine_delivery_quotes' )->fetchColumn() ); self::assertNull( $this->reader()->current( RequestContext::create() ) );
	}
	public function test_foreign_or_revoked_owner_refuses_before_private_session_lookup(): void {
		$this->accepted(); $this->environment->read_authorized = false;
		$sessions = new class implements CartQuoteSessionStore { public int $loads = 0; public function load( QuoteOwner $owner ): ?CartQuoteSessionEnvelope { ++$this->loads; throw new \LogicException( 'Private session must not be loaded.' ); } public function compare_and_swap( QuoteOwner $owner, ?CartQuoteSessionEnvelope $expected, CartQuoteSessionEnvelope $replacement ): bool { return false; } public function expires_at( QuoteOwner $owner ): int { return 0; } };
		self::assertNull( $this->reader( $sessions )->current( RequestContext::create() ) ); self::assertSame( 0, $sessions->loads );
	}
	public function test_changed_cart_unknown_evidence_stale_physical_guard_and_expiry_all_refuse_without_repricing(): void {
		$this->accepted(); $original = $this->sessions->current->to_private_json(); $body = $this->factory->pdo->query( 'SELECT private_body_json FROM durable_delivery_engine_delivery_quotes' )->fetchColumn();
		$this->environment->current = CartQuoteFixtures::draft( 3 ); self::assertNull( $this->reader()->current( RequestContext::create() ) ); $this->environment->current = CartQuoteFixtures::draft();
		$this->environment->evidence_available = false; self::assertNull( $this->reader()->current( RequestContext::create() ) ); $this->environment->evidence_available = true;
		$this->factory->pdo->exec( "UPDATE durable_fence SET context_digest='" . hash( 'sha256', 'new-physical-context' ) . "'" ); self::assertNull( $this->reader()->current( RequestContext::create() ) ); $this->factory->pdo->exec( "UPDATE durable_fence SET context_digest='" . $this->sessions->current->original_issue()->context()->digest() . "'" );
		$this->factory->utc = '2026-10-07 05:05:00.000000'; self::assertNull( $this->reader()->current( RequestContext::create() ) ); self::assertSame( $original, $this->sessions->current->to_private_json() ); self::assertSame( $body, $this->factory->pdo->query( 'SELECT private_body_json FROM durable_delivery_engine_delivery_quotes' )->fetchColumn() ); self::assertSame( 1, $this->environment->preparations ); self::assertSame( 2, $this->factory->count( 'operation_changes' ) );
	}
	public function test_private_evidence_cannot_serialize_and_authority_is_rechecked_for_every_effect(): void {
		$this->accepted(); $evidence = $this->reader()->current( RequestContext::create() ); self::assertNotNull( $evidence ); self::assertTrue( $evidence->authorize( $evidence->owner(), 'delivery_quote.bind' ) ); $this->environment->read_authorized = false; self::assertFalse( $evidence->authorize( $evidence->owner(), 'delivery_quote.bind' ) ); $this->expectException( \LogicException::class ); json_encode( $evidence, JSON_THROW_ON_ERROR );
	}
}
