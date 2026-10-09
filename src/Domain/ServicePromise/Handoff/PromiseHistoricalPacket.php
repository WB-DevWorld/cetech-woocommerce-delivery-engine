<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson, PromiseResult, PromiseShape, PublicPromiseView};

/** Immutable captured history; decoding proves structure/linkage, never fresh admission or calculation. */
final readonly class PromiseHistoricalPacket implements \JsonSerializable {
	private function __construct( private string $json, private array $input, private array $result ) {}
	public static function capture( PromiseResult $result, string $customer_text ): self {
		$input = $result->input(); $views = []; foreach ( $input->policy()->endpoint_terminal_component_ids() as $id ) { $views[] = [ 'terminal_component_id' => $id, 'fields' => PublicPromiseView::from_result( $result, $id )->fields() ]; }
		return self::from_array( [ 'format' => 1, 'input_json' => $input->to_private_json(), 'input_digest' => $input->digest(), 'result_json' => $result->to_private_json(), 'result_digest' => $result->digest(), 'public_views' => $views, 'customer_text' => $customer_text ] );
	}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data ); PromiseShape::fields( $data, [ 'format', 'input_json', 'input_digest', 'result_json', 'result_digest', 'public_views', 'customer_text' ] ); PromiseShape::integer( $data['format'], 1, 1 );
		if ( ! is_string( $data['input_json'] ) || ! is_string( $data['result_json'] ) ) { PromiseShape::invalid(); }
		$input_digest = PromiseShape::digest( $data['input_digest'] ); $result_digest = PromiseShape::digest( $data['result_digest'] );
		if ( ! hash_equals( $input_digest, hash( 'sha256', 'cetech-service-promise-input-v1:' . $data['input_json'] ) ) || ! hash_equals( $result_digest, hash( 'sha256', 'cetech-service-promise-result-v1:' . $data['result_json'] ) ) ) { PromiseShape::invalid(); }
		$input = PromiseHistoricalCodec::input( $data['input_json'] ); $result = PromiseHistoricalCodec::result( $data['result_json'], $input, $input_digest ); $seen = []; $views = PromiseShape::list( $data['public_views'], 1, 16 );
		foreach ( $views as $view ) {
			$view = PromiseShape::object( $view ); PromiseShape::fields( $view, [ 'terminal_component_id', 'fields' ] ); $id = PromiseShape::id( $view['terminal_component_id'] ); if ( isset( $seen[$id] ) || ! in_array( $id, $input['policy']['endpoint_terminal_component_ids'], true ) ) { PromiseShape::invalid(); } $seen[$id] = true;
			$expected = [ 'format_version' => 1, 'service_label' => $input['policy']['service']['customer_label'], 'state' => $result['state'], 'display_timezone' => $input['policy']['promise_timezone'], 'reason_codes' => $result['reason_codes'] ]; $found = null === $result['body'];
			foreach ( $result['body']['terminal_windows'] ?? [] as $window ) { if ( $id !== $window['component_id'] ) { continue; } unset( $window['component_id'], $window['calendar_refs'] ); $expected += $window; $found = true; }
			if ( 'relative_window' === $result['state'] ) { $expected['relative_explanation'] = 'after_payment_confirmation'; }
			if ( ! $found || PromiseJson::encode( $expected ) !== PromiseJson::encode( PromiseShape::object( $view['fields'] ) ) ) { PromiseShape::invalid(); }
		}
		if ( array_keys( $seen ) !== $input['policy']['endpoint_terminal_component_ids'] ) { PromiseShape::invalid(); } PromiseShape::text( $data['customer_text'], 2048 );
		return new self( PromiseJson::encode( $data ), $input, $result );
	}
	public static function from_json( string $json ): self { $packet = self::from_array( PromiseJson::decode( $json ) ); if ( $packet->to_private_json() !== $json ) { PromiseShape::invalid(); } return $packet; }
	public function private_facts(): array { return PromiseJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-historical-packet-v1:' . $this->json ); }
	public function input_facts(): array { return $this->input; }
	public function result_facts(): array { return $this->result; }
	public function customer_text(): string { return $this->private_facts()['customer_text']; }
	public function public_facts(): array { $data = $this->private_facts(); return [ 'views' => array_map( static fn( array $view ): array => $view['fields'], $data['public_views'] ), 'customer_text' => $data['customer_text'] ]; }
	/** Exclusive captured boundaries. The caller supplies its trusted native event instant. */
	public function feasibility_at( QuoteTime $at ): bool {
		$time = $at->epoch_microseconds(); $captured = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $this->input['evaluated_at'] )->epoch_microseconds(); if ( $time < $captured ) { return false; }
		$ends = [ $this->input['anchor']['quote_expires_at'] ]; if ( null === $this->result['body'] ) { if ( null !== $this->input['policy']['effective_until'] ) { $ends[] = $this->input['policy']['effective_until']; } foreach ( $ends as $end ) { if ( $time >= \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $end )->epoch_microseconds() ) { return false; } } return true; } foreach ( [ $this->input['anchor']['accept_until'] ?? null, $this->input['policy']['effective_until'], $this->input['capacity']['valid_until'] ?? null ] as $end ) { if ( null !== $end ) { $ends[] = $end; } }
		foreach ( $ends as $end ) { if ( $time >= \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $end )->epoch_microseconds() ) { return false; } }
		if ( 'absolute_window' === $this->result['state'] ) { foreach ( $this->result['body']['terminal_windows'] as $window ) { if ( in_array( $window['component_id'], $this->input['policy']['endpoint_terminal_component_ids'], true ) && $time > \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $window['until'] )->epoch_microseconds() ) { return false; } } }
		return true;
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An authorized frozen promise projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private promise packets cannot be serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private promise packets require strict history decoding.' ); }
}
