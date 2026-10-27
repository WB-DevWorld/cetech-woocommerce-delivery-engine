<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryCurrency;

use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteShape};

/**
 * Trusted internal source/context/receipt only; digest equality grants no authentication or freshness.
 * Receipt-scoped reuse only; calls without a receipt are not durable/concurrent exactly-once work.
 */
final readonly class ConvertOnceCurrencyService implements \JsonSerializable {
	private ?string $provider_code;
	private ?int $provider_version;
	public function __construct( private CurrencyConversionProvider $provider ) {
		try {
			$code = QuoteShape::machine( $provider->code() );
			$version = QuoteShape::integer( $provider->version(), 1, 1000000 );
		} catch ( \Throwable ) { $code = null; $version = null; }
		$this->provider_code = $code; $this->provider_version = $version;
	}

	/**
	 * Caller supplies trusted unconverted base money/context. Plain QuoteMoney cannot detect
	 * a caller relabelling converted money as a new base. Mismatched receipts never trigger fallback.
	 */
	public function convert( QuoteMoney $source, array $context, ?CurrencyConversionReceipt $prior = null ): CurrencyConversionReceipt {
		$context = CurrencyConversionReceipt::context( $context );
		$refusal = CurrencyConversionReceipt::input_refusal( $source, $context );
		if ( null !== $refusal ) { return CurrencyConversionReceipt::unavailable( $source, $context, $refusal ); }
		if ( null === $this->provider_code || null === $this->provider_version ) { return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_unavailable' ); }
		if ( $context['provider']['code'] !== $this->provider_code || $context['provider']['version'] !== $this->provider_version ) {
			return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_mismatch' );
		}
		try {
			if ( $this->provider->code() !== $this->provider_code || $this->provider->version() !== $this->provider_version ) {
				return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_mismatch' );
			}
		} catch ( \Throwable ) { return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_unavailable' ); }
		if ( null !== $prior ) {
			return $prior->matches_request( $source, $context ) ? $prior : CurrencyConversionReceipt::unavailable( $source, $context, 'receipt_mismatch' );
		}
		try {
			$evidence = $this->provider->convert( $source, $context, CurrencyConversionReceipt::identity( $source, $context ) );
			if ( $this->provider->code() !== $this->provider_code || $this->provider->version() !== $this->provider_version ) {
				return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_mismatch' );
			}
		} catch ( \Throwable ) { return CurrencyConversionReceipt::unavailable( $source, $context, 'provider_unavailable' ); }
		return CurrencyConversionReceipt::from_provider( $source, $context, $this->provider_code, $this->provider_version, $evidence );
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Currency services cannot expose their private provider.' ); }
	public function __serialize(): array { throw new \LogicException( 'Currency services cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Currency services cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_currency_conversion' => 'redacted' ]; }
}
