<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCurrency;

use CetechDeliveryEngine\Application\DeliveryCurrency\{ConvertOnceCurrencyService, CurrencyConversionProvider};
use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fixture conversion/rounding is provider chosen; these tests certify no real FX adapter. */
final class ConvertOnceCurrencyServiceTest extends TestCase {
	public function test_conversion_delegates_once_and_preserves_provider_amount_rounding_rate_and_time(): void {
		$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
		$source = self::source(); $receipt = $service->convert( $source, self::context() );
		self::assertTrue( $receipt->converted() ); self::assertNull( $receipt->reason_code() ); self::assertSame( 1, $provider->conversions );
		self::assertSame( [ 'amount' => '8.12', 'currency' => 'USD', 'precision' => 2 ], $receipt->target_money()->facts() );
		self::assertSame( '0.65000000000000000001', $receipt->private_facts()['conversion']['applied_rate'] );
		self::assertSame( '2026-10-21 08:32:10.123456', $receipt->private_facts()['conversion']['converted_at'] );
		self::assertSame( $source->facts(), $receipt->private_facts()['source'] );
		self::assertSame( $source->facts(), $receipt->private_facts()['conversion']['source'] );
		self::assertSame( 'GHS', $receipt->private_facts()['context']['base'] );
		self::assertSame( 'USD', $receipt->private_facts()['context']['presentment'] );
		self::assertSame( 'USD', $receipt->private_facts()['context']['charged'] );
		self::assertSame( '12.50', $source->amount() );
	}

	public function test_matching_receipt_reuse_returns_the_same_object_without_another_conversion(): void {
		$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
		$receipt = $service->convert( self::source(), self::context() );
		$provider->reset_counts();
		$reused = $service->convert( self::source(), self::context(), $receipt );
		self::assertSame( $receipt, $reused );
		self::assertSame( 0, $provider->conversions );
	}

	public function test_calls_without_a_receipt_may_convert_again_and_do_not_claim_durable_exactly_once(): void {
		$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
		$first = $service->convert( self::source(), self::context() );
		$second = $service->convert( self::source(), self::context() );
		self::assertSame( 2, $provider->conversions ); self::assertNotSame( $first, $second );
		self::assertSame( $first->request_digest(), $second->request_digest() );
	}

	#[DataProvider( 'request_changes' )]
	public function test_changed_input_with_a_prior_receipt_refuses_without_conversion_fallback( string $change ): void {
		$source = self::source(); $context = self::context();
		$original = ( new ConvertOnceCurrencyService( new CurrencyConversionFixture() ) )->convert( $source, $context );
		switch ( $change ) {
			case 'amount': $source = self::source( '13.00' ); break;
			case 'source_precision': $source = self::source( precision: 3 ); break;
			case 'source_currency': $source = self::source( currency: 'EUR' ); $context['base'] = 'EUR'; break;
			case 'target': $context['presentment'] = 'EUR'; $context['charged'] = 'EUR'; break;
			case 'target_precision': $context['target_precision'] = 3; break;
			case 'material': $context['material_digest'] = hash( 'sha256', 'other cart material' ); break;
			case 'provider_context': $context['provider_context_digest'] = hash( 'sha256', 'changed provider context' ); break;
			case 'provider_code': $context['provider']['code'] = 'other_currency_provider'; break;
			case 'provider_version': $context['provider']['version'] = 3; break;
		}
		$provider = new CurrencyConversionFixture( $context['provider']['code'], $context['provider']['version'] );
		$service = new ConvertOnceCurrencyService( $provider ); $provider->reset_counts();
		$receipt = $service->convert( $source, $context, $original );
		self::assertFalse( $receipt->converted() ); self::assertNull( $receipt->target_money() ); self::assertSame( 'receipt_mismatch', $receipt->reason_code() );
		self::assertSame( 0, $provider->conversions );
	}
	public static function request_changes(): array {
		return array_map( static fn( string $change ): array => [ $change ], [ 'amount', 'source_precision', 'source_currency', 'target', 'target_precision', 'material', 'provider_context', 'provider_code', 'provider_version' ] );
	}

