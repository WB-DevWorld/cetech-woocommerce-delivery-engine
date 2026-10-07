<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
require_once __DIR__ . '/../Runtime/InMemoryQuoteRateCardRepository.php';

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyFixedBaseQuoteProvider;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteEngineCapture;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderRegistry;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyQuoteProviderTest extends TestCase {
	public static function charges(): array { return [ 'shipment' => [ 'fixed_per_shipment', '12.50', '12.5000' ], 'item' => [ 'fixed_per_item', '12.50', '25.0000' ], 'zero' => [ 'fixed_per_item', '0', '0.0000' ] ]; }
	#[DataProvider('charges')]
	public function test_group_amount_is_exact_retained_engine_result( string $type, string $base, string $expected ): void {
		$repository = new InMemoryQuoteRateCardRepository( [ F::card( $type, $base ) ] );
		$legacy = ( new RateQuoteEngine( $repository ) )->quote( new RateQuoteRequest( 20, 50, 2, new CurrencyCode( 'GHS' ), 10, null, null, null, null, 40 ) );
		$captured = ( new LegacyQuoteEngineCapture() )->capture( F::context(), $repository );
		self::assertTrue( $legacy->success ); self::assertSame( $expected, $legacy->amount?->amount() ); self::assertSame( $expected, $captured[0]['expected']->amount() ); self::assertSame( $type, $captured[0]['charge_type'] ); self::assertSame( 1, $captured[0]['rate_card_id'] );
	}
	public function test_integer_quantities_of_every_member_are_added_for_one_group(): void {
		$data = F::context()->private_facts(); $second = $data['lines'][0]; $second['line_key'] = 'line_two'; $second['product_id'] = 11; $second['quantity'] = '3'; $data['lines'][] = $second; $data['groups'][0]['line_keys'][] = 'line_two';
		$result = ( new LegacyQuoteEngineCapture() )->capture( QuoteContext::from_array( $data ), new InMemoryQuoteRateCardRepository( [ F::card( 'fixed_per_item', '2.25' ) ] ) );
		self::assertSame( '11.2500', $result[0]['expected']->amount() );
	}
	public function test_positive_scoped_card_cannot_match_an_absent_request_dimension(): void {
		$repository = new InMemoryQuoteRateCardRepository( [ F::card( 'fixed_per_shipment', '12.50', [ 'supplier_id' => 8 ] ) ] );
		self::assertTrue( ( new RateQuoteEngine( $repository ) )->quote( new RateQuoteRequest( 20, 50, 2, new CurrencyCode( 'GHS' ) ) )->success );
		$this->expectException( \InvalidArgumentException::class ); ( new LegacyQuoteEngineCapture() )->capture( F::context(), $repository );
	}
	public function test_unknown_price_does_not_become_a_retained_free_price(): void {
		$this->expectException( \InvalidArgumentException::class ); ( new LegacyQuoteEngineCapture() )->capture( F::context(), new InMemoryQuoteRateCardRepository( [ F::card( 'fixed_per_shipment', '' ) ] ) );
	}
	public function test_fractional_group_member_is_refused_instead_of_integer_coercion(): void {
		$data = F::context()->private_facts(); $data['lines'][0]['quantity'] = '1.5';
		$this->expectException( \InvalidArgumentException::class ); ( new LegacyQuoteEngineCapture() )->capture( QuoteContext::from_array( $data ), new InMemoryQuoteRateCardRepository( [ F::card() ] ) );
	}
	public function test_limit_plus_one_candidate_view_never_returns_a_partial_winner(): void {
		$cards = []; for ( $id = 1; $id <= 1001; ++$id ) { $cards[] = F::card( overrides: [ 'id' => $id ] ); }
		$this->expectException( \InvalidArgumentException::class ); ( new LegacyQuoteEngineCapture() )->capture( F::context(), new InMemoryQuoteRateCardRepository( $cards ) );
	}
	public function test_provider_is_explicit_and_preserves_unavailable_cost_and_native_receipts(): void {
		$provider = new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), F::terms() ); $registry = new QuoteProviderRegistry( [ $provider ] );
		$terms = $registry->capture( $provider->code(), 1, $provider->profile(), 1, F::context() );
		self::assertSame( F::terms()->to_private_json(), $terms->to_private_json() ); self::assertSame( [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ], $terms->private_facts()['groups'][0]['cost'] ); self::assertSame( 'none', $terms->private_facts()['groups'][0]['promotion']['state'] );
		$id = QuoteId::generate(); $header = QuoteHeader::issue( $id, QuoteFixtures::owner(), F::context(), $terms, QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'issue' ), 'accept' => QuoteFixtures::digest( 'accept' ), 'invalidate' => QuoteFixtures::digest( 'invalidate' ) ], $provider->profile(), 1, QuoteFixtures::reference( $id ) );
		self::assertTrue( DeliveryQuote::issue( $header, F::context(), $terms )->usable_at( QuoteFixtures::time() ) );
	}
	public function test_default_registry_has_no_native_provider_registration(): void {
		$this->expectException( \InvalidArgumentException::class ); ( new QuoteProviderRegistry() )->get( LegacyFixedBaseQuoteProvider::CODE, 1, LegacyFixedBaseQuoteProvider::CODE, 1 );
	}
	public function test_provider_refuses_changed_context_instead_of_recapturing(): void {
		$provider = new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), F::terms() ); $data = F::context()->private_facts(); $data['selection_digest'] = QuoteFixtures::digest( 'changed' );
		$this->expectException( \InvalidArgumentException::class ); $provider->capture( QuoteContext::from_array( $data ) );
	}
	public function test_unknown_tax_receipt_cannot_claim_native_price_capture(): void {
		$data = F::terms()->private_facts(); $data['groups'][0]['native_tax_receipt'] = [ 'state' => 'unavailable', 'reason' => 'tax_context_unavailable' ];
		$this->expectException( \InvalidArgumentException::class ); new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), QuoteTerms::from_array( $data ) );
	}
	public function test_native_raw_tax_and_display_money_remain_distinct(): void {
		$data = F::terms()->private_facts(); $group = &$data['groups'][0];
		$money = static fn( string $value, int $scale = 6 ): array => [ 'amount' => $value, 'currency' => 'GHS', 'precision' => $scale ];
		$group['list'] = $group['final'] = $money( '12.500000' ); $group['tax'] = $money( '0.012345' ); $group['total'] = $money( '12.512345' );
		$group['promotion']['amount'] = $money( '0.000000' );
		$group['native_tax_receipt']['exempt'] = false; $group['native_tax_receipt']['tax_status'] = 'taxable'; $group['native_tax_receipt']['rates'] = [ [ 'rate_id' => 17, 'amount' => $money( '0.012345' ) ] ]; $group['native_tax_receipt']['rounded_tax'] = $money( '0.01', 2 );
		$group['native_money_receipt']['native_total'] = $money( '12.512345' ); $group['native_money_receipt']['display_total'] = $money( '12.51', 2 ); unset( $group );
		$terms = ( new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), QuoteTerms::from_array( $data ) ) )->capture( F::context() );
		$captured = $terms->private_facts()['groups'][0];
		self::assertSame( '0.012345', $captured['tax']['amount'] ); self::assertSame( '0.01', $captured['native_tax_receipt']['rounded_tax']['amount'] ); self::assertSame( '12.512345', $captured['total']['amount'] ); self::assertSame( '12.51', $captured['native_money_receipt']['display_total']['amount'] );
	}
	public function test_captured_explicit_zero_is_usable_but_cost_stays_unavailable(): void {
		$data = F::terms()->private_facts(); $zero = [ 'amount' => '0.00', 'currency' => 'GHS', 'precision' => 2 ];
		foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { $data['groups'][0][$field] = $zero; }
		$data['groups'][0]['native_money_receipt']['native_total'] = $zero; $data['groups'][0]['native_money_receipt']['display_total'] = $zero;
		$terms = ( new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), QuoteTerms::from_array( $data ) ) )->capture( F::context() );
		self::assertSame( '0.00', $terms->private_facts()['groups'][0]['final']['amount'] ); self::assertSame( 'unavailable', $terms->private_facts()['groups'][0]['cost']['state'] ); self::assertTrue( $terms->checkout_acceptable() );
	}
	public function test_unproved_conversion_refuses_retention(): void {
		$data = F::context()->private_facts(); $data['currency']['presentment'] = 'USD';
		$this->expectException( \InvalidArgumentException::class ); new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), QuoteContext::from_array( $data ), F::terms() );
	}
	public function test_generic_serialization_does_not_disclose_prepared_private_facts(): void {
		$provider = new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), F::context(), F::terms() );
		try { json_encode( $provider, JSON_THROW_ON_ERROR ); self::fail( 'Private provider facts were serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		$this->expectException( \LogicException::class ); serialize( $provider );
	}
}
