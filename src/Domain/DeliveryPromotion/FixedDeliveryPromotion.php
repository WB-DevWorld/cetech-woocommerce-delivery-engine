<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape};

/** One explicit fixed discount, separate from base rates and observed economic subsidy. */
final readonly class FixedDeliveryPromotion implements \JsonSerializable {
	public const FORMAT = 1;
	private function __construct( private string $json ) {}

	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'code', 'version', 'discount', 'funding_source' ] );
		if ( self::FORMAT !== $data['format_version'] ) { QuoteShape::invalid(); }
		QuoteShape::machine( $data['code'] );
		QuoteShape::integer( $data['version'], 1, 1000000 );
		$data['discount'] = QuoteMoney::from_array( QuoteShape::object( $data['discount'] ) )->facts();
		$funding = QuoteShape::object( $data['funding_source'] );
		QuoteShape::fields( $funding, [ 'code', 'version' ] );
		QuoteShape::machine( $funding['code'] );
		QuoteShape::integer( $funding['version'], 1, 1000000 );
		return new self( QuoteJson::encode( $data ) );
	}

	/** Decode the frozen rule body, without looking up current merchant configuration. */
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }

	public function code(): string { return $this->private_facts()['code']; }
	public function version(): int { return $this->private_facts()['version']; }
	public function discount(): QuoteMoney { return QuoteMoney::from_array( $this->private_facts()['discount'] ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-fixed-delivery-promotion-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private promotion rules require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private promotion rules cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private promotion rules cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion' => 'redacted' ]; }
}
