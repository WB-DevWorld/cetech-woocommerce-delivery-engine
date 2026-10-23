<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteIssueCommand, FixedPromotionQuotePlacementEvidence, FixedPromotionQuotePlacementReadResult, QuoteCurrentEvidenceGuard, QuoteCurrentEvidenceValidity, QuoteDurableCommand, QuoteDurableService, QuoteIssueCommand, QuoteOperationProfile, QuotePlacementEvidence, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuotePlacementService, QuoteProviderRegistry};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, DeliveryQuoteSnapshotProjection, DeliveryQuoteSnapshotReadiness, DeliveryQuoteSnapshotReadResult, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteBinding, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once dirname( __DIR__, 2 ) . '/stubs/woocommerce-order-stub.php';

/** Observed accepted promotion facts never become a native placement or payment grant. */
final class FixedPromotionQuotePlacementBoundaryTest extends TestCase {
	private function evidence(): FixedPromotionQuotePlacementEvidence {
		$p = F::mixed_provider();
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'boundary private pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), '38eb41b0-d08d-4a25-9808-6ad03e6aa001', $slots, Q::time()->plus_seconds( 45 ) );
		$id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $reference );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $reference->public_fields() ] );
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $issue ); $accepted = Q::time()->plus_seconds( 1 );
		$row = QuoteStoredRow::from_row( array_replace( QuoteStorageFixtures::quote( state: 'accepted' )->row(), [
			'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'header_json' => $header->to_private_json(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(),
			'private_body_json' => QuoteJson::encode( [ 'context' => $p->context()->private_facts(), 'terms' => $p->terms()->private_facts() ] ),
			'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'],
			'created_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => $accepted->sql(), 'transition_at' => $accepted->sql(),
		] ) );
		$at = Q::time()->plus_seconds( 2 );
		return new FixedPromotionQuotePlacementEvidence( $command, $row, $at, QuoteCurrentEvidenceValidity::capture( $at, $command->hold_until() ), new PromotionReadBoundaryCurrentProbe() );
	}

	private function durable( PromotionReadBoundaryFactory $factory, int &$authorized ): QuoteDurableService {
		return new QuoteDurableService( $factory, new QuoteProviderRegistry(), static function() use ( &$authorized ): bool { ++$authorized; throw new \LogicException( 'Format3 must refuse before native authority.' ); } );
	}

	public function test_observed_forward_facts_still_refuse_the_native_carrier_before_ports_or_authority(): void {
		$e = $this->evidence(); $authorized = 0;
		try {
			new QuotePlacementEvidence( $e->quote_record(), $e->reference(), $e->header(), $e->context(), $e->guard(), static function() use ( &$authorized ): bool { ++$authorized; return true; }, $e->evaluated_at() );
			self::fail( 'Promotion observations must not construct the retained native carrier.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $authorized ); self::assertSame( 0, $e->guard()->calls );
	}

	public function test_native_orchestration_and_order_staging_reject_the_observational_type_without_effects(): void {
		$e = $this->evidence(); $factory = new PromotionReadBoundaryFactory(); $authorized = 0; $durable = $this->durable( $factory, $authorized );
		$order = new \WC_Order( [ 'id' => 9102, 'status' => 'pending', 'total' => '22.00', 'meta' => [ 'original' => 'untouched' ] ] );
		$original = serialize( $order ); $binding = QuoteStorageFixtures::binding( $e->quote_record(), order: 9102 ); $stager = new QuoteNativeOrderStager( $factory );
		foreach ( [
			static fn() => new QuotePlacementService( $durable, $e ),
			static fn() => $stager->mapping( $order, $e ),
			static fn() => $stager->stage( $order, $e, $binding, 'classic' ),
		] as $call ) {
			try { $call(); self::fail( 'An observational promotion read must not enter native placement.' ); }
			catch ( \TypeError $error ) { self::assertStringContainsString( 'QuotePlacementEvidence', $error->getMessage() ); }
		}
		self::assertSame( $original, serialize( $order ) ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $e->guard()->calls );
	}

	public function test_generic_current_and_mutation_routes_remain_closed_even_with_complete_observed_facts(): void {
		$e = $this->evidence(); $factory = new PromotionReadBoundaryFactory(); $authorized = 0; $durable = $this->durable( $factory, $authorized ); $saved = new PromotionReadBoundarySavedProbe();
		$binding = QuoteStorageFixtures::binding( $e->quote_record() ); $request = RequestContext::create(); $before = $GLOBALS['cetech_de_test_options'] ?? [];
		$current = $durable->current( $e->owner(), $e->reference(), $e->context(), $request );
		self::assertSame( 'unavailable', $current->status ); self::assertNull( $current->quote );
		foreach ( [
			static fn() => $durable->accept( $e->owner(), $e->reference(), $e->header(), $e->context(), $request ),
			static fn() => $durable->invalidate( $e->owner(), $e->reference(), $e->header(), $e->context(), $request ),
			static fn() => $durable->bind( $e->owner(), $e->reference(), $e->header(), $binding, $request, $e->context() ),
			static fn() => $durable->seal( $e->owner(), $e->reference(), $e->header(), $binding, $request, $e->context() ),
			static fn() => $durable->verify_binding( $e->owner(), $e->reference(), $e->header(), $binding, $request, $e->context(), $saved ),
			static fn() => $durable->reconcile( QuoteDurableCommand::accept( $e->owner(), $e->reference(), $e->header(), $e->context() ), $request ),
		] as $call ) {
			$result = $call(); self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertNull( $result->binding );
		}
		self::assertSame( $before, $GLOBALS['cetech_de_test_options'] ?? [] ); self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls ); self::assertSame( 0, $e->guard()->calls );
	}

	public function test_observed_acceptance_and_even_a_prepared_proof_do_not_admit_seal_or_saved_payment(): void {
		$e = $this->evidence(); $factory = new PromotionReadBoundaryFactory(); $authorized = 0; $durable = $this->durable( $factory, $authorized ); $saved = new PromotionReadBoundarySavedProbe();
		$binding = QuoteStorageFixtures::binding( $e->quote_record() ); $row = $binding->row(); $at = $e->evaluated_at();
		$verified = QuoteBinding::from_row( array_replace( $row, [ 'revision' => 2, 'snapshot_digest' => Q::digest( 'observed snapshot' ), 'context_digest' => Q::digest( 'observed context' ), 'verified_at' => $at->sql() ] ), $e->quote_record() );
		$sealed = QuoteBinding::from_row( array_replace( $verified->row(), [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $at->sql() ] ), $e->quote_record() );
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => $row['order_id'], 'status' => 'pending', 'total' => '22.00' ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $verified, 1, $local, $saved );
		$result = $durable->seal_placement( $e->owner(), $e->reference(), $e->header(), $verified, RequestContext::create(), $e->context(), $proof );
		self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion );
		self::assertFalse( $durable->admit_sealed_placement( $e->owner(), $e->reference(), $e->header(), $sealed, RequestContext::create(), $e->context(), 1, $local, $saved ) );
		self::assertFalse( $durable->known_rejected_placement( $e->owner(), $e->reference(), $e->header(), $verified, $saved ) );
		self::assertNull( $durable->acknowledged_promise_seal( $e->owner(), $e->reference(), $e->header(), $sealed, $saved ) );
		self::assertSame( 0, $factory->opens ); self::assertSame( 0, $authorized ); self::assertSame( 0, $saved->calls ); self::assertSame( 0, $e->guard()->calls );
	}

	public function test_compiled_snapshot_reading_and_public_projection_do_not_enable_a_writer_or_generic_authorizer(): void {
		$e = $this->evidence();
		$envelope = DeliveryQuoteSnapshotEnvelope::from_captured( $e->header(), $e->context(), $e->terms(), $e->accepted_at(), QuoteId::generate(), Q::digest( 'observed native context' ) );
		$profile = [ 'code' => $e->header()->profile(), 'version' => $e->header()->profile_version() ];
		self::assertTrue( DeliveryQuoteSnapshotReadiness::supports( 3, $profile ) );
		self::assertSame( 3, $envelope->private_facts()['format'] );
		$contract = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $contract['reader_only'] ); self::assertFalse( $contract['writer_enabled'] );
		$public = DeliveryQuoteSnapshotProjection::for_customer( new DeliveryQuoteSnapshotReadResult( 'recorded', $envelope ) );
		self::assertSame( 'recorded', $public['status'] ); self::assertCount( 2, $public['groups'] );
		foreach ( $public['groups'] as $group ) { self::assertSame( [ 'customer_label', 'list', 'final', 'tax', 'total', 'promotion', 'rounded_tax', 'display_total' ], array_keys( $group ) ); }
		$json = QuoteJson::encode( $public ); self::assertStringNotContainsString( 'private_fixed_funder', $json ); self::assertStringNotContainsString( Q::digest( 'boundary private pool' ), $json ); self::assertStringNotContainsString( $e->reference()->handle(), $json );
		$command = QuoteDurableCommand::accept( $e->owner(), $e->reference(), $e->header(), $e->context() ); $calls = 0;
		$profile = new QuoteOperationProfile( 'delivery_quote.accept', $command, static function() use ( &$calls ): bool { ++$calls; return true; }, $e->guard() );
		self::assertFalse( $profile->authorize( $command->identity ) ); self::assertFalse( QuoteOperationProfile::registry()->get( 'delivery_quote.accept', 1 )->authorize( $command->identity ) ); self::assertSame( 0, $calls );
	}

	public function test_ready_observations_do_not_change_generic_model_authority_or_export_into_a_customer_packet(): void {
		$e = $this->evidence(); $read = new FixedPromotionQuotePlacementReadResult( 'ready', evidence: $e );
		$model = DeliveryQuote::issue( $e->header(), $e->context(), $e->terms() ); self::assertFalse( $model->usable_at( $e->evaluated_at() ) );
		try { $model->accept( $e->owner(), $e->reference(), $e->context(), $e->evaluated_at(), $e->header()->revision(), $e->header()->body_digest(), $e->header()->expires_at() ); self::fail( 'Observed promotion facts must not open generic model acceptance.' ); }
		catch ( \DomainException ) { self::assertSame( 'issued', $model->state() ); }
		foreach ( [ static fn() => json_encode( [ 'delivery' => $read ], JSON_THROW_ON_ERROR ), static fn() => serialize( [ 'delivery' => $read ] ) ] as $export ) {
			try { $export(); self::fail( 'Internal observations must not be exported by a generic customer envelope.' ); }
			catch ( \LogicException $error ) { self::assertStringNotContainsString( $e->reference()->handle(), $error->getMessage() ); }
		}
		self::assertSame( 0, $e->guard()->calls );
	}
}

final class PromotionReadBoundaryFactory implements OperationConnectionFactory {
	public int $opens = 0;
	public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'Forward observations cannot open a native mutation or payment owner.' ); }
}
final class PromotionReadBoundaryCurrentProbe implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Native boundary must not query promotion sources.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'Native boundary must not verify promotion sources.' ); }
}
final class PromotionReadBoundarySavedProbe implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Forward observation cannot load saved placement tables.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'Forward observation cannot verify saved placement.' ); }
}
