<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyProfile, FixedPromotionQuoteVerifyResult, QuoteCurrentEvidenceGuard, QuoteDurableCommand, QuoteDurableService, QuoteOperationProfile, QuotePlacementEvidence, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuoteProviderRegistry, QuoteReceiptVerifier};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotReadiness, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\{OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionVerificationReceiptTest.php';
require_once dirname( __DIR__, 2 ) . '/stubs/woocommerce-order-stub.php';

/** Linked synthetic verification history is not native staging, sealing or payment authority. */
final class FixedPromotionVerifyNativeBoundaryTest extends TestCase {

	private function verified_result( PromotionVerifyReceiptFixture $f ): FixedPromotionQuoteVerifyResult {
		$records = $f->records();
		self::assertTrue( ( new QuoteReceiptVerifier() )->promotion_verification_verified( $f->quote, $records, $f->binding, $f->command ) );
		$result = new FixedPromotionQuoteVerifyResult( new OperationAttemptResult( OperationOutcome::accepted(), $records['verify_binding']->completion ), $f->command, $f->quote, $f->binding );
		self::assertSame( 2, $result->binding->revision() );
		return $result;
	}

	private function durable( PromotionVerifyBoundaryFactory $factory, int &$authorized ): QuoteDurableService {
		return new QuoteDurableService( $factory, new QuoteProviderRegistry(), static function() use ( &$authorized ): bool { ++$authorized; throw new \LogicException( 'Native promotion gates must refuse before authority.' ); } );
	}

	public function test_verified_history_cannot_open_the_retained_native_evidence_carrier(): void {
		$f = new PromotionVerifyReceiptFixture(); $result = $this->verified_result( $f ); $guard = new PromotionVerifyBoundaryCurrentGuard(); $authorized = 0;
		try {
			new QuotePlacementEvidence( $result->quote, $f->command->reference(), $f->command->header(), $f->command->context(), $guard, static function() use ( &$authorized ): bool { ++$authorized; return true; }, Q::time()->plus_seconds( 5 ) );
			self::fail( 'Verified promotion history must not open retained native placement.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $authorized ); self::assertSame( 0, $guard->calls );
	}

	public function test_verified_binding_and_recovered_generic_command_refuse_before_authority_or_sql(): void {
		$f = new PromotionVerifyReceiptFixture(); $result = $this->verified_result( $f ); $factory = new PromotionVerifyBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionVerifyBoundarySavedGuard(); $request = RequestContext::create();
		$c = FixedPromotionQuoteVerifyCommand::from_recovery_array( $result->command->to_recovery_array() );
		$before = [ $result->quote->row(), $result->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ];
		foreach ( [
			static fn() => $service->verify_binding( $c->owner(), $c->reference(), $c->header(), $result->binding, $request, $c->context(), $saved ),
			static fn() => $service->seal( $c->owner(), $c->reference(), $c->header(), $result->binding, $request, $c->context() ),
			static fn() => $service->reconcile( QuoteDurableCommand::verify_binding( $c->owner(), $c->reference(), $c->header(), $result->binding, $saved, $c->context() ), $request ),
		] as $call ) {
			$refused = $call(); self::assertSame( 'rejected', $refused->attempt->outcome->state ); self::assertNull( $refused->attempt->completion ); self::assertNull( $refused->quote ); self::assertNull( $refused->binding );
		}
		self::assertSame( $before, [ $result->quote->row(), $result->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ] ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}

	public function test_internal_verified_result_and_its_recovery_do_not_enter_native_order_staging(): void {
		$f = new PromotionVerifyReceiptFixture(); $result = $this->verified_result( $f ); $factory = new PromotionVerifyBoundaryFactory(); $stager = new QuoteNativeOrderStager( $factory );
		$order = new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00', 'meta' => [ 'unchanged' => 'original' ] ] ); $before = serialize( $order );
		$recovered = FixedPromotionQuoteVerifyCommand::from_recovery_array( $result->command->to_recovery_array() );
		foreach ( [ $result, $result->command, $recovered ] as $internal ) {
			foreach ( [ static fn() => $stager->mapping( $order, $internal ), static fn() => $stager->stage( $order, $internal, $result->binding, 'classic' ) ] as $call ) {
				try { $call(); self::fail( 'Verified core facts are not native placement evidence.' ); }
				catch ( \TypeError ) { self::assertTrue( true ); }
			}
		}
		self::assertSame( $before, serialize( $order ) ); self::assertSame( 0, $factory->opens );
	}

	public function test_verified_history_does_not_admit_sealing_saved_payment_or_terminal_disposition(): void {
		$f = new PromotionVerifyReceiptFixture(); $result = $this->verified_result( $f ); $factory = new PromotionVerifyBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionVerifyBoundarySavedGuard(); $c = $result->command;
		$sealed = QuoteBinding::from_row( array_replace( $result->binding->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $result->quote );
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $result->binding, 1, $local, $saved );
		$refused = $service->seal_placement( $c->owner(), $c->reference(), $c->header(), $result->binding, RequestContext::create(), $c->context(), $proof );
		self::assertSame( 'rejected', $refused->attempt->outcome->state ); self::assertNull( $refused->attempt->completion );
		self::assertFalse( $service->admit_sealed_placement( $c->owner(), $c->reference(), $c->header(), $sealed, RequestContext::create(), $c->context(), 1, $local, $saved ) );
		self::assertFalse( $service->known_rejected_placement( $c->owner(), $c->reference(), $c->header(), $result->binding, $saved ) );
		self::assertNull( $service->acknowledged_promise_seal( $c->owner(), $c->reference(), $c->header(), $sealed, $saved ) );
		self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}

	public function test_generic_receipts_and_default_profiles_remain_closed_for_fully_linked_verified_history(): void {
		$f = new PromotionVerifyReceiptFixture(); $result = $this->verified_result( $f ); $records = $f->records(); $verifier = new QuoteReceiptVerifier();
		self::assertFalse( $verifier->verify( $result->quote, $records, $result->binding ) ); self::assertFalse( $verifier->issue_verified( $result->quote, $records ) );
		$registry = QuoteOperationProfile::registry(); $generic = $registry->get( 'delivery_quote.verify_binding', 1 );
		self::assertInstanceOf( QuoteOperationProfile::class, $generic ); self::assertFalse( $generic->authorize( $result->command->identity ) );
		self::assertFalse( ( new FixedPromotionQuoteVerifyProfile() )->authorize( $result->command->identity ) );
		$saved = new PromotionVerifyBoundarySavedGuard(); $c = $result->command;
		$native = QuoteDurableCommand::verify_binding( $c->owner(), $c->reference(), $c->header(), $result->binding, $saved, $c->context() ); $calls = 0;
		$profile = new QuoteOperationProfile( 'delivery_quote.verify_binding', $native, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertFalse( $profile->authorize( $native->identity ) ); self::assertSame( 0, $calls ); self::assertSame( 0, $saved->calls );
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] );
	}
}

final class PromotionVerifyBoundaryFactory implements OperationConnectionFactory {
	public int $opens = 0;
	public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'Native promotion gates cannot open SQL.' ); }
}
final class PromotionVerifyBoundaryCurrentGuard implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Verified history cannot inspect native current tables.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'Verified history cannot grant native current authority.' ); }
}
final class PromotionVerifyBoundarySavedGuard implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Verified history cannot inspect native saved-order tables.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'Verified history cannot admit native payment.' ); }
}
