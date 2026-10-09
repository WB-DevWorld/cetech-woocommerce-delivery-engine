<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** New internal profile's one issue/evaluation capture and fixed 300s expiry; no native event authority. */
final readonly class PromiseAnchor implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		$kind = PromiseShape::choice( $data['kind'] ?? null, [ 'order_accepted', 'checkout_capture', 'payment_confirmed' ] );
		$extra = match ( $kind ) { 'order_accepted' => 'accept_until', 'checkout_capture' => 'capture_at', 'payment_confirmed' => 'awaited_event' };
		PromiseShape::fields( $data, [ 'format_version', 'kind', 'evaluated_at', 'quote_expires_at', $extra ] );
		PromiseShape::integer( $data['format_version'], 1, 1 );
		$evaluated = PromiseShape::instant( $data['evaluated_at'] );
		$expires = PromiseShape::instant( $data['quote_expires_at'] );
		$ttl = $expires->epoch_microseconds() - $evaluated->epoch_microseconds();
		if ( 300000000 !== $ttl ) { PromiseShape::invalid(); }
		$out = [ 'format_version' => 1, 'kind' => $kind, 'evaluated_at' => $evaluated->sql(), 'quote_expires_at' => $expires->sql() ];
		if ( 'order_accepted' === $kind ) {
			$until = PromiseShape::instant( $data['accept_until'] );
			if ( $until->compare( $evaluated ) <= 0 || $until->compare( $expires ) > 0 ) { PromiseShape::invalid(); }
			$out['accept_until'] = $until->sql();
		} elseif ( 'checkout_capture' === $kind ) {
			$captured = PromiseShape::instant( $data['capture_at'] );
			if ( ! $captured->equals( $evaluated ) ) { PromiseShape::invalid(); }
			$out['capture_at'] = $captured->sql();
		} else {
			$out['awaited_event'] = PromiseShape::choice( $data['awaited_event'], [ 'woocommerce_payment_confirmed' ] );
		}
		return new self( $out );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function kind(): string { return $this->data['kind']; }
	public function evaluated_at(): \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime { return PromiseShape::instant( $this->data['evaluated_at'] ); }
	public function quote_expires_at(): \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime { return PromiseShape::instant( $this->data['quote_expires_at'] ); }
	public function accept_until(): ?\CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime { return isset( $this->data['accept_until'] ) ? PromiseShape::instant( $this->data['accept_until'] ) : null; }
	public function private_facts(): array { return $this->data; }
	public function to_private_json(): string { return PromiseJson::encode( $this->data ); }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-anchor-v1:' . $this->to_private_json() ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise projection is required.' ); }
}
