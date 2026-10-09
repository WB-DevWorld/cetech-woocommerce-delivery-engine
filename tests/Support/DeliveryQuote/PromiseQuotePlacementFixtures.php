<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{PromiseQuoteSealLinkage,QuoteAdmissionAttempt,QuoteAdmissionGate,QuoteCurrentEvidenceValidity,QuoteDurableCommand,QuoteDurableResult,QuoteDurableService,QuoteIssueCommand,QuotePlacementProof,QuotePlacementSavedEvidenceGuard,QuotePlacementService,QuoteProviderRegistry,QuoteTimedCurrentEvidenceGuard,ServicePromiseQuoteProvider};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteOwner,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseInput;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;

require_once __DIR__ . '/QuoteDurableFixtures.php';
require_once __DIR__ . '/QuoteStorageFixtures.php';
require_once dirname( __DIR__ ) . '/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Owned SQLite lifecycle and detached promise vectors. This is not native-source or MariaDB qualification. */
final class PromiseQuotePlacementFixture {
	public readonly QuoteDurableFixtureFactory $factory;
	public readonly QuoteDurableService $service;
	public readonly QuoteContext $context;
	public readonly QuotePlacementSavedEvidenceGuard $saved;
	public QuoteDurableResult $original;
	public QuoteDurableResult $accepted;
	public QuoteDurableResult $verified;
	public ?QuoteDurableResult $sealed = null;
	public function __construct( \WC_Order $order, ?PromiseInput $input = null, ?PromiseHistoricalPacket $packet = null ) {
		$input ??= F::input(); $this->context = F::context( $input ); $terms = F::terms( $packet ?? F::packet( F::result( $input ) ) );
		$this->factory = $f = new QuoteDurableFixtureFactory(); $f->utc = F::time()->sql();
		$f->pdo->exec( "ALTER TABLE durable_fence ADD COLUMN valid_until TEXT NOT NULL DEFAULT '2026-10-09 10:05:00.000000'" );
		$q = $f->pdo->prepare( 'UPDATE durable_fence SET context_digest=? WHERE id=1' ); $q->execute( [ $this->context->digest() ] );
		$f->pdo->exec( 'CREATE TABLE durable_order_facts(order_id INTEGER PRIMARY KEY,snapshot_digest TEXT NOT NULL,context_digest TEXT NOT NULL)' );
		$q = $f->pdo->prepare( 'INSERT INTO durable_order_facts VALUES(?,?,?)' ); $q->execute( [ $order->get_id(), QuoteFixtures::digest( 'promise-saved-snapshot' ), QuoteFixtures::digest( 'promise-saved-context' ) ] );
		$this->saved = new class implements QuotePlacementSavedEvidenceGuard {
			public function tables( OperationSession $s ): array { return [ 'durable_order_facts' ]; }
			public function verify( OperationSession $s, QuoteBinding $b ): bool { $r = $s->get_row( $s->prepare( 'SELECT snapshot_digest,context_digest FROM durable_order_facts WHERE order_id=%d FOR UPDATE', $b->row()['order_id'] ) ); return is_array( $r ) && $r['snapshot_digest'] === $b->row()['snapshot_digest'] && $r['context_digest'] === $b->row()['context_digest']; }
		};
		$control = new QuoteFixtureControl( $f ); $authorize = static fn(): bool => $f->authorized;
		$readiness = new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void {} };
		$this->service = new QuoteDurableService( $f, new QuoteProviderRegistry( [ new ServicePromiseQuoteProvider( F::owner(), $this->context, $terms ) ] ), $authorize, new QuoteAdmissionGate( $f, $authorize, static function(): void {}, $control ), $readiness, control: $control, evidence: new PromiseQuoteFixtureCurrentEvidence( $f ), publication: new QuoteFixturePublication() );
		$this->original = $this->service->issue( QuoteIssueCommand::create( F::owner(), $this->context, 'legacy_fixed_base_v1', 1, 'service_promise_v1', 1, 'promise-original-placement' ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); $this->require_accepted( $this->original );
		$f->utc = F::time()->plus_seconds( 1 )->sql(); $q = $this->original->quote;
		$this->accepted = $this->service->accept( F::owner(), $this->original->command->reference(), $q->header(), $this->context, RequestContext::create() ); $this->require_accepted( $this->accepted );
		$q = $this->accepted->quote; $placement = QuotePlacementService::placement_id_for( F::owner(), $q->header() ); $row = QuoteStorageFixtures::binding( $q, order: $order->get_id(), placement_uuid: $placement )->row(); $names = QuoteDurableCommand::binding_namespaces( F::owner(), $q->header(), $placement, true );
		$binding = QuoteBinding::from_row( array_replace( $row, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ), $q );
		$f->utc = F::time()->plus_seconds( 2 )->sql(); $bound = $this->service->bind( F::owner(), $this->original->command->reference(), $q->header(), $binding, RequestContext::create(), $this->context ); $this->require_accepted( $bound );
		$f->utc = F::time()->plus_seconds( 3 )->sql(); $binding = QuoteBinding::from_row( array_replace( $bound->binding->row(), [ 'revision' => 2, 'snapshot_digest' => QuoteFixtures::digest( 'promise-saved-snapshot' ), 'context_digest' => QuoteFixtures::digest( 'promise-saved-context' ), 'verified_at' => $f->utc ] ), $q );
		$this->verified = $this->service->verify_binding( F::owner(), $this->original->command->reference(), $q->header(), $binding, RequestContext::create(), $this->context, $this->saved ); $this->require_accepted( $this->verified );
	}
	public function seal( \WC_Order $order, ?QuoteTime $at = null ): QuoteDurableResult {
		$q = $this->verified->quote; $captured = F::time()->plus_seconds( 4 ); $this->factory->utc = ( $at ?? $captured )->sql();
		$proof = QuotePlacementProof::capture( $this->verified->binding, 1, EmergencyCheckoutLocalBinding::capture( $order ) ?? throw new \LogicException( 'Native shape unavailable.' ), $this->saved, $q->terms(), $captured );
		return $this->sealed = $this->service->seal_placement( F::owner(), $this->original->command->reference(), $q->header(), $this->verified->binding, RequestContext::create(), $this->context, $proof );
	}
	public function linkage(): ?PromiseQuoteSealLinkage { $q = $this->sealed?->quote; return null === $q || null === $this->sealed->binding ? null : $this->service->acknowledged_promise_seal( F::owner(), $this->original->command->reference(), $q->header(), $this->sealed->binding, $this->saved ); }
	private function require_accepted( QuoteDurableResult $r ): void { if ( 'accepted' !== $r->attempt->outcome->state || null === $r->quote ) { throw new \LogicException( 'Fixture lifecycle failed: ' . ( $r->attempt->outcome->error?->code ?? $r->attempt->outcome->state ) ); } }
}

