<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryCommercial;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests, FixedPromotionNativeResult};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, NativeCartQuotePreparation, QuoteNativeCaptureSource, QuoteNativeState};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteReference, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures};

require_once dirname( __DIR__ ) . '/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__ ) . '/DeliveryQuote/LegacyQuoteProviderFixtures.php';

/** Actual retained capture over synthetic native facts; never physical Woo or campaign authority. */
final class FixedPromotionQuoteFixture {
	public static function key(): string { return NativeCartQuotePreparation::component_key( 'in_store|delivery|20' ); }
	public static function owner(): QuoteOwner { return QuoteFixtures::owner(); }
	public static function original_context(): QuoteContext {
		$data = LegacyQuoteProviderFixtures::context()->private_facts(); $data['groups'][0]['component_key'] = self::key(); $data['lines'][0]['component_key'] = self::key(); return QuoteContext::from_array( $data );
	}
	public static function original_terms(): QuoteTerms {
		$data = LegacyQuoteProviderFixtures::terms()->private_facts(); $data['groups'][0]['component_key'] = self::key(); return QuoteTerms::from_array( $data );
	}
	public static function original_header( ?QuoteContext $context = null, ?QuoteTerms $terms = null ): QuoteHeader {
		return QuoteHeader::issue( QuoteId::generate(), self::owner(), $context ?? self::original_context(), $terms ?? self::original_terms(), QuoteFixtures::time(), self::namespaces(), 'legacy_fixed_base_v1' );
	}
	public static function rule( string $discount = '3.00' ): FixedDeliveryPromotion {
		return FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'fixed_promotion_fixture', 'version' => 1,
			'discount' => [ 'amount' => $discount, 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'private_fixed_funder', 'version' => 2 ] ] );
	}
	public static function rules(): array { return [ [ 'component_key' => self::key(), 'rule' => self::rule() ] ]; }
	public static function plan( ?QuoteTerms $terms = null, ?FixedDeliveryPromotion $rule = null ): DeliveryPromotionPlan {
		$terms ??= self::original_terms(); $rule ??= self::rule();
		return DeliveryPromotionPlan::from_evidence( $terms, self::key(), $rule, 'fixed_planning_fixture', 1,
			[ 'format_version' => 1, 'provider' => [ 'code' => 'fixed_planning_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => self::key(), 'rule_digest' => $rule->digest(),
				'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => QuoteFixtures::digest( 'fixed planning eligibility' ) ],
				'budget' => [ 'state' => 'available', 'evidence_digest' => QuoteFixtures::digest( 'fixed planning budget' ) ] ] );
	}
	public static function source(): FixedPromotionQuoteFixtureSource { return new FixedPromotionQuoteFixtureSource( self::facts() ); }
	public static function result( ?QuoteNativeCaptureSource $source = null ): FixedPromotionNativeResult {
		$context = self::original_context(); $terms = self::original_terms();
		$requests = FixedPromotionNativeRequests::from_captured( self::original_header( $context, $terms ), $context, $terms,
			[ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ] ], [ [ 'component_key' => self::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		return ( new FixedPromotionNativeCapture( $source ?? self::source() ) )->capture( self::owner(), $requests );
	}
	public static function provider( ?FixedPromotionNativeResult $result = null, ?array $rules = null ): FixedPromotionQuoteProvider {
		return FixedPromotionQuoteProvider::from_native_result( $result ?? self::result(), $rules ?? self::rules() );
	}
	public static function second_key(): string { return NativeCartQuotePreparation::component_key( 'second|delivery|20' ); }
	public static function mixed_rules(): array { return [ ...self::rules(), [ 'component_key' => self::second_key(), 'rule' => null ] ]; }
	/** Includes an explicit unadjusted component; only the first component has a frozen plan. */
	public static function mixed_result(): FixedPromotionNativeResult {
		$context_data = self::original_context()->private_facts(); $terms_data = self::original_terms()->private_facts();
		$line = $context_data['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $line['component_key'] = self::second_key(); $context_data['lines'][] = $line;
		$group = $context_data['groups'][0]; $group['component_key'] = self::second_key(); $group['line_keys'] = [ 'line_two' ]; $context_data['groups'][] = $group;
		$group = $terms_data['groups'][0]; $group['component_key'] = self::second_key(); $terms_data['groups'][] = $group;
		$context = QuoteContext::from_array( $context_data ); $terms = QuoteTerms::from_array( $terms_data );
		$requests = FixedPromotionNativeRequests::from_captured( self::original_header( $context, $terms ), $context, $terms,
			[ [ 'component_key' => self::key(), 'plan' => self::plan( $terms ) ], [ 'component_key' => self::second_key(), 'plan' => null ] ],
			[ [ 'component_key' => self::key(), 'legacy_group_id' => 'in_store|delivery|20' ], [ 'component_key' => self::second_key(), 'legacy_group_id' => 'second|delivery|20' ] ] );
		$facts = self::facts(); $package = $facts['packages'][0]; $package['group_id'] = 'second|delivery|20'; $package['cost'] = '12.50';
		$package['taxes'][0]['amount'] = '2.50'; $package['fresh_taxes'][0]['amount'] = '2.50'; $package['rounded_tax'] = '2.50'; $package['display_total'] = '15.00';
		$package['members'][0]['line_key'] = 'line_two'; $package['members'][0]['product_id'] = 11;
		$facts['packages'][] = $package; $facts['cart_shipping_total'] = '22.00'; $facts['cart_shipping_tax'] = '4.40';
		return ( new FixedPromotionNativeCapture( new FixedPromotionQuoteFixtureSource( $facts ) ) )->capture( self::owner(), $requests );
	}
	public static function mixed_provider(): FixedPromotionQuoteProvider { return self::provider( self::mixed_result(), self::mixed_rules() ); }
	public static function header( ?FixedPromotionQuoteProvider $provider = null, ?QuoteReference $reference = null ): QuoteHeader {
		$provider ??= self::provider();
		return QuoteHeader::issue( $reference?->id() ?? QuoteId::generate(), self::owner(), $provider->context(), $provider->terms(), QuoteFixtures::time(), self::namespaces(), 'fixed_promotion_native_v1', 1, $reference );
	}
	private static function namespaces(): array { return [ 'issue' => QuoteFixtures::digest( 'fixed issue' ), 'accept' => QuoteFixtures::digest( 'fixed accept' ), 'invalidate' => QuoteFixtures::digest( 'fixed invalidate' ) ]; }
	public static function facts(): array {
		$package = [ 'group_id' => 'in_store|delivery|20', 'rate_id' => 'delivery_engine_selected_offer:1:fixture', 'method_id' => 'delivery_engine_selected_offer', 'instance_id' => 1, 'tax_status' => 'taxable', 'label' => 'Delivery', 'cost' => '9.50',
			'taxes' => [ [ 'rate_id' => 7, 'amount' => '1.90' ] ], 'fresh_taxes' => [ [ 'rate_id' => 7, 'amount' => '1.90' ] ], 'rounded_tax' => '1.90', 'display_total' => '11.40',
			'members' => [ [ 'line_key' => 'line_one', 'product_id' => 10, 'variation_id' => null, 'quantity' => '2' ] ] ];
		$source = [ 'option_rows' => [], 'tax_rows' => [], 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [], 'session_row' => [], 'customer_rows' => [],
			'selectors' => [ 'option_names' => [ 'woocommerce_currency' ], 'tax_class' => '', 'method_instance_ids' => [ 1 ], 'session_key' => 'fixture-session', 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_' ] ];
		return [ 'currency' => 'GHS', 'display_precision' => 2, 'internal_precision' => 4, 'exempt' => false, 'tax_class' => '', 'location_digest' => QuoteFixtures::digest( 'tax_location' ), 'destination_digest' => QuoteFixtures::digest( 'destination' ),
			'key_epoch' => 'fixture_key_1', 'rounding' => 'per_line', 'tax_enabled' => true, 'coupons' => 0, 'unsupported_effects' => false,
			'cart_shipping_total' => '9.50', 'cart_shipping_tax' => '1.90', 'packages' => [ $package ], 'source_facts' => $source ];
	}
}

final class FixedPromotionQuoteFixtureSource implements QuoteNativeCaptureSource {
	public int $reads = 0;
	public bool $same = true;
	private ?string $captured = null;
	public function __construct( public array $data ) {}
	public function current_owner(): QuoteOwner { return FixedPromotionQuoteFixture::owner(); }
	public function capture(): QuoteNativeState { ++$this->reads; $this->captured = QuoteJson::encode( $this->data ); return QuoteNativeState::from_array( $this->data ); }
	public function unchanged(): bool { return $this->same && null !== $this->captured && $this->captured === QuoteJson::encode( $this->data ); }
}
