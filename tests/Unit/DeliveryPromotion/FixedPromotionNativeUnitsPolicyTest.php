<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeGroupRequest, QuoteNativeReceiptCapture};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion, FixedPromotionEligibilityFamily};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteMoney, QuoteTerms};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\SqlFixedPromotionRuleEligibilityGuard;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionEligibilityFixture as E, PromotionEligibilitySession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real capture codec with synthetic native rows; no WordPress or merchant-policy acceptance. */
final class FixedPromotionNativeUnitsPolicyTest extends TestCase {
	/** The actual legacy stack requests rate money at scale four and retains native receipts. */
	private function base(): array {
		$source = F::source(); $source->data['cart_shipping_total'] = '7.0000'; $source->data['cart_shipping_tax'] = '0.70';
		$package =& $source->data['packages'][0]; $package['cost'] = '7.0000'; $package['taxes'][0]['amount'] = '0.70'; $package['fresh_taxes'][0]['amount'] = '0.70'; $package['rounded_tax'] = '0.70'; $package['display_total'] = '7.70'; unset( $package );
		$receipt = ( new QuoteNativeReceiptCapture( $source ) )->capture( F::owner(), [ new QuoteNativeGroupRequest( F::key(), 'in_store|delivery|20', QuoteMoney::from_array( [ 'amount' => '7.0000', 'currency' => 'GHS', 'precision' => 4 ] ) ) ] );
		$context = $receipt->bind_context( F::original_context() ); $group = $receipt->group( F::key() ); $terms = F::original_terms()->private_facts();
		$terms['groups'][0] = array_replace( $terms['groups'][0], [ 'list' => $group->final_money()->facts(), 'final' => $group->final_money()->facts(), 'tax' => $group->tax_money()->facts(), 'total' => $group->total_money()->facts(), 'promotion' => $group->promotion(), 'native_tax_receipt' => $group->native_tax_receipt(), 'native_money_receipt' => $group->native_money_receipt() ] );
		return [ $context, QuoteTerms::from_array( $terms ) ];
	}
	private function rule( int $precision = 4 ): FixedDeliveryPromotion { $facts = F::rule()->private_facts(); $facts['discount'] = [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => $precision ]; return FixedDeliveryPromotion::from_array( $facts ); }
	public function test_captured_display_two_and_native_four_produce_the_same_scope_without_rewriting_money(): void {
		[ $context, $terms ] = $this->base(); $before = [ $context->to_private_json(), $terms->to_private_json() ];
		self::assertSame( 2, $context->private_facts()['currency']['precision'] ); self::assertSame( 4, $terms->private_facts()['groups'][0]['list']['precision'] );
		self::assertSame( E::scope(), FixedPromotionEligibilityFamily::subject_for_component( $context, $terms, F::key(), F::rule()->code() ) );
		$rule = $this->rule(); self::assertNull( DeliveryPromotionPlan::preflight( $terms, F::key(), $rule ) );
		$plan = DeliveryPromotionPlan::from_evidence( $terms, F::key(), $rule, 'native_unit_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'native_unit_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => F::key(), 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => Q::digest( 'synthetic eligibility only' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'synthetic availability only' ) ] ] );
		self::assertTrue( $plan->planned() ); $money = $plan->private_facts()['money']; self::assertSame( '7.0000', $money['list']['amount'] ); self::assertSame( '3.0000', $money['discount']['amount'] ); self::assertSame( '4.0000', $money['final']['amount'] );
		self::assertSame( $before, [ $context->to_private_json(), $terms->to_private_json() ] );
		self::assertSame( 'money_unit_mismatch', DeliveryPromotionPlan::preflight( $terms, F::key(), $this->rule( 6 ) )->reason_code() );
	}
	public function test_published_policy_revalidates_real_shaped_native_units_and_derives_its_original_account(): void {
		[ $context, $terms ] = $this->base(); $session = new PromotionEligibilitySession(); $family = E::family(); $rule = $this->rule();
		$payload = [ 'rule' => $rule->private_facts(), 'budget_account_key' => E::account() ]; $session->rows[2][0]['payload_json'] = $family->payload_schema()->encode( $payload ); $session->rows[2][0]['content_hash'] = RuleContent::hash( $family, $payload, RuleStartMode::Immediate, RuleTime::parse( E::PUBLISHED ), null, 10, null );
		$guard = new SqlFixedPromotionRuleEligibilityGuard( $family ); $result = $guard->planning_evidence( $session, F::owner(), $context, $terms, F::key(), $rule, E::at() );
		self::assertNotNull( $result ); self::assertSame( E::account(), $result['account_key'] ); self::assertSame( 'eligible', $result['eligibility']['state'] );
		self::assertNull( $guard->planning_evidence( $session, F::owner(), $context, $terms, F::key(), $this->rule( 6 ), E::at() ) );
	}
	#[DataProvider('incompatible_contexts')]
	public function test_subject_refuses_native_scale_below_display_or_foreign_charged_currency( string $change ): void {
		[ $context, $terms ] = $this->base(); $facts = $context->private_facts();
		if ( 'precision' === $change ) { $facts['currency']['precision'] = 5; }
		else { foreach ( [ 'base', 'presentment', 'charged' ] as $field ) { $facts['currency'][$field] = 'USD'; } }
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionEligibilityFamily::subject_for_component( QuoteContext::from_array( $facts ), $terms, F::key(), F::rule()->code() );
	}
	public static function incompatible_contexts(): array { return [ [ 'precision' ], [ 'currency' ] ]; }
	#[DataProvider('mixed_native_units')]
	public function test_mixed_native_units_are_rejected_by_the_immutable_terms_codec_before_subject_selection( string $field, string $unit ): void {
		[ $context, $terms ] = $this->base(); $facts = $terms->private_facts(); $facts['groups'][0][$field][$unit] = 'precision' === $unit ? 6 : 'USD';
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionEligibilityFamily::subject_for_component( $context, QuoteTerms::from_array( $facts ), F::key(), F::rule()->code() );
	}
	public static function mixed_native_units(): array { return [ [ 'list', 'precision' ], [ 'final', 'precision' ], [ 'tax', 'precision' ], [ 'total', 'precision' ], [ 'list', 'currency' ], [ 'final', 'currency' ], [ 'tax', 'currency' ], [ 'total', 'currency' ] ]; }
}
