<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{DeliveryPromotionEvidenceProvider, FixedDeliveryPromotionCalculator};
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyFixedBaseQuoteProvider;
use CetechDeliveryEngine\Domain\DeliveryEconomics\QuoteEconomicsFacts;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Synthetic provider evidence proves pure planning, never native eligibility or reserved budget. */
final class FixedDeliveryPromotionCalculatorTest extends TestCase {
	#[DataProvider( 'fixed_discounts' )]
	public function test_one_explicit_fixed_discount_is_subtracted_exactly( string $discount, string $final ): void {
		$terms = QuoteFixtures::terms();
		$provider = new PromotionEvidenceFixture();
		$rule = self::rule( $discount );
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, self::key( $terms ), $rule );
		self::assertTrue( $plan->planned() );
		self::assertNull( $plan->reason_code() );
		self::assertSame( 1, $provider->calls );
		self::assertSame( '12.50', $plan->preview_money()['list']['amount'] );
		self::assertSame( $final, $plan->preview_money()['final']['amount'] );
		self::assertSame( $rule->discount()->facts(), $plan->preview_money()['discount'] );
		self::assertSame( $terms->digest(), $plan->private_facts()['terms_digest'] );
		self::assertSame( $rule->digest(), $plan->private_facts()['rule_digest'] );
		self::assertSame( [ 'code' => 'private_promotion_funder', 'version' => 7 ], $plan->private_facts()['funding_source'] );
	}
	public static function fixed_discounts(): array { return [ 'discount' => [ '3', '9.50' ], 'explicit free' => [ '12.50', '0.00' ], 'explicit zero' => [ '0', '12.50' ] ]; }

	public function test_preview_is_pre_tax_and_source_native_receipts_stay_unchanged(): void {
		$data = QuoteFixtures::terms()->private_facts();
		$group = &$data['groups'][0];
		$group['tax']['amount'] = '2.50'; $group['total']['amount'] = '15.00';
		$group['native_tax_receipt']['exempt'] = false; $group['native_tax_receipt']['tax_status'] = 'taxable';
		$group['native_tax_receipt']['rates'] = [ [ 'rate_id' => 7, 'amount' => $group['tax'] ] ];
		$group['native_tax_receipt']['rounded_tax']['amount'] = '2.50';
		$group['native_money_receipt']['native_total'] = $group['total']; $group['native_money_receipt']['display_total'] = $group['total'];
		unset( $group );
		$terms = QuoteTerms::from_array( $data );
		$before = $terms->to_private_json();
		$plan = ( new FixedDeliveryPromotionCalculator( new PromotionEvidenceFixture() ) )->calculate( $terms, self::key( $terms ), self::rule() );
		$preview = $plan->preview_money();
		self::assertSame( [ 'scope', 'component_key', 'list', 'discount', 'final' ], array_keys( $preview ) );
		self::assertSame( 'hypothetical_pre_tax', $preview['scope'] );
		self::assertSame( '9.50', $preview['final']['amount'] );
		self::assertSame( $before, $terms->to_private_json() );
		self::assertSame( '15.00', $terms->display_money()[0]['total']['amount'] );
		$public = json_encode( $preview, JSON_THROW_ON_ERROR );
		foreach ( [ 'private_promotion_funder', 'fixture_promotion_provider', 'evidence_digest', 'native_total', 'display_total', 'budget', 'rule_digest' ] as $private ) { self::assertStringNotContainsString( $private, $public ); }
		self::assertArrayNotHasKey( 'tax', $preview );
	}

	#[DataProvider( 'unsupported_inputs' )]
	public function test_unsupported_inputs_refuse_before_calling_the_provider( string $edit, string $reason ): void {
		$terms = QuoteFixtures::terms(); $key = self::key( $terms ); $rule = self::rule();
		if ( 'missing_component' === $edit ) { $key = QuoteFixtures::digest( 'other component' ); }
		elseif ( 'currency' === $edit ) { $rule = self::rule( currency: 'USD' ); }
		elseif ( 'precision' === $edit ) { $rule = self::rule( precision: 3 ); }
		elseif ( 'excessive' === $edit ) { $rule = self::rule( '12.51' ); }
		else {
			$data = $terms->private_facts();
			$data['groups'][0]['promotion'] = 'already_applied' === $edit
				? [ 'state' => 'applied', 'amount' => [ 'amount' => '0.00', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'other_promotion', 'version' => 1 ] ]
				: [ 'state' => 'unavailable', 'reason' => 'promotion_context_unavailable' ];
			$terms = QuoteTerms::from_array( $data );
		}
		$provider = new PromotionEvidenceFixture();
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, $key, $rule );
		self::assertFalse( $plan->planned() ); self::assertNull( $plan->preview_money() );
		self::assertSame( $reason, $plan->reason_code() ); self::assertSame( 0, $provider->calls );
	}
	public static function unsupported_inputs(): array {
		return [ [ 'missing_component', 'component_unavailable' ], [ 'currency', 'money_unit_mismatch' ], [ 'precision', 'money_unit_mismatch' ], [ 'excessive', 'discount_exceeds_list' ], [ 'already_applied', 'promotion_already_applied' ], [ 'promotion_unknown', 'promotion_unavailable' ] ];
	}

	#[DataProvider( 'negative_decisions' )]
	public function test_ineligible_unknown_and_exhausted_evidence_never_produce_money( string $section, array $decision, string $reason ): void {
		$provider = new PromotionEvidenceFixture();
		$provider->edit = static function( array $data ) use ( $section, $decision ): array { $data[$section] = $decision; return $data; };
		$terms = QuoteFixtures::terms();
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, self::key( $terms ), self::rule() );
		self::assertFalse( $plan->planned() ); self::assertNull( $plan->preview_money() ); self::assertSame( $reason, $plan->reason_code() );
		self::assertArrayNotHasKey( 'money', $plan->private_facts() );
	}
	public static function negative_decisions(): array {
		return [
			[ 'eligibility', [ 'state' => 'ineligible', 'evidence_digest' => hash( 'sha256', 'ineligible fixture' ) ], 'ineligible' ],
			[ 'eligibility', [ 'state' => 'unavailable', 'reason' => 'source_unavailable' ], 'eligibility_unavailable' ],
			[ 'budget', [ 'state' => 'exhausted', 'evidence_digest' => hash( 'sha256', 'exhausted fixture' ) ], 'budget_exhausted' ],
			[ 'budget', [ 'state' => 'unavailable', 'reason' => 'not_recorded' ], 'budget_unavailable' ],
		];
	}

	#[DataProvider( 'invalid_evidence' )]
	public function test_stale_foreign_coerced_and_partial_receipts_refuse( string $edit ): void {
		$provider = new PromotionEvidenceFixture();
		$provider->edit = static function( array $data ) use ( $edit ): array {
			switch ( $edit ) {
				case 'format': $data['format_version'] = 2; break;
				case 'coerced_format': $data['format_version'] = '1'; break;
				case 'terms': $data['terms_digest'] = QuoteFixtures::digest( 'stale terms' ); break;
				case 'component': $data['component_key'] = QuoteFixtures::digest( 'other component' ); break;
				case 'rule': $data['rule_digest'] = self::rule( '4' )->digest(); break;
				case 'provider': $data['provider']['code'] = 'other_provider'; break;
				case 'provider_version': $data['provider']['version'] = 99; break;
				case 'coerced_provider_version': $data['provider']['version'] = '2'; break;
				case 'scalar_provider': $data['provider'] = false; break;
				case 'missing_budget': unset( $data['budget'] ); break;
				case 'scalar_eligibility': $data['eligibility'] = true; break;
				case 'unknown_eligibility': $data['eligibility']['state'] = 'approved'; break;
				case 'unknown_budget': $data['budget']['state'] = 'unlimited'; break;
				case 'missing_proof': unset( $data['budget']['evidence_digest'] ); break;
				case 'numeric_proof': $data['eligibility']['evidence_digest'] = 123; break;
				case 'unavailable_with_proof': $data['budget']['state'] = 'unavailable'; $data['budget']['reason'] = 'not_recorded'; break;
				case 'unavailable_unknown_reason': $data['budget'] = [ 'state' => 'unavailable', 'reason' => 'assume_available' ]; break;
				case 'extra_reservation': $data['reservation_id'] = 'not-authority'; break;
				case 'oversized': $data['note'] = str_repeat( 'x', 65536 ); break;
			}
			return $data;
		};
		$terms = QuoteFixtures::terms();
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, self::key( $terms ), self::rule() );
		self::assertFalse( $plan->planned() ); self::assertNull( $plan->preview_money() ); self::assertSame( 'evidence_invalid', $plan->reason_code() );
	}
	public static function invalid_evidence(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'format', 'coerced_format', 'terms', 'component', 'rule', 'provider', 'provider_version', 'coerced_provider_version', 'scalar_provider', 'missing_budget', 'scalar_eligibility', 'unknown_eligibility', 'unknown_budget', 'missing_proof', 'numeric_proof', 'unavailable_with_proof', 'unavailable_unknown_reason', 'extra_reservation', 'oversized' ] );
	}

	public function test_v2_packet_only_change_invalidates_replayed_evidence(): void {
		$original = PromiseHandoffFixture::terms();
		$changed = PromiseHandoffFixture::terms( PromiseHistoricalPacket::capture( PromiseHandoffFixture::result(), 'Changed original promise explanation' ) );
		self::assertSame( $original->base_terms()->digest(), $changed->base_terms()->digest() );
		$provider = new PromotionEvidenceFixture();
		$provider->edit = static function( array $data ) use ( $original ): array { $data['terms_digest'] = $original->digest(); return $data; };
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $changed, self::key( $changed ), self::rule() );
		self::assertFalse( $plan->planned() ); self::assertSame( 'evidence_invalid', $plan->reason_code() );
	}

	public function test_provider_failures_and_changed_identity_do_not_leak_private_errors(): void {
		$terms = QuoteFixtures::terms();
		foreach ( [ 'failure', 'changed_identity' ] as $mode ) {
			$provider = new PromotionEvidenceFixture();
			$provider->failure = 'failure' === $mode; $provider->change_identity = 'changed_identity' === $mode;
			$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, self::key( $terms ), self::rule() );
			self::assertFalse( $plan->planned() ); self::assertNull( $plan->preview_money() );
			self::assertSame( 'failure' === $mode ? 'evidence_unavailable' : 'evidence_invalid', $plan->reason_code() );
			self::assertStringNotContainsString( 'SECRET_PROVIDER_FAILURE', $plan->to_private_json() );
		}
	}

	public function test_private_export_is_detached_and_does_not_infer_an_observed_subsidy(): void {
		$terms = QuoteFixtures::terms(); $unknown = [ 'state' => 'unavailable', 'reason' => 'not_recorded' ];
		$economics = QuoteEconomicsFacts::for_terms( $terms, [ [ 'component_key' => self::key( $terms ), 'estimated_internal_cost' => $unknown, 'actual_internal_cost' => $unknown, 'provider_payable' => $unknown, 'subsidy' => [ 'amount' => $unknown, 'funding_source' => null ] ] ] );
		$before = $economics->to_private_json();
		$plan = ( new FixedDeliveryPromotionCalculator( new PromotionEvidenceFixture() ) )->calculate( $terms, self::key( $terms ), self::rule() );
		$digest = $plan->digest();
		$export = $plan->private_facts(); $export['funding_source']['code'] = 'replacement'; $export['money']['final']['amount'] = '999.00';
		self::assertSame( $digest, $plan->digest() ); self::assertSame( $before, $economics->to_private_json() );
		self::assertArrayNotHasKey( 'subsidy', $plan->private_facts() );
		self::assertSame( 'unavailable', $economics->private_facts()['groups'][0]['subsidy']['amount']['state'] );
	}

	public function test_later_provider_reference_mutation_cannot_rewrite_the_captured_plan(): void {
		$decision = [ 'state' => 'available', 'evidence_digest' => hash( 'sha256', 'original planning observation' ) ];
		$provider = new PromotionEvidenceFixture();
		$provider->edit = static function( array $data ) use ( &$decision ): array { $data['budget'] = &$decision; return $data; };
		$terms = QuoteFixtures::terms();
		$plan = ( new FixedDeliveryPromotionCalculator( $provider ) )->calculate( $terms, self::key( $terms ), self::rule() );
		$before = $plan->to_private_json();
		$decision['state'] = 'exhausted';
		self::assertTrue( $plan->planned() );
		self::assertSame( $before, $plan->to_private_json() );
		self::assertSame( 'available', $plan->private_facts()['evidence']['budget']['state'] );
	}

	public function test_the_existing_native_provider_still_refuses_a_promoted_quote(): void {
		$terms = LegacyQuoteProviderFixtures::terms()->private_facts();
		$terms['groups'][0]['promotion'] = [ 'state' => 'applied', 'amount' => [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'fixture_fixed_delivery', 'version' => 4 ] ];
		foreach ( [ 'final', 'total' ] as $field ) { $terms['groups'][0][$field]['amount'] = '9.50'; }
		foreach ( [ 'native_total', 'display_total' ] as $field ) { $terms['groups'][0]['native_money_receipt'][$field]['amount'] = '9.50'; }
		$this->expectException( \InvalidArgumentException::class );
		new LegacyFixedBaseQuoteProvider( QuoteFixtures::owner(), LegacyQuoteProviderFixtures::context(), QuoteTerms::from_array( $terms ) );
	}

	#[DataProvider( 'private_exports' )]
	public function test_private_carriers_refuse_generic_export_and_redact_debugging( string $carrier, string $kind ): void {
		$terms = QuoteFixtures::terms(); $calculator = new FixedDeliveryPromotionCalculator( new PromotionEvidenceFixture() );
		$object = 'plan' === $carrier ? $calculator->calculate( $terms, self::key( $terms ), self::rule() ) : $calculator;
		if ( 'debug' === $kind ) {
			ob_start(); var_dump( $object ); $output = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $output );
			foreach ( [ 'private_promotion_funder', 'fixture_promotion_provider', '9.50', $terms->digest() ] as $private ) { self::assertStringNotContainsString( $private, $output ); }
			return;
		}
		$this->expectException( \LogicException::class );
		if ( 'json' === $kind ) { json_encode( $object, JSON_THROW_ON_ERROR ); }
		elseif ( 'php' === $kind ) { serialize( $object ); }
		else { $class = get_class( $object ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	}
	public static function private_exports(): array {
		$cases = []; foreach ( [ 'plan', 'calculator' ] as $carrier ) { foreach ( [ 'json', 'php', 'unserialize', 'debug' ] as $kind ) { $cases[] = [ $carrier, $kind ]; } }
		return $cases;
	}

	private static function key( QuoteTerms $terms ): string { return $terms->private_facts()['groups'][0]['component_key']; }
	private static function rule( string $discount = '3', string $currency = 'GHS', int $precision = 2 ): FixedDeliveryPromotion {
		return FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'fixture_fixed_delivery', 'version' => 4, 'discount' => [ 'amount' => $discount, 'currency' => $currency, 'precision' => $precision ], 'funding_source' => [ 'code' => 'private_promotion_funder', 'version' => 7 ] ] );
	}
}

final class PromotionEvidenceFixture implements DeliveryPromotionEvidenceProvider {
	public int $calls = 0;
	public ?\Closure $edit = null;
	public bool $failure = false;
	public bool $change_identity = false;
	private string $identity = 'fixture_promotion_provider';
	public function code(): string { return $this->identity; }
	public function version(): int { return 2; }
	public function capture( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule ): array {
		++$this->calls;
		if ( $this->failure ) { throw new \RuntimeException( 'SECRET_PROVIDER_FAILURE' ); }
		$data = [ 'format_version' => 1, 'provider' => [ 'code' => $this->code(), 'version' => $this->version() ], 'terms_digest' => $terms->digest(), 'component_key' => $component_key, 'rule_digest' => $rule->digest(),
			'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => hash( 'sha256', 'fixture eligible' ) ],
			'budget' => [ 'state' => 'available', 'evidence_digest' => hash( 'sha256', 'fixture available planning budget' ) ] ];
		if ( $this->change_identity ) { $this->identity = 'changed_provider'; }
		return null === $this->edit ? $data : ( $this->edit )( $data );
	}
}
