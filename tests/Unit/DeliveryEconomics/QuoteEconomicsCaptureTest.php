<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryEconomics;

use CetechDeliveryEngine\Application\DeliveryEconomics\QuoteEconomicsCapture;
use CetechDeliveryEngine\Domain\DeliveryEconomics\{EconomicAmountFact, QuoteEconomicsFacts};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteTerms};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Pure source/projection contract tests, without native capture, storage or admission authority. */
final class QuoteEconomicsCaptureTest extends TestCase {
	public function test_customer_charge_estimate_actual_payable_and_subsidy_remain_independent(): void {
		$terms = QuoteFixtures::terms();
		$before = $terms->to_private_json();
		$capture = QuoteEconomicsCapture::capture( $terms, self::economic_groups( $terms ) );
		$group = $capture->private_economics()['groups'][0];
		self::assertSame( '12.50', $capture->customer_money()[0]['final']['amount'] );
		self::assertSame( '3.00', $group['estimated_internal_cost']['money']['amount'] );
		self::assertSame( '4.00', $group['actual_internal_cost']['money']['amount'] );
		self::assertSame( '2.00', $group['provider_payable']['money']['amount'] );
		self::assertSame( '1.00', $group['subsidy']['amount']['money']['amount'] );
		self::assertSame( 'private_subsidy_observer', $group['subsidy']['amount']['provenance']['source_code'] );
		self::assertSame( [ 'code' => 'private_funding_policy', 'version' => 7 ], $group['subsidy']['funding_source'] );
		self::assertSame( $terms->digest(), $capture->terms_digest() );
		self::assertSame( $before, $terms->to_private_json() );
	}

	public function test_public_projection_is_exactly_the_existing_whitelist(): void {
		$terms = QuoteFixtures::terms();
		$capture = QuoteEconomicsCapture::capture( $terms, self::economic_groups( $terms ) );
		self::assertSame( $terms->display_money(), $capture->customer_money() );
		$public = json_encode( $capture->customer_money(), JSON_THROW_ON_ERROR );
		foreach ( [ 'estimated_internal_cost', 'actual_internal_cost', 'provider_payable', 'subsidy', 'private_funding_policy', 'private_subsidy_observer', 'evidence_digest' ] as $private ) {
			self::assertStringNotContainsString( $private, $public );
		}
	}

	public function test_legacy_cost_and_customer_discount_do_not_supply_unrecorded_economics(): void {
		$data = QuoteFixtures::terms()->private_facts();
		$data['groups'][0]['list']['amount'] = '15.00';
		$data['groups'][0]['promotion']['state'] = 'applied';
		$data['groups'][0]['promotion']['amount']['amount'] = '2.50';
		$data['groups'][0]['cost'] = [ 'state' => 'known', 'amount' => [ 'amount' => '99.00', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'legacy_untyped_cost', 'version' => 1 ] ];
		$terms = QuoteTerms::from_array( $data );
		$groups = self::unknown_groups( $terms );
		$capture = QuoteEconomicsCapture::capture( $terms, $groups );
		$recorded = $capture->private_economics()['groups'][0];
		foreach ( [ 'estimated_internal_cost', 'actual_internal_cost', 'provider_payable' ] as $field ) {
			self::assertSame( [ 'reason' => 'not_recorded', 'state' => 'unavailable' ], $recorded[$field] );
		}
		self::assertSame( [ 'amount' => [ 'reason' => 'not_recorded', 'state' => 'unavailable' ], 'funding_source' => null ], $recorded['subsidy'] );
		self::assertSame( '2.50', $capture->customer_money()[0]['promotion']['amount']['amount'] );
		self::assertSame( '99.00', $terms->private_facts()['groups'][0]['cost']['amount']['amount'] );
	}

	public function test_known_zero_subsidy_requires_explicit_source_and_differs_from_unavailable(): void {
		$terms = QuoteFixtures::terms();
		$groups = self::unknown_groups( $terms );
		$unknown = QuoteEconomicsFacts::for_terms( $terms, $groups );
		$groups[0]['subsidy'] = [ 'amount' => self::amount( '0', 'private_subsidy_observer' ), 'funding_source' => [ 'code' => 'private_funding_policy', 'version' => 7 ] ];
		$known = QuoteEconomicsFacts::for_terms( $terms, $groups );
		self::assertNotSame( $unknown->digest(), $known->digest() );
		self::assertSame( '0.00', $known->private_facts()['groups'][0]['subsidy']['amount']['money']['amount'] );
		self::assertSame( [ 'reason' => 'not_recorded', 'state' => 'unavailable' ], $unknown->private_facts()['groups'][0]['subsidy']['amount'] );
	}

