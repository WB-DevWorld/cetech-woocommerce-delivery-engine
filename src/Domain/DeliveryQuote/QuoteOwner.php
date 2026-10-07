<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Server-derived, purpose-separated hashes; no cookie, nonce or address copy. */
final readonly class QuoteOwner implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		QuoteShape::fields( $data, [ 'site_id', 'kind', 'principal_hash', 'session_hash', 'key_epoch' ] );
		return new self( [ 'site_id' => QuoteShape::integer( $data['site_id'] ), 'kind' => QuoteShape::choice( $data['kind'], [ 'customer', 'guest' ] ), 'principal_hash' => QuoteShape::digest( $data['principal_hash'] ), 'session_hash' => QuoteShape::digest( $data['session_hash'] ), 'key_epoch' => QuoteShape::machine( $data['key_epoch'] ) ] );
	}
	public function site_id(): int { return $this->data['site_id']; }
	public function kind(): string { return $this->data['kind']; }
	public function key_epoch(): string { return $this->data['key_epoch']; }
	public function facts(): array { return $this->data; }
	public function digest(): string { return hash( 'sha256', 'cetech-quote-owner-v1:' . QuoteJson::encode( $this->data ) ); }
	public function equals( self $other ): bool { return hash_equals( QuoteJson::encode( $this->data ), QuoteJson::encode( $other->data ) ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote projection is required.' ); }
}