	#[DataProvider( 'unsupported_sources' )]
	public function test_identity_multihop_or_already_converted_money_is_not_converted( string $change, string $reason ): void {
		$source = self::source(); $context = self::context();
		if ( 'identity' === $change ) { $context['presentment'] = 'GHS'; $context['charged'] = 'GHS'; }
		elseif ( 'multihop' === $change ) { $context['charged'] = 'EUR'; }
		else { $source = self::source( currency: 'USD' ); }
		$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider ); $provider->reset_counts();
		$receipt = $service->convert( $source, $context );
		self::assertFalse( $receipt->converted() ); self::assertSame( $reason, $receipt->reason_code() ); self::assertNull( $receipt->target_money() );
		self::assertSame( 0, $provider->conversions ); self::assertSame( 0, $provider->identity_reads );
	}
	public static function unsupported_sources(): array {
		return [ [ 'identity', 'unsupported_currency_chain' ], [ 'multihop', 'unsupported_currency_chain' ], [ 'already_target', 'source_currency_mismatch' ] ];
	}

	public function test_receipt_cannot_masquerade_as_unconverted_source_money(): void {
		$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
		$receipt = $service->convert( self::source(), self::context() );
		$provider->reset_counts();
		try { $service->convert( $receipt, self::context() ); self::fail( 'A receipt must not be accepted as source money.' ); }
		catch ( \TypeError ) { self::assertSame( 0, $provider->conversions ); }
		$refused = $service->convert( $receipt->target_money(), self::context() );
		self::assertFalse( $refused->converted() ); self::assertSame( 'source_currency_mismatch', $refused->reason_code() );
		self::assertSame( 0, $provider->conversions );
	}

	public function test_provider_unavailability_never_invents_zero_and_can_be_reused_without_retry(): void {
		$provider = new CurrencyConversionFixture(); $provider->unavailable = true;
		$service = new ConvertOnceCurrencyService( $provider ); $receipt = $service->convert( self::source(), self::context() );
		self::assertFalse( $receipt->converted() ); self::assertNull( $receipt->target_money() ); self::assertSame( 'conversion_unavailable', $receipt->reason_code() );
		$provider->reset_counts();
		self::assertSame( $receipt, $service->convert( self::source(), self::context(), $receipt ) );
		self::assertSame( 0, $provider->conversions );
	}

	public function test_changed_adapter_code_or_version_refuses_even_a_matching_prior_receipt(): void {
		foreach ( [ 'code', 'version' ] as $change ) {
			$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
			$prior = $service->convert( self::source(), self::context() );
			$provider->replace_identity( 'code' === $change ? 'changed_provider' : 'fixture_currency_provider', 'version' === $change ? 3 : 2 );
			$provider->reset_counts();
			$receipt = $service->convert( self::source(), self::context(), $prior );
			self::assertFalse( $receipt->converted() ); self::assertNull( $receipt->target_money() );
			self::assertSame( 'provider_mismatch', $receipt->reason_code() ); self::assertSame( 0, $provider->conversions );
		}
	}

	public function test_provider_errors_and_identity_change_fail_closed_without_private_error_text(): void {
		foreach ( [ 'throws', 'changes_identity', 'wrong_provider' ] as $mode ) {
			$provider = new CurrencyConversionFixture(); $service = new ConvertOnceCurrencyService( $provider );
			$provider->failure = 'throws' === $mode; $provider->change_identity = 'changes_identity' === $mode;
			$context = self::context(); if ( 'wrong_provider' === $mode ) { $context['provider']['code'] = 'unknown_provider'; }
			$receipt = $service->convert( self::source(), $context );
			self::assertFalse( $receipt->converted() ); self::assertNull( $receipt->target_money() );
			self::assertSame( 'throws' === $mode ? 'provider_unavailable' : 'provider_mismatch', $receipt->reason_code() );
			self::assertStringNotContainsString( 'PRIVATE_PROVIDER_ERROR', $receipt->to_private_json() );
		}
	}

	#[DataProvider( 'private_exports' )]
	public function test_private_service_and_receipt_refuse_generic_export_and_redact_debug( string $carrier, string $kind ): void {
		$service = new ConvertOnceCurrencyService( new CurrencyConversionFixture() );
		$object = 'receipt' === $carrier ? $service->convert( self::source(), self::context() ) : $service;
		if ( 'debug' === $kind ) {
			ob_start(); var_dump( $object ); $output = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $output );
			foreach ( [ 'fixture_currency_provider', '8.12', '0.65000000000000000001', self::context()['material_digest'] ] as $private ) { self::assertStringNotContainsString( $private, $output ); }
			return;
		}
		$this->expectException( \LogicException::class );
		if ( 'json' === $kind ) { json_encode( $object, JSON_THROW_ON_ERROR ); }
		elseif ( 'php' === $kind ) { serialize( $object ); }
		else { $class = get_class( $object ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	}
	public static function private_exports(): array {
		$cases = []; foreach ( [ 'receipt', 'service' ] as $carrier ) { foreach ( [ 'json', 'php', 'unserialize', 'debug' ] as $kind ) { $cases[] = [ $carrier, $kind ]; } }
		return $cases;
	}

	private static function source( string $amount = '12.50', string $currency = 'GHS', int $precision = 2 ): QuoteMoney {
		return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => $precision ] );
	}
	private static function context(): array {
		return [ 'format_version' => 1, 'material_digest' => hash( 'sha256', 'private cart material' ), 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2,
			'provider' => [ 'code' => 'fixture_currency_provider', 'version' => 2 ], 'provider_context_digest' => hash( 'sha256', 'private provider context' ) ];
	}
}

final class CurrencyConversionFixture implements CurrencyConversionProvider {
	public int $identity_reads = 0;
	public int $conversions = 0;
	public bool $failure = false;
	public bool $unavailable = false;
	public bool $change_identity = false;
	public function __construct( private string $identity = 'fixture_currency_provider', private int $revision = 2 ) {}
	public function code(): string { ++$this->identity_reads; return $this->identity; }
	public function version(): int { ++$this->identity_reads; return $this->revision; }
	public function reset_counts(): void { $this->identity_reads = 0; $this->conversions = 0; }
	public function replace_identity( string $code, int $version ): void { $this->identity = $code; $this->revision = $version; }
	public function convert( QuoteMoney $source, array $context, string $request_digest ): array {
		++$this->conversions;
		if ( $this->failure ) { throw new \RuntimeException( 'PRIVATE_PROVIDER_ERROR' ); }
		$data = [ 'format_version' => 1, 'state' => 'converted', 'provider' => [ 'code' => $this->identity, 'version' => $this->revision ], 'request_digest' => $request_digest ];
		if ( $this->unavailable ) { $data['state'] = 'unavailable'; $data['reason'] = 'source_unavailable'; return $data; }
		$data += [ 'source' => $source->facts(), 'target' => [ 'amount' => '8.12', 'currency' => $context['charged'], 'precision' => $context['target_precision'] ],
			'applied_rate' => '0.65000000000000000001', 'converted_at' => '2026-10-21 08:32:10.123456', 'evidence_digest' => hash( 'sha256', 'private external conversion receipt' ) ];
		if ( $this->change_identity ) { $this->identity = 'changed_provider'; }
		return $data;
	}
}
