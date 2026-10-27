<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCurrency;

use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyConversionReceiptTest extends TestCase {
	public function test_full_request_identity_separates_material_and_provider_context(): void {
		$source = self::source(); $context = self::context();
		$identity = CurrencyConversionReceipt::identity( $source, $context );
		self::assertSame( $identity, CurrencyConversionReceipt::identity( $source, array_reverse( $context, true ) ) );
		foreach ( [ 'material_digest', 'provider_context_digest' ] as $field ) {
			$changed = $context; $changed[$field] = hash( 'sha256', 'changed ' . $field );
			self::assertNotSame( $identity, CurrencyConversionReceipt::identity( $source, $changed ) );
		}
	}

	public function test_very_small_positive_rate_is_preserved_as_a_string_without_float_underflow(): void {
		$evidence = self::evidence();
		$evidence['applied_rate'] = '0.' . str_repeat( '0', 80 ) . '1';
		$evidence['target']['amount'] = '0.00';
		$receipt = CurrencyConversionReceipt::from_provider( self::source(), self::context(), 'fixture_currency_provider', 2, $evidence );
		self::assertTrue( $receipt->converted() );
		self::assertSame( $evidence['applied_rate'], $receipt->private_facts()['conversion']['applied_rate'] );
		self::assertSame( '0.00', $receipt->target_money()->amount() );
	}

	#[DataProvider( 'invalid_evidence' )]
	public function test_malformed_or_mismatched_provider_results_never_become_money( string $edit ): void {
		$evidence = self::evidence();
		switch ( $edit ) {
			case 'float_rate': $evidence['applied_rate'] = 0.65; break;
			case 'zero_rate': $evidence['applied_rate'] = '0.0000'; break;
			case 'negative_rate': $evidence['applied_rate'] = '-0.65'; break;
			case 'exponent_rate': $evidence['applied_rate'] = '6.5e-1'; break;
			case 'overlong_rate': $evidence['applied_rate'] = '0.' . str_repeat( '0', 127 ) . '1'; break;
			case 'offset_time': $evidence['converted_at'] = '2026-10-21T10:00:00+02:00'; break;
			case 'invalid_time': $evidence['converted_at'] = '2026-02-30 08:00:00.000000'; break;
			case 'source_amount': $evidence['source']['amount'] = '13.00'; break;
			case 'source_precision': $evidence['source']['precision'] = 3; break;
			case 'target_currency': $evidence['target']['currency'] = 'EUR'; break;
			case 'target_precision': $evidence['target']['precision'] = 3; break;
			case 'request_identity': $evidence['request_digest'] = hash( 'sha256', 'foreign request' ); break;
			case 'provider_version': $evidence['provider']['version'] = 3; break;
			case 'unknown_state': $evidence['state'] = 'pending'; break;
			case 'missing_source': unset( $evidence['source'] ); break;
		}
		$receipt = CurrencyConversionReceipt::from_provider( self::source(), self::context(), 'fixture_currency_provider', 2, $evidence );
		self::assertFalse( $receipt->converted() );
		self::assertNull( $receipt->target_money() );
		self::assertSame( 'evidence_invalid', $receipt->reason_code() );
	}
	public static function invalid_evidence(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'float_rate', 'zero_rate', 'negative_rate', 'exponent_rate', 'overlong_rate', 'offset_time', 'invalid_time', 'source_amount', 'source_precision', 'target_currency', 'target_precision', 'request_identity', 'provider_version', 'unknown_state', 'missing_source' ] );
	}

	public function test_caller_and_provider_references_cannot_mutate_frozen_evidence(): void {
		$context = self::context(); $provider_context = $context['provider_context_digest'];
		$context['provider_context_digest'] = &$provider_context;
		$evidence = self::evidence(); $target = $evidence['target']; $evidence['target'] = &$target;
		$receipt = CurrencyConversionReceipt::from_provider( self::source(), $context, 'fixture_currency_provider', 2, $evidence );
		$before = $receipt->to_private_json();
		$provider_context = hash( 'sha256', 'replacement provider context' ); $target['amount'] = '999.00';
		$export = $receipt->private_facts(); $export['conversion']['applied_rate'] = '999';
		self::assertSame( $before, $receipt->to_private_json() );
		self::assertSame( '8.12', $receipt->target_money()->amount() );
	}

	#[DataProvider( 'invalid_contexts' )]
	public function test_context_requires_material_and_exact_provider_target_facts( string $edit ): void {
		$context = self::context();
		if ( 'missing_material' === $edit ) { unset( $context['material_digest'] ); }
		elseif ( 'coerced_precision' === $edit ) { $context['target_precision'] = '2'; }
		else { $context['provider']['api_secret'] = 'PRIVATE_SECRET'; }
		$this->expectException( \InvalidArgumentException::class );
		CurrencyConversionReceipt::context( $context );
	}
	public static function invalid_contexts(): array { return [ [ 'missing_material' ], [ 'coerced_precision' ], [ 'extra_provider_field' ] ]; }

	private static function source(): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => '12.50', 'currency' => 'GHS', 'precision' => 2 ] ); }
	private static function context(): array {
		return [ 'format_version' => 1, 'material_digest' => hash( 'sha256', 'private cart material' ), 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2,
			'provider' => [ 'code' => 'fixture_currency_provider', 'version' => 2 ], 'provider_context_digest' => hash( 'sha256', 'private provider context' ) ];
	}
	private static function evidence(): array {
		return [ 'format_version' => 1, 'state' => 'converted', 'provider' => [ 'code' => 'fixture_currency_provider', 'version' => 2 ],
			'request_digest' => CurrencyConversionReceipt::identity( self::source(), self::context() ), 'source' => self::source()->facts(),
			'target' => [ 'amount' => '8.12', 'currency' => 'USD', 'precision' => 2 ], 'applied_rate' => '0.65000000000000000001',
			'converted_at' => '2026-10-21 08:32:10.123456', 'evidence_digest' => hash( 'sha256', 'private external conversion receipt' ) ];
	}
}
