<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryCurrency;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape, QuoteTime};

/** Immutable provider conversion evidence, not a native charge or a durable execution ledger. */
final readonly class CurrencyConversionReceipt implements \JsonSerializable {
	private const REASONS = [ 'unsupported_currency_chain', 'source_currency_mismatch', 'provider_mismatch', 'provider_unavailable', 'receipt_mismatch', 'conversion_unavailable', 'evidence_invalid' ];
	private function __construct( private string $json ) {}

	/** Exact caller-supplied material and provider context, without coercion or defaults. */
	public static function context( array $data ): array {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'material_digest', 'base', 'presentment', 'charged', 'target_precision', 'provider', 'provider_context_digest' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); }
		QuoteShape::digest( $data['material_digest'] );
		QuoteShape::digest( $data['provider_context_digest'] );
		foreach ( [ 'base', 'presentment', 'charged' ] as $field ) { QuoteShape::currency( $data[$field] ); }
		QuoteShape::integer( $data['target_precision'], 0, 6 );
		$provider = QuoteShape::object( $data['provider'] );
		QuoteShape::fields( $provider, [ 'code', 'version' ] );
		QuoteShape::machine( $provider['code'] );
		QuoteShape::integer( $provider['version'], 1, 1000000 );
		return $data;
	}

	/** Includes source precision/value, target precision and both independent context identities. */
	public static function identity( QuoteMoney $source, array $context ): string {
		return hash( 'sha256', 'cetech-currency-conversion-request-v1:' . QuoteJson::encode( [ 'source' => $source->facts(), 'context' => self::context( $context ) ] ) );
	}

	/** Only a distinct base -> presentment=charged conversion is supported by this boundary. */
	public static function input_refusal( QuoteMoney $source, array $context ): ?string {
		$context = self::context( $context );
		if ( $source->currency() !== $context['base'] ) { return 'source_currency_mismatch'; }
		if ( $context['base'] === $context['charged'] || $context['presentment'] !== $context['charged'] ) { return 'unsupported_currency_chain'; }
		return null;
	}

	/** The supplied adapter's actual identity must independently agree with context and response. */
	public static function from_provider( QuoteMoney $source, array $context, string $provider_code, int $provider_version, array $evidence ): self {
		$context = self::context( $context );
		$refusal = self::input_refusal( $source, $context );
		if ( null !== $refusal ) { return self::unavailable( $source, $context, $refusal ); }
		try {
			QuoteShape::machine( $provider_code ); QuoteShape::integer( $provider_version, 1, 1000000 );
			if ( $context['provider']['code'] !== $provider_code || $context['provider']['version'] !== $provider_version ) { QuoteShape::invalid(); }
			$evidence = QuoteJson::detach( $evidence );
			$converted = 'converted' === ( $evidence['state'] ?? null );
			QuoteShape::fields( $evidence, $converted
				? [ 'format_version', 'state', 'provider', 'request_digest', 'source', 'target', 'applied_rate', 'converted_at', 'evidence_digest' ]
				: [ 'format_version', 'state', 'provider', 'request_digest', 'reason' ] );
			if ( 1 !== $evidence['format_version'] ) { QuoteShape::invalid(); }
			$provider = QuoteShape::object( $evidence['provider'] );
			QuoteShape::fields( $provider, [ 'code', 'version' ] );
			if ( $provider['code'] !== $provider_code || $provider['version'] !== $provider_version
				|| ! hash_equals( self::identity( $source, $context ), QuoteShape::digest( $evidence['request_digest'] ) ) ) { QuoteShape::invalid(); }
			if ( ! $converted ) {
				QuoteShape::choice( $evidence['state'], [ 'unavailable' ] );
				QuoteShape::choice( $evidence['reason'], [ 'source_unavailable', 'unsupported_context' ] );
				$data = self::base( $source, $context );
				$data['state'] = 'unavailable'; $data['reason_code'] = 'conversion_unavailable'; $data['conversion'] = $evidence;
				return new self( QuoteJson::encode( $data ) );
			}
			$echoed_source = QuoteMoney::from_array( QuoteShape::object( $evidence['source'] ) );
			$target = QuoteMoney::from_array( QuoteShape::object( $evidence['target'] ) );
			if ( ! $source->equals( $echoed_source ) || $target->currency() !== $context['charged'] || $target->precision() !== $context['target_precision'] ) { QuoteShape::invalid(); }
			$rate = $evidence['applied_rate'];
			if ( ! is_string( $rate ) || strlen( $rate ) > 128 || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $rate )
				|| '' === str_replace( [ '0', '.' ], '', $rate ) ) { QuoteShape::invalid(); }
			QuoteTime::parse( $evidence['converted_at'] );
			QuoteShape::digest( $evidence['evidence_digest'] );
			$evidence['source'] = $echoed_source->facts(); $evidence['target'] = $target->facts();
		} catch ( \Throwable ) {
			return self::unavailable( $source, $context, 'evidence_invalid' );
		}
		$data = self::base( $source, $context );
		$data['state'] = 'converted'; $data['reason_code'] = null; $data['conversion'] = $evidence;
		return new self( QuoteJson::encode( $data ) );
	}

	public static function unavailable( QuoteMoney $source, array $context, string $reason ): self {
		QuoteShape::choice( $reason, self::REASONS );
		$data = self::base( $source, $context );
		$data['state'] = 'unavailable'; $data['reason_code'] = $reason;
		return new self( QuoteJson::encode( $data ) );
	}
	/**
	 * Restore captured evidence without invoking an adapter or consulting a current rate.
	 * Structural replay does not authenticate provenance, establish freshness or prevent duplicates.
	 */
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'request_digest', 'source', 'context', 'conversion', 'state', 'reason_code' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); }
		$state = QuoteShape::choice( $data['state'], [ 'converted', 'unavailable' ] );
		$source = QuoteMoney::from_array( QuoteShape::object( $data['source'] ) );
		$context = self::context( QuoteShape::object( $data['context'] ) );
		if ( null === $data['conversion'] ) {
			if ( 'unavailable' !== $state ) { QuoteShape::invalid(); }
			$replayed = self::unavailable( $source, $context, QuoteShape::choice( $data['reason_code'], self::REASONS ) );
		} else {
			$replayed = self::from_provider( $source, $context, $context['provider']['code'], $context['provider']['version'], QuoteShape::object( $data['conversion'] ) );
		}
		if ( ! hash_equals( $replayed->to_private_json(), QuoteJson::encode( $data ) ) ) { QuoteShape::invalid(); }
		return $replayed;
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }

	public function converted(): bool { return 'converted' === $this->private_facts()['state']; }
	public function reason_code(): ?string { return $this->private_facts()['reason_code']; }
	public function target_money(): ?QuoteMoney { return $this->converted() ? QuoteMoney::from_array( $this->private_facts()['conversion']['target'] ) : null; }
	/** Reuse binds a captured snapshot; it does not assert that a provider's current context is unchanged. */
	public function matches_request( QuoteMoney $source, array $context ): bool { return hash_equals( $this->request_digest(), self::identity( $source, $context ) ); }
	public function request_digest(): string { return $this->private_facts()['request_digest']; }
	/** Explicit private export; caller must authorize the material and conversion provenance. */
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-currency-conversion-receipt-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private currency receipts require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private currency receipts cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private currency receipts cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_currency_conversion' => 'redacted' ]; }

	private static function base( QuoteMoney $source, array $context ): array {
		$context = self::context( $context );
		return [ 'format_version' => 1, 'request_digest' => self::identity( $source, $context ), 'source' => $source->facts(), 'context' => $context, 'conversion' => null ];
	}
}