	public function test_group_order_is_canonical_and_exports_are_detached(): void {
		$terms = self::two_group_terms();
		$groups = self::economic_groups( $terms );
		$funding = $groups[0]['subsidy']['funding_source'];
		$groups[0]['subsidy']['funding_source'] = &$funding;
		$facts = QuoteEconomicsFacts::for_terms( $terms, $groups );
		$same = QuoteEconomicsFacts::for_terms( $terms, array_reverse( $groups ) );
		self::assertSame( $facts->digest(), $same->digest() );
		$funding['code'] = 'replacement';
		$export = $facts->private_facts();
		$export['groups'][0]['actual_internal_cost']['money']['amount'] = '999.00';
		self::assertSame( $same->to_private_json(), $facts->to_private_json() );
		self::assertSame( $facts->digest(), QuoteEconomicsFacts::from_array( $terms, $facts->private_facts() )->digest() );
	}

	public function test_binding_includes_v2_promise_packet_without_changing_public_money(): void {
		$terms = PromiseHandoffFixture::terms();
		$facts = QuoteEconomicsFacts::for_terms( $terms, self::unknown_groups( $terms ) );
		self::assertTrue( $facts->matches_terms( $terms ) );
		self::assertFalse( $facts->matches_terms( $terms->base_terms() ) );
		$other = PromiseHandoffFixture::terms( PromiseHistoricalPacket::capture( PromiseHandoffFixture::result(), 'Another captured delivery explanation' ) );
		self::assertSame( $terms->base_terms()->digest(), $other->base_terms()->digest() );
		self::assertSame( $terms->display_money(), $other->display_money() );
		self::assertFalse( $facts->matches_terms( $other ) );
		$this->expectException( \InvalidArgumentException::class );
		QuoteEconomicsCapture::bind( $other, $facts );
	}

	#[DataProvider( 'invalid_bindings' )]
	public function test_partial_foreign_unsourced_and_mixed_unit_components_refuse( string $edit ): void {
		$terms = self::two_group_terms();
		$data = QuoteEconomicsFacts::for_terms( $terms, self::economic_groups( $terms ) )->private_facts();
		switch ( $edit ) {
			case 'format': $data['format_version'] = 2; break;
			case 'coerced_format': $data['format_version'] = '1'; break;
			case 'terms_digest': $data['terms_digest'] = QuoteFixtures::digest( 'other terms' ); break;
			case 'foreign_component': $data['groups'][0]['component_key'] = QuoteFixtures::digest( 'foreign component' ); break;
			case 'duplicate_component': $data['groups'][1] = $data['groups'][0]; break;
			case 'missing_component': array_pop( $data['groups'] ); break;
			case 'empty_components': $data['groups'] = []; break;
			case 'scalar_components': $data['groups'] = false; break;
			case 'array_component_key': $data['groups'][0]['component_key'] = [ $data['groups'][0]['component_key'] ]; break;
			case 'scalar_estimate': $data['groups'][0]['estimated_internal_cost'] = '3.00'; break;
			case 'scalar_subsidy': $data['groups'][0]['subsidy'] = false; break;
			case 'array_funding_code': $data['groups'][0]['subsidy']['funding_source']['code'] = [ 'merchant' ]; break;
			case 'missing_estimate': unset( $data['groups'][0]['estimated_internal_cost'] ); break;
			case 'estimate_currency': $data['groups'][0]['estimated_internal_cost']['money']['currency'] = 'USD'; break;
			case 'actual_precision': $data['groups'][0]['actual_internal_cost']['money']['precision'] = 3; break;
			case 'payable_currency': $data['groups'][0]['provider_payable']['money']['currency'] = 'USD'; break;
			case 'subsidy_currency': $data['groups'][0]['subsidy']['amount']['money']['currency'] = 'USD'; break;
			case 'missing_funding': $data['groups'][0]['subsidy']['funding_source'] = null; break;
			case 'zero_subsidy_missing_funding': $data['groups'][0]['subsidy']['amount']['money']['amount'] = '0.00'; $data['groups'][0]['subsidy']['funding_source'] = null; break;
			case 'unavailable_funding': $data['groups'][0]['subsidy']['amount'] = EconomicAmountFact::unavailable( 'not_recorded' )->private_facts(); break;
			case 'coerced_funding_version': $data['groups'][0]['subsidy']['funding_source']['version'] = '7'; break;
			case 'inferred_margin': $data['groups'][0]['margin'] = '4.50'; break;
			case 'extra_root': $data['ready'] = true; break;
			case 'oversized_payload': $data['note'] = str_repeat( 'x', 65536 ); break;
		}
		$this->expectException( \InvalidArgumentException::class );
		QuoteEconomicsFacts::from_array( $terms, $data );
	}

