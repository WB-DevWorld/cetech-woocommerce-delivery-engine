<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteShape};

/** Finite history outcome; unsupported/malformed input never exposes a partial private packet. */
final readonly class QuoteCommercialEvidenceReadResult implements \JsonSerializable {
	private function __construct( private string $state, private ?QuoteCommercialEvidencePacket $value = null ) {}
	/** An optional independently retained digest checks integrity, never self-authenticates the source. */
	public static function read( string $json, ?string $expected_digest = null ): self {
		try {
			$data = QuoteJson::decode( $json, QuoteCommercialEvidencePacket::MAX_BYTES );
			if ( self::future_versions( $data ) ) { return new self( 'unsupported' ); }
			$packet = QuoteCommercialEvidencePacket::from_array( $data );
			if ( null !== $expected_digest && ! hash_equals( QuoteShape::digest( $expected_digest ), $packet->digest() ) ) { return new self( 'malformed' ); }
			return new self( 'readable', $packet );
		} catch ( \Throwable ) { return new self( 'malformed' ); }
	}
	public function status(): string { return $this->state; }
	public function packet(): ?QuoteCommercialEvidencePacket { return $this->value; }
	public function jsonSerialize(): never { throw new \LogicException( 'Commercial history reads require explicit private access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Commercial history reads cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Commercial history reads require strict decoding.' ); }
	public function __debugInfo(): array { return [ 'commercial_history_status' => $this->state, 'private_commercial_evidence' => 'redacted' ]; }

	/** Inspect only recognized version locations after the shared byte/depth/node/duplicate budgets. */
	private static function future_versions( array $data ): bool {
		if ( self::future( $data, 'format_version', 1 ) ) { return true; }
		foreach ( [ 'header', 'context', 'terms' ] as $field ) { if ( self::future( $data[$field] ?? null, 'format_version', 2 ) ) { return true; } }
		if ( self::future( $data['economics'] ?? null, 'format_version', 1 ) || self::future( $data['context']['promise_capture'] ?? null, 'format', 1 ) ) { return true; }
		foreach ( is_array( $data['terms']['promise_packets'] ?? null ) ? $data['terms']['promise_packets'] : [] as $group ) {
			if ( self::future( is_array( $group ) ? $group['packet'] ?? null : null, 'format', 1 ) ) { return true; }
		}
		foreach ( is_array( $data['groups'] ?? null ) ? $data['groups'] : [] as $group ) {
			if ( ! is_array( $group ) ) { continue; }
			foreach ( [ $group['promotion']['rule'] ?? null, $group['promotion']['plan'] ?? null, $group['promotion']['plan']['evidence'] ?? null,
				$group['currency']['receipt'] ?? null, $group['currency']['receipt']['context'] ?? null, $group['currency']['receipt']['conversion'] ?? null ] as $facts ) {
				if ( self::future( $facts, 'format_version', 1 ) ) { return true; }
			}
		}
		return false;
	}
	private static function future( mixed $facts, string $field, int $maximum ): bool { return is_array( $facts ) && is_int( $facts[$field] ?? null ) && $facts[$field] > $maximum; }
}
