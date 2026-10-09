<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation, PromiseQuoteSealLinkage, QuoteOperationProfile, QuoteSavedOrderPlacementEvidenceReader};
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, QuoteNativeOrderStager};
use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Application\Shipment\{ShipmentOriginalPromiseReference, ShipmentOriginalPromiseReferenceReader};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteJson, QuoteStoredRow, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationConnectionFactory, OperationMaterialEvent, OperationRecord, OperationSession};
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseResult};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures, QuoteStorageFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Pure synthetic links only. Native physical authorization and ACK proof remain disposable-site qualification. */
final class ShipmentOriginalPromiseReferenceTest extends TestCase {
	private const GROUP = 'in_warehouse|delivery|1';

	#[DataProvider( 'winning_states' )]
	public function test_original_reference_preserves_structured_packet_and_event_when_current_eta_changes( string $state ): void {
		[ $envelope, $linkage ] = $this->captured( $state ); $shipment = $this->shipment(); $ref = ShipmentOriginalPromiseReference::from_original( $shipment, $envelope, $linkage );
		$changed = $shipment->withCurrentEta( 'Staff revised current estimate' ); $after = ShipmentOriginalPromiseReference::from_original( $changed, $envelope, $linkage );
		self::assertSame( $ref->to_private_json(), $after->to_private_json() ); self::assertSame( QuoteJson::encode( $envelope->promise_packet()->group( NativeCartQuotePreparation::component_key( self::GROUP ) )->public_facts() ), QuoteJson::encode( $ref->private_facts()['promise']['original'] ) );
		self::assertSame( $state, $ref->private_facts()['promise']['result_state'] ); self::assertSame( 'accepted', $ref->private_facts()['promise']['commitment_state'] ); self::assertSame( 'q06_placement_sealed', $ref->private_facts()['promise']['final_event']['kind'] );
		self::assertSame( $linkage->private_facts()['final_event'], $ref->private_facts()['promise']['final_event'] ); self::assertSame( $linkage->digest(), $ref->private_facts()['promise']['linkage_digest'] ); self::assertSame( 'Original estimate', $shipment->eta_original ); self::assertSame( $shipment->eta_original, $changed->eta_original );
		self::assertSame( hash( 'sha256', 'cetech-shipment-original-promise-reference-v1:' . $ref->to_private_json() ), $ref->digest() );
	}
	public static function winning_states(): iterable { yield [ 'absolute_window' ]; yield [ 'relative_window' ]; }

	#[DataProvider( 'link_mismatches' )]
	public function test_original_reference_refuses_foreign_or_changed_saved_links( string $change ): void {
		[ $envelope, $linkage ] = $this->captured(); $facts = $envelope->private_facts(); $shipment = $this->shipment();
		match ( $change ) {
			'body' => $facts['body_digest'] = QuoteFixtures::digest( 'changed-body' ),
			'context' => $facts['context_digest'] = QuoteFixtures::digest( 'changed-context' ),
			'placement' => $facts['placement_id'] = '00000000-0000-4000-8000-000000000098',
			'order' => $shipment = $this->shipment( order: 101 ),
			'group' => $shipment = $this->shipment( group: 'foreign|delivery|1' ),
			'unsaved' => $shipment = $this->shipment( id: 0 ),
			'choice' => $shipment = $this->shipment( choice: 'store_pickup' ),
		};
		$this->expectException( \InvalidArgumentException::class ); ShipmentOriginalPromiseReference::from_original( $shipment, DeliveryQuoteSnapshotEnvelope::from_array( $facts ), $linkage );
	}
	public static function link_mismatches(): iterable { foreach ( [ 'body', 'context', 'placement', 'order', 'group', 'unsaved', 'choice' ] as $change ) { yield [ $change ]; } }