	public static function invalid_bindings(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'format', 'coerced_format', 'terms_digest', 'foreign_component', 'duplicate_component', 'missing_component', 'empty_components', 'scalar_components', 'array_component_key', 'scalar_estimate', 'scalar_subsidy', 'array_funding_code', 'missing_estimate', 'estimate_currency', 'actual_precision', 'payable_currency', 'subsidy_currency', 'missing_funding', 'zero_subsidy_missing_funding', 'unavailable_funding', 'coerced_funding_version', 'inferred_margin', 'extra_root', 'oversized_payload' ] );
	}

	#[DataProvider( 'private_carriers' )]
	public function test_generic_export_and_debugging_never_disclose_private_capture( string $carrier, string $export ): void {
		$terms = QuoteFixtures::terms();
		$facts = QuoteEconomicsFacts::for_terms( $terms, self::economic_groups( $terms ) );
		$object = 'facts' === $carrier ? $facts : QuoteEconomicsCapture::bind( $terms, $facts );
		if ( 'debug' === $export ) {
			ob_start();
			var_dump( $object );
			$output = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $output );
			foreach ( [ 'private_funding_policy', 'private_subsidy_observer', $terms->digest(), '12.50' ] as $private ) { self::assertStringNotContainsString( $private, $output ); }
			return;
		}
		$this->expectException( \LogicException::class );
		if ( 'json' === $export ) { json_encode( $object, JSON_THROW_ON_ERROR ); }
		elseif ( 'php' === $export ) { serialize( $object ); }
		else { $class = get_class( $object ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	}

	public static function private_carriers(): array {
		$cases = [];
		foreach ( [ 'facts', 'capture' ] as $carrier ) { foreach ( [ 'json', 'php', 'unserialize', 'debug' ] as $export ) { $cases[] = [ $carrier, $export ]; } }
		return $cases;
	}

	private static function two_group_terms(): QuoteTerms {
		$data = QuoteFixtures::terms()->private_facts();
		$second = $data['groups'][0];
		$second['component_key'] = QuoteFixtures::digest( 'second component' );
		$data['groups'][] = $second;
		return QuoteTerms::from_array( $data );
	}

	private static function economic_groups( QuoteTerms $terms ): array {
		return array_map( static fn( array $group ): array => [
			'component_key' => $group['component_key'],
			'estimated_internal_cost' => self::amount( '3', 'private_estimate_observer' ),
			'actual_internal_cost' => self::amount( '4', 'private_actual_observer' ),
			'provider_payable' => self::amount( '2', 'private_payable_observer' ),
			'subsidy' => [ 'amount' => self::amount( '1', 'private_subsidy_observer' ), 'funding_source' => [ 'code' => 'private_funding_policy', 'version' => 7 ] ],
		], $terms->private_facts()['groups'] );
	}

	private static function unknown_groups( QuoteTerms $terms ): array {
		$unknown = EconomicAmountFact::unavailable( 'not_recorded' )->private_facts();
		return array_map( static fn( array $group ): array => [
			'component_key' => $group['component_key'], 'estimated_internal_cost' => $unknown, 'actual_internal_cost' => $unknown,
			'provider_payable' => $unknown, 'subsidy' => [ 'amount' => $unknown, 'funding_source' => null ],
		], $terms->private_facts()['groups'] );
	}

	private static function amount( string $amount, string $source ): array {
		return EconomicAmountFact::known( QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => 'GHS', 'precision' => 2 ] ), $source, 2, QuoteFixtures::digest( $source . ':' . $amount ) )->private_facts();
	}
}
