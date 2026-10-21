<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteShape, QuoteTime};

/** Frozen allocation lifecycle. Expiry denies use; it never releases an uncertain allocation. */
final readonly class FixedPromotionBudgetReservation implements \JsonSerializable {
	private function __construct( private string $json ) {}
	public static function hold( FixedPromotionBudgetRequest $request, QuoteTime $at, QuoteTime $exclusive_until ): self {
		return self::from_array( [ 'format_version' => 1, 'request' => $request->private_facts(), 'state' => 'held', 'revision' => 1, 'held_at' => $at->sql(), 'expires_at' => $exclusive_until->sql(), 'changed_at' => $at->sql() ] );
	}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data ); QuoteShape::fields( $data, [ 'format_version', 'request', 'state', 'revision', 'held_at', 'expires_at', 'changed_at' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); } $request = FixedPromotionBudgetRequest::from_array( QuoteShape::object( $data['request'] ) ); $data['request'] = $request->private_facts();
		$state = QuoteShape::choice( $data['state'], [ 'held', 'committed', 'released' ] ); QuoteShape::integer( $data['revision'], 1, 2 );
		foreach ( [ 'held_at', 'expires_at', 'changed_at' ] as $field ) { if ( ! is_string( $data[$field] ) ) { QuoteShape::invalid(); } }
		$held = QuoteTime::parse( $data['held_at'] ); $expires = QuoteTime::parse( $data['expires_at'] ); $changed = QuoteTime::parse( $data['changed_at'] );
		if ( $held->compare( $expires ) >= 0 || $held->compare( $request->quote_created_at() ) < 0 || $held->compare( $request->quote_expires_at() ) >= 0 || $changed->compare( $held ) < 0
			|| ( 'held' === $state && ( 1 !== $data['revision'] || ! $changed->equals( $held ) ) ) || ( 'held' !== $state && 2 !== $data['revision'] ) || ( 'committed' === $state && $changed->compare( $expires ) >= 0 ) ) { QuoteShape::invalid(); }
		return new self( QuoteJson::encode( $data ) );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function request(): FixedPromotionBudgetRequest { return FixedPromotionBudgetRequest::from_array( $this->private_facts()['request'] ); }
	public function state(): string { return $this->private_facts()['state']; }
	public function revision(): int { return $this->private_facts()['revision']; }
	public function held_at(): QuoteTime { return QuoteTime::parse( $this->private_facts()['held_at'] ); }
	public function expires_at(): QuoteTime { return QuoteTime::parse( $this->private_facts()['expires_at'] ); }
	public function changed_at(): QuoteTime { return QuoteTime::parse( $this->private_facts()['changed_at'] ); }
	public function matches( FixedPromotionBudgetRequest $request ): bool { return hash_equals( $this->request()->digest(), $request->digest() ); }
	public function is_held_at( QuoteTime $at ): bool { return 'held' === $this->state() && $at->compare( $this->held_at() ) >= 0 && $at->compare( $this->expires_at() ) < 0; }
	public function commit( QuoteTime $at ): self {
		if ( $at->compare( $this->changed_at() ) < 0 ) { QuoteShape::invalid(); } if ( 'committed' === $this->state() ) { return $this; }
		if ( ! $this->is_held_at( $at ) ) { QuoteShape::invalid(); }
		return self::from_array( array_replace( $this->private_facts(), [ 'state' => 'committed', 'revision' => 2, 'changed_at' => $at->sql() ] ) );
	}
	/** Requires separate current lifecycle authorization; no expiry/cancellation/refund inference. */
	public function release( QuoteTime $at ): self {
		if ( $at->compare( $this->changed_at() ) < 0 ) { QuoteShape::invalid(); } if ( 'released' === $this->state() ) { return $this; }
		if ( 'held' !== $this->state() ) { QuoteShape::invalid(); }
		return self::from_array( array_replace( $this->private_facts(), [ 'state' => 'released', 'revision' => 2, 'changed_at' => $at->sql() ] ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Budget reservations require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Budget reservations cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Budget reservations require strict hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_reservation' => 'redacted' ]; }
}