/** Explicit pure timed seam for detached source rows; no native runtime/source callbacks. */
final class PromiseQuoteFixtureCurrentEvidence implements QuoteTimedCurrentEvidenceGuard {
	public function __construct( private QuoteDurableFixtureFactory $factory ) {}
	public function tables( OperationSession $session ): array { return [ 'durable_fence' ]; }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { $row = $session->get_row( 'SELECT context_digest FROM durable_fence WHERE id=1 FOR UPDATE' ); return $this->factory->guard_ok && is_array( $row ) && $row['context_digest'] === $context->digest(); }
	public function verify_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $captured_at ): bool { return null !== $this->validity_at( $session, $owner, $context, $captured_at ); }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $captured_at ): ?QuoteCurrentEvidenceValidity {
		if ( ! $this->verify( $session, $owner, $context ) ) { return null; }
		$row = $session->get_row( 'SELECT valid_until FROM durable_fence WHERE id=1 FOR UPDATE' ); if ( ! is_array( $row ) || ! is_string( $row['valid_until'] ?? null ) ) { return null; }
		$until = QuoteTime::parse( $row['valid_until'] ); return $captured_at->compare( $until ) < 0 ? QuoteCurrentEvidenceValidity::capture( $captured_at, $until ) : null;
	}
}
