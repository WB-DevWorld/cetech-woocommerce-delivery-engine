<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryEconomics;

use CetechDeliveryEngine\Domain\DeliveryEconomics\EconomicAmountFact;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EconomicAmountFactTest extends TestCase {
	public function test_known_zero_and_unavailable_are_different_observations(): void {
		$zero = EconomicAmountFact::known( self::money( '0' ), 'invoice_adapter', 3, self::evidence() );
		$unknown = EconomicAmountFact::unavailable( 'not_recorded' );
		self::assertTrue( $zero->is_known() );
		self::assertFalse( $unknown->is_known() );
		self::assertSame( '0.00', $zero->private_facts()['money']['amount'] );
		self::assertSame( [ 'evidence_digest' => self::evidence(), 'source_code' => 'invoice_adapter', 'source_version' => 3 ], $zero->private_facts()['provenance'] );
		self::assertSame( [ 'state' => 'unavailable', 'reason' => 'not_recorded' ], $unknown->private_facts() );
	}

	public function test_source_and_export_references_cannot_mutate_a_recorded_observation(): void {
		$data = self::known_data();
		$provenance = $data['provenance'];
		$data['provenance'] = &$provenance;
		$fact = EconomicAmountFact::from_array( $data );
		$provenance['source_version'] = 99;
		$export = $fact->private_facts();
		$export['provenance']['source_code'] = 'replacement';
		$export['money']['amount'] = '999.00';
		self::assertSame( self::known_data(), $fact->private_facts() );
	}

	#[DataProvider( 'other_money_units' )]
	public function test_known_observations_refuse_currency_conversion_and_precision_reinterpretation( array $basis ): void {
		$fact = EconomicAmountFact::from_array( self::known_data() );
		$this->expectException( \InvalidArgumentException::class );
		$fact->assert_money_unit( QuoteMoney::from_array( $basis ) );
	}

	public static function other_money_units(): array {
		return [
			'other currency' => [ [ 'amount' => '8.00', 'currency' => 'USD', 'precision' => 2 ] ],
			'other precision' => [ [ 'amount' => '8.000', 'currency' => 'GHS', 'precision' => 3 ] ],
		];
	}

	public function test_same_money_unit_does_not_require_the_customer_charge_or_an_unavailable_amount(): void {
		EconomicAmountFact::from_array( self::known_data() )->assert_money_unit( self::money( '900' ) );
		$unknown = EconomicAmountFact::unavailable( 'source_unavailable' );
		$unknown->assert_money_unit( QuoteMoney::from_array( [ 'amount' => '1', 'currency' => 'USD', 'precision' => 0 ] ) );
		self::assertSame( [ 'state' => 'unavailable', 'reason' => 'source_unavailable' ], $unknown->private_facts() );
	}

	#[DataProvider( 'invalid_observations' )]
	public function test_partial_coerced_or_unsourced_observations_refuse( string $edit ): void {
		$data = self::known_data();
		switch ( $edit ) {
			case 'missing_provenance': unset( $data['provenance'] ); break;
			case 'missing_evidence': unset( $data['provenance']['evidence_digest'] ); break;
			case 'extra_note': $data['provenance']['private_note'] = 'unbounded'; break;
			case 'invalid_digest': $data['provenance']['evidence_digest'] = 'not-a-digest'; break;
			case 'empty_source': $data['provenance']['source_code'] = ''; break;
			case 'coerced_version': $data['provenance']['source_version'] = '1'; break;
			case 'zero_version': $data['provenance']['source_version'] = 0; break;
			case 'overlong_source': $data['provenance']['source_code'] = str_repeat( 's', 65 ); break;
			case 'float': $data['money']['amount'] = 4.25; break;
			case 'scalar_money': $data['money'] = '4.25'; break;
			case 'scalar_provenance': $data['provenance'] = false; break;
			case 'array_source': $data['provenance']['source_code'] = [ 'invoice_adapter' ]; break;
			case 'float_version': $data['provenance']['source_version'] = 3.0; break;
			case 'numeric_evidence': $data['provenance']['evidence_digest'] = 123; break;
			case 'array_state': $data['state'] = [ 'known' ]; break;
			case 'negative': $data['money']['amount'] = '-4.25'; break;
			case 'unknown_state': $data['state'] = 'estimated'; break;
			case 'unavailable_with_zero': $data = [ 'state' => 'unavailable', 'reason' => 'not_recorded', 'money' => self::money( '0' )->facts() ]; break;
			case 'unknown_reason': $data = [ 'state' => 'unavailable', 'reason' => 'assume_free' ]; break;
			case 'array_reason': $data = [ 'state' => 'unavailable', 'reason' => [ 'not_recorded' ] ]; break;
		}
		$this->expectException( \InvalidArgumentException::class );
		EconomicAmountFact::from_array( $data );
	}

	public static function invalid_observations(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'missing_provenance', 'missing_evidence', 'extra_note', 'invalid_digest', 'empty_source', 'coerced_version', 'zero_version', 'overlong_source', 'float', 'scalar_money', 'scalar_provenance', 'array_source', 'float_version', 'numeric_evidence', 'array_state', 'negative', 'unknown_state', 'unavailable_with_zero', 'unknown_reason', 'array_reason' ] );
	}

	#[DataProvider( 'implicit_exports' )]
	public function test_private_observations_refuse_implicit_exports( string $kind ): void {
		$fact = EconomicAmountFact::from_array( self::known_data() );
		$this->expectException( \LogicException::class );
		if ( 'json' === $kind ) { json_encode( $fact, JSON_THROW_ON_ERROR ); }
		else { serialize( $fact ); }
	}
	public static function implicit_exports(): array { return [ [ 'json' ], [ 'php' ] ]; }

	public function test_unserialization_cannot_bypass_validation(): void {
		$class = EconomicAmountFact::class;
		$this->expectException( \LogicException::class );
		unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' );
	}

	public function test_debugging_redacts_money_and_provenance(): void {
		$fact = EconomicAmountFact::from_array( self::known_data() );
		ob_start();
		var_dump( $fact );
		$output = (string) ob_get_clean();
		self::assertStringContainsString( 'redacted', $output );
		foreach ( [ 'invoice_adapter', self::evidence(), '4.25' ] as $private ) { self::assertStringNotContainsString( $private, $output ); }
	}

	private static function money( string $amount ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => 'GHS', 'precision' => 2 ] ); }
	private static function evidence(): string { return hash( 'sha256', 'private-invoice-receipt' ); }
	private static function known_data(): array {
		return [ 'state' => 'known', 'money' => [ 'amount' => '4.25', 'currency' => 'GHS', 'precision' => 2 ],
			'provenance' => [ 'evidence_digest' => self::evidence(), 'source_code' => 'invoice_adapter', 'source_version' => 3 ] ];
	}
}
