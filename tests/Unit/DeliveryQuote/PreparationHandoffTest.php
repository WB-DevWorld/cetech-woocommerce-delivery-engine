<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteDurableCommand,QuoteIssueCommand,QuoteOperationProfile,QuotePreparationAttempt,QuotePreparationCommand,QuotePreparationGate};
use CetechDeliveryEngine\Application\Operation\{OperationCoordinator,OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote,QuoteHeader,QuoteId,QuoteReference};
use CetechDeliveryEngine\Domain\Operation\{OperationProfileRegistry,OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixtureEvidence,QuoteFixturePublication,QuoteFixtures};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';

/** Actual owned SQLite C03 effects; native SQL isolation is proved separately. */
final class PreparationHandoffTest extends TestCase {
	public function test_early_intent_remains_immutable_while_full_issue_is_accepted_and_reconciled_once(): void {
		$f = new QuoteDurableFixtureFactory(); $authorize = static fn(): bool => true; $control = new QuoteFixtureControl( $f );
		$gate = new QuotePreparationGate( $f, $authorize, static function(): void {}, $control );
		$early = QuotePreparationCommand::create( QuoteFixtures::owner(), QuoteFixtures::digest( 'raw_loaded_draft' ), 'a9fa5bde-1b6b-45b4-8fb3-707b304c434a', 'fixture_v1', 1, 'fixture_v1', 1 );
		$grant = $gate->admit( $early, QuotePreparationAttempt::generate() ); self::assertSame( 'preparation_allowed', $grant->status ); self::assertSame( 0, $f->count( 'operation_records' ) ); self::assertTrue( $grant->lease->claim_preparation() );
		$issue = QuoteIssueCommand::create( $early->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $early->original_token() );
		$lease = $grant->lease->bind_issue( $issue ); self::assertTrue( $lease->claim_capture() );
		$id = QuoteId::generate(); $reference = QuoteReference::generate( $id ); $terms = QuoteFixtures::terms();
		$header = QuoteHeader::issue( $id, $issue->owner(), $issue->context(), $terms, $lease->created_at(), $issue->namespace_hashes( $id ), $issue->profile(), $issue->profile_version(), $reference );
		$command = QuoteDurableCommand::captured_issue( $issue, $lease, DeliveryQuote::issue( $header, $issue->context(), $terms ), $reference );
		$profiles = []; foreach ( QuoteOperationProfile::OPERATIONS as $name ) { $profiles[] = new QuoteOperationProfile( $name, $command, $authorize, new QuoteFixtureEvidence( $f ), $control, new QuoteFixturePublication() ); }
		$readiness = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$coordinator = new OperationCoordinator( new OperationProfileRegistry( $profiles ), $f, $readiness );
		$first = $coordinator->attempt( $command->identity, $command, RequestContext::create() ); self::assertSame( 'accepted', $first->outcome->state );
		self::assertSame( 1, $f->count( 'delivery_quotes' ) ); self::assertSame( 1, $f->count( 'operation_records' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) ); self::assertSame( 3, $f->count( 'delivery_quote_budget_windows' ) );
		$row = $f->pdo->query( "SELECT * FROM durable_delivery_engine_delivery_quote_budget_windows WHERE slot_kind='admission'" )->fetch( \PDO::FETCH_ASSOC );
		self::assertSame( $early->intent_digest(), $row['admission_intent_digest'] ); self::assertNotSame( $issue->intent_digest(), $row['admission_intent_digest'] ); self::assertSame( 'consumed', $row['lease_state'] ); self::assertSame( $id->value(), $row['consumed_quote_uuid'] );
		self::assertSame( $issue->intent_digest(), $f->pdo->query( 'SELECT intent_hash FROM durable_delivery_engine_operation_records' )->fetchColumn() );
		self::assertSame( [ 1, 1 ], array_map( 'intval', $f->pdo->query( "SELECT attempt_count FROM durable_delivery_engine_delivery_quote_budget_windows WHERE slot_kind!='admission' ORDER BY id" )->fetchAll( \PDO::FETCH_COLUMN ) ) );
		$replay = $coordinator->reconcile( $command->identity, $command, RequestContext::create() ); self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed ); self::assertSame( $first->completion->to_json(), $replay->completion->to_json() ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		$duplicate = $gate->admit( QuotePreparationCommand::from_private_array( $early->to_private_array() ), QuotePreparationAttempt::generate() ); self::assertSame( 'completed', $duplicate->status ); self::assertSame( $id->value(), $duplicate->completed_id()->value() ); self::assertNull( $duplicate->lease ); self::assertSame( 3, $f->count( 'delivery_quote_budget_windows' ) );
	}
}
