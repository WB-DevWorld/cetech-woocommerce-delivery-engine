<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteJson,QuoteShape,QuoteStoredRow,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationRecord;

/** Separate immutable final-event linkage; construction alone supplies no native read or payment authority. */
final readonly class PromiseQuoteSealLinkage implements \JsonSerializable {
	private function __construct( private string $json, private \CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseQuotePacket $packet ) {}
	public static function from_verified( QuoteStoredRow $quote, QuoteBinding $binding, OperationRecord $seal ): self {
		if ( 2 !== $quote->header()->format_version() || 'service_promise_v1' !== $quote->header()->profile() || null === $quote->context() || null === $quote->terms() || 'accepted' !== $quote->state() || 'sealed' !== $binding->state() || 3 !== $binding->revision() || $seal->site_id !== $quote->site_id() || 'delivery_quote.seal' !== $seal->operation || 2 !== $seal->operation_version || 'accepted' !== $seal->state || null === $seal->completion || null === $seal->event || null === $seal->audit_id ) { QuoteShape::invalid(); }
		$b = $binding->row(); $r = $seal->completion->result; $at = QuoteTime::parse( $b['sealed_at'] );
		$identity = new \CetechDeliveryEngine\Domain\Contracts\OperationIdentity( $quote->site_id(), 'delivery_quote_customer', $quote->header()->owner()->digest(), 'delivery_quote.seal', 2, 'placement:' . $b['placement_uuid'], $quote->header()->namespace_hashes()['issue'] );
		if ( ! $seal->matches_identity( $identity ) || $b['seal_namespace_hash'] !== $identity->namespace_digest() ) { QuoteShape::invalid(); }
		foreach ( [ 'quote_id' => $quote->header()->id()->value(), 'quote_revision' => 2, 'body_digest' => $quote->header()->body_digest(), 'owner_digest' => $quote->header()->owner()->digest(), 'namespace_hash' => $b['seal_namespace_hash'], 'order_id' => $b['order_id'], 'placement_id' => $b['placement_uuid'], 'binding_id' => $binding->id(), 'binding_revision' => 3, 'state' => 'sealed', 'manifest_digest' => $b['managed_group_manifest_digest'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'native_money_digest' => $b['native_money_digest'], 'completed_at' => $at->epoch_microseconds() ] as $key => $value ) { if ( ( $r[$key] ?? null ) !== $value ) { QuoteShape::invalid(); } }
		if ( $seal->event->actor !== [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ] ) { QuoteShape::invalid(); }
		if ( $seal->namespace_hash !== $b['seal_namespace_hash'] || ! $quote->terms()->feasibility_at( $at ) || ! $quote->header()->valid_at( $at ) ) { QuoteShape::invalid(); }
		$source = [ 'record_id' => $seal->id, 'audit_id' => $seal->audit_id, 'site_id' => $seal->site_id, 'operation' => $seal->operation, 'operation_version' => $seal->operation_version, 'namespace_hash' => $seal->namespace_hash, 'intent_hash' => $seal->intent_hash, 'target_hash' => $seal->target_hash, 'completion' => QuoteJson::decode( $seal->completion->to_json(), 16384 ), 'event' => QuoteJson::decode( $seal->event->to_json(), 16384 ) ];
		$groups = []; foreach ( $quote->terms()->promise_packet()->private_facts()['groups'] as $group ) { $groups[] = [ 'component_key' => $group['component_key'], 'input_digest' => $group['packet']['input_digest'], 'result_digest' => $group['packet']['result_digest'], 'historical_packet_digest' => \CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket::from_array( $group['packet'] )->digest(), 'result_state' => \CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket::from_array( $group['packet'] )->result_facts()['state'] ]; }
		return new self( QuoteJson::encode( [ 'format' => 1, 'site_id' => $quote->site_id(), 'quote_id' => $quote->header()->id()->value(), 'body_digest' => $quote->header()->body_digest(), 'order_id' => $b['order_id'], 'placement_id' => $b['placement_uuid'], 'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'], 'promise_packet_digest' => $quote->terms()->promise_packet()->digest(), 'groups' => $groups, 'final_event' => [ 'kind' => 'q06_placement_sealed', 'occurred_at' => $at->sql(), 'source_receipt_digest' => hash( 'sha256', 'cetech-service-promise-q06-seal-v1:' . QuoteJson::encode( $source ) ) ] ] ), $quote->terms()->promise_packet() );
	}
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-seal-linkage-v1:' . $this->json ); }
	/** Structured originals for an authorized shipment/history consumer, with no live timezone rehydration. */
	public function accepted_groups(): array { $out = []; foreach ( $this->private_facts()['groups'] as $group ) { if ( in_array( $group['result_state'], [ 'absolute_window', 'relative_window' ], true ) ) { $out[] = $this->original_reference( $group['component_key'] ); } } return $out; }
	public function recorded_groups(): array { $out = []; foreach ( $this->private_facts()['groups'] as $group ) { $out[] = $this->historical_reference( $group['component_key'] ); } return $out; }
	public function original_reference( string $component_key ): array { if ( ! in_array( $this->packet->group( $component_key )->result_facts()['state'], [ 'absolute_window', 'relative_window' ], true ) ) { QuoteShape::invalid(); } return $this->historical_reference( $component_key ); }
	private function historical_reference( string $component_key ): array {
		$f = $this->private_facts(); $reference = null; foreach ( $f['groups'] as $group ) { if ( $group['component_key'] === $component_key ) { $reference = $group; break; } } if ( null === $reference ) { QuoteShape::invalid(); }
		return [ 'format' => 1, ...$reference, 'commitment_state' => in_array( $reference['result_state'], [ 'absolute_window', 'relative_window' ], true ) ? 'accepted' : 'recorded_refusal', 'original' => $this->packet->group( $component_key )->public_facts(), 'quote' => [ 'site_id' => $f['site_id'], 'quote_id' => $f['quote_id'], 'body_digest' => $f['body_digest'], 'promise_packet_digest' => $f['promise_packet_digest'] ], 'snapshot' => [ 'order_id' => $f['order_id'], 'placement_id' => $f['placement_id'], 'snapshot_digest' => $f['snapshot_digest'], 'context_digest' => $f['context_digest'] ], 'final_event' => $f['final_event'], 'linkage_digest' => $this->digest() ];
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Final promise linkage requires authorized private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Final promise linkage cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Final promise linkage cannot be hydrated generically.' ); }
}
