<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeShippingTaxProjection,QuoteNativeTaxSource};
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteJson,QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures,QuoteFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A guarded matcher is authoritative only for the native zero representation, never for repricing. */
final class QuoteNativeShippingTaxProjectionTest extends TestCase {
	private function fixture( bool $enabled = true ): array {
		$rows = [];
		foreach ( [ 11, 12, 99 ] as $id ) { $rows[] = [ 'tax_rate_id' => (string) $id, 'tax_rate_country' => 99 === $id ? 'US' : 'GH', 'tax_rate_state' => '', 'tax_rate' => '5.0000', 'tax_rate_name' => 'Native ' . $id, 'tax_rate_priority' => (string) $id, 'tax_rate_compound' => '0', 'tax_rate_shipping' => '1', 'tax_rate_order' => '0', 'tax_rate_class' => '' ]; }
		$source = [ 'option_rows' => [ [ 'option_id' => '1', 'option_name' => 'woocommerce_currency', 'option_value' => 'GHS', 'autoload' => 'no' ] ], 'tax_rows' => $rows, 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [], 'customer_rows' => [], 'selectors' => [ 'option_names' => [ 'woocommerce_currency' ], 'tax_class' => '', 'method_instance_ids' => [ 0 ], 'session_key' => 'native-fixture', 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_' ] ];
		$effective = [ 'currency' => 'GHS', 'precision' => 2, 'exempt' => false, 'tax_class' => '', 'location_digest' => QuoteFixtures::digest( 'location' ), 'rounding' => 'per_line', 'tax_enabled' => $enabled ];
		$original = QuoteNativeTaxSource::from_private_facts( [ 'format_version' => 1, 'digest_version' => 2, ...$effective, 'source' => $source ] );
		$c = LegacyQuoteProviderFixtures::context()->private_facts(); $c['tax']['context_digest'] = $original->digest(); $context = QuoteContext::from_array( $c );
		$terms = LegacyQuoteProviderFixtures::terms()->private_facts(); $term = &$terms['groups'][0];
		foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { $term[$field]['amount'] = '0'; }
		$term['promotion']['amount']['amount'] = '0';
		$term['native_tax_receipt'] = [ 'state' => 'recorded', 'source' => 'woocommerce', 'context_digest' => $original->digest(), 'exempt' => false, 'tax_status' => 'taxable', 'tax_class' => '', 'location_digest' => $effective['location_digest'], 'rounding' => 'per_line', 'rates' => [], 'rounded_tax' => [ 'amount' => '0', 'currency' => 'GHS', 'precision' => 2 ], 'display_precision' => 2 ];
		$term['native_money_receipt']['native_total']['amount'] = '0'; $term['native_money_receipt']['display_total']['amount'] = '0'; unset( $term );
		$term = QuoteTerms::from_array( $terms )->private_facts()['groups'][0];
		$physical = [ ...$source, 'session_row' => [] ];
		$rates = []; foreach ( [ 11, 12 ] as $id ) { $rates[$id] = [ 'rate' => 5.0, 'label' => 'Native ' . $id, 'shipping' => 'yes', 'compound' => 'no' ]; }
		return [ $original, $context, $physical, $effective, $rates, $term ];
	}
	private function projection( array $f ): QuoteNativeShippingTaxProjection { return QuoteNativeShippingTaxProjection::from_verified_native( ...array_slice( $f, 0, 5 ) ); }
	public function test_classic_empty_and_exact_native_full_zero_map_share_terms_without_rewriting_facts(): void {
		$f = $this->fixture(); $projection = $this->projection( $f ); $term = $f[5]; $stored = [ 12 => '0.000000', 11 => 0.0 ]; $before = serialize( [ $term, $stored, $f[2] ] );
		self::assertTrue( $projection->accepts_zero_map( $term, [] ) ); self::assertTrue( $projection->accepts_zero_map( $term, $stored ) ); self::assertSame( [ 11 => '0', 12 => '0' ], $projection->zero_map( $term ) );
		self::assertSame( QuoteJson::encode( $f[2] ), QuoteJson::encode( $projection->physical() ) ); self::assertSame( $f[4], $projection->native_rates() ); self::assertSame( $before, serialize( [ $term, $stored, $f[2] ] ) );
		$copy = $projection->physical(); $copy['option_rows'][0]['option_value'] = 'USD'; self::assertSame( 'GHS', $projection->physical()['option_rows'][0]['option_value'] );
	}
	#[DataProvider( 'bad_maps' )]
	public function test_unknown_partial_malformed_and_unrounded_nonzero_maps_are_refused( array $map ): void { $f = $this->fixture(); self::assertFalse( $this->projection( $f )->accepts_zero_map( $f[5], $map ) ); }
	public static function bad_maps(): array { return [ 'missing matched ID' => [ [ 11 => '0' ] ], 'known class row outside native match' => [ [ 11 => '0', 12 => '0', 99 => '0' ] ], 'foreign ID' => [ [ 11 => '0', 12 => '0', 98 => '0' ] ], 'wrong complete set' => [ [ 11 => '0', 99 => '0' ] ], 'tiny positive' => [ [ 11 => '0.000001', 12 => '0' ] ], 'tiny negative' => [ [ 11 => '-0.000001', 12 => '0' ] ], 'noncanonical ID' => [ [ '011' => '0', 12 => '0' ] ], 'zero ID' => [ [ 0 => '0', 11 => '0', 12 => '0' ] ], 'overflow ID' => [ [ '999999999999999999999999' => '0' ] ], 'exponential zero' => [ [ 11 => '0e0', 12 => '0' ] ], 'null zero' => [ [ 11 => null, 12 => '0' ] ], 'not finite' => [ [ 11 => NAN, 12 => '0' ] ] ]; }
	#[DataProvider( 'bad_terms' )]
	public function test_alternate_never_changes_nonzero_terms_or_native_tax_context( string $kind ): void {
		$f = $this->fixture(); $term = $f[5];
		match ( $kind ) {
			'final', 'tax' => $term[$kind] = [ 'amount' => '0.000001', 'currency' => 'GHS', 'precision' => 6 ],
			'rounded' => $term['native_tax_receipt']['rounded_tax'] = [ 'amount' => '0.000001', 'currency' => 'GHS', 'precision' => 6 ],
			'rates' => $term['native_tax_receipt']['rates'] = [ [ 'rate_id' => 11, 'amount' => [ 'amount' => '0', 'currency' => 'GHS', 'precision' => 2 ] ] ],
			'exempt' => $term['native_tax_receipt']['exempt'] = true,
			'status' => $term['native_tax_receipt']['tax_status'] = 'none',
			'class' => $term['native_tax_receipt']['tax_class'] = 'reduced-rate',
			'location' => $term['native_tax_receipt']['location_digest'] = QuoteFixtures::digest( 'other-location' ),
			'context' => $term['native_tax_receipt']['context_digest'] = QuoteFixtures::digest( 'other-context' ),
			'rounding' => $term['native_tax_receipt']['rounding'] = 'subtotal',
			'precision' => $term['native_tax_receipt']['display_precision'] = 3,
			'currency' => $term['final']['currency'] = 'USD',
		};
		self::assertNull( $this->projection( $f )->zero_map( $term ) ); self::assertFalse( $this->projection( $f )->accepts_zero_map( $term, [ 11 => '0', 12 => '0' ] ) );
	}
	public static function bad_terms(): array { return array_map( static fn( string $k ): array => [ $k ], array_combine( [ 'final', 'tax', 'rounded', 'rates', 'exempt', 'status', 'class', 'location', 'context', 'rounding', 'precision', 'currency' ], [ 'final', 'tax', 'rounded', 'rates', 'exempt', 'status', 'class', 'location', 'context', 'rounding', 'precision', 'currency' ] ) ); }
	public function test_disabled_native_taxes_never_authorize_zero_entries(): void { $f = $this->fixture( false ); self::assertNull( $this->projection( $f )->zero_map( $f[5] ) ); }
	public function test_private_matcher_result_detaches_referenced_native_fields(): void { $f = $this->fixture(); $label = 'Native 11'; $f[4][11]['label'] = &$label; $p = $this->projection( $f ); $label = 'changed after capture'; self::assertSame( 'Native 11', $p->native_rates()[11]['label'] ); self::assertTrue( $p->accepts_zero_map( $f[5], [ 11 => '0', 12 => '0' ] ) ); }
	public function test_rounded_money_precision_remains_original_even_when_amount_is_zero(): void { $f = $this->fixture(); $f[5]['native_tax_receipt']['rounded_tax']['precision'] = 3; self::assertNull( $this->projection( $f )->zero_map( $f[5] ) ); }
	#[DataProvider( 'bad_sources' )]
	public function test_every_original_source_family_and_effective_selector_is_fenced( string $kind ): void {
		$f = $this->fixture();
		match ( $kind ) {
			'option' => $f[2]['option_rows'][0]['option_value'] = 'USD',
			'tax' => $f[2]['tax_rows'][0]['tax_rate'] = '6.0000',
			'class' => $f[2]['tax_class_rows'][] = [ 'tax_rate_class_id' => '1', 'name' => 'Other', 'slug' => 'other' ],
			'location' => $f[2]['tax_location_rows'][] = [ 'location_id' => '1', 'location_code' => 'OTHER', 'tax_rate_id' => '11', 'location_type' => 'city' ],
			'method' => $f[2]['method_rows'][] = [ 'zone_id' => '1', 'instance_id' => '1', 'method_id' => 'other', 'method_order' => '0', 'is_enabled' => '1' ],
			'customer' => $f[2]['customer_rows'][] = [ 'umeta_id' => '1', 'user_id' => '1', 'meta_key' => 'is_vat_exempt', 'meta_value' => 'yes' ],
			'site' => $f[2]['selectors']['site_id'] = 2,
			'prefix' => $f[2]['selectors']['table_prefix'] = 'other_',
			'exempt' => $f[3]['exempt'] = true,
			'enabled' => $f[3]['tax_enabled'] = false,
		};
		$this->expectException( \Exception::class ); $this->projection( $f );
	}
	public static function bad_sources(): array { return array_map( static fn( string $k ): array => [ $k ], array_combine( [ 'option', 'tax', 'class', 'location', 'method', 'customer', 'site', 'prefix', 'exempt', 'enabled' ], [ 'option', 'tax', 'class', 'location', 'method', 'customer', 'site', 'prefix', 'exempt', 'enabled' ] ) ); }
	#[DataProvider( 'bad_matchers' )]
	public function test_matcher_shape_and_physical_rate_attributes_are_closed( string $kind ): void {
		$f = $this->fixture();
		match ( $kind ) {
			'unknown' => $f[4][98] = $f[4][11], 'label' => $f[4][11]['label'] = 'forged', 'rate' => $f[4][11]['rate'] = 6.0, 'integer rate' => $f[4][11]['rate'] = 5, 'shipping' => $f[4][11]['shipping'] = 'no', 'compound' => $f[4][11]['compound'] = 'yes', 'extra' => $f[4][11]['extra'] = true, 'missing' => $f[4][11] = [ 'rate' => 5.0 ], 'nan' => $f[4][11]['rate'] = NAN, 'noncanonical' => $f[4]['011'] = $f[4][11],
		};
		$this->expectException( \UnexpectedValueException::class ); $this->projection( $f );
	}
	public static function bad_matchers(): array { return array_map( static fn( string $k ): array => [ $k ], array_combine( [ 'unknown', 'label', 'rate', 'integer rate', 'shipping', 'compound', 'extra', 'missing', 'nan', 'noncanonical' ], [ 'unknown', 'label', 'rate', 'integer rate', 'shipping', 'compound', 'extra', 'missing', 'nan', 'noncanonical' ] ) ); }
	public function test_native_zero_tax_item_census_retains_exact_stored_rates(): void {
		$f = $this->fixture(); $projection = $this->projection( $f ); $term = $f[5]; $map = [ 11 => '0', 12 => '0' ]; self::assertTrue( $projection->accepts_zero_map( $term, $map ) );
		$native = [ 'lines' => [], 'tax' => [ [ 'rate_id' => 11, 'tax' => '0', 'shipping_tax' => '0' ], [ 'rate_id' => 12, 'tax' => '0', 'shipping_tax' => '0' ] ], 'total_tax' => '0' ]; $raw = serialize( $native );
		$stager = ( new \ReflectionClass( QuoteNativeOrderStager::class ) )->newInstanceWithoutConstructor(); $assert = new \ReflectionMethod( $stager, 'assert_tax_lines' );
		try { $assert->invoke( $stager, $native, [ $term ], 'GHS' ); self::fail( 'Unproved extra tax items were accepted.' ); } catch ( \UnexpectedValueException ) { self::assertTrue( true ); }
		$assert->invoke( $stager, $native, [ $term ], 'GHS', [ $term['component_key'] => $projection->zero_map( $term ) ] ); self::assertSame( $raw, serialize( $native ) );
		$native['tax'][] = [ 'rate_id' => 99, 'tax' => '0', 'shipping_tax' => '0' ]; $this->expectException( \UnexpectedValueException::class ); $assert->invoke( $stager, $native, [ $term ], 'GHS', [ $term['component_key'] => $projection->zero_map( $term ) ] );
	}
	public function test_zero_candidate_fastpath_does_not_request_lookup_for_nonzero_or_empty_maps(): void { self::assertFalse( QuoteNativeShippingTaxProjection::is_native_zero_candidate( [] ) ); self::assertFalse( QuoteNativeShippingTaxProjection::is_native_zero_candidate( [ 11 => '0.000001' ] ) ); self::assertTrue( QuoteNativeShippingTaxProjection::is_native_zero_candidate( [ 11 => '0.0000' ] ) ); }
	public function test_projection_cannot_be_accidentally_published_or_serialized(): void { $p = $this->projection( $this->fixture() ); foreach ( [ static fn(): string => serialize( $p ), static fn(): string|false => json_encode( $p ) ] as $consumer ) { try { $consumer(); self::fail( 'Private projection escaped.' ); } catch ( \LogicException ) { self::assertTrue( true ); } } }
}
