<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Fixed pure hook-registration fence; no callback is invoked inside the source owner. */
final readonly class LegacyQuoteSourceLocalBinding implements \JsonSerializable {
	private const HOOKS = [ 'query', 'get_post_metadata', 'pre_get_posts', 'posts_pre_query', 'get_object_terms', 'get_the_terms', 'wp_get_object_terms', 'get_terms', 'woocommerce_product_class', 'woocommerce_product_type_query', 'woocommerce_product_get_parent_id', 'woocommerce_product_get_status', 'woocommerce_product_get_stock_status', 'woocommerce_product_get_manage_stock', 'woocommerce_product_get_stock_quantity', 'woocommerce_product_get_backorders', 'woocommerce_product_get_price', 'woocommerce_product_get_category_ids', 'woocommerce_product_variation_get_parent_id', 'woocommerce_variation_is_visible', 'woocommerce_product_variation_get_status', 'woocommerce_product_variation_get_stock_status', 'woocommerce_product_variation_get_manage_stock', 'woocommerce_product_variation_get_stock_quantity', 'woocommerce_product_variation_get_backorders', 'woocommerce_product_variation_get_price', 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable', 'woocommerce_product_is_in_stock', 'woocommerce_product_backorders_allowed', 'woocommerce_stock_amount', 'woocommerce_states', 'pre_option', 'default_option' ];
	private function __construct( private string $fingerprint ) {}
	public static function capture(): self { return new self( self::fingerprint( true ) ); }
	public function unchanged(): bool { try { return hash_equals( $this->fingerprint, self::fingerprint( false ) ); } catch ( \Throwable ) { return false; } }
	public function jsonSerialize(): never { throw new \LogicException( 'Native source bindings are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native source bindings are private.' ); }
	private static function fingerprint( bool $refuse_effects ): string {
		$hooks = self::HOOKS; foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { foreach ( [ 'pre_option_', 'option_', 'default_option_' ] as $prefix ) { $hooks[] = $prefix . $name; } } $values = [];
		foreach ( $hooks as $hook ) { $entry = $GLOBALS['wp_filter'][$hook] ?? null; if ( null === $entry ) { $values[$hook] = []; continue; } if ( ! is_object( $entry ) || ! property_exists( $entry, 'callbacks' ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $items = [];
			foreach ( $entry->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $callback ) { $fn = $callback['function'] ?? null; $accepted_args = $callback['accepted_args'] ?? null; $known_pure = 'woocommerce_stock_amount' === $hook && 'intval' === $fn && 10 === (int) $priority && 1 === $accepted_args; if ( $refuse_effects && ! $known_pure ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $items[] = [ (int) $priority, $accepted_args, is_string( $fn ) ? $fn : ( is_object( $fn ) ? spl_object_id( $fn ) : 'changed' ) ]; } } $values[$hook] = $items;
		} return hash( 'sha256', \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $values ) );
	}
}
