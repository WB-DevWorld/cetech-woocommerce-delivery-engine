<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetService, FixedPromotionBudgetStore};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteOrderEvidenceGuard, FixedPromotionQuoteSealedEvidence, QuoteCurrentEvidenceGuard, QuoteDurableCommand, QuoteDurableService, QuoteOperationProfile, QuotePlacementEvidence, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuoteProviderRegistry, QuoteReceiptVerifier, QuoteRetentionService};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotReadiness, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionSealedReadFixture;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionSealNativeBoundaryTest.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** A successful fresh observation exports facts, never native/payment/funding authority. */
final class FixedPromotionSealedReadNativeBoundaryTest extends TestCase {

	private function ready( PromotionSealedReadFixture $fixture ): FixedPromotionQuoteSealedEvidence {
		$fixture->current_revision = 3; $evidence = $fixture->read( 3 );
		self::assertInstanceOf( FixedPromotionQuoteSealedEvidence::class, $evidence );
		self::assertSame( 'sealed', $evidence->binding()->state() ); self::assertSame( 3, $evidence->binding()->revision() );
		self::assertSame( 3, $evidence->control_revision() ); self::assertSame( 1, $fixture->command->control_revision() );
		return $evidence;
	}

	private function durable( PromotionSealBoundaryFactory $factory, int &$calls ): QuoteDurableService {
		return new QuoteDurableService( $factory, new QuoteProviderRegistry(), static function() use ( &$calls ): bool { ++$calls; throw new \LogicException( 'Detached observations cannot invoke native authorization.' ); } );
	}

