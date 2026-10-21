<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape};

/** Constant-size account summary. Its held and allocated money are distinct from payments. */
final readonly class FixedPromotionBudgetState implements \JsonSerializable {
	private function __construct( private string $json ) {}
	public static function create( int $site_id, string $account_key, array $funding_source, QuoteMoney $limit ): self {
		$zero = QuoteMoney::from_array( [ 'amount' => '0', 'currency' => $limit->currency(), 'precision' => $limit->precision() ] );
		return self::from_array( [ 'format_version' => 1, 'site_id' => $site_id, 'account_key' => $account_key, 'funding_source' => $funding_source, 'revision' => 1, 'limit' => $limit->facts(), 'held' => $zero->facts(), 'spent' => $zero->facts() ] );
	}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data ); QuoteShape::fields( $data, [ 'format_version', 'site_id', 'account_key', 'funding_source', 'revision', 'limit', 'held', 'spent' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); } QuoteShape::integer( $data['site_id'] ); QuoteShape::digest( $data['account_key'] ); QuoteShape::integer( $data['revision'] );
		$funding = QuoteShape::object( $data['funding_source'] ); QuoteShape::fields( $funding, [ 'code', 'version' ] ); QuoteShape::machine( $funding['code'] ); QuoteShape::integer( $funding['version'], 1, 1000000 );
		$money = []; foreach ( [ 'limit', 'held', 'spent' ] as $field ) { $money[$field] = QuoteMoney::from_array( QuoteShape::object( $data[$field] ) ); $data[$field] = $money[$field]->facts(); }
		if ( $money['held']->add( $money['spent'] )->compare( $money['limit'] ) > 0 ) { QuoteShape::invalid(); }
		return new self( QuoteJson::encode( $data ) );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function site_id(): int { return $this->private_facts()['site_id']; }
	public function account_key(): string { return $this->private_facts()['account_key']; }
	public function funding_source(): array { return $this->private_facts()['funding_source']; }
	public function revision(): int { return $this->private_facts()['revision']; }
	public function limit(): QuoteMoney { return QuoteMoney::from_array( $this->private_facts()['limit'] ); }
	public function held(): QuoteMoney { return QuoteMoney::from_array( $this->private_facts()['held'] ); }
	public function spent(): QuoteMoney { return QuoteMoney::from_array( $this->private_facts()['spent'] ); }
	public function remaining(): QuoteMoney { return $this->limit()->subtract( $this->held()->add( $this->spent() ) ); }
	public function reserve( QuoteMoney $amount ): self { if ( $amount->compare( $this->remaining() ) > 0 ) { QuoteShape::invalid(); } return $this->next( $this->held()->add( $amount ), $this->spent() ); }
	public function commit( QuoteMoney $amount ): self { return $this->next( $this->held()->subtract( $amount ), $this->spent()->add( $amount ) ); }
	public function release( QuoteMoney $amount ): self { return $this->next( $this->held()->subtract( $amount ), $this->spent() ); }
	private function next( QuoteMoney $held, QuoteMoney $spent ): self {
		if ( PHP_INT_MAX === $this->revision() ) { QuoteShape::invalid(); }
		return self::from_array( array_replace( $this->private_facts(), [ 'revision' => $this->revision() + 1, 'held' => $held->facts(), 'spent' => $spent->facts() ] ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Budget accounts require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Budget accounts cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Budget accounts require strict hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_state' => 'redacted' ]; }
}
