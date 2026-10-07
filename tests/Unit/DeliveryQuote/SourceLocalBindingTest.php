<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceLocalBinding;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Native callable-registration protocol fixtures, not a WordPress runtime proof. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SourceLocalBindingTest extends TestCase {
	protected function setUp(): void {
		if ( ! class_exists( '\WP_Hook', false ) ) { eval( 'class WP_Hook { public array $callbacks = []; }' ); }
		if ( ! class_exists( '\wpdb', false ) ) { eval( 'class wpdb { public int $calls = 0; public function remove_placeholder_escape() { ++$this->calls; throw new LogicException("Callback must not execute."); } }' ); }
		if ( ! class_exists( '\WC_Deprecated_Filter_Hooks', false ) ) { eval( 'class WC_Deprecated_Filter_Hooks { protected array $deprecated_hooks = ["woocommerce_product_get_price" => "woocommerce_get_price", "woocommerce_product_get_stock_quantity" => "woocommerce_get_stock_quantity"]; public int $calls = 0; public function maybe_handle_deprecated_hook() { ++$this->calls; throw new LogicException("Callback must not execute."); } public function replace_mapping(string $hook,string $target): void { $this->deprecated_hooks[$hook] = $target; } }' ); }
		if ( ! class_exists( '\Automattic\WooCommerce\Internal\ScheduledSalePriceReconciler', false ) ) { eval( 'namespace Automattic\WooCommerce\Internal; class ScheduledSalePriceReconciler { public int $calls = 0; public function reconcile_price() { ++$this->calls; throw new \LogicException("Callback must not execute."); } }' ); }
		$GLOBALS['wpdb'] = new \wpdb(); $GLOBALS['wp_filter'] = [];
	}
	public function test_exact_native_default_bundle_is_accepted_without_invoking_callbacks(): void {
		$bridge = $this->native_defaults(); $binding = LegacyQuoteSourceLocalBinding::capture();
		self::assertTrue( $binding->unchanged() ); self::assertSame( 0, $GLOBALS['wpdb']->calls ); self::assertSame( 0, $bridge->calls );
	}
	public function test_replacing_global_database_invalidates_the_original_query_binding(): void {
		$this->native_defaults(); $binding = LegacyQuoteSourceLocalBinding::capture(); $GLOBALS['wpdb'] = new \wpdb(); self::assertFalse( $binding->unchanged() );
	}
	public function test_native_term_count_and_scheduled_price_helpers_are_exactly_fenced(): void {
		$this->native_defaults(); $sale = new \Automattic\WooCommerce\Internal\ScheduledSalePriceReconciler();
		$GLOBALS['wp_filter']['get_terms']->callbacks[10][] = [ 'function' => 'wc_change_term_counts', 'accepted_args' => 2 ];
		$GLOBALS['wp_filter']['woocommerce_product_get_price']->callbacks[99] = [ [ 'function' => [ $sale, 'reconcile_price' ], 'accepted_args' => 2 ] ];
		$binding = LegacyQuoteSourceLocalBinding::capture(); self::assertTrue( $binding->unchanged() ); self::assertSame( 0, $sale->calls );
		$GLOBALS['wp_filter']['woocommerce_product_get_price']->callbacks[99][0]['accepted_args'] = 3; self::assertFalse( $binding->unchanged() );
	}
	public function test_nested_term_count_callback_cannot_enter_through_the_native_helper(): void {
		$this->hook( 'get_terms', 10, 'wc_change_term_counts', 2 ); $binding = LegacyQuoteSourceLocalBinding::capture();
		$this->hook( 'woocommerce_change_term_counts', 10, static fn( mixed $value ): mixed => $value, 1 ); self::assertFalse( $binding->unchanged() ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_scheduled_price_subclass_does_not_receive_the_native_allowance(): void {
		$sale = new class() extends \Automattic\WooCommerce\Internal\ScheduledSalePriceReconciler {}; $this->hook( 'woocommerce_product_get_price', 99, [ $sale, 'reconcile_price' ], 2 ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_database_identity_cannot_be_reused_after_original_references_are_removed(): void {
		$this->hook( 'query', 0, [ $GLOBALS['wpdb'], 'remove_placeholder_escape' ], 1 ); $binding = LegacyQuoteSourceLocalBinding::capture();
		$GLOBALS['wp_filter'] = []; unset( $GLOBALS['wpdb'] ); $GLOBALS['wpdb'] = new \wpdb(); $this->hook( 'query', 0, [ $GLOBALS['wpdb'], 'remove_placeholder_escape' ], 1 ); self::assertFalse( $binding->unchanged() );
	}
	public function test_query_callback_from_another_database_object_is_refused(): void {
		$this->hook( 'query', 0, [ new \wpdb(), 'remove_placeholder_escape' ], 1 ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_deprecated_mapping_mutation_is_fenced_without_calling_native_methods(): void {
		$bridge = $this->native_defaults(); $binding = LegacyQuoteSourceLocalBinding::capture(); $bridge->replace_mapping( 'woocommerce_product_get_price', 'unknown_price' ); self::assertFalse( $binding->unchanged() ); self::assertSame( 0, $bridge->calls );
	}
	public function test_old_price_callback_is_not_hidden_behind_the_native_bridge(): void {
		$this->native_defaults(); $binding = LegacyQuoteSourceLocalBinding::capture(); $this->hook( 'woocommerce_get_price', 10, static fn( mixed $value ): mixed => $value, 1 ); self::assertFalse( $binding->unchanged() ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_replacement_bridge_object_is_fenced_even_with_the_same_class_and_mapping(): void {
		$this->native_defaults(); $binding = LegacyQuoteSourceLocalBinding::capture(); $this->hook( 'woocommerce_product_get_price', -1000, [ new \WC_Deprecated_Filter_Hooks(), 'maybe_handle_deprecated_hook' ], 8 ); self::assertFalse( $binding->unchanged() );
	}
	public function test_wrong_native_tuple_and_subclass_are_refused(): void {
		$this->native_defaults(); $GLOBALS['wp_filter']['woocommerce_product_get_price']->callbacks[-1000][0]['accepted_args'] = 7; $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_database_subclass_is_not_native_placeholder_removal(): void {
		$GLOBALS['wpdb'] = new class() extends \wpdb {}; $this->hook( 'query', 0, [ $GLOBALS['wpdb'], 'remove_placeholder_escape' ], 1 ); $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_total_callback_bound_is_checked_before_traversing_a_large_bucket(): void {
		$hook = new \WP_Hook(); $hook->callbacks = [ 10 => array_fill( 0, 257, [ 'function' => 'intval', 'accepted_args' => 1 ] ) ]; $GLOBALS['wp_filter']['woocommerce_stock_amount'] = $hook; $this->expectException( \RuntimeException::class ); LegacyQuoteSourceLocalBinding::capture();
	}
	public function test_callback_bound_is_aggregate_across_hook_buckets(): void {
		$hook = new \WP_Hook(); $hook->callbacks = [ 10 => array_fill( 0, 256, [ 'function' => 'intval', 'accepted_args' => 1 ] ) ]; $GLOBALS['wp_filter']['woocommerce_stock_amount'] = $hook; $binding = LegacyQuoteSourceLocalBinding::capture(); self::assertTrue( $binding->unchanged() ); $this->hook( 'get_terms', 10, '_post_format_get_terms', 3 ); self::assertFalse( $binding->unchanged() );
	}
	private function native_defaults(): \WC_Deprecated_Filter_Hooks {
		$bridge = new \WC_Deprecated_Filter_Hooks(); $this->hook( 'query', 0, [ $GLOBALS['wpdb'], 'remove_placeholder_escape' ], 1 ); $this->hook( 'get_terms', 10, '_post_format_get_terms', 3 ); $this->hook( 'wp_get_object_terms', 10, '_post_format_wp_get_object_terms', 1 ); $this->hook( 'woocommerce_product_get_stock_quantity', -1000, [ $bridge, 'maybe_handle_deprecated_hook' ], 8 ); $this->hook( 'woocommerce_product_get_price', -1000, [ $bridge, 'maybe_handle_deprecated_hook' ], 8 ); $this->hook( 'woocommerce_stock_amount', 10, 'intval', 1 ); return $bridge;
	}
	private function hook( string $name, int $priority, mixed $callback, int $args ): void { $hook = new \WP_Hook(); $hook->callbacks = [ $priority => [ [ 'function' => $callback, 'accepted_args' => $args ] ] ]; $GLOBALS['wp_filter'][$name] = $hook; }
}