	public function test_successful_observations_have_no_transitive_live_port_or_generic_persistence(): void {
		$f = new PromotionSealedReadFixture(); $e = $this->ready( $f ); $before = $f->bytes(); $seen = new \SplObjectStorage();
		$inspect = function( mixed $value ) use ( &$inspect, $seen ): void {
			if ( is_array( $value ) ) { foreach ( $value as $child ) { $inspect( $child ); } return; }
			if ( ! is_object( $value ) || $seen->contains( $value ) ) { return; } $seen->attach( $value );
			foreach ( [ \Closure::class, \WC_Order::class, OperationSession::class, OperationConnectionFactory::class, FixedPromotionBudgetStore::class, FixedPromotionQuoteOrderEvidenceGuard::class, QuoteCurrentEvidenceGuard::class, QuotePlacementSavedEvidenceGuard::class ] as $port ) {
				self::assertNotInstanceOf( $port, $value, 'A fresh observation retained a live participant.' );
			}
			foreach ( ( new \ReflectionObject( $value ) )->getProperties() as $property ) { if ( ! $property->isStatic() && $property->isInitialized( $value ) ) { $inspect( $property->getValue( $value ) ); } }
		};
		$inspect( $e );
		foreach ( [ 'authorize', 'guard', 'saved_guard', 'order_evidence', 'commit', 'release', 'admit_sealed_placement' ] as $capability ) { self::assertFalse( method_exists( $e, $capability ) ); }
		self::assertNotInstanceOf( OperationCommand::class, $e );
		foreach ( [ static fn() => json_encode( $e, JSON_THROW_ON_ERROR ), static fn() => serialize( $e ) ] as $persist ) {
			try { $persist(); self::fail( 'Detached observations must not become a persisted capability.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		}
		self::assertSame( [ 'private_promotion_sealed_evidence' => 'redacted' ], $e->__debugInfo() ); self::assertSame( $before, $f->bytes() );
	}

	public function test_fresh_sealed_observations_do_not_open_legacy_carrier_stager_or_proof(): void {
		$f = new PromotionSealedReadFixture(); $e = $this->ready( $f ); $guard = new PromotionSealBoundaryCurrentGuard(); $saved = new PromotionSealBoundarySavedGuard(); $factory = new PromotionSealBoundaryFactory(); $authorized = 0;
		$order = new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00', 'meta' => [ 'original' => 'unchanged' ] ] ); $before = serialize( $order ); $stager = new QuoteNativeOrderStager( $factory );
		try {
			new QuotePlacementEvidence( $e->quote_record(), $e->reference(), $e->header(), $e->context(), $guard, static function() use ( &$authorized ): bool { ++$authorized; return true; }, $e->evaluated_at() );
			self::fail( 'Fresh format3 observations cannot open the retained native evidence carrier.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		foreach ( [ static fn() => $stager->mapping( $order, $e ), static fn() => $stager->stage( $order, $e, $e->binding(), 'order_pay' ) ] as $stage ) {
			try { $stage(); self::fail( 'Detached observations are not native placement evidence.' ); } catch ( \TypeError ) { self::assertTrue( true ); }
		}
		$local = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $local );
		try { QuotePlacementProof::capture( $e->binding(), $e->control_revision(), $local, $saved ); self::fail( 'An observed sealed binding is not a new prepared2 placement proof.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertSame( 'Verified placement evidence is required.', $error->getMessage() ); }
		self::assertSame( $before, serialize( $order ) ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $guard->calls ); self::assertSame( 0, $saved->calls );
	}

	public function test_fresh_read_does_not_admit_saved_payment_or_terminal_disposition(): void {
		$f = new PromotionSealedReadFixture(); $e = $this->ready( $f ); $before = $f->bytes(); $factory = new PromotionSealBoundaryFactory(); $calls = 0; $service = $this->durable( $factory, $calls ); $saved = new PromotionSealBoundarySavedGuard(); $request = RequestContext::create();
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 700, 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		self::assertFalse( $service->admit_sealed_placement( $e->owner(), $e->reference(), $e->header(), $e->binding(), $request, $e->context(), $e->control_revision(), $local, $saved ) );
		self::assertFalse( $service->known_rejected_placement( $e->owner(), $e->reference(), $e->header(), $e->binding(), $saved ) );
		self::assertNull( $service->acknowledged_promise_seal( $e->owner(), $e->reference(), $e->header(), $e->binding(), $saved ) );
		foreach ( [ static fn() => $service->verify_binding( $e->owner(), $e->reference(), $e->header(), $e->binding(), $request, $e->context(), $saved ), static fn() => $service->seal( $e->owner(), $e->reference(), $e->header(), $e->binding(), $request, $e->context() ) ] as $call ) {
			$refused = $call(); self::assertSame( 'rejected', $refused->attempt->outcome->state ); self::assertNull( $refused->attempt->completion ); self::assertNull( $refused->quote ); self::assertNull( $refused->binding );
		}
		self::assertSame( 0, $factory->opens ); self::assertSame( 0, $calls ); self::assertSame( 0, $saved->calls ); self::assertSame( $before, $f->bytes() );
	}

	public function test_observed_control_revision_does_not_enable_generic_registry_or_snapshot_writer(): void {
		$f = new PromotionSealedReadFixture(); $e = $this->ready( $f ); $saved = new PromotionSealBoundarySavedGuard(); $calls = 0;
		$command = QuoteDurableCommand::verify_binding( $e->owner(), $e->reference(), $e->header(), $f->command->verified_binding(), $saved, $e->context() );
		$profile = new QuoteOperationProfile( 'delivery_quote.verify_binding', $command, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertFalse( $profile->authorize( $command->identity ) ); self::assertFalse( QuoteOperationProfile::registry()->get( 'delivery_quote.seal', 2 )->authorize( $f->command->identity ) );
		self::assertFalse( ( new QuoteReceiptVerifier() )->verify( $e->quote_record(), [], $e->binding() ) );
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] );
		self::assertSame( 0, $calls ); self::assertSame( 0, $saved->calls );
	}

	public function test_observations_cannot_be_spend_release_or_retention_commands(): void {
		$f = new PromotionSealedReadFixture(); $e = $this->ready( $f ); $before = $f->bytes(); $calls = 0; $session = $this->createMock( OperationSession::class ); $store = $this->createMock( FixedPromotionBudgetStore::class );
		foreach ( [ 'site_id', 'table_prefix', 'in_transaction', 'is_retired', 'query', 'get_row', 'get_results' ] as $method ) { $session->expects( self::never() )->method( $method ); }
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( self::never() )->method( $method ); }
		$budget = new FixedPromotionBudgetService( $store, static function() use ( &$calls ): bool { ++$calls; return true; } );
		foreach ( [ static fn() => $budget->commit( $session, $e, Q::time()->plus_seconds( 6 ) ), static fn() => $budget->release( $session, $e, Q::time()->plus_seconds( 6 ) ) ] as $spend ) {
			try { $spend(); self::fail( 'Read observations do not select a funding lifecycle action.' ); } catch ( \TypeError ) { self::assertTrue( true ); }
		}
		$factory = new PromotionSealBoundaryFactory(); $retention = new QuoteRetentionService( $factory, QuoteOperationProfile::registry(), static function() use ( &$calls ): bool { ++$calls; return true; } );
		try { $retention->attempt( $e, RequestContext::create() ); self::fail( 'Current sealed observations are not retention authority.' ); } catch ( \TypeError ) { self::assertTrue( true ); }
		self::assertSame( 0, $calls ); self::assertSame( 0, $factory->opens ); self::assertSame( $before, $f->bytes() );
	}
}