	public function test_optional_refusal_has_recorded_history_without_accepted_original_reference(): void {
		[ $envelope, $linkage ] = $this->captured( 'unavailable' ); self::assertSame( [], $linkage->accepted_groups() ); self::assertSame( 'recorded_refusal', $linkage->recorded_groups()[0]['commitment_state'] );
		$this->expectException( \InvalidArgumentException::class ); ShipmentOriginalPromiseReference::from_original( $this->shipment(), $envelope, $linkage );
	}
	public function test_generic_serialization_cannot_disclose_private_original_links(): void {
		[ $envelope, $linkage ] = $this->captured(); $reference = ShipmentOriginalPromiseReference::from_original( $this->shipment(), $envelope, $linkage );
		try { json_encode( $reference, JSON_THROW_ON_ERROR ); self::fail( 'Generic JSON must refuse.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		$this->expectException( \LogicException::class ); serialize( $reference );
	}
	public function test_generic_unserialization_cannot_create_an_original_reference_carrier(): void {
		$class = ShipmentOriginalPromiseReference::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' );
	}
	public function test_reader_authorizes_before_private_packet_reads_and_cannot_accept_an_envelope_alone(): void {
		$factory = new class implements OperationConnectionFactory { public int $opens = 0; public function open(): OperationSession { ++$this->opens; throw new \RuntimeException( 'No original SQL acknowledgement is available.' ); } };
		$order = $this->getMockBuilder( \WC_Order::class )->setConstructorArgs( [ [ 'id' => 100 ] ] )->onlyMethods( [ 'get_meta', 'get_items' ] )->getMock();
		$order->expects( self::never() )->method( 'get_meta' ); $order->expects( self::never() )->method( 'get_items' ); $authorized = 0;
		$reader = new ShipmentOriginalPromiseReferenceReader( new QuoteSavedOrderPlacementEvidenceReader( $factory, new QuoteNativeOrderStager( $factory ), static function() use ( &$authorized ): bool { ++$authorized; return false; } ) );
		self::assertNull( $reader->read( $this->shipment(), $order ) ); self::assertSame( 1, $authorized ); self::assertSame( 0, $factory->opens );
		self::assertNull( $reader->read( $this->shipment( order: 101 ), $order ) ); self::assertSame( 1, $authorized ); self::assertSame( 0, $factory->opens );
	}
	private function shipment( int $id = 10, int $order = 100, string $group = self::GROUP, string $choice = 'delivery' ): Shipment {
		return Shipment::create( $order, $group, id: $id, fulfilment_choice: $choice, eta_original: 'Original estimate', eta_current: 'Current estimate' );
	}
	/** Exact domain carriers exercise comparison seams, never actual producer or physical read authority. */
	private function captured( string $state = 'absolute_window' ): array {
		$key = NativeCartQuotePreparation::component_key( self::GROUP ); $base = F::base_context()->private_facts(); $base['groups'][0]['component_key'] = $base['lines'][0]['component_key'] = $key; $base = QuoteContext::from_array( $base );
		$input = F::input( 'relative_window' === $state ? [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] : [ 'promise_required' => 'unavailable' !== $state ] )->private_facts();
		$input['material'] = [ 'group_id' => $key, 'material_digest' => $base->digest() ]; $input = PromiseInput::from_array( $input );
		$result = 'unavailable' === $state ? PromiseResult::from_array( [ 'format_version' => 1, 'state' => 'unavailable', 'input' => $input->private_facts(), 'input_digest' => $input->digest(), 'graph_digest' => $input->policy()->graph()->digest(), 'body' => null, 'reason_codes' => [ 'estimate_unavailable' ] ] ) : ( new DeterministicPromiseCalculator( CalculationFixture::runtime() ) )->calculate( $input, [] );
		$packet = PromiseHistoricalPacket::capture( $result, 'unavailable' === $state ? 'Delivery estimate unavailable' : 'Original delivery commitment' );
		$context = QuoteContext::from_base_promises( $base, $input->private_facts()['site_id'], [ [ 'component_key' => $key, 'assignment_receipt_digest' => QuoteFixtures::digest( 'assignment' ), 'policy_reference' => $input->policy()->reference()->private_facts(), 'input' => $input->to_private_json(), 'input_digest' => $input->digest() ] ] );
		$base_terms = F::base_terms()->private_facts(); $base_terms['groups'][0]['component_key'] = $key; $terms = QuoteTerms::from_base_promises( QuoteTerms::from_array( $base_terms ), [ [ 'component_key' => $key, 'packet' => $packet->private_facts() ] ] ); $header = F::header( $context, $terms );
		$row = F::row( 'accepted' ); $row['material_digest'] = $header->material_digest(); $row['body_digest'] = $header->body_digest(); $row['header_json'] = $header->to_private_json(); $row['private_body_json'] = QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ); $quote = QuoteStoredRow::from_row( $row );
		$binding = QuoteStorageFixtures::binding( $quote ); $b = $binding->row(); $sealed = $quote->accepted_at()->plus_seconds( 2 );
		$identity = new OperationIdentity( 1, 'delivery_quote_customer', $header->owner()->digest(), 'delivery_quote.seal', 2, 'placement:' . $b['placement_uuid'], $header->namespace_hashes()['issue'] );
		$b['snapshot_digest'] = QuoteFixtures::digest( 'original-snapshot' ); $b['context_digest'] = QuoteFixtures::digest( 'original-context' ); $b['verified_at'] = $quote->accepted_at()->plus_seconds( 1 )->sql(); $b['sealed_at'] = $sealed->sql(); $b['state'] = 'sealed'; $b['revision'] = 3; $b['seal_namespace_hash'] = $identity->namespace_digest(); $binding = QuoteBinding::from_row( $b, $quote );
		$profile = new QuoteOperationProfile( 'delivery_quote.seal', profile_version: 2 );
		$result = [ 'quote_id' => $header->id()->value(), 'quote_revision' => 2, 'state' => 'sealed', 'body_digest' => $header->body_digest(), 'owner_digest' => $header->owner()->digest(), 'namespace_hash' => $b['seal_namespace_hash'], 'completed_at' => $sealed->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => $binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => 100, 'binding_revision' => 3, 'manifest_digest' => $b['managed_group_manifest_digest'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'] ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $header->owner()->digest(), 'quote_id' => $header->id()->value(), 'purpose' => 'private_quote_invalidation' ] );
		$target = array_intersect_key( $result, array_flip( [ 'quote_id', 'body_digest', 'owner_digest', 'namespace_hash', 'quote_revision', 'binding_revision' ] ) );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', 'delivery_quote_customer' ), 'principal_hash' => hash( 'sha256', $header->owner()->digest() ) ], $target, 'quote_sealed', 2, 3, [ 'binding' ] );
		$operation = OperationRecord::from_row( [ 'id' => 201, 'site_id' => 1, 'namespace_hash' => $b['seal_namespace_hash'], 'intent_hash' => QuoteFixtures::digest( 'seal-intent' ), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.seal', 'operation_version' => 2, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'state' => 'accepted', 'publication_state' => 'pending', 'completion_json' => $completion->to_json(), 'audit_id' => 501, 'row_version' => 2, 'created_at' => $sealed->sql(), 'updated_at' => $sealed->sql(), 'completed_at' => $sealed->sql() ], $profile, [ 'id' => 501, 'site_id' => 1, 'operation_id' => 201, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => $sealed->sql() ] );
		return [ DeliveryQuoteSnapshotEnvelope::from_captured( $header, $context, $terms, $quote->accepted_at(), \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $b['placement_uuid'] ), $b['context_digest'] ), PromiseQuoteSealLinkage::from_verified( $quote, $binding, $operation ) ];
	}
}
