<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealProfile, FixedPromotionQuoteSealResult, PromiseQuoteSealLinkage, QuoteCurrentEvidenceGuard, QuoteDurableCommand, QuoteDurableService, QuoteOperationProfile, QuotePlacementEvidence, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuoteProviderRegistry, QuoteReceiptVerifier, QuoteRetentionService};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotReadiness, QuoteNativeOrderStager};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\{OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionSealReceiptTest.php';
require_once __DIR__ . '/RetentionTest.php';
require_once dirname( __DIR__, 2 ) . '/stubs/woocommerce-order-stub.php';

/** Positive five-receipt core history never grants native staging, spending or payment authority. */
final class FixedPromotionSealNativeBoundaryTest extends TestCase {

	private function sealed_result( PromotionSealReceiptFixture $f ): FixedPromotionQuoteSealResult {
		$records = $f->records();
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_seal_verified( $f->quote, $records, $f->binding, $f->command ) );
		$result = new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::accepted(), $records['seal']->completion ), $f->command, $f->quote, $f->binding );
		self::assertSame( 'sealed', $result->binding->state() ); self::assertSame( 3, $result->binding->revision() );
		return $result;
	}

	private function durable( PromotionSealBoundaryFactory $factory, int &$authorized ): QuoteDurableService {
		return new QuoteDurableService( $factory, new QuoteProviderRegistry(), static function() use ( &$authorized ): bool { ++$authorized; throw new \LogicException( 'A core seal must not reach native authority.' ); } );
	}

	public function test_sealed_history_cannot_open_the_retained_native_evidence_carrier(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $guard = new PromotionSealBoundaryCurrentGuard(); $authorized = 0;
		try {
			new QuotePlacementEvidence( $result->quote, $f->command->reference(), $f->command->header(), $f->command->context(), $guard, static function() use ( &$authorized ): bool { ++$authorized; return true; }, Q::time()->plus_seconds( 6 ) );
			self::fail( 'Sealed promotion history must not open retained native placement.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $authorized ); self::assertSame( 0, $guard->calls );
	}

	public function test_recovered_seal_does_not_authorize_generic_seal_verification_or_replay(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $factory = new PromotionSealBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionSealBoundarySavedGuard(); $request = RequestContext::create();
		$c = FixedPromotionQuoteSealCommand::from_recovery_array( $result->command->to_recovery_array() );
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $c->verified_binding(), $c->control_revision(), $local, $saved );
		$generic = QuoteDurableCommand::seal_placement( $c->owner(), $c->reference(), $c->header(), $c->verified_binding(), $proof, $c->context() );
		$before = [ $result->quote->row(), $result->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ];
		foreach ( [
			static fn() => $service->verify_binding( $c->owner(), $c->reference(), $c->header(), $result->binding, $request, $c->context(), $saved ),
			static fn() => $service->seal( $c->owner(), $c->reference(), $c->header(), $result->binding, $request, $c->context() ),
			static fn() => $service->reconcile( $generic, $request ),
		] as $call ) {
			$refused = $call(); self::assertSame( 'rejected', $refused->attempt->outcome->state ); self::assertNull( $refused->attempt->completion ); self::assertNull( $refused->quote ); self::assertNull( $refused->binding );
		}
		self::assertSame( $before, [ $result->quote->row(), $result->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ] ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}

	public function test_core_sealed_result_and_recovery_do_not_enter_native_order_staging(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $factory = new PromotionSealBoundaryFactory(); $stager = new QuoteNativeOrderStager( $factory );
		$order = new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00', 'meta' => [ 'unchanged' => 'original' ] ] ); $before = serialize( $order );
		$recovered = FixedPromotionQuoteSealCommand::from_recovery_array( $result->command->to_recovery_array() );
		foreach ( [ $result, $result->command, $recovered ] as $internal ) {
			foreach ( [ static fn() => $stager->mapping( $order, $internal ), static fn() => $stager->stage( $order, $internal, $result->binding, 'classic' ) ] as $call ) {
				try { $call(); self::fail( 'Sealed core facts are not native placement evidence.' ); }
				catch ( \TypeError ) { self::assertTrue( true ); }
			}
		}
		self::assertSame( $before, serialize( $order ) ); self::assertSame( 0, $factory->opens );
	}

	public function test_linked_actual_seal_cannot_admit_saved_payment_or_supply_legacy_promise_linkage(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $factory = new PromotionSealBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionSealBoundarySavedGuard(); $c = $result->command;
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $c->verified_binding(), $c->control_revision(), $local, $saved );
		$refused = $service->seal_placement( $c->owner(), $c->reference(), $c->header(), $c->verified_binding(), RequestContext::create(), $c->context(), $proof );
		self::assertSame( 'rejected', $refused->attempt->outcome->state ); self::assertNull( $refused->attempt->completion );
		self::assertFalse( $service->admit_sealed_placement( $c->owner(), $c->reference(), $c->header(), $result->binding, RequestContext::create(), $c->context(), $c->control_revision(), $local, $saved ) );
		self::assertFalse( $service->known_rejected_placement( $c->owner(), $c->reference(), $c->header(), $result->binding, $saved ) );
		self::assertNull( $service->acknowledged_promise_seal( $c->owner(), $c->reference(), $c->header(), $result->binding, $saved ) );
		$verifier = new QuoteReceiptVerifier(); $records = $f->records();
		self::assertNull( $verifier->promise_seal( $result->quote, $records, $result->binding ) );
		self::assertFalse( $verifier->placement_rejected( $result->quote, $records, $result->binding ) );
		self::assertFalse( $verifier->original_placement_rejected( $result->quote, $records, $result->binding->row()['placement_uuid'], $result->binding ) );
		try { PromiseQuoteSealLinkage::from_verified( $result->quote, $result->binding, $f->records()['seal'] ); self::fail( 'An E15 seal is not the legacy promise payment/fulfilment companion.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid delivery quote facts.', $error->getMessage() ); }
		self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}

	public function test_retain_original_hold_seal_never_opens_generic_receipts_default_authority_or_writers(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $records = $f->records(); $verifier = new QuoteReceiptVerifier(); $c = $result->command;
		self::assertSame( 'retain_original_hold_v1', FixedPromotionQuoteSealCommand::FUNDING_POLICY );
		self::assertSame( $c->verification()->allocation_digest(), $c->allocation_digest() ); self::assertTrue( $c->verification()->hold_until()->equals( $c->hold_until() ) );
		self::assertFalse( $verifier->verify( $result->quote, $records, $result->binding ) ); self::assertFalse( $verifier->issue_verified( $result->quote, $records ) );
		$generic = QuoteOperationProfile::registry()->get( 'delivery_quote.seal', 2 );
		self::assertInstanceOf( QuoteOperationProfile::class, $generic ); self::assertFalse( $generic->authorize( $c->identity ) ); self::assertFalse( ( new FixedPromotionQuoteSealProfile() )->authorize( $c->identity ) );
		$saved = new PromotionSealBoundarySavedGuard(); $local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $c->verified_binding(), $c->control_revision(), $local, $saved );
		$native = QuoteDurableCommand::seal_placement( $c->owner(), $c->reference(), $c->header(), $c->verified_binding(), $proof, $c->context() ); $calls = 0;
		$profile = new QuoteOperationProfile( 'delivery_quote.seal', $native, static function() use ( &$calls ): bool { ++$calls; return true; }, profile_version: 2 );
		self::assertFalse( $profile->authorize( $native->identity ) ); self::assertSame( 0, $calls ); self::assertSame( 0, $saved->calls );
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] );
	}

	public function test_linked_sealed_three_remains_protected_in_explicit_generic_retention(): void {
		$f = new PromotionSealReceiptFixture(); $result = $this->sealed_result( $f ); $factory = new RetentionUnitFactory();
		$insert = static function( string $suffix, array $row ) use ( $factory ): void {
			$statement = $factory->db->prepare( 'INSERT INTO storage_delivery_engine_' . $suffix . '(' . implode( ',', array_keys( $row ) ) . ') VALUES(' . implode( ',', array_fill( 0, count( $row ), '?' ) ) . ')' ); $statement->execute( array_values( $row ) );
		};
		$insert( 'delivery_quotes', $result->quote->row() ); $insert( 'delivery_quote_bindings', $result->binding->row() );
		foreach ( $f->rows as $row ) { $insert( 'operation_records', $row ); }
		foreach ( $f->events as $event ) { $insert( 'operation_changes', $event ); }
		$read = static fn( string $suffix ): array => $factory->db->query( 'SELECT * FROM storage_delivery_engine_' . $suffix . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC );
		$before = [ $read( 'delivery_quotes' ), $read( 'delivery_quote_bindings' ), $read( 'operation_records' ), $read( 'operation_changes' ) ];
		$references = new RetentionUnitReferences(); $references->on_inspect = static function(): void { self::fail( 'A sealed accepted quote cannot grant retention reference/deletion authority.' ); };
		$service = new QuoteRetentionService( $factory, QuoteOperationProfile::registry(), static fn( int $site ): bool => 1 === $site, $references, new RetentionUnitControl(), new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} } );
		$start = $service->start( 1, RequestContext::create() ); self::assertSame( 'accepted', $start->attempt->outcome->state ); self::assertNotNull( $start->checkpoint );
		$batch = $service->batch( $start->checkpoint, RequestContext::create() ); self::assertSame( 'accepted', $batch->attempt->outcome->state ); self::assertNotNull( $batch->checkpoint );
		self::assertSame( 1, $batch->checkpoint->facts()['inspected'] ); self::assertSame( 1, $batch->checkpoint->facts()['protected'] ); self::assertSame( 0, $batch->checkpoint->facts()['stripped'] );
		self::assertSame( $before[0], $read( 'delivery_quotes' ) ); self::assertSame( $before[1], $read( 'delivery_quote_bindings' ) );
		self::assertSame( $before[2], array_slice( $read( 'operation_records' ), 0, 5 ) ); self::assertSame( $before[3], array_slice( $read( 'operation_changes' ), 0, 5 ) );
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_seal_verified( $result->quote, $f->records(), $result->binding, $result->command ) );
	}
}

final class PromotionSealBoundaryFactory implements OperationConnectionFactory {
	public int $opens = 0;
	public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'A core seal must not open native SQL.' ); }
}
final class PromotionSealBoundaryCurrentGuard implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Sealed history cannot inspect native current tables.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'Sealed history cannot grant native current authority.' ); }
}
final class PromotionSealBoundarySavedGuard implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Sealed history cannot inspect native saved-order tables.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'A core seal cannot admit native payment.' ); }
}
