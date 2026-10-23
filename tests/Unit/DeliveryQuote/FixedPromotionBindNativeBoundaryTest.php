<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCurrentEvidenceGuard, QuoteDurableCommand, QuoteDurableService, QuoteOperationProfile, QuotePlacementEvidence, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuoteProviderRegistry, QuoteReceiptVerifier};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotReadiness, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionBindingReceiptTest.php';
require_once dirname( __DIR__, 2 ) . '/stubs/woocommerce-order-stub.php';

/** A durable prepared promotion association is not native verification, sealing or payment admission. */
final class FixedPromotionBindNativeBoundaryTest extends TestCase {
	private function durable( PromotionBindBoundaryFactory $factory, int &$authorized ): QuoteDurableService {
		return new QuoteDurableService( $factory, new QuoteProviderRegistry(), static function() use ( &$authorized ): bool { ++$authorized; throw new \LogicException( 'The native promotion boundary must refuse before authority.' ); } );
	}
	public function test_prepared_promotion_binding_cannot_construct_the_retained_native_evidence_carrier(): void {
		$f = new PromotionBindReceiptFixture(); $guard = new PromotionBindBoundaryCurrentGuard(); $authorized = 0;
		try {
			new QuotePlacementEvidence( $f->quote, $f->acceptance->reference(), $f->acceptance->header(), $f->acceptance->context(), $guard, static function() use ( &$authorized ): bool { ++$authorized; return true; }, Q::time()->plus_seconds( 3 ) );
			self::fail( 'A prepared promotion binding must not open the native evidence carrier.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $authorized ); self::assertSame( 0, $guard->calls );
	}
	public function test_generic_bind_verification_and_sealing_refuse_before_authorization_or_sql(): void {
		$f = new PromotionBindReceiptFixture(); $factory = new PromotionBindBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionBindBoundarySavedGuard(); $request = RequestContext::create();
		$quote = $f->quote; $acceptance = $f->acceptance;
		$before = [ $quote->row(), $f->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ];
		foreach ( [
			static fn() => $service->bind( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $f->binding, $request, $acceptance->context() ),
			static fn() => $service->verify_binding( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $f->binding, $request, $acceptance->context(), $saved ),
			static fn() => $service->seal( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $f->binding, $request, $acceptance->context() ),
			static fn() => $service->reconcile( QuoteDurableCommand::bind( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $f->binding, $acceptance->context() ), $request ),
		] as $call ) {
			$result = $call(); self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertNull( $result->binding );
		}
		self::assertSame( $before, [ $quote->row(), $f->binding->row(), $GLOBALS['cetech_de_test_options'] ?? [] ] ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}
	public function test_new_command_is_not_a_generic_native_or_order_staging_capability(): void {
		$f = new PromotionBindReceiptFixture(); $factory = new PromotionBindBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $stager = new QuoteNativeOrderStager( $factory );
		$order = new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00', 'meta' => [ 'untouched' => 'original' ] ] ); $before = serialize( $order );
		foreach ( [
			static fn() => $service->reconcile( $f->command, RequestContext::create() ),
			static fn() => $stager->mapping( $order, $f->command ),
			static fn() => $stager->stage( $order, $f->command, $f->binding, 'classic' ),
		] as $call ) {
			try { $call(); self::fail( 'The internal bind command must not enter native staging.' ); }
			catch ( \TypeError ) { self::assertTrue( true ); }
		}
		self::assertSame( $before, serialize( $order ) ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized );
	}
	public function test_even_structurally_later_binding_facts_do_not_create_seal_or_saved_payment_authority(): void {
		$f = new PromotionBindReceiptFixture(); $factory = new PromotionBindBoundaryFactory(); $authorized = 0; $service = $this->durable( $factory, $authorized ); $saved = new PromotionBindBoundarySavedGuard(); $acceptance = $f->acceptance;
		$verified = QuoteBinding::from_row( array_replace( $f->binding->row(), [ 'revision' => 2, 'snapshot_digest' => Q::digest( 'binding boundary snapshot' ), 'context_digest' => Q::digest( 'binding boundary context' ), 'verified_at' => Q::time()->plus_seconds( 4 )->sql() ] ), $f->quote );
		$sealed = QuoteBinding::from_row( array_replace( $verified->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $f->quote );
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $verified, 1, $local, $saved );
		$result = $service->seal_placement( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $verified, RequestContext::create(), $acceptance->context(), $proof );
		self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion );
		self::assertFalse( $service->admit_sealed_placement( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $sealed, RequestContext::create(), $acceptance->context(), 1, $local, $saved ) );
		self::assertFalse( $service->known_rejected_placement( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $verified, $saved ) );
		self::assertNull( $service->acknowledged_promise_seal( $acceptance->owner(), $acceptance->reference(), $acceptance->header(), $sealed, $saved ) );
		self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls );
	}
	public function test_generic_receipts_and_default_authorizers_stay_closed_for_a_fully_linked_prepared_binding(): void {
		$f = new PromotionBindReceiptFixture(); $records = $f->records(); $verifier = new QuoteReceiptVerifier();
		self::assertTrue( $verifier->promotion_binding_verified( $f->quote, $records, $f->binding, $f->command ) );
		self::assertFalse( $verifier->verify( $f->quote, $records, $f->binding ) ); self::assertFalse( $verifier->issue_verified( $f->quote, $records ) );
		self::assertFalse( QuoteOperationProfile::registry()->get( 'delivery_quote.bind', 1 )->authorize( $f->command->identity ) );
		$native = QuoteDurableCommand::bind( $f->acceptance->owner(), $f->acceptance->reference(), $f->acceptance->header(), $f->binding, $f->acceptance->context() ); $calls = 0;
		$profile = new QuoteOperationProfile( 'delivery_quote.bind', $native, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertFalse( $profile->authorize( $native->identity ) ); self::assertSame( 0, $calls );
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] );
	}
	public function test_prepared_history_remains_private_and_does_not_export_into_an_ordinary_customer_packet(): void {
		$f = new PromotionBindReceiptFixture();
		foreach ( [ $f->command, $f->binding ] as $private ) {
			foreach ( [ static fn() => json_encode( [ 'delivery' => $private ], JSON_THROW_ON_ERROR ), static fn() => serialize( [ 'delivery' => $private ] ) ] as $export ) {
				try { $export(); self::fail( 'Private prepared history requires an explicit authorized consumer.' ); }
				catch ( \LogicException $error ) { self::assertStringNotContainsString( $f->acceptance->reference()->handle(), $error->getMessage() ); }
			}
		}
		ob_start(); var_dump( $f->command ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $f->acceptance->reference()->handle(), $debug ); self::assertStringNotContainsString( $f->quote->header()->id()->value(), $debug );
	}
}

final class PromotionBindBoundaryFactory implements OperationConnectionFactory {
	public int $opens = 0;
	public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'The native promotion boundary cannot open SQL.' ); }
}
final class PromotionBindBoundaryCurrentGuard implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'The native boundary cannot inspect current promotion tables.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'The native boundary cannot grant current promotion authority.' ); }
}
final class PromotionBindBoundarySavedGuard implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Prepared history cannot inspect native saved-order tables.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'Prepared history cannot admit saved-order payment.' ); }
}
