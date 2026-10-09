<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NativeMatchedTaxHookTest extends TestCase {
	public function test_empty_actual_matcher_hook_preserves_pure_native_guard(): void { $out = $this->probe( 'empty' ); self::assertTrue( $out['before'] ); self::assertTrue( $out['current'] ); self::assertTrue( $out['supported'] ); $this->assert_inert( $out ); }
	public function test_exact_native_title_default_is_supported_without_invoking_it(): void { $out = $this->probe( 'title_default', 'sanitize_title' ); self::assertTrue( $out['supported'] ); self::assertTrue( $out['before'] ); self::assertTrue( $out['current'] ); $this->assert_inert( $out ); }
	public function test_exact_native_charset_defaults_are_supported_without_invoking_them(): void { $out = $this->probe( 'charset_default', 'option_blog_charset' ); self::assertTrue( $out['supported'] ); self::assertTrue( $out['before'] ); self::assertTrue( $out['current'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'matcher_hooks' )]
	public function test_unknown_actual_matcher_registration_is_refused_before_native_capture( string $hook ): void { $out = $this->probe( 'before_capture', $hook ); self::assertFalse( $out['supported'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'matcher_hooks' )]
	public function test_late_actual_matcher_registration_invalidates_already_prewarmed_raw_source_fence( string $hook ): void { $out = $this->probe( 'after_prewarm', $hook ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->assert_inert( $out ); }
	public static function matcher_hooks(): array { return array_map( static fn( string $hook ): array => [ $hook ], [ 'woocommerce_matched_tax_rates', 'sanitize_text_field', 'woocommerce_format_postcode', 'sanitize_title', 'esc_html', 'pre_option_blog_charset', 'option_blog_charset', 'default_option_blog_charset', 'woocommerce_order_item_get_method_id', 'woocommerce_countries_base_country', 'woocommerce_countries_base_state', 'woocommerce_countries_base_postcode', 'woocommerce_countries_base_city', 'wc_tax_enabled', 'woocommerce_get_base_location', 'pre_wp_load_alloptions', 'alloptions', 'pre_cache_alloptions', 'wp_autoload_values_to_autoload', 'woocommerce_order_get_items', 'woocommerce_order_get_is_vat_exempt', 'woocommerce_order_item_get_cetech_de_group_id', 'woocommerce_order_item_get_product_id', 'woocommerce_order_item_get_variation_id', 'woocommerce_get_product_from_item', 'woocommerce_order_item_product', 'woocommerce_order_get__cetech_de_delivery_quote_snapshot', 'woocommerce_order_get__cetech_de_order_delivery_snapshot_version', 'woocommerce_order_get__cetech_de_delivery_quote_format', 'woocommerce_order_get__cetech_de_quote_native_draft', 'woocommerce_order_get__cetech_de_quote_reference', 'woocommerce_order_get__cetech_de_quote_native_tax_source', 'woocommerce_order_item_get__cetech_de_delivery_snapshot', 'woocommerce_order_item_get__cetech_de_delivery_snapshot_version', 'woocommerce_order_item_get__cetech_de_delivery_quote_format', 'woocommerce_order_item_get__cetech_de_quote_line_key', 'woocommerce_order_item_get__cetech_de_cart_item_key', 'woocommerce_order_item_get_quantity', 'woocommerce_order_type_to_group', 'wc_get_price_decimal_separator', 'pre_option_woocommerce_price_decimal_sep', 'option_woocommerce_price_decimal_sep', 'default_option_woocommerce_price_decimal_sep' ] ); }
	#[DataProvider( 'altered_title_defaults' )]
	public function test_altered_native_title_default_is_refused_before_capture( string $variation ): void { $out = $this->probe( 'title_' . $variation, 'sanitize_title' ); self::assertFalse( $out['supported'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'altered_title_defaults' )]
	public function test_altering_native_title_default_invalidates_the_prewarmed_raw_fence( string $variation ): void { $out = $this->probe( 'title_late_' . $variation, 'sanitize_title' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->assert_inert( $out ); }
	public static function altered_title_defaults(): array { return [ 'priority' => [ 'priority' ], 'accepted argument count' => [ 'args' ], 'callable alias' => [ 'alias' ], 'foreign callable' => [ 'foreign' ], 'registration key' => [ 'key' ], 'duplicate registration' => [ 'duplicate' ] ]; }
	public function test_removing_native_title_default_invalidates_the_prewarmed_raw_fence(): void { $out = $this->probe( 'title_late_remove', 'sanitize_title' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); self::assertTrue( $out['supported'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'altered_charset_defaults' )]
	public function test_altered_native_charset_default_is_refused_before_capture( string $variation ): void { $out = $this->probe( 'charset_' . $variation, 'option_blog_charset' ); self::assertFalse( $out['supported'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'altered_charset_defaults' )]
	public function test_altering_native_charset_defaults_invalidates_the_prewarmed_raw_fence( string $variation ): void { $out = $this->probe( 'charset_late_' . $variation, 'option_blog_charset' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->assert_inert( $out ); }
	public static function altered_charset_defaults(): array { return array_map( static fn( string $value ): array => [ $value ], [ 'priority', 'args', 'alias', 'foreign', 'key', 'duplicate', 'duplicate_pair' ] ); }
	#[DataProvider( 'valid_charset_changes' )]
	public function test_reordering_or_removing_native_charset_defaults_cannot_reuse_original_raw_fence( string $variation ): void { $out = $this->probe( 'charset_late_' . $variation, 'option_blog_charset' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); self::assertTrue( $out['supported'] ); $this->assert_inert( $out ); }
	public static function valid_charset_changes(): array { return [ [ 'reorder' ], [ 'remove' ] ]; }
	private function assert_inert( array $out ): void { self::assertSame( 0, $out['callback_calls'] ); self::assertTrue( $out['registration_unchanged'] ); }
	private function probe( string $mode, string $hook = 'woocommerce_matched_tax_rates' ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-matched-tax-hook-probe.php', $mode, $hook ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 8, JSON_THROW_ON_ERROR );
	}
}
