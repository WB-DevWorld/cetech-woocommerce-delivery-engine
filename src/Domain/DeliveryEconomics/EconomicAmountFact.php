<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryEconomics;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape};

/** A privately sourced observation, never a calculator or an unavailable-to-zero fallback. */
final readonly class EconomicAmountFact implements \JsonSerializable {

	private function __construct( private ?QuoteMoney $money, private ?array $provenance, private ?string $reason ) {}

	public static function known( QuoteMoney $money, string $source_code, int $source_version, string $evidence_digest ): self {
		return self::from_array( [ 'state' => 'known', 'money' => $money->facts(), 'provenance' => [
			'source_code' => $source_code, 'source_version' => $source_version, 'evidence_digest' => $evidence_digest,
		] ] );
	}

	public static function unavailable( string $reason ): self {
		return self::from_array( [ 'state' => 'unavailable', 'reason' => $reason ] );
	}

	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		if ( 'unavailable' === ( $data['state'] ?? null ) ) {
			QuoteShape::fields( $data, [ 'state', 'reason' ] );
			return new self( null, null, QuoteShape::choice( $data['reason'], [ 'not_recorded', 'source_unavailable', 'unsupported_context' ] ) );
		}
		QuoteShape::fields( $data, [ 'state', 'money', 'provenance' ] );
		QuoteShape::choice( $data['state'], [ 'known' ] );
		$money = QuoteMoney::from_array( QuoteShape::object( $data['money'] ) );
		$provenance = QuoteShape::object( $data['provenance'] );
		QuoteShape::fields( $provenance, [ 'source_code', 'source_version', 'evidence_digest' ] );
		QuoteShape::machine( $provenance['source_code'] );
		QuoteShape::integer( $provenance['source_version'], 1, 1000000 );
		QuoteShape::digest( $provenance['evidence_digest'] );
		return new self( $money, $provenance, null );
	}

	public function is_known(): bool { return null !== $this->money; }

	/** Refuses a conversion or precision reinterpretation; it does not compare amounts. */
	public function assert_money_unit( QuoteMoney $basis ): void {
		if ( null !== $this->money && ( $this->money->currency() !== $basis->currency() || $this->money->precision() !== $basis->precision() ) ) {
			QuoteShape::invalid();
		}
	}

	/** Explicit private export; the containing quote and economic purpose require authorization. */
	public function private_facts(): array {
		return null === $this->money
			? [ 'state' => 'unavailable', 'reason' => $this->reason ]
			: [ 'state' => 'known', 'money' => $this->money->facts(), 'provenance' => $this->provenance ];
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Private economics require an explicit authorized projection.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private economics cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private economics cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_economics' => 'redacted' ]; }
}
