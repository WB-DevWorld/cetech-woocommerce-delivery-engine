<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
require_once __DIR__ . '/../../stubs/woocommerce-product-stub.php';

// Registration-shape fixture only; no WordPress runtime behavior is claimed.
if ( ! class_exists( '\WP_Hook', false ) ) { eval( 'class WP_Hook { public array $callbacks = []; }' ); }

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourcePlan;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceLocalBinding;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteNativeSourcePreparer;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteCapturedSourceView;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures as F;
use PHPUnit\Framework\TestCase;

final class SourceSnapshotTest extends TestCase {
	public function test_complete_thousand_candidate_hash_preserves_the_quote_body_budget(): void {
		$cards = []; for ( $id = 1; $id <= 1000; ++$id ) { $cards[] = F::card( overrides: [ 'id' => $id ] ); }
		$snapshot = $this->snapshot( $cards ); self::assertSame( 1000, $snapshot->candidate_count( 20, 50, 'GHS' ) ); self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $snapshot->candidate_digest( 20, 50, 'GHS' ) ); self::assertSame( 65536, QuoteJson::MAX_BYTES );
		$cards[999]['base_amount'] = '99.00'; self::assertFalse( $snapshot->matches( $this->snapshot( $cards ) ) );
	}
	public function test_same_second_full_row_change_cannot_hide_behind_a_timestamp(): void {
		$old = F::card( overrides: [ 'updated_at' => '2026-10-07 05:00:00' ] ); $new = $old; $new['base_amount'] = '8.00';
		self::assertFalse( $this->snapshot( [ $old ] )->matches( $this->snapshot( [ $new ] ) ) ); self::assertSame( $old['updated_at'], $new['updated_at'] );
	}
	public function test_inactive_candidate_is_fenced_but_not_given_to_the_retained_engine(): void {
		$inactive = F::card( overrides: [ 'id' => 2, 'status' => 'inactive' ] ); $snapshot = $this->snapshot( [ F::card(), $inactive ] );
		self::assertSame( 2, $snapshot->candidate_count( 20, 50, 'GHS' ) ); self::assertCount( 1, $snapshot->active_repository()->listActiveForQuoteMatch( 20, 50, 'GHS' ) ); $inactive['priority'] = 1; self::assertFalse( $snapshot->matches( $this->snapshot( [ F::card(), $inactive ] ) ) );
	}
	public function test_effective_to_remains_inclusive_at_the_legacy_second(): void {
		$snapshot = $this->snapshot( [ F::card( overrides: [ 'effective_to' => '2026-10-07 05:00:00' ] ) ] );
		self::assertTrue( $snapshot->applicable_at( QuoteTime::parse( '2026-10-07 05:00:00.999999' ) ) ); self::assertFalse( $snapshot->applicable_at( QuoteTime::parse( '2026-10-07 05:00:01.000000' ) ) );
	}
	public function test_future_candidate_activation_refuses_even_when_all_rows_are_unchanged(): void {
		$cards = [ F::card(), F::card( overrides: [ 'id' => 2, 'effective_from' => '2026-10-07 05:00:01', 'priority' => 1 ] ) ]; $snapshot = $this->snapshot( $cards );
		self::assertTrue( $snapshot->applicable_at( QuoteTime::parse( '2026-10-07 05:00:00.999999' ) ) ); self::assertFalse( $snapshot->applicable_at( QuoteTime::parse( '2026-10-07 05:00:01.000000' ) ) ); self::assertFalse( $snapshot->matches( $this->snapshot( $cards, at: '2026-10-07 05:00:01.000000' ) ) );
	}
	public function test_snapshot_detaches_nested_input_references(): void {
		$amount = '12.50'; $row = F::card(); $row['base_amount'] = &$amount; $source = 'private_old'; $rows = [ 'product_meta' => [ [ 'meta_id' => '1', 'meta_value' => &$source ] ] ]; $snapshot = $this->snapshot( [ $row ], $rows ); $digest = $snapshot->policy_digest(); $amount = '999.00'; $source = 'private_new';
		self::assertSame( '12.50', $snapshot->active_repository()->findById( 1 )['base_amount'] ); self::assertSame( 'private_old', $snapshot->rows_for( 'product_meta' )[0]['meta_value'] ); self::assertSame( $digest, $snapshot->policy_digest() );
	}
	public function test_native_receipt_binding_does_not_accept_a_new_quantity_or_scope(): void {
		$snapshot = $this->snapshot( [ F::card() ] ); $facts = $snapshot->context()->private_facts(); $facts['tax'] = [ 'context_digest' => hash( 'sha256', 'new_tax' ), 'native_money_digest' => hash( 'sha256', 'new_money' ) ]; foreach ( $facts['groups'] as &$group ) { $group['policy_digest'] = $snapshot->policy_digest(); $group['candidate_digest'] = $snapshot->candidate_digest( 20, 50, 'GHS' ); $group['candidate_count'] = 1; } unset( $group );
		self::assertSame( $facts['tax'], $snapshot->bind_context( QuoteContext::from_array( $facts ) )->context()->private_facts()['tax'] ); $facts['lines'][0]['quantity'] = '3'; $this->expectException( \InvalidArgumentException::class ); $snapshot->bind_context( QuoteContext::from_array( $facts ) );
	}
	public function test_no_member_can_be_hidden_by_the_first_member_tuple(): void {
		$plan = F::plan(); $facts = F::context()->private_facts(); $line = $facts['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $facts['lines'][] = $line; $facts['groups'][0]['line_keys'][] = 'line_two'; $proofs = $plan->member_proofs(); $wrong = $proofs[0]; $wrong['line_key'] = 'line_two'; $wrong['supplier'] = [ 'state' => 'known', 'id' => 999 ]; $proofs[] = $wrong; $this->expectException( \InvalidArgumentException::class ); LegacyQuoteSourcePlan::create( QuoteFixtures::owner(), QuoteContext::from_array( $facts ), $proofs, $plan->rate_ranges(), $plan->fences() );
	}
	public function test_missing_scope_child_selector_is_not_complete(): void {
		$plan = F::plan(); $fences = array_values( array_filter( $plan->fences(), static fn( array $fence ): bool => 'scope_fields' !== $fence['source'] ) ); $this->expectException( \InvalidArgumentException::class ); LegacyQuoteSourcePlan::create( QuoteFixtures::owner(), F::context(), $plan->member_proofs(), $plan->rate_ranges(), $fences );
	}
	public function test_all_finite_source_selectors_fit_without_an_arbitrary_sql_selector(): void {
		$plan = F::plan(); $fences = $plan->fences(); $existing = array_column( $fences, 'source' ); foreach ( LegacyQuoteSourcePlan::SOURCES as $source => $_ ) { if ( in_array( $source, $existing, true ) ) { continue; } $fences[] = match ( $source ) { 'legacy_rules' => [ 'source' => $source, 'targets' => [ [ 'type' => 'product', 'id' => 10 ] ] ], 'coverage_groups', 'coverage_members', 'coverage_postcodes' => [ 'source' => $source ], default => [ 'source' => $source, 'ids' => [ 1 ] ] }; }
		$full = LegacyQuoteSourcePlan::create( QuoteFixtures::owner(), F::context(), $plan->member_proofs(), $plan->rate_ranges(), $fences ); $session = $this->createMock( OperationSession::class ); $session->method( 'site_id' )->willReturn( 1 ); $session->method( 'table_prefix' )->willReturn( 'proof_' ); self::assertCount( 20, $full->fences() ); self::assertCount( 21, $full->tables( $session ) );
	}
	public function test_private_snapshot_refuses_generic_json(): void { $this->expectException( \LogicException::class ); json_encode( $this->snapshot( [ F::card() ] ), JSON_THROW_ON_ERROR ); }
	public function test_captured_repository_never_writes_a_source(): void { $this->expectException( \LogicException::class ); $this->snapshot( [ F::card() ] )->active_repository()->save( [ 'base_amount' => '0' ] ); }
	public function test_changed_hook_registration_is_refused_without_invoking_a_callback(): void {
		$old = $GLOBALS['wp_filter'] ?? null; $GLOBALS['wp_filter'] = []; $binding = LegacyQuoteSourceLocalBinding::capture(); self::assertTrue( $binding->unchanged() ); $called = false; $GLOBALS['wp_filter']['woocommerce_product_get_price'] = self::hook( [ 'callbacks' => [ 10 => [ [ 'function' => static function () use ( &$called ): void { $called = true; } ] ] ] ] );
		try { self::assertFalse( $binding->unchanged() ); self::assertFalse( $called ); } finally { if ( null === $old ) { unset( $GLOBALS['wp_filter'] ); } else { $GLOBALS['wp_filter'] = $old; } }
	}
	public function test_default_native_stock_integer_filter_is_allowed_but_exactly_fenced(): void {
		$old = $GLOBALS['wp_filter'] ?? null; $GLOBALS['wp_filter'] = [ 'woocommerce_stock_amount' => self::hook( [ 'callbacks' => [ 10 => [ [ 'function' => 'intval', 'accepted_args' => 1 ] ] ] ] ) ];
		try { $binding = LegacyQuoteSourceLocalBinding::capture(); self::assertTrue( $binding->unchanged() ); $GLOBALS['wp_filter']['woocommerce_stock_amount']->callbacks[10][0]['accepted_args'] = 2; self::assertFalse( $binding->unchanged() ); } finally { if ( null === $old ) { unset( $GLOBALS['wp_filter'] ); } else { $GLOBALS['wp_filter'] = $old; } }
	}
	public function test_native_wordpress_term_defaults_are_transparent_to_product_taxonomies_and_fenced(): void {
		$old = $GLOBALS['wp_filter'] ?? null; $GLOBALS['wp_filter'] = [ 'get_terms' => self::hook( [ 'callbacks' => [ 10 => [ [ 'function' => '_post_format_get_terms', 'accepted_args' => 3 ] ] ] ] ), 'wp_get_object_terms' => self::hook( [ 'callbacks' => [ 10 => [ [ 'function' => '_post_format_wp_get_object_terms', 'accepted_args' => 1 ] ] ] ] ) ];
		try { $binding = LegacyQuoteSourceLocalBinding::capture(); self::assertTrue( $binding->unchanged() ); $GLOBALS['wp_filter']['get_terms']->callbacks[10][0]['accepted_args'] = 2; self::assertFalse( $binding->unchanged() ); } finally { if ( null === $old ) { unset( $GLOBALS['wp_filter'] ); } else { $GLOBALS['wp_filter'] = $old; } }
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'variation_hooks' )]
	public function test_variation_material_hooks_cannot_appear_after_preparation( string $hook ): void {
		$old = $GLOBALS['wp_filter'] ?? null; $GLOBALS['wp_filter'] = [];
		try { $binding = LegacyQuoteSourceLocalBinding::capture(); $GLOBALS['wp_filter'][$hook] = self::hook( [ 'callbacks' => [ 10 => [ [ 'function' => 'fixture_callback', 'accepted_args' => 1 ] ] ] ] ); self::assertFalse( $binding->unchanged() ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture(); } finally { if ( null === $old ) { unset( $GLOBALS['wp_filter'] ); } else { $GLOBALS['wp_filter'] = $old; } }
	}
	public static function variation_hooks(): array { return [ [ 'woocommerce_variation_is_visible' ], [ 'woocommerce_product_variation_get_parent_id' ] ]; }
	public function test_retained_zone_walk_terminates_in_the_closed_universe(): void {
		$rows = []; for ( $id = 1; $id <= 200; ++$id ) { $rows[] = [ 'id' => (string) $id, 'internal_code' => 'zone_' . $id, 'status' => 0 === $id % 2 ? 'active' : 'inactive' ]; } $repository = ( new LegacyQuoteCapturedSourceView( $this->snapshot( [], [ 'zones' => $rows ] ) ) )->zones();
		$page = $repository->page_after( 0, 100, [ 'status' => 'active' ] ); self::assertCount( 100, $page ); self::assertSame( '200', $page[99]['id'] ); self::assertSame( [], $repository->page_after( 200, 100, [ 'status' => 'active' ] ) );
	}
	public function test_retained_legacy_candidates_are_exact_active_targets_and_priority_ordered(): void {
		$rows = [ [ 'id' => '3', 'target_type' => 'product', 'target_id' => '10', 'priority' => '30', 'status' => 'active' ], [ 'id' => '2', 'target_type' => 'product', 'target_id' => '10', 'priority' => '20', 'status' => 'inactive' ], [ 'id' => '1', 'target_type' => 'category', 'target_id' => '100', 'priority' => '10', 'status' => 'active' ] ];
		$repository = ( new LegacyQuoteCapturedSourceView( $this->snapshot( [], [ 'legacy_rules' => $rows ] ) ) )->legacy_rules(); self::assertSame( [ '1', '3' ], array_column( $repository->findActiveByTargets( [ [ 'target_type' => 'product', 'target_id' => 10 ], [ 'target_type' => 'category', 'target_id' => 100 ] ] ), 'id' ) ); self::assertSame( [], $repository->findActiveByTargets( [ [ 'target_type' => 'product', 'target_id' => 999 ] ] ) );
	}
	public function test_retained_ecr_hydration_uses_every_captured_child_without_io(): void {
		$rows = [ 'scopes' => [ [ 'id' => '5', 'scope_type' => 'product', 'scope_id' => '10', 'slice_key' => '', 'parent_product_id' => null, 'status' => 'active', 'config_version' => '3', 'source' => 'native', 'legacy_rule_id' => null, 'created_at' => null, 'updated_at' => null ] ], 'scope_fields' => [ [ 'scope_row_id' => '5', 'field_key' => 'origin_id', 'mode' => 'override', 'value_type' => 'int', 'value_text' => '40' ] ], 'scope_collections' => [ [ 'scope_row_id' => '5', 'field_key' => 'delivery_offer_ids', 'mode' => 'replace', 'members_json' => '[20,21]' ] ] ];
		$repository = ( new LegacyQuoteCapturedSourceView( $this->snapshot( [], $rows ) ) )->scopes(); $scope = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, 10, '' ); self::assertSame( 3, $scope->scope->config_version ); self::assertSame( 40, $scope->scalars['origin_id']->value ); self::assertSame( [ 20, 21 ], $scope->collections['delivery_offer_ids']->members ); self::assertSame( [], $repository->findByScope( ConfigurationScopeType::Product, 999 ) );
	}
	public function test_repeated_product_or_parent_managed_variations_use_total_stock_demand(): void {
		$a = new SourceSnapshotStockProduct( 10, 10 ); $b = new SourceSnapshotStockProduct( 10, 10 ); self::assertTrue( $a->has_enough_stock( 6 ) ); self::assertTrue( $b->has_enough_stock( 6 ) ); self::assertFalse( LegacyQuoteNativeSourcePreparer::inventory_demand_available( [ [ 'product' => $a, 'quantity' => 6 ], [ 'product' => $b, 'quantity' => 6 ] ] ) );
		self::assertTrue( LegacyQuoteNativeSourcePreparer::inventory_demand_available( [ [ 'product' => $a, 'quantity' => 6 ], [ 'product' => new SourceSnapshotStockProduct( 11, 10 ), 'quantity' => 6 ] ] ) ); self::assertTrue( LegacyQuoteNativeSourcePreparer::inventory_demand_available( [ [ 'product' => new SourceSnapshotStockProduct( 10, 12 ), 'quantity' => 6 ], [ 'product' => new SourceSnapshotStockProduct( 10, 12 ), 'quantity' => 6 ] ] ) );
	}
	public function test_actual_member_receipts_survive_the_canonical_context_roundtrip(): void {
		$rule = new ResolvedProductDeliveryRule( 1, 'product', 10, null, 3, 'in_warehouse', 'delivery', [ 20 ], null, null, 40, 1 ); $result = new ProductRuleResolutionResult( true, null, 'product', 10, null, [], '', [ $rule ], [ 'in_warehouse' => $rule ], [], [], [], null ); $runtime = new ProductDeliveryRuntimeResolution( $result, 'legacy' );
		$source = LegacyQuoteNativeSourcePreparer::source_facts( $runtime, $rule ); $inventory = LegacyQuoteNativeSourcePreparer::inventory_facts( new SourceSnapshotStockProduct( 10, 10 ), 2 ); $facts = F::context()->private_facts(); $facts['lines'][0]['source'] = $source; $facts['lines'][0]['inventory'] = $inventory; $roundtrip = QuoteContext::from_array( $facts )->private_facts();
		self::assertSame( $source, $roundtrip['lines'][0]['source'] ); self::assertSame( $inventory, $roundtrip['lines'][0]['inventory'] );
	}
	public function test_inventory_receipt_uses_native_price_presence_without_adopting_merchandise_amount(): void {
		$regular = LegacyQuoteNativeSourcePreparer::inventory_facts( new SourceSnapshotPricedProduct( '20.00' ), 2 );
		self::assertSame( $regular, LegacyQuoteNativeSourcePreparer::inventory_facts( new SourceSnapshotPricedProduct( '9.00' ), 2 ) );
		self::assertSame( $regular, LegacyQuoteNativeSourcePreparer::inventory_facts( new SourceSnapshotPricedProduct( '0.00' ), 2 ) );
		$empty = LegacyQuoteNativeSourcePreparer::inventory_facts( new SourceSnapshotPricedProduct( '' ), 2 ); self::assertSame( 'ineligible', $empty['status'] ); self::assertNotSame( $regular, $empty );
	}
	public function test_unfenced_offer_reference_refuses_before_any_live_offer_lookup(): void {
		$view = new LegacyQuoteCapturedSourceView( $this->snapshot( [], [ 'offers' => [ [ 'id' => '20' ] ], 'legacy_rules' => [ [ 'delivery_offer_ids' => '[20,21]' ] ] ] ) ); $this->expectException( \RuntimeException::class ); $view->offers();
	}
	public function test_unverified_native_runtime_cannot_open_a_source_transaction(): void {
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' ); $this->expectException( \RuntimeException::class ); ( new LegacyQuoteNativeSourcePreparer( $factory ) )->prepare( QuoteFixtures::owner(), F::context() );
	}
	private static function hook( array $facts ): \WP_Hook { $hook = new \WP_Hook(); $hook->callbacks = $facts['callbacks']; return $hook; }
	private function snapshot( array $cards, array $rows = [], string $at = '2026-10-07 05:00:00.000000' ): LegacyQuoteSourceSnapshot { $plan = F::plan(); return LegacyQuoteSourceSnapshot::captured( $plan, F::context(), $rows, [ LegacyQuoteSourcePlan::range_key( $plan->rate_ranges()[0] ) => $cards ], QuoteTime::parse( $at ) ); }
}

/** Narrow native-stock algorithm fixture; this is not real WooCommerce qualification. */
class SourceSnapshotStockProduct extends \WC_Product {
	public function __construct( private int $owner_id, private int $stock ) { parent::__construct(); }
	public function get_stock_managed_by_id(): int { return $this->owner_id; } public function managing_stock(): bool { return true; } public function backorders_allowed(): bool { return false; } public function has_enough_stock( int $quantity ): bool { return $quantity <= $this->stock; }
	public function get_id(): int { return $this->owner_id; } public function get_stock_quantity(): int { return $this->stock; } public function get_status(): string { return 'publish'; } public function is_purchasable(): bool { return true; } public function get_stock_status(): string { return $this->stock > 0 ? 'instock' : 'outofstock'; } public function get_backorders(): string { return 'no'; } public function is_in_stock(): bool { return $this->stock > 0; }
}

/** Models only Woo's retained empty/nonempty purchasability predicate, not sale computation. */
final class SourceSnapshotPricedProduct extends SourceSnapshotStockProduct {
	public function __construct( private string $price ) { parent::__construct( 10, 10 ); }
	public function is_purchasable(): bool { return '' !== $this->price; }
}
