<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class CoreTermsTest extends TestCase {
	public function test_unavailable_cost_and_unrecorded_route_do_not_masquerade_as_zero(): void {
		$terms = QuoteFixtures::terms(); $data = $terms->private_facts(); self::assertSame( [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ], $data['groups'][0]['cost'] ); self::assertSame( [ 'state' => 'not_recorded' ], $data['groups'][0]['route'] );
		$data['groups'][0]['cost'] = [ 'state' => 'known', 'amount' => [ 'amount' => '0', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'fixture_cost_v1', 'version' => 1 ] ]; $data['groups'][0]['route'] = [ 'state' => 'known', 'distance' => '0', 'distance_unit' => 'km', 'duration_seconds' => '0', 'provider' => [ 'code' => 'fixture_route_v1', 'version' => 1 ] ]; $known = QuoteTerms::from_array( $data ); self::assertNotSame( $terms->digest(), $known->digest() ); self::assertSame( '0.00', $known->private_facts()['groups'][0]['cost']['amount']['amount'] ); self::assertSame( '0', $known->private_facts()['groups'][0]['route']['distance'] );
	}
	public function test_unknown_promotion_is_distinct_and_cannot_be_checkout_accepted(): void { $data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['promotion'] = [ 'state' => 'unavailable', 'reason' => 'promotion_context_unavailable' ]; $terms = QuoteTerms::from_array( $data ); self::assertFalse( $terms->checkout_acceptable() ); self::assertNotSame( QuoteFixtures::terms()->digest(), $terms->digest() ); self::assertSame( [ 'state' => 'unavailable', 'amount' => null ], $terms->display_money()[0]['promotion'] ); }
	public function test_missing_tax_or_native_money_evidence_is_typed_unknown_not_an_invented_display_charge(): void {
		$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['native_tax_receipt'] = [ 'state' => 'not_recorded' ]; $data['groups'][0]['native_money_receipt'] = [ 'state' => 'unavailable', 'reason' => 'money_context_unavailable' ]; $terms = QuoteTerms::from_array( $data ); self::assertFalse( $terms->checkout_acceptable() ); self::assertNull( $terms->display_money()[0]['rounded_tax'] ); self::assertNull( $terms->display_money()[0]['display_total'] );
	}
	public function test_captured_rounded_tax_is_never_labeled_as_the_display_charge(): void { $terms = QuoteFixtures::terms(); $display = $terms->display_money()[0]; self::assertSame( '0.00', $display['rounded_tax']['amount'] ); self::assertSame( '12.50', $display['display_total']['amount'] ); self::assertSame( [ 'state' => 'none', 'amount' => [ 'amount' => '0.00', 'currency' => 'GHS', 'precision' => 2 ] ], $display['promotion'] ); self::assertArrayNotHasKey( 'cost', $display ); self::assertArrayNotHasKey( 'provider', $display ); self::assertArrayNotHasKey( 'policy_digest', $display ); self::assertArrayNotHasKey( 'route', $display ); }
	public function test_precise_rate_taxes_and_captured_display_rounding_remain_distinct(): void {
		$data = QuoteFixtures::terms()->private_facts(); $group = &$data['groups'][0]; foreach ( [ 'list', 'final' ] as $field ) { $group[$field] = [ 'amount' => '1.000000', 'currency' => 'GHS', 'precision' => 6 ]; } $group['tax'] = [ 'amount' => '0.125000', 'currency' => 'GHS', 'precision' => 6 ]; $group['total'] = [ 'amount' => '1.125000', 'currency' => 'GHS', 'precision' => 6 ]; $group['promotion']['amount'] = [ 'amount' => '0.000000', 'currency' => 'GHS', 'precision' => 6 ]; $group['native_tax_receipt']['exempt'] = false; $group['native_tax_receipt']['tax_status'] = 'taxable'; $group['native_tax_receipt']['rates'] = [ [ 'rate_id' => 7, 'amount' => $group['tax'] ] ]; $group['native_tax_receipt']['rounded_tax']['amount'] = '0.13'; $group['native_money_receipt']['native_total'] = $group['total']; $group['native_money_receipt']['display_total']['amount'] = '1.13'; $terms = QuoteTerms::from_array( $data );
		self::assertTrue( $terms->checkout_acceptable() ); self::assertSame( '0.125000', $terms->private_facts()['groups'][0]['native_tax_receipt']['rates'][0]['amount']['amount'] ); self::assertSame( '0.13', $terms->display_money()[0]['rounded_tax']['amount'] ); self::assertSame( '1.13', $terms->display_money()[0]['display_total']['amount'] );
	}
	#[DataProvider( 'invalid_terms_edits' )]
	public function test_inconsistent_or_undeclared_receipts_refuse( string $field ): void {
		$data = QuoteFixtures::terms()->private_facts(); $group = &$data['groups'][0];
		switch ( $field ) {
			case 'unknown_version': $data['format_version'] = 2; break;
			case 'private_extension': $group['diagnostic'] = 'PRIVATE_QUERY'; break;
			case 'wrong_total': $group['total']['amount'] = '12.51'; break;
			case 'missing_zero': unset( $group['tax']['amount'] ); break;
			case 'inferred_promotion': $group['promotion'] = [ 'state' => 'none' ]; break;
			case 'wrong_discount': $group['promotion']['amount']['amount'] = '1.00'; break;
			case 'negative_cost': $group['cost'] = [ 'state' => 'known', 'amount' => [ 'amount' => '-1', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'fixture_v1', 'version' => 1 ] ]; break;
			case 'unrecorded_route_zero': $group['route'] = [ 'state' => 'not_recorded', 'distance' => '0' ]; break;
			case 'unknown_route_unit': $group['route'] = [ 'state' => 'known', 'distance' => '1', 'distance_unit' => 'mile', 'duration_seconds' => '1', 'provider' => [ 'code' => 'fixture_v1', 'version' => 1 ] ]; break;
			case 'exempt_positive_tax': $group['native_tax_receipt']['rates'] = [ [ 'rate_id' => 1, 'amount' => [ 'amount' => '0.01', 'currency' => 'GHS', 'precision' => 2 ] ] ]; break;
			case 'native_total_mismatch': $group['native_money_receipt']['native_total']['amount'] = '13.00'; break;
			case 'display_precision': $group['native_money_receipt']['display_precision'] = 6; break;
			case 'display_currency': $group['native_money_receipt']['display_total']['currency'] = 'USD'; break;
			case 'raw_label': $group['customer_label'] = '<b>Delivery</b>'; break;
			case 'provider_version': $group['provider']['version'] = '1'; break;
		}
		$this->expectException( \InvalidArgumentException::class ); $this->expectExceptionMessage( 'Invalid delivery quote facts.' ); QuoteTerms::from_array( $data );
	}
	public static function invalid_terms_edits(): array { return array_map( static fn( string $field ): array => [ $field ], [ 'unknown_version', 'private_extension', 'wrong_total', 'missing_zero', 'inferred_promotion', 'wrong_discount', 'negative_cost', 'unrecorded_route_zero', 'unknown_route_unit', 'exempt_positive_tax', 'native_total_mismatch', 'display_precision', 'display_currency', 'raw_label', 'provider_version' ] ); }
	public function test_terms_are_detached_and_whole_body_json_cannot_bypass_private_authority(): void { $data = QuoteFixtures::terms()->private_facts(); $group = $data['groups'][0]; $data['groups'][0] = &$group; $terms = QuoteTerms::from_array( $data ); $digest = $terms->digest(); $group['customer_label'] = 'Another label'; self::assertSame( $digest, $terms->digest() ); $this->expectException( \LogicException::class ); json_encode( $terms, JSON_THROW_ON_ERROR ); }
}
