<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;

/** Immutable typed linkage only. A null event is unresolved pre-seal data and proves no final acceptance. */
final readonly class AcceptedPromiseReceipt implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		PromiseShape::fields( $data, [ 'format_version', 'result', 'result_digest', 'quote', 'snapshot', 'final_event' ] );
		PromiseShape::integer( $data['format_version'], 1, 1 );
		$result = PromiseResult::from_array( PromiseShape::object( $data['result'] ) );
		if ( ! in_array( $result->state(), [ 'absolute_window', 'relative_window' ], true ) || ! hash_equals( PromiseShape::digest( $data['result_digest'] ), $result->digest() ) ) { PromiseShape::invalid(); }
		$input = $result->input(); $quote = PromiseShape::object( $data['quote'] );
		PromiseShape::fields( $quote, [ 'site_id', 'quote_id', 'issued_at', 'expires_at', 'body_digest' ] );
		$site = PromiseShape::id( $quote['site_id'] );
		if ( ! is_string( $quote['quote_id'] ) ) { PromiseShape::invalid(); }
		try { $quote_id = QuoteId::from_string( $quote['quote_id'] )->value(); } catch ( \Throwable ) { PromiseShape::invalid(); }
		$issued = PromiseShape::instant( $quote['issued_at'] ); $expires = PromiseShape::instant( $quote['expires_at'] );
		if ( $site !== $input->site_id() || ! $issued->equals( $input->anchor()->evaluated_at() ) || ! $expires->equals( $input->anchor()->quote_expires_at() ) || $expires->epoch_microseconds() - $issued->epoch_microseconds() !== 300000000 ) { PromiseShape::invalid(); }
		$quote = [ 'site_id' => $site, 'quote_id' => $quote_id, 'issued_at' => $issued->sql(), 'expires_at' => $expires->sql(), 'body_digest' => PromiseShape::digest( $quote['body_digest'] ) ];
		$snapshot = PromiseShape::object( $data['snapshot'] );
		PromiseShape::fields( $snapshot, [ 'site_id', 'order_id', 'group_id', 'snapshot_digest' ] );
		if ( $site !== PromiseShape::id( $snapshot['site_id'] ) || PromiseShape::id( $snapshot['group_id'] ) !== $input->private_facts()['material']['group_id'] ) { PromiseShape::invalid(); }
		$snapshot = [ 'site_id' => $site, 'order_id' => PromiseShape::integer( $snapshot['order_id'], 1 ), 'group_id' => $snapshot['group_id'], 'snapshot_digest' => PromiseShape::digest( $snapshot['snapshot_digest'] ) ];
		$event = null;
		if ( null !== $data['final_event'] ) {
			$event = PromiseShape::object( $data['final_event'] );
			PromiseShape::fields( $event, [ 'kind', 'occurred_at', 'source_receipt_digest', 'order_id', 'snapshot_digest' ] );
			PromiseShape::choice( $event['kind'], [ 'q06_placement_sealed' ] ); $occurred = PromiseShape::instant( $event['occurred_at'] );
			if ( $occurred->compare( $input->anchor()->evaluated_at() ) < 0 || $occurred->compare( $expires ) >= 0 || PromiseShape::integer( $event['order_id'], 1 ) !== $snapshot['order_id'] || ! hash_equals( PromiseShape::digest( $event['snapshot_digest'] ), $snapshot['snapshot_digest'] ) || ( null !== $input->anchor()->accept_until() && $occurred->compare( $input->anchor()->accept_until() ) >= 0 ) ) { PromiseShape::invalid(); }
			if ( 'order_accepted' === $input->anchor()->kind() ) {
				foreach ( $result->private_facts()['body']['terminal_windows'] as $window ) { if ( in_array( $window['component_id'], $input->policy()->endpoint_terminal_component_ids(), true ) && $occurred->compare( PromiseShape::instant( $window['until'] ) ) > 0 ) { PromiseShape::invalid(); } }
			}
			$capacity = $input->capacity()->private_facts();
			if ( 'required' === $capacity['mode'] && $occurred->compare( PromiseShape::instant( $capacity['valid_until'] ) ) >= 0 ) { PromiseShape::invalid(); }
			$event = [ 'kind' => 'q06_placement_sealed', 'occurred_at' => $occurred->sql(), 'source_receipt_digest' => PromiseShape::digest( $event['source_receipt_digest'] ), 'order_id' => $snapshot['order_id'], 'snapshot_digest' => $snapshot['snapshot_digest'] ];
		}
		return new self( PromiseJson::detach( [ 'format_version' => 1, 'result' => $result->private_facts(), 'result_digest' => $result->digest(), 'quote' => $quote, 'snapshot' => $snapshot, 'final_event' => $event ] ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function result(): PromiseResult { return PromiseResult::from_array( $this->data['result'] ); }
	public function event_link_status(): string { return null === $this->data['final_event'] ? 'unresolved' : 'captured_q06_seal'; }
	public function private_facts(): array { return $this->data; }
	public function to_private_json(): string { return PromiseJson::encode( $this->data ); }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-accepted-receipt-v1:' . $this->to_private_json() ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise projection is required.' ); }
}
