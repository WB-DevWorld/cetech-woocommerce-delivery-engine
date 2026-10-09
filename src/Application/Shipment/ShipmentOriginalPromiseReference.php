<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuoteSealLinkage;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, PromiseSnapshotCore};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteShape};
use CetechDeliveryEngine\Domain\Shipment\Shipment;

/** Detached original facts. Native authorization and original ACK evidence belong to the reader. */
final readonly class ShipmentOriginalPromiseReference implements \JsonSerializable {
	private function __construct( private string $json ) {}

	/** This structural constructor alone never establishes native read or acceptance authority. */
	public static function from_original( Shipment $shipment, DeliveryQuoteSnapshotEnvelope $envelope, PromiseQuoteSealLinkage $linkage ): self {
		if ( $shipment->id < 1 || ! $envelope->is_promise() || 'delivery' !== $shipment->fulfilment_choice ) { QuoteShape::invalid(); }
		$group = PromiseSnapshotCore::group( $envelope, $shipment->delivery_group_id );
		$packet = $envelope->promise_packet()->group( $group['key'] ); $result = $packet->result_facts();
		if ( ! in_array( $result['state'], [ 'absolute_window', 'relative_window' ], true ) ) { QuoteShape::invalid(); }
		$original = $linkage->original_reference( $group['key'] );
		QuoteShape::fields( $original, [ 'format', 'component_key', 'input_digest', 'result_digest', 'historical_packet_digest', 'result_state', 'commitment_state', 'original', 'quote', 'snapshot', 'final_event', 'linkage_digest' ] );
		$facts = $envelope->private_facts(); $saved = $packet->private_facts();
		if ( 1 !== $original['format'] || $original['component_key'] !== $group['key'] || 'accepted' !== $original['commitment_state'] || $original['result_state'] !== $result['state']
			|| $original['input_digest'] !== $saved['input_digest'] || $original['result_digest'] !== $saved['result_digest'] || $original['historical_packet_digest'] !== $packet->digest()
			|| $original['original'] !== $packet->public_facts() || $original['quote']['quote_id'] !== $facts['quote_id'] || $original['quote']['body_digest'] !== $facts['body_digest']
			|| $original['quote']['promise_packet_digest'] !== $facts['promise_packet_digest'] || $original['snapshot']['order_id'] !== $shipment->order_id
			|| $original['snapshot']['placement_id'] !== $facts['placement_id'] || $original['snapshot']['context_digest'] !== $facts['context_digest'] ) { QuoteShape::invalid(); }
		QuoteShape::digest( $original['snapshot']['snapshot_digest'] );
		return new self( QuoteJson::encode( [ 'format' => 1, 'shipment' => [ 'shipment_id' => $shipment->id, 'order_id' => $shipment->order_id, 'delivery_group_id' => $shipment->delivery_group_id ], 'promise' => $original ] ) );
	}

	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-shipment-original-promise-reference-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Original shipment promise requires authorized private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Original shipment promise cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Original shipment promise requires the authorized reader.' ); }
}
