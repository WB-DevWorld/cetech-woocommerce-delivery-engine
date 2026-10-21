<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryEconomics\{EconomicAmountFact, QuoteEconomicsFacts};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Historical structural replay only: no provider, live policy, native charge or freshness proof. */
final class CommercialCarrierReplayTest extends TestCase {
	public function test_frozen_rule_economics_plan_and_conversion_round_trip_without_current_sources(): void {
		$terms = QuoteFixtures::terms(); $rule = self::rule(); $economics = self::economics( $terms );
		$plan = self::plan( $terms, $rule ); $conversion = self::conversion();
		$restored_rule = FixedDeliveryPromotion::from_json( $rule->to_private_json() );
		$restored_economics = QuoteEconomicsFacts::from_json( $terms, $economics->to_private_json() );
		$restored_plan = DeliveryPromotionPlan::from_json( $terms, $restored_rule, $plan->to_private_json() );
		$restored_conversion = CurrencyConversionReceipt::from_json( $conversion->to_private_json() );
		foreach ( [ [ $rule, $restored_rule ], [ $economics, $restored_economics ], [ $plan, $restored_plan ], [ $conversion, $restored_conversion ] ] as [ $original, $restored ] ) {
			self::assertSame( $original->to_private_json(), $restored->to_private_json() );
			self::assertSame( $original->digest(), $restored->digest() );
		}
		self::assertSame( '9.50', $restored_plan->preview_money()['final']['amount'] );
		self::assertSame( '8.12', $restored_conversion->target_money()->amount() );
		self::assertSame( '0.65000000000000000001', $restored_conversion->private_facts()['conversion']['applied_rate'] );
		self::assertSame( '0.00', $restored_economics->private_facts()['groups'][0]['actual_internal_cost']['money']['amount'] );
		self::assertSame( 'unavailable', $restored_economics->private_facts()['groups'][0]['estimated_internal_cost']['state'] );
	}

