<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Fixed pure registration fence; no native callback is invoked by this value. */
final readonly class LegacyQuoteSourceLocalBinding implements \JsonSerializable {
	private const HOOKS = [ 'query', 'get_post_metadata', 'pre_get_posts', 'posts_pre_query', 'get_object_terms', 'get_the_terms', 'wp_get_object_terms', 'get_terms', 'woocommerce_product_class', 'woocommerce_product_type_query', 'woocommerce_product_get_parent_id', 'woocommerce_product_get_status', 'woocommerce_product_get_stock_status', 'woocommerce_product_get_manage_stock', 'woocommerce_product_get_stock_quantity', 'woocommerce_product_get_backorders', 'woocommerce_product_get_price', 'woocommerce_product_get_category_ids', 'woocommerce_product_variation_get_parent_id', 'woocommerce_variation_is_visible', 'woocommerce_product_variation_get_status', 'woocommerce_product_variation_get_stock_status', 'woocommerce_product_variation_get_manage_stock', 'woocommerce_product_variation_get_stock_quantity', 'woocommerce_product_variation_get_backorders', 'woocommerce_product_variation_get_price', 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable', 'woocommerce_product_is_in_stock', 'woocommerce_product_backorders_allowed', 'woocommerce_stock_amount', 'woocommerce_states', 'pre_option', 'default_option', 'woocommerce_get_price', 'woocommerce_get_stock_quantity' ];
	private const LEGACY_HOOKS = [ 'woocommerce_product_get_price' => 'woocommerce_get_price', 'woocommerce_product_get_stock_quantity' => 'woocommerce_get_stock_quantity' ];
	private const MAX_CALLBACKS = 256;
	/** Strong references keep native object identities unique for this binding's lifetime. */
	private function __construct( private string $fingerprint, private array $native_objects ) {}
	public static function capture(): self { $objects = []; $fingerprint = self::fingerprint( $objects ); return new self( $fingerprint, array_values( $objects ) ); }
	public function unchanged(): bool { try { return hash_equals( $this->fingerprint, self::fingerprint() ); } catch ( \Throwable ) { return false; } }
	public function jsonSerialize(): never { throw new \LogicException( 'Native source bindings are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native source bindings are private.' ); }

	private static function fingerprint( ?array &$objects = null ): string {
		$hooks = self::HOOKS;
		foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { foreach ( [ 'pre_option_', 'option_', 'default_option_' ] as $prefix ) { $hooks[] = $prefix . $name; } }
		$db = $GLOBALS['wpdb'] ?? null;
		if ( null !== $objects && is_object( $db ) ) { $objects[spl_object_id( $db )] = $db; }
		$values = [ 'native_wpdb' => is_object( $db ) ? [ get_class( $db ), spl_object_id( $db ) ] : null, 'hooks' => [] ]; $total = 0;
		foreach ( $hooks as $hook ) {
			$callbacks = self::callbacks( $hook ); $items = [];
			foreach ( $callbacks as $priority => $at_priority ) {
				if ( ! is_int( $priority ) || ! is_array( $at_priority ) || count( $at_priority ) > self::MAX_CALLBACKS - $total ) { self::unavailable(); }
				$total += count( $at_priority );
				foreach ( $at_priority as $callback ) {
					if ( ! is_array( $callback ) || ! array_key_exists( 'function', $callback ) || ! is_int( $callback['accepted_args'] ?? null ) ) { self::unavailable(); }
					$fn = $callback['function']; $args = $callback['accepted_args']; $mapping = null;
					$known = ( 'woocommerce_stock_amount' === $hook && 'intval' === $fn && 10 === $priority && 1 === $args )
						|| ( 'get_terms' === $hook && '_post_format_get_terms' === $fn && 10 === $priority && 3 === $args )
						|| ( 'wp_get_object_terms' === $hook && '_post_format_wp_get_object_terms' === $fn && 10 === $priority && 1 === $args );
					if ( is_array( $fn ) && array_is_list( $fn ) && 2 === count( $fn ) && is_object( $fn[0] ) && is_string( $fn[1] ) ) {
						$known = 'query' === $hook && $fn[0] === $db && 'wpdb' === get_class( $db ) && 'remove_placeholder_escape' === $fn[1] && 0 === $priority && 1 === $args;
						if ( isset( self::LEGACY_HOOKS[$hook] ) && 'WC_Deprecated_Filter_Hooks' === get_class( $fn[0] ) && 'maybe_handle_deprecated_hook' === $fn[1] && -1000 === $priority && 8 === $args ) {
							$raw = self::raw_property( $fn[0], 'deprecated_hooks' );
							$mapping = is_array( $raw ) ? ( $raw[$hook] ?? null ) : null;
							$known = self::LEGACY_HOOKS[$hook] === $mapping && [] === self::callbacks( self::LEGACY_HOOKS[$hook] );
						}
						$identity = [ get_class( $fn[0] ), spl_object_id( $fn[0] ), $fn[1] ];
						if ( null !== $objects ) { $objects[spl_object_id( $fn[0] )] = $fn[0]; }
					} else { $identity = is_string( $fn ) ? $fn : null; }
					if ( ! $known ) { self::unavailable(); }
					$items[] = [ $priority, $args, $identity, $mapping ];
				}
			}
			$values['hooks'][$hook] = $items;
		}
		return hash( 'sha256', \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $values ) );
	}

	private static function callbacks( string $hook ): array {
		$filters = $GLOBALS['wp_filter'] ?? [];
		if ( ! is_array( $filters ) ) { self::unavailable(); }
		$entry = $filters[$hook] ?? null;
		if ( null === $entry ) { return []; }
		if ( ! is_object( $entry ) || 'WP_Hook' !== get_class( $entry ) ) { self::unavailable(); }
		$callbacks = self::raw_property( $entry, 'callbacks' );
		if ( ! is_array( $callbacks ) || count( $callbacks ) > self::MAX_CALLBACKS ) { self::unavailable(); }
		return $callbacks;
	}

	private static function raw_property( object $object, string $name ): mixed {
		$property = new \ReflectionProperty( get_class( $object ), $name );
		if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { self::unavailable(); }
		return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object );
	}
	private static function unavailable(): never { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
}
