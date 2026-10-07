<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjection;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjectionResult;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';

final class ProjectionShopperTest extends TestCase {
	private function assert_private_absent( array $fields, array $values = [] ): void {
		$json = json_encode( $fields, JSON_THROW_ON_ERROR );
		foreach ( [ 'owner', 'principal', 'session', 'fingerprint', 'digest', 'component_key', 'origin', 'supplier', 'rate_card', 'provider', 'policy',
			'cost', 'margin', 'address', 'coordinates', 'acceptance_handle', 'namespace', 'token', 'private_sentinel', ...$values ] as $private ) {
			self::assertStringNotContainsString( $private, $json );
		}
	}

	public function test_shopper_quote_has_an_exact_public_schema_and_real_captured_money_without_private_keys(): void {
		$quote = QuoteFixtures::issue(); $request = RequestContext::create();
		$result = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), $request );
		self::assertSame( 'shopper', $result->purpose() ); $fields = $result->fields();
		self::assertSame( [ 'contract_version', 'decision_kind', 'quote_id', 'status', 'currently_applicable', 'expires_at', 'customer_label', 'money', 'reason_code', 'recovery_action', 'correlation_id' ], array_keys( $fields ) );
		self::assertSame( 'delivery_quote', $fields['decision_kind'] ); self::assertSame( $quote->header()->id()->value(), $fields['quote_id'] );
		self::assertSame( 'issued', $fields['status'] ); self::assertTrue( $fields['currently_applicable'] );
		self::assertSame( '2026-10-07T05:05:00.000000Z', $fields['expires_at'] ); self::assertSame( $request->correlation_id, $fields['correlation_id'] );
		self::assertNull( $fields['reason_code'] ); self::assertNull( $fields['recovery_action'] );
		self::assertSame( [ 'customer_label', 'list_price', 'promotion', 'final_price', 'tax', 'rounded_tax', 'total', 'display_total' ], array_keys( $fields['money'][0] ) );
		self::assertSame( [ 'amount' => '12.50', 'currency' => 'GHS', 'precision' => 2 ], $fields['money'][0]['final_price'] );
		self::assertSame( [ 'state' => 'none', 'amount' => [ 'amount' => '0.00', 'currency' => 'GHS', 'precision' => 2 ] ], $fields['money'][0]['promotion'] );
		self::assertSame( '0.00', $fields['money'][0]['rounded_tax']['amount'] ); self::assertSame( '12.50', $fields['money'][0]['display_total']['amount'] );
		self::assertSame( $fields, json_decode( json_encode( $result, JSON_THROW_ON_ERROR ), true, 32, JSON_THROW_ON_ERROR ) );
		$this->assert_private_absent( $fields, [ $quote->header()->body_digest(), $quote->header()->material_digest(), $quote->header()->owner()->digest(),
			QuoteFixtures::digest( 'component' ), ...array_values( $quote->header()->namespace_hashes() ) ] );
		self::assertArrayNotHasKey( 'admitted', $fields ); self::assertArrayNotHasKey( 'paid', $fields ); self::assertArrayNotHasKey( 'placed', $fields );
	}

	public function test_known_cost_route_provider_and_private_tax_receipt_never_enter_shopper_or_diagnostic_projection(): void {
		$group = QuoteFixtures::terms()->private_facts()['groups'][0];
		$group['provider'] = [ 'code' => 'private_sentinel_price', 'version' => 817 ];
		$group['promotion']['provider'] = [ 'code' => 'private_sentinel_promo', 'version' => 818 ];
		$group['cost'] = [ 'state' => 'known', 'amount' => [ 'amount' => '981237.1234', 'currency' => 'GHS', 'precision' => 4 ],
			'provider' => [ 'code' => 'private_sentinel_cost', 'version' => 819 ] ];
		$group['route'] = [ 'state' => 'known', 'distance' => '978123.1234', 'distance_unit' => 'km', 'duration_seconds' => '987123.1234',
			'provider' => [ 'code' => 'private_sentinel_route', 'version' => 820 ] ];
		$quote = QuoteFixtures::issue( terms: QuoteFixtures::terms( [ 'groups' => [ $group ] ] ) );
		$private = [ '981237.1234', '978123.1234', '987123.1234', $group['policy_digest'], $group['native_tax_receipt']['location_digest'],
			$quote->header()->owner()->facts()['session_hash'], $quote->header()->owner()->facts()['principal_hash'] ];
		$this->assert_private_absent( QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields(), $private );
		$this->assert_private_absent( QuoteProjection::for_diagnostic_log( $quote, QuoteFixtures::time(), RequestContext::create() )->fields(),
			[ ...$private, $quote->header()->id()->value(), '12.50', 'Fixture delivery' ] );
	}

	public function test_diagnostic_projection_has_its_own_allowlist_without_money_or_quote_ownership(): void {
		$quote = QuoteFixtures::issue(); $request = RequestContext::create();
		$result = QuoteProjection::for_diagnostic_log( $quote, QuoteFixtures::time(), $request );
		self::assertSame( 'diagnostic', $result->purpose() ); $fields = $result->fields();
		self::assertSame( [ 'contract_version', 'operation', 'evaluated_at', 'stored_state', 'current_status', 'currently_applicable', 'reason_code', 'recovery_action', 'revision', 'correlation_id' ], array_keys( $fields ) );
		self::assertSame( 'delivery_quote.read', $fields['operation'] ); self::assertSame( 'issued', $fields['stored_state'] ); self::assertSame( 1, $fields['revision'] );
		$this->assert_private_absent( $fields, [ $quote->header()->id()->value() ] );
		self::assertArrayNotHasKey( 'money', $fields ); self::assertArrayNotHasKey( 'accepted_change', $fields );
		self::assertSame( $fields, json_decode( json_encode( $result, JSON_THROW_ON_ERROR ), true, 32, JSON_THROW_ON_ERROR ) );
	}

	public function test_exact_expiry_and_clock_regression_refuse_current_use_without_repricing_or_renewal(): void {
		$quote = QuoteFixtures::issue(); $original = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields();
		foreach ( [ [ QuoteFixtures::time( '2026-10-07 04:59:59.999999' ), 'issued', 'quote_unavailable', 'retry_later' ],
			[ QuoteFixtures::time( '2026-10-07 05:05:00.000000' ), 'expired', 'quote_expired', 'refresh_and_review' ],
			[ QuoteFixtures::time( '2026-10-07 05:05:00.000001' ), 'expired', 'quote_expired', 'refresh_and_review' ] ] as [ $at, $status, $reason, $recovery ] ) {
			$fields = QuoteProjection::for_shopper( $quote, $at, RequestContext::create() )->fields();
			self::assertSame( $status, $fields['status'] ); self::assertFalse( $fields['currently_applicable'] );
			self::assertSame( $reason, $fields['reason_code'] ); self::assertSame( $recovery, $fields['recovery_action'] );
			self::assertSame( $original['expires_at'], $fields['expires_at'] ); self::assertSame( $original['money'], $fields['money'] ); self::assertSame( $original['quote_id'], $fields['quote_id'] );
		}
	}

	public function test_explicit_applied_promotion_is_not_dropped_or_inferred_from_equal_amounts(): void {
		$group = QuoteFixtures::terms()->private_facts()['groups'][0];
		$group['promotion']['state'] = 'applied';
		$quote = QuoteFixtures::issue( terms: QuoteFixtures::terms( [ 'groups' => [ $group ] ] ) );
		$promotion = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields()['money'][0]['promotion'];
		self::assertSame( 'applied', $promotion['state'] ); self::assertSame( '0.00', $promotion['amount']['amount'] );
		self::assertArrayNotHasKey( 'provider', $promotion );
	}

	public function test_unknown_promotion_and_native_receipts_stay_unknown_without_a_fabricated_zero_display(): void {
		$group = QuoteFixtures::terms()->private_facts()['groups'][0];
		$group['promotion'] = [ 'state' => 'unavailable', 'reason' => 'promotion_context_unavailable' ];
		$group['native_tax_receipt'] = [ 'state' => 'unavailable', 'reason' => 'tax_context_unavailable' ];
		$group['native_money_receipt'] = [ 'state' => 'unavailable', 'reason' => 'money_context_unavailable' ];
		$quote = QuoteFixtures::issue( terms: QuoteFixtures::terms( [ 'groups' => [ $group ] ] ) );
		$fields = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields();
		self::assertFalse( $fields['currently_applicable'] ); self::assertSame( 'quote_unavailable', $fields['reason_code'] );
		self::assertSame( [ 'state' => 'unavailable', 'amount' => null ], $fields['money'][0]['promotion'] );
		self::assertNull( $fields['money'][0]['rounded_tax'] ); self::assertNull( $fields['money'][0]['display_total'] );
		self::assertStringNotContainsString( 'promotion_context_unavailable', json_encode( $fields, JSON_THROW_ON_ERROR ) );
	}

	public function test_projection_and_private_facts_are_detached_from_mutated_output_arrays(): void {
		$quote = QuoteFixtures::issue(); $result = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() );
		$original = $result->fields(); $copy = $result->fields();
		$copy['money'][0]['final_price']['amount'] = '99999'; $copy['money'][0]['promotion']['address'] = [ 'private_sentinel' ];
		self::assertSame( $original, $result->fields() );
		$private = $quote->terms()->private_facts(); $private['groups'][0]['customer_label'] = 'private_sentinel'; $private['groups'][0]['cost']['address'] = 'private_sentinel';
		self::assertSame( $original['money'], QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields()['money'] );
	}

	public function test_nested_and_renamed_private_extension_fields_refuse_before_a_quote_can_be_projected(): void {
		foreach ( [ 'renamed', 'address', 'extension' ] as $key ) {
			$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0][$key] = [ [ 'deeper' => [ 'private_sentinel' ] ] ];
			try { QuoteTerms::from_array( $data ); self::fail( 'Undeclared private extension must refuse.' ); }
			catch ( InvalidArgumentException $error ) { self::assertStringNotContainsString( 'private_sentinel', $error->getMessage() ); }
			$data = QuoteFixtures::context()->private_facts(); $data['groups'][0][$key] = [ 'private_sentinel' ];
			try { QuoteContext::from_array( $data ); self::fail( 'Undeclared context extension must refuse.' ); }
			catch ( InvalidArgumentException $error ) { self::assertStringNotContainsString( 'private_sentinel', $error->getMessage() ); }
		}
	}

	public function test_private_core_carriers_cannot_be_serialized_as_a_nested_shopper_payload(): void {
		$quote = QuoteFixtures::issue();
		foreach ( [ $quote->header(), $quote->header()->owner(), $quote->context(), $quote->terms() ] as $private ) {
			try { json_encode( [ 'extension' => [ 'renamed' => $private ] ], JSON_THROW_ON_ERROR ); self::fail( 'Private generic JSON must refuse.' ); }
			catch ( LogicException $error ) { self::assertStringNotContainsString( $quote->header()->body_digest(), $error->getMessage() ); }
		}
	}

	public function test_estimate_purpose_is_explicit_and_cannot_masquerade_as_current_checkout_quote(): void {
		$context = QuoteFixtures::context()->private_facts(); $context['kind'] = 'estimate'; $context['destination']['kind'] = 'estimate';
		$quote = QuoteFixtures::issue( context: QuoteContext::from_array( $context ) );
		$fields = QuoteProjection::for_shopper( $quote, QuoteFixtures::time(), RequestContext::create() )->fields();
		self::assertSame( 'delivery_estimate', $fields['decision_kind'] ); self::assertFalse( $fields['currently_applicable'] );
		self::assertSame( 'quote_unavailable', $fields['reason_code'] ); self::assertSame( '12.50', $fields['money'][0]['final_price']['amount'] );
	}

	public function test_accepted_receipt_and_expired_current_applicability_are_distinct_without_erasing_history(): void {
		$issued = QuoteFixtures::issue(); $header = $issued->header();
		$accepted = $issued->accept( $header->owner(), QuoteFixtures::reference( $header->id() ), $issued->context(), QuoteFixtures::time()->plus_seconds( 10 ),
			$header->revision(), $header->body_digest(), $header->expires_at() );
		$before = QuoteProjection::for_shopper( $accepted, QuoteFixtures::time()->plus_seconds( 11 ), RequestContext::create() )->fields();
		self::assertSame( 'accepted', $before['status'] ); self::assertTrue( $before['currently_applicable'] );
		$expired = QuoteProjection::for_shopper( $accepted, $header->expires_at(), RequestContext::create() )->fields();
		self::assertSame( 'expired', $expired['status'] ); self::assertFalse( $expired['currently_applicable'] ); self::assertSame( $before['money'], $expired['money'] );
		$diagnostic = QuoteProjection::for_diagnostic_log( $accepted, $header->expires_at(), RequestContext::create() )->fields();
		self::assertSame( 'accepted', $diagnostic['stored_state'] ); self::assertSame( 'expired', $diagnostic['current_status'] ); self::assertSame( 2, $diagnostic['revision'] );
		self::assertSame( '2026-10-07 05:00:10.000000', $accepted->accepted_at()->sql() );
	}

	public function test_material_invalidation_has_recoverable_projection_without_rewriting_original_money(): void {
		$issued = QuoteFixtures::issue(); $header = $issued->header(); $current = $issued->context()->private_facts(); $current['lines'][0]['quantity'] = '3';
		$invalid = $issued->invalidate( $header->owner(), QuoteFixtures::reference( $header->id() ), QuoteContext::from_array( $current ), QuoteFixtures::time()->plus_seconds( 10 ),
			$header->revision(), $header->body_digest(), $header->expires_at() );
		$fields = QuoteProjection::for_shopper( $invalid, QuoteFixtures::time()->plus_seconds( 11 ), RequestContext::create() )->fields();
		self::assertSame( 'invalidated', $fields['status'] ); self::assertFalse( $fields['currently_applicable'] );
		self::assertSame( 'quote_invalidated', $fields['reason_code'] ); self::assertSame( 'refresh_and_review', $fields['recovery_action'] );
		self::assertSame( '12.50', $fields['money'][0]['final_price']['amount'] ); self::assertSame( $header->id()->value(), $fields['quote_id'] );
	}

	public function test_stripped_model_has_no_replacement_or_zero_price_and_cannot_be_used_for_checkout(): void {
		$issued = QuoteFixtures::issue(); $header = $issued->header(); $at = $header->expires_at()->plus_seconds( 1800 );
		$stripped = $issued->strip_for_model( $header->owner(), QuoteFixtures::reference( $header->id() ), $at, $header->revision(), $header->body_digest(), $header->expires_at() );
		$fields = QuoteProjection::for_shopper( $stripped, $at, RequestContext::create() )->fields();
		self::assertSame( 'stripped', $fields['status'] ); self::assertFalse( $fields['currently_applicable'] ); self::assertSame( [], $fields['money'] );
		self::assertSame( 'quote_unavailable', $fields['reason_code'] ); self::assertSame( 'Delivery', $fields['customer_label'] );
		self::assertNull( $stripped->terms() ); self::assertNull( $stripped->context() );
		$this->assert_private_absent( $fields, [ $header->body_digest(), $header->material_digest() ] );
	}
}
