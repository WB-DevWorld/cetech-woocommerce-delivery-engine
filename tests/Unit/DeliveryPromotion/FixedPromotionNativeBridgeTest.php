<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests, FixedPromotionNativeResult};
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeCaptureSource, QuoteNativeReceiptCapture, QuoteNativeState};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteOwner, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Real retained capture code over synthetic attestations; this does not certify Woo HTTP. */
final class FixedPromotionNativeBridgeTest extends TestCase {
	public function test_discounted_native_amount_and_fresh_taxes_are_captured_beside_original_identity(): void {
		$requests = self::requests(); $source = new PromotionNativeFixtureSource( self::facts() );
		$before = $requests->original_terms()->to_private_json();
		$result = ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $requests );
		$group = $result->native_receipt()->group( self::key() );
		self::assertSame( 1, $source->reads );
		self::assertSame( '9.5000', $group->final_money()->amount() );
		self::assertSame( '1.9000', $group->tax_money()->amount() );
		self::assertSame( '11.4000', $group->total_money()->amount() );
		self::assertSame( '11.40', $group->native_money_receipt()['display_total']['amount'] );
		self::assertSame( $requests, $result->original_requests() );
		self::assertSame( $before, $requests->original_terms()->to_private_json() );
		self::assertSame( '12.50', $requests->original_terms()->private_facts()['groups'][0]['final']['amount'] );
		self::assertNotSame( $requests->original_context()->digest(), $result->native_context()->digest() );
		self::assertSame( $requests->original_context()->private_facts()['lines'], $result->native_context()->private_facts()['lines'] );
		self::assertSame( $result->native_receipt()->tax_facts(), $result->native_context()->private_facts()['tax'] );
		self::assertTrue( $result->unchanged() );
		// Existing capture's internal tags are retained, not relabelled as a promoted accepted quote.
		self::assertSame( 'none', $group->promotion()['state'] );
		$this->expectException( \InvalidArgumentException::class );
		$requests->header()->assert_body( $result->native_context(), $requests->original_terms() );
	}

	#[DataProvider( 'native_failures' )]
	public function test_stale_native_money_tax_membership_and_owner_never_yield_a_result( string $edit ): void {
		$facts = self::facts();
		switch ( $edit ) {
			case 'old_price': $facts['packages'][0]['cost'] = '12.50'; $facts['cart_shipping_total'] = '12.50'; $facts['packages'][0]['display_total'] = '14.40'; break;
			case 'stale_tax': $facts['packages'][0]['fresh_taxes'][0]['amount'] = '2.50'; break;
			case 'member': $facts['packages'][0]['members'][0]['product_id'] = 11; break;
			case 'quantity': $facts['packages'][0]['members'][0]['quantity'] = '3'; break;
			case 'destination': $facts['destination_digest'] = QuoteFixtures::digest( 'foreign destination' ); break;
			case 'currency': $facts['currency'] = 'USD'; break;
			case 'coupon': $facts['coupons'] = 1; break;
			case 'aggregate': $facts['cart_shipping_total'] = '9.51'; break;
		}
		$source = new PromotionNativeFixtureSource( $facts );
		$source->revoke = 'owner_revoked' === $edit; $source->same = 'changed_source' !== $edit; $source->fail = 'source_error' === $edit;
		try { ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), self::requests() ); self::fail( 'Changed native facts must refuse.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Promoted native capture unavailable.', $error->getMessage() ); }
		self::assertSame( 1, $source->reads );
	}
	public static function native_failures(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'old_price', 'stale_tax', 'member', 'quantity', 'destination', 'currency', 'coupon', 'aggregate', 'owner_revoked', 'changed_source', 'source_error' ] );
	}

	public function test_foreign_explicit_owner_refuses_before_any_native_source_method(): void {
		$owner = QuoteFixtures::owner()->facts(); $owner['session_hash'] = QuoteFixtures::digest( 'foreign session' );
		$source = new PromotionNativeFixtureSource( self::facts() );
		try { ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteOwner::from_array( $owner ), self::requests() ); self::fail(); }
		catch ( \InvalidArgumentException ) { self::assertSame( 0, $source->reads ); self::assertSame( 0, $source->owner_reads ); }
	}

	public function test_v2_promise_capture_is_refused_intact_before_any_source_call(): void {
		$context = PromiseHandoffFixture::context(); $terms = PromiseHandoffFixture::terms();
		$header = PromiseHandoffFixture::header( $context, $terms );
		$before = [ $context->to_private_json(), $terms->to_private_json(), $header->to_private_json() ];
		$source = new PromotionNativeFixtureSource( self::facts() );
		try {
			$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ], self::mapping() );
			( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $requests ); self::fail( 'Existing promise capture must not be stripped or rebound.' );
		} catch ( \InvalidArgumentException ) { self::assertSame( 0, $source->reads ); self::assertSame( 0, $source->owner_reads ); }
		self::assertSame( $before, [ $context->to_private_json(), $terms->to_private_json(), $header->to_private_json() ] );
	}

	#[DataProvider( 'explicit_discounts' )]
	public function test_explicit_free_or_zero_discount_still_requires_the_matching_native_money( string $discount, string $cost, string $tax, string $display ): void {
		$context = LegacyQuoteProviderFixtures::context(); $terms = LegacyQuoteProviderFixtures::terms();
		$requests = FixedPromotionNativeRequests::from_captured( self::header( $context, $terms ), $context, $terms, [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms, $discount ) ] ], self::mapping() );
		$facts = self::facts(); $facts['packages'][0]['cost'] = $cost; $facts['cart_shipping_total'] = $cost; $facts['cart_shipping_tax'] = $tax;
		$facts['packages'][0]['taxes'][0]['amount'] = $tax; $facts['packages'][0]['fresh_taxes'][0]['amount'] = $tax;
		$facts['packages'][0]['rounded_tax'] = $tax; $facts['packages'][0]['display_total'] = $display;
		$result = ( new FixedPromotionNativeCapture( new PromotionNativeFixtureSource( $facts ) ) )->capture( QuoteFixtures::owner(), $requests );
		self::assertSame( $cost, $requests->native_requests()[0]->expected_ex_tax->amount() );
		self::assertSame( $display, $result->native_receipt()->group( self::key() )->native_money_receipt()['display_total']['amount'] );
	}
	public static function explicit_discounts(): array { return [ [ '12.50', '0.00', '0.00', '0.00' ], [ '0.00', '12.50', '2.50', '15.00' ] ]; }

	#[DataProvider( 'invalid_requests' )]
	public function test_preflight_rejects_incomplete_foreign_or_ambiguous_plans_and_mapping( string $edit ): void {
		$context = LegacyQuoteProviderFixtures::context(); $terms = LegacyQuoteProviderFixtures::terms();
		$header = self::header( $context, $terms );
		$slots = [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ];
		$mapping = self::mapping();
		switch ( $edit ) {
			case 'missing_slot': $slots = []; break;
			case 'duplicate_slot': $slots[] = $slots[0]; break;
			case 'foreign_slot': $slots[0]['component_key'] = QuoteFixtures::digest( 'foreign component' ); break;
			case 'no_plan': $slots[0]['plan'] = null; break;
			case 'untyped_plan': $slots[0]['plan'] = $slots[0]['plan']->private_facts(); break;
			case 'refused_plan': $slots[0]['plan'] = DeliveryPromotionPlan::refused( $terms, self::key(), self::rule(), 'budget_exhausted' ); break;
			case 'foreign_terms': $data = $terms->private_facts(); $data['groups'][0]['customer_label'] = 'Different immutable terms'; $slots[0]['plan'] = self::plan( QuoteTerms::from_array( $data ) ); break;
			case 'missing_mapping': $mapping = []; break;
			case 'duplicate_mapping': $mapping[] = $mapping[0]; break;
			case 'foreign_mapping': $mapping[0]['component_key'] = QuoteFixtures::digest( 'foreign component' ); break;
			case 'posted_price': $mapping[0]['amount'] = '0.00'; break;
			case 'unknown_header_body': $context_data = $context->private_facts(); $context_data['selection_digest'] = QuoteFixtures::digest( 'changed selected offer' ); $context = QuoteContext::from_array( $context_data ); break;
		}
		$source = new PromotionNativeFixtureSource( self::facts() );
		try {
			$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, $slots, $mapping );
			( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $requests ); self::fail( 'Unsupported preparation must refuse.' );
		} catch ( \InvalidArgumentException ) { self::assertSame( 0, $source->reads ); self::assertSame( 0, $source->owner_reads ); }
	}
	public static function invalid_requests(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'missing_slot', 'duplicate_slot', 'foreign_slot', 'no_plan', 'untyped_plan', 'refused_plan', 'foreign_terms', 'missing_mapping', 'duplicate_mapping', 'foreign_mapping', 'posted_price', 'unknown_header_body' ] );
	}

	public function test_exact_two_component_coverage_preserves_explicit_no_adjustment_and_refuses_duplicate_legacy_ids(): void {
		$context_data = LegacyQuoteProviderFixtures::context()->private_facts();
		$second = QuoteFixtures::digest( 'second component' );
		$line = $context_data['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $line['component_key'] = $second; $context_data['lines'][] = $line;
		$group = $context_data['groups'][0]; $group['component_key'] = $second; $group['line_keys'] = [ 'line_two' ]; $context_data['groups'][] = $group;
		$context = QuoteContext::from_array( $context_data );
		$terms_data = LegacyQuoteProviderFixtures::terms()->private_facts(); $group = $terms_data['groups'][0]; $group['component_key'] = $second; $terms_data['groups'][] = $group;
		$terms = QuoteTerms::from_array( $terms_data ); $header = self::header( $context, $terms );
		$slots = [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ], [ 'component_key' => $second, 'plan' => null ] ];
		$mapping = [ ...self::mapping(), [ 'component_key' => $second, 'legacy_group_id' => 'second|delivery|20' ] ];
		$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, $slots, $mapping );
		$by_key = []; foreach ( $requests->native_requests() as $request ) { $by_key[$request->component_key] = $request->expected_ex_tax->amount(); }
		self::assertSame( '9.50', $by_key[self::key()] ); self::assertSame( '12.50', $by_key[$second] );
		$permuted = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, array_reverse( $slots ), array_reverse( $mapping ) );
		self::assertSame( $requests->digest(), $permuted->digest() );
		$facts = self::facts(); $package = $facts['packages'][0];
		$package['group_id'] = 'second|delivery|20'; $package['cost'] = '12.50';
		$package['taxes'][0]['amount'] = '2.50'; $package['fresh_taxes'][0]['amount'] = '2.50'; $package['rounded_tax'] = '2.50'; $package['display_total'] = '15.00';
		$package['members'] = [ [ 'line_key' => 'line_two', 'product_id' => 11, 'variation_id' => null, 'quantity' => '2' ] ];
		$facts['packages'][] = $package; $facts['cart_shipping_total'] = '22.00'; $facts['cart_shipping_tax'] = '4.40';
		$source = new PromotionNativeFixtureSource( $facts );
		$result = ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $requests );
		self::assertSame( 1, $source->reads );
		self::assertSame( '9.5000', $result->native_receipt()->group( self::key() )->final_money()->amount() );
		self::assertSame( '12.5000', $result->native_receipt()->group( $second )->final_money()->amount() );
		self::assertSame( '1.9000', $result->native_receipt()->group( self::key() )->tax_money()->amount() );
		self::assertSame( '2.5000', $result->native_receipt()->group( $second )->tax_money()->amount() );
		self::assertSame( $context->private_facts()['lines'], $result->native_context()->private_facts()['lines'] );
		$mapping[1]['legacy_group_id'] = $mapping[0]['legacy_group_id'];
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionNativeRequests::from_captured( $header, $context, $terms, $slots, $mapping );
	}

	public function test_request_digest_binds_trusted_mapping_and_frozen_plans_cannot_be_changed_by_references(): void {
		$context = LegacyQuoteProviderFixtures::context(); $terms = LegacyQuoteProviderFixtures::terms(); $header = self::header( $context, $terms );
		$plan = self::plan( $terms ); $slots = [ [ 'component_key' => self::key(), 'plan' => &$plan ] ];
		$mapping = self::mapping(); $legacy = $mapping[0]['legacy_group_id']; $mapping[0]['legacy_group_id'] = &$legacy;
		$first = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, $slots, $mapping ); $before = $first->digest();
		$plan = null; $legacy = 'different|delivery|20';
		self::assertSame( $before, $first->digest() ); self::assertNotNull( $first->plans()[0]['plan'] );
		$second = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ], $mapping );
		self::assertNotSame( $first->digest(), $second->digest() );
		$changed_header = $header->private_facts(); $changed_header['owner']['principal_hash'] = QuoteFixtures::digest( 'different original owner' );
		$foreign_header = QuoteHeader::from_array( $changed_header );
		$foreign = FixedPromotionNativeRequests::from_captured( $foreign_header, $context, $terms, [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ], self::mapping() );
		self::assertSame( $header->id()->value(), $foreign_header->id()->value() ); self::assertSame( $header->body_digest(), $foreign_header->body_digest() );
		self::assertNotSame( $first->digest(), $foreign->digest() );
		$source = new PromotionNativeFixtureSource( self::facts() );
		try { ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $foreign ); self::fail( 'Foreign header owner must refuse.' ); }
		catch ( \InvalidArgumentException ) { self::assertSame( 0, $source->reads ); self::assertSame( 0, $source->owner_reads ); }
	}

	public function test_typed_result_refuses_unrelated_undiscounted_native_receipt_and_later_fence_changes(): void {
		$requests = self::requests(); $source = new PromotionNativeFixtureSource( self::facts() );
		$result = ( new FixedPromotionNativeCapture( $source ) )->capture( QuoteFixtures::owner(), $requests );
		$source->same = false; self::assertFalse( $result->unchanged() ); self::assertSame( 1, $source->reads );
		$base = self::facts(); $base['packages'][0]['cost'] = '12.50'; $base['cart_shipping_total'] = '12.50'; $base['packages'][0]['display_total'] = '14.40';
		$original_native = new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeGroupRequest( self::key(), 'in_store|delivery|20', \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney::from_array( [ 'amount' => '12.50', 'currency' => 'GHS', 'precision' => 2 ] ) );
		$receipt = ( new QuoteNativeReceiptCapture( new PromotionNativeFixtureSource( $base ) ) )->capture( QuoteFixtures::owner(), [ $original_native ] );
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionNativeResult::from_captured( $requests, $receipt );
	}

	public function test_private_carriers_refuse_generic_exports_and_redact_debug(): void {
		$requests = self::requests(); $capture = new FixedPromotionNativeCapture( new PromotionNativeFixtureSource( self::facts() ) );
		$result = $capture->capture( QuoteFixtures::owner(), $requests );
		foreach ( [ $requests, $capture, $result ] as $carrier ) {
			foreach ( [ 'json', 'php', 'unserialize' ] as $format ) {
				try {
					if ( 'json' === $format ) { json_encode( $carrier, JSON_THROW_ON_ERROR ); }
					elseif ( 'php' === $format ) { serialize( $carrier ); }
					else { $class = get_class( $carrier ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
					self::fail( 'Private native evidence must not be exported implicitly.' );
				} catch ( \LogicException ) { self::assertTrue( true ); }
			}
			ob_start(); var_dump( $carrier ); $debug = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( 'private_funder', $debug ); self::assertStringNotContainsString( '9.50', $debug );
		}
	}

	private static function key(): string { return QuoteFixtures::digest( 'component' ); }
	private static function mapping(): array { return [ [ 'component_key' => self::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ]; }
	private static function header( QuoteContext $context, QuoteTerms $terms ): QuoteHeader {
		$id = QuoteId::generate();
		return QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $terms, QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'issue' ), 'accept' => QuoteFixtures::digest( 'accept' ), 'invalidate' => QuoteFixtures::digest( 'invalidate' ) ], 'legacy_fixed_base_v1' );
	}
	private static function requests(): FixedPromotionNativeRequests {
		$context = LegacyQuoteProviderFixtures::context(); $terms = LegacyQuoteProviderFixtures::terms();
		return FixedPromotionNativeRequests::from_captured( self::header( $context, $terms ), $context, $terms, [ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ], self::mapping() );
	}
	private static function rule( string $discount = '3.00' ): FixedDeliveryPromotion {
		return FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'test_fixed', 'version' => 1, 'discount' => [ 'amount' => $discount, 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'private_funder', 'version' => 1 ] ] );
	}
	private static function plan( QuoteTerms $terms, string $discount = '3.00' ): DeliveryPromotionPlan {
		$rule = self::rule( $discount );
		return DeliveryPromotionPlan::from_evidence( $terms, self::key(), $rule, 'trusted_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'trusted_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => self::key(), 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => QuoteFixtures::digest( 'eligibility' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => QuoteFixtures::digest( 'budget' ) ] ] );
	}
	private static function facts(): array {
		$package = [ 'group_id' => 'in_store|delivery|20', 'rate_id' => 'delivery_engine_selected_offer:1:fixture', 'method_id' => 'delivery_engine_selected_offer', 'instance_id' => 1, 'tax_status' => 'taxable', 'label' => 'Delivery', 'cost' => '9.50', 'taxes' => [ [ 'rate_id' => 7, 'amount' => '1.90' ] ], 'fresh_taxes' => [ [ 'rate_id' => 7, 'amount' => '1.90' ] ], 'rounded_tax' => '1.90', 'display_total' => '11.40', 'members' => [ [ 'line_key' => 'line_one', 'product_id' => 10, 'variation_id' => null, 'quantity' => '2' ] ] ];
		$source = [ 'option_rows' => [], 'tax_rows' => [], 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [], 'session_row' => [], 'customer_rows' => [], 'selectors' => [ 'option_names' => [ 'woocommerce_currency' ], 'tax_class' => '', 'method_instance_ids' => [ 1 ], 'session_key' => 'fixture-session', 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_' ] ];
		return [ 'currency' => 'GHS', 'display_precision' => 2, 'internal_precision' => 4, 'exempt' => false, 'tax_class' => '', 'location_digest' => QuoteFixtures::digest( 'tax_location' ), 'destination_digest' => QuoteFixtures::digest( 'destination' ), 'key_epoch' => 'fixture_key_1', 'rounding' => 'per_line', 'tax_enabled' => true, 'coupons' => 0, 'unsupported_effects' => false, 'cart_shipping_total' => '9.50', 'cart_shipping_tax' => '1.90', 'packages' => [ $package ], 'source_facts' => $source ];
	}
}

final class PromotionNativeFixtureSource implements QuoteNativeCaptureSource {
	public int $reads = 0;
	public int $owner_reads = 0;
	public bool $same = true;
	public bool $revoke = false;
	public bool $fail = false;
	public function __construct( private array $facts ) {}
	public function current_owner(): QuoteOwner {
		++$this->owner_reads; $facts = QuoteFixtures::owner()->facts();
		if ( $this->revoke && $this->reads > 0 ) { $facts['principal_hash'] = QuoteFixtures::digest( 'revoked' ); }
		return QuoteOwner::from_array( $facts );
	}
	public function capture(): QuoteNativeState {
		++$this->reads;
		if ( $this->fail ) { throw new \RuntimeException( 'PRIVATE_NATIVE_ERROR' ); }
		return QuoteNativeState::from_array( $this->facts );
	}
	public function unchanged(): bool { return $this->same; }
}