	public function test_economics_and_plan_replay_bind_the_complete_v2_promise_terms(): void {
		$terms = PromiseHandoffFixture::terms(); $rule = self::rule();
		$economics = self::economics( $terms ); $plan = self::plan( $terms, $rule );
		self::assertSame( $economics->digest(), QuoteEconomicsFacts::from_json( $terms, $economics->to_private_json() )->digest() );
		self::assertSame( $plan->digest(), DeliveryPromotionPlan::from_json( $terms, $rule, $plan->to_private_json() )->digest() );
		foreach ( [ static fn() => QuoteEconomicsFacts::from_json( $terms->base_terms(), $economics->to_private_json() ), static fn() => DeliveryPromotionPlan::from_json( $terms->base_terms(), $rule, $plan->to_private_json() ) ] as $read ) {
			try { $read(); self::fail( 'Base-only terms must not replace full frozen promise terms.' ); }
			catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}

	public function test_every_representable_generic_promotion_refusal_retains_its_exact_historical_reason(): void {
		$terms = QuoteFixtures::terms(); $rule = self::rule(); $key = self::key( $terms );
		foreach ( [ 'component_unavailable', 'promotion_unavailable', 'promotion_already_applied', 'money_unit_mismatch', 'discount_exceeds_list', 'evidence_unavailable', 'evidence_invalid', 'eligibility_unavailable', 'ineligible', 'budget_unavailable', 'budget_exhausted' ] as $reason ) {
			$refused = DeliveryPromotionPlan::refused( $terms, $key, $rule, $reason );
			$restored = DeliveryPromotionPlan::from_json( $terms, $rule, $refused->to_private_json() );
			self::assertSame( $refused->digest(), $restored->digest() );
			self::assertFalse( $restored->planned() ); self::assertNull( $restored->preview_money() );
		}
	}

	public function test_declared_negative_promotion_evidence_stays_negative_on_replay(): void {
		$terms = QuoteFixtures::terms(); $rule = self::rule(); $evidence = self::promotion_evidence( $terms, $rule );
		$evidence['budget'] = [ 'state' => 'exhausted', 'evidence_digest' => hash( 'sha256', 'exhausted frozen budget' ) ];
		$plan = DeliveryPromotionPlan::from_evidence( $terms, self::key( $terms ), $rule, 'frozen_promotion_provider', 2, $evidence );
		$restored = DeliveryPromotionPlan::from_json( $terms, $rule, $plan->to_private_json() );
		self::assertSame( $plan->to_private_json(), $restored->to_private_json() );
		self::assertSame( 'budget_exhausted', $restored->reason_code() ); self::assertNull( $restored->preview_money() );
	}

	#[DataProvider( 'invalid_plans' )]
	public function test_altered_plan_output_or_binding_cannot_gain_planning_authority( string $edit ): void {
		$terms = QuoteFixtures::terms(); $rule = self::rule(); $data = self::plan( $terms, $rule )->private_facts();
		switch ( $edit ) {
			case 'money': $data['money']['final']['amount'] = '1.00'; break;
			case 'funding': $data['funding_source']['code'] = 'different_funder'; break;
			case 'reason': $data['reason_code'] = 'budget_exhausted'; break;
			case 'terms': $data['terms_digest'] = hash( 'sha256', 'foreign terms' ); break;
			case 'component': $data['component_key'] = hash( 'sha256', 'foreign component' ); break;
			case 'rule': $data['rule']['version'] = 3; break;
			case 'budget': $data['evidence']['budget']['state'] = 'exhausted'; break;
			case 'missing_evidence': $data['evidence'] = null; break;
			case 'extra': $data['native_charge_admitted'] = true; break;
		}
		$this->expectException( \InvalidArgumentException::class ); DeliveryPromotionPlan::from_array( $terms, $rule, $data );
	}
	public static function invalid_plans(): array { return array_map( static fn( string $edit ): array => [ $edit ], [ 'money', 'funding', 'reason', 'terms', 'component', 'rule', 'budget', 'missing_evidence', 'extra' ] ); }

	public function test_foreign_frozen_rule_is_not_used_to_reprice_a_saved_plan(): void {
		$terms = QuoteFixtures::terms(); $plan = self::plan( $terms, self::rule() );
		$data = self::rule()->private_facts(); $data['discount']['amount'] = '4.00';
		$this->expectException( \InvalidArgumentException::class );
		DeliveryPromotionPlan::from_json( $terms, FixedDeliveryPromotion::from_array( $data ), $plan->to_private_json() );
	}

	public function test_generic_and_provider_declared_currency_unavailability_never_become_zero(): void {
		$source = self::source(); $context = self::currency_context();
		foreach ( [ 'unsupported_currency_chain', 'source_currency_mismatch', 'provider_mismatch', 'provider_unavailable', 'receipt_mismatch', 'conversion_unavailable', 'evidence_invalid' ] as $reason ) {
			$receipt = CurrencyConversionReceipt::unavailable( $source, $context, $reason );
			$restored = CurrencyConversionReceipt::from_json( $receipt->to_private_json() );
			self::assertSame( $receipt->to_private_json(), $restored->to_private_json() ); self::assertFalse( $restored->converted() ); self::assertNull( $restored->target_money() );
		}
		$evidence = [ 'format_version' => 1, 'state' => 'unavailable', 'provider' => $context['provider'], 'request_digest' => CurrencyConversionReceipt::identity( $source, $context ), 'reason' => 'source_unavailable' ];
		$receipt = CurrencyConversionReceipt::from_provider( $source, $context, 'frozen_currency_provider', 2, $evidence );
		$restored = CurrencyConversionReceipt::from_json( $receipt->to_private_json() );
		self::assertSame( $receipt->digest(), $restored->digest() ); self::assertNull( $restored->target_money() );
		self::assertSame( 'source_unavailable', $restored->private_facts()['conversion']['reason'] );
	}

	#[DataProvider( 'invalid_conversions' )]
	public function test_altered_conversion_state_or_request_binding_is_not_restored( string $edit ): void {
		$data = self::conversion()->private_facts();
		switch ( $edit ) {
			case 'request': $data['request_digest'] = hash( 'sha256', 'other request' ); break;
			case 'source': $data['source']['amount'] = '99.00'; break;
			case 'context': $data['context']['provider_context_digest'] = hash( 'sha256', 'other provider context' ); break;
			case 'target_currency': $data['conversion']['target']['currency'] = 'EUR'; break;
			case 'provider': $data['conversion']['provider']['version'] = 3; break;
			case 'state': $data['state'] = 'unavailable'; break;
			case 'reason': $data['reason_code'] = 'provider_unavailable'; break;
			case 'missing_evidence': $data['conversion'] = null; break;
			case 'extra': $data['already_charged'] = true; break;
		}
		$this->expectException( \InvalidArgumentException::class ); CurrencyConversionReceipt::from_array( $data );
	}
	public static function invalid_conversions(): array { return array_map( static fn( string $edit ): array => [ $edit ], [ 'request', 'source', 'context', 'target_currency', 'provider', 'state', 'reason', 'missing_evidence', 'extra' ] ); }

	#[DataProvider( 'carrier_names' )]
	public function test_unknown_versions_duplicate_fields_and_oversized_json_refuse( string $carrier ): void {
		$original = self::carrier_json( $carrier );
		$cases = [ str_replace( '"format_version":1', '"format_version":2', $original ), str_replace( '"format_version":1', '"format_version":1,"format_version":1', $original ), str_repeat( ' ', 65536 ) . $original ];
		foreach ( $cases as $json ) {
			try { self::decode( $carrier, $json ); self::fail( 'Unknown, duplicate or oversized historical facts must refuse.' ); }
			catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}
	public static function carrier_names(): array { return [ [ 'rule' ], [ 'economics' ], [ 'plan' ], [ 'currency' ] ]; }

	public function test_restored_objects_still_require_private_exports_and_detach_the_decoded_input(): void {
		$data = self::conversion()->private_facts(); $rate = $data['conversion']['applied_rate']; $data['conversion']['applied_rate'] = &$rate;
		$receipt = CurrencyConversionReceipt::from_array( $data ); $before = $receipt->to_private_json(); $rate = '999';
		self::assertSame( $before, $receipt->to_private_json() );
		$objects = [ $receipt, DeliveryPromotionPlan::from_json( QuoteFixtures::terms(), self::rule(), self::plan( QuoteFixtures::terms(), self::rule() )->to_private_json() ) ];
		foreach ( $objects as $object ) {
			try { json_encode( $object, JSON_THROW_ON_ERROR ); self::fail( 'Generic export must remain private.' ); }
			catch ( \LogicException ) { self::assertTrue( true ); }
			ob_start(); var_dump( $object ); $debug = (string) ob_get_clean(); self::assertStringContainsString( 'redacted', $debug );
			self::assertStringNotContainsString( 'frozen_private_funder', $debug );
		}
	}

	private static function key( QuoteTerms $terms ): string { return $terms->private_facts()['groups'][0]['component_key']; }
	private static function rule(): FixedDeliveryPromotion {
		return FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'frozen_fixed_discount', 'version' => 4, 'discount' => [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'frozen_private_funder', 'version' => 7 ] ] );
	}
	private static function promotion_evidence( QuoteTerms $terms, FixedDeliveryPromotion $rule ): array {
		return [ 'format_version' => 1, 'provider' => [ 'code' => 'frozen_promotion_provider', 'version' => 2 ], 'terms_digest' => $terms->digest(), 'component_key' => self::key( $terms ), 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => hash( 'sha256', 'frozen eligible' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => hash( 'sha256', 'frozen available' ) ] ];
	}
	private static function plan( QuoteTerms $terms, FixedDeliveryPromotion $rule ): DeliveryPromotionPlan {
		return DeliveryPromotionPlan::from_evidence( $terms, self::key( $terms ), $rule, 'frozen_promotion_provider', 2, self::promotion_evidence( $terms, $rule ) );
	}
	private static function economics( QuoteTerms $terms ): QuoteEconomicsFacts {
		$unknown = EconomicAmountFact::unavailable( 'not_recorded' )->private_facts();
		$zero = EconomicAmountFact::known( self::source( '0.00' ), 'frozen_actual_cost', 2, hash( 'sha256', 'actual cost evidence' ) )->private_facts();
		return QuoteEconomicsFacts::for_terms( $terms, [ [ 'component_key' => self::key( $terms ), 'estimated_internal_cost' => $unknown, 'actual_internal_cost' => $zero, 'provider_payable' => $unknown, 'subsidy' => [ 'amount' => $unknown, 'funding_source' => null ] ] ] );
	}
	private static function source( string $amount = '12.50' ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => 'GHS', 'precision' => 2 ] ); }
	private static function currency_context(): array {
		return [ 'format_version' => 1, 'material_digest' => hash( 'sha256', 'original material' ), 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2, 'provider' => [ 'code' => 'frozen_currency_provider', 'version' => 2 ], 'provider_context_digest' => hash( 'sha256', 'original provider context' ) ];
	}
	private static function conversion(): CurrencyConversionReceipt {
		$source = self::source(); $context = self::currency_context();
		return CurrencyConversionReceipt::from_provider( $source, $context, 'frozen_currency_provider', 2, [ 'format_version' => 1, 'state' => 'converted', 'provider' => $context['provider'], 'request_digest' => CurrencyConversionReceipt::identity( $source, $context ), 'source' => $source->facts(), 'target' => [ 'amount' => '8.12', 'currency' => 'USD', 'precision' => 2 ], 'applied_rate' => '0.65000000000000000001', 'converted_at' => '2026-10-21 09:10:11.123456', 'evidence_digest' => hash( 'sha256', 'frozen conversion evidence' ) ] );
	}
	private static function carrier_json( string $carrier ): string {
		return match ( $carrier ) { 'rule' => self::rule()->to_private_json(), 'economics' => self::economics( QuoteFixtures::terms() )->to_private_json(), 'plan' => self::plan( QuoteFixtures::terms(), self::rule() )->to_private_json(), 'currency' => self::conversion()->to_private_json() };
	}
	private static function decode( string $carrier, string $json ): object {
		return match ( $carrier ) { 'rule' => FixedDeliveryPromotion::from_json( $json ), 'economics' => QuoteEconomicsFacts::from_json( QuoteFixtures::terms(), $json ), 'plan' => DeliveryPromotionPlan::from_json( QuoteFixtures::terms(), self::rule(), $json ), 'currency' => CurrencyConversionReceipt::from_json( $json ) };
	}
}
