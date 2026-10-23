<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Native cart setup after admission, or read-only restoration of an existing calculation. */
final class NativeCartQuoteShipping {
	public static function prepare_after_admission(): void {
		[ $cart, $session ] = self::native_objects();
		$cart->calculate_totals();
		// Native receipt guards include physical session bytes. Publish the normal
		// Woo cart session before capture so a fresh request sees the same packet.
		$session->save_data();
	}
	/** Explicit promotion preparation must re-enter native rates even when the package hash is unchanged. */
	public static function prepare_promotion_after_admission(): void {
		[ $cart, $session ] = self::native_objects();
		$packages = $cart->get_shipping_packages();
		if ( ! is_array( $packages ) || [] === $packages || count( $packages ) > 200 ) { self::fail(); }
		// Validate the whole bounded layout before mutating any native session entry.
		foreach ( $packages as $index => $package ) {
			if ( ! is_int( $index ) || $index < 0 || $index > 199 || ! is_array( $package ) ) { self::fail(); }
		}
		foreach ( array_keys( $packages ) as $index ) { $session->__unset( 'shipping_for_package_' . $index ); }
		$cart->calculate_totals();
		$session->save_data();
	}
	/** Retain the original native session before callbacks; cleanup never calculates or calls layout filters. */
	public static function promotion_failure_cleanup(): \Closure {
		[ $cart, $session ] = self::native_objects();
		$db = $GLOBALS['wpdb'] ?? null; if ( ! is_object( $db ) || 'wpdb' !== get_class( $db ) ) { self::fail(); }
		$prefix = self::raw( $db, 'prefix' ); if ( ! is_string( $prefix ) || strlen( $prefix ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::fail(); }
		$identity = [ self::raw( $session, '_customer_id' ), self::raw( $session, '_cookie' ), self::raw( $session, '_has_cookie' ) ];
		$property = new \ReflectionProperty( '\WC_Shipping', '_instance' ); $shipping = $property->getValue();
		if ( ( null !== $shipping && ( ! $shipping instanceof \WC_Shipping || 'WC_Shipping' !== get_class( $shipping ) ) ) || ! is_string( $identity[0] ) || '' === $identity[0] || ! is_string( $identity[1] ) || ! is_bool( $identity[2] ) ) { self::fail(); }
		$wc = $GLOBALS['woocommerce'];
		return static function () use ( $cart, $session, $shipping, $identity, $prefix, $property, $wc ): array {
			// A replaced global cart/session does not authorize touching its new owner.
			// The captured original remains the cleanup target while its physical key is exact.
			if ( $identity !== [ self::raw( $session, '_customer_id' ), self::raw( $session, '_cookie' ), self::raw( $session, '_has_cookie' ) ] ) { self::fail(); }
			$data = self::raw( $session, '_data' ); if ( ! is_array( $data ) || count( $data ) > 1024 ) { self::fail(); } $keys = []; $nodes = 0; self::cleanup_value( $data, 0, $nodes );
			foreach ( array_keys( $data ) as $key ) {
				if ( ! is_string( $key ) || ! str_starts_with( $key, 'shipping_for_package_' ) ) { continue; }
				if ( 1 !== preg_match( '/\Ashipping_for_package_(0|[1-9][0-9]{0,2})\z/D', $key, $match ) || (int) $match[1] > 199 ) { self::fail(); }
				$keys[] = $key;
			}
			if ( count( $keys ) > 200 ) { self::fail(); }
			foreach ( [ ...$keys, 'cart_totals', 'chosen_shipping_methods' ] as $key ) { $session->__unset( $key ); }
			$target = $shipping;
			if ( null === $target && ( $GLOBALS['woocommerce'] ?? null ) === $wc && self::raw( $wc, 'cart' ) === $cart && self::raw( $wc, 'session' ) === $session ) { $target = $property->getValue(); }
			if ( null !== $target ) { if ( ! $target instanceof \WC_Shipping || 'WC_Shipping' !== get_class( $target ) ) { self::fail(); } self::write_raw( $target, 'packages', [] ); }
			self::write_raw( $cart, 'shipping_methods', [] ); self::write_raw( $cart, 'has_calculated_shipping', false ); self::write_raw( $cart, 'totals', [] );
			$session->save_data();
			$value = serialize( self::raw( $session, '_data' ) ); if ( strlen( $value ) > 1048576 ) { self::fail(); }
			return [ 'table_prefix' => $prefix, 'session_key' => $identity[0], 'session_value' => $value ];
		};
	}
	private static function cleanup_value( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > 12 || ++$nodes > 16384 ) { self::fail(); }
		if ( is_array( $value ) ) { foreach ( $value as $key => $part ) { if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 255 ) ) { self::fail(); } self::cleanup_value( $part, $depth + 1, $nodes ); } return; }
		if ( is_string( $value ) && strlen( $value ) <= 1048576 || null === $value || is_int( $value ) || is_bool( $value ) || is_float( $value ) && is_finite( $value ) ) { return; } self::fail();
	}
	/** Exact fresh SQL readback; native save_data() has no reliable write acknowledgement. */
	public static function acknowledge_promotion_cleanup( \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory $factory, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner, array $expected ): void {
		$session = null; $begun = false;
		try {
			if ( array_keys( $expected ) !== [ 'table_prefix', 'session_key', 'session_value' ] || ! is_string( $expected['table_prefix'] ) || strlen( $expected['table_prefix'] ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $expected['table_prefix'] ) || ! is_string( $expected['session_key'] ) || '' === $expected['session_key'] || strlen( $expected['session_key'] ) > 128 || ! is_string( $expected['session_value'] ) || strlen( $expected['session_value'] ) > 1048576 ) { self::fail(); }
			$session = $factory->open(); $binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			$pin = static function ( bool $active ) use ( $session, $binding, $owner, $expected ): void { if ( $session->is_retired() || $session->in_transaction() !== $active || [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] !== $binding || $binding[0] !== $owner->site_id() || $binding[1] !== $expected['table_prefix'] ) { self::fail(); } };
			$pin( false ); if ( ! $session->begin() ) { self::fail(); } $begun = true; $pin( true );
			$table = $binding[1] . 'woocommerce_sessions'; if ( ! $session->validate_tables( [ $table ] ) ) { self::fail(); } $pin( true );
			$sql = $session->prepare( "SELECT session_key,CASE WHEN OCTET_LENGTH(session_value)<=1048576 THEN session_value ELSE NULL END AS session_value FROM `{$table}` WHERE session_key=%s LIMIT 2 FOR UPDATE", $expected['session_key'] ); $pin( true );
			$rows = $session->get_results( $sql ); $pin( true );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || array_keys( $rows[0] ) !== [ 'session_key', 'session_value' ] || $rows[0]['session_key'] !== $expected['session_key'] || ! is_string( $rows[0]['session_value'] ) || ! hash_equals( $expected['session_value'], $rows[0]['session_value'] ) ) { self::fail(); }
			if ( ! $session->rollback() ) { self::fail(); } $begun = false; $pin( false );
			if ( ! $session->retire() || ! $session->is_retired() || $session->in_transaction() ) { self::fail(); }
		} catch ( \Throwable $error ) { throw new \RuntimeException( 'Native promotion cache cleanup unconfirmed.', 0, $error ); }
		finally { if ( null !== $session && ! $session->is_retired() ) { if ( $begun ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	/** No rate calculation, cart total calculation, native session setter or persistence. */
	public static function restore_cached_calculation(): void {
		[ $cart, $session ] = self::native_objects();
		if ( ! QuoteNativeWooSource::supports_current_hooks() ) { self::fail(); }
		// Native layout helpers have a few filters outside the captured Q04 list.
		// This read path supports their unmodified native behavior only.
		foreach ( [ 'woocommerce_shipping_package_hash_ignored_fields', 'woocommerce_shipping_package_name', 'woocommerce_product_needs_shipping', 'woocommerce_product_get_virtual', 'woocommerce_product_variation_get_virtual', 'woocommerce_cart_display_prices_including_tax', 'woocommerce_cart_get_subtotal', 'woocommerce_cart_get_subtotal_tax', 'pre_option_woocommerce_tax_display_cart', 'option_woocommerce_tax_display_cart', 'default_option_woocommerce_tax_display_cart', 'woocommerce_shipping_enabled', 'pre_option_woocommerce_shipping_debug_mode', 'option_woocommerce_shipping_debug_mode', 'default_option_woocommerce_shipping_debug_mode', 'woocommerce_customer_get_shipping_address_1', 'woocommerce_customer_get_shipping_address_2' ] as $name ) {
			$hook = $GLOBALS['wp_filter'][$name] ?? null;
			if ( 'woocommerce_shipping_package_name' === $name ) { if ( ! self::retained_package_name_hook( $hook ) ) { self::fail(); } continue; }
			if ( null !== $hook && ( ! is_object( $hook ) || [] !== self::raw( $hook, 'callbacks' ) ) ) { self::fail(); }
		}
		$wc = $GLOBALS['woocommerce']; $shipping = $wc->shipping();
		if ( ! $shipping instanceof \WC_Shipping || 'WC_Shipping' !== get_class( $shipping ) || ! class_exists( '\WC_Shipping_Rate' ) ) { self::fail(); }
		$rate_class = new \ReflectionClass( '\WC_Shipping_Rate' ); if ( $rate_class->hasMethod( '__wakeup' ) || $rate_class->hasMethod( '__unserialize' ) ) { self::fail(); }
		$hook = $GLOBALS['wp_filter']['woocommerce_shipping_package_hash_ignored_fields'] ?? null;
		if ( null !== $hook && ( ! is_object( $hook ) || [] !== self::raw( $hook, 'callbacks' ) ) ) { self::fail(); }
		if ( 'yes' === get_option( 'woocommerce_shipping_debug_mode', 'no' ) ) { self::fail(); }
		$data = self::raw( $session, '_data' ); if ( ! is_array( $data ) ) { self::fail(); }
		$chosen = self::decode( $data['chosen_shipping_methods'] ?? null ); $stored_totals = self::decode( $data['cart_totals'] ?? null ); $totals = self::raw( $cart, 'totals' );
		if ( ! is_array( $chosen ) || [] === $chosen || count( $chosen ) > 200 || ! is_array( $stored_totals ) || ! is_array( $totals ) ) { self::fail(); }
		foreach ( [ 'shipping_total', 'shipping_tax' ] as $key ) { if ( self::decimal( $totals[$key] ?? null ) !== self::decimal( $stored_totals[$key] ?? null ) ) { self::fail(); } }
		$packages = $cart->get_shipping_packages(); if ( ! is_array( $packages ) || [] === $packages || count( $packages ) > 200 || count( $packages ) !== count( $chosen ) ) { self::fail(); }
		self::cached_shipping_version();
		$hash_method = new \ReflectionMethod( '\WC_Shipping', 'get_package_hash' ); $selected = []; $cost = 0.0; $tax = 0.0; $rate_count = 0;
		foreach ( $packages as $index => &$package ) {
			if ( ! is_int( $index ) || $index < 0 || $index > 199 || ! is_array( $package ) || ! is_array( $package['contents'] ?? null ) || [] === $package['contents'] || count( $package['contents'] ) > 200 || ! is_string( $chosen[$index] ?? null ) || strlen( $chosen[$index] ) > 255 ) { self::fail(); }
			$stored = self::decode( $data['shipping_for_package_' . $index] ?? null, true );
			self::cached_shipping_version();
			if ( ! is_array( $stored ) || count( $stored ) !== 2 || ! is_string( $stored['package_hash'] ?? null ) || ! is_array( $stored['rates'] ?? null ) || [] === $stored['rates'] || count( $stored['rates'] ) > 200 - $rate_count || $stored['package_hash'] !== $hash_method->invoke( $shipping, $package ) ) { self::fail(); }
			$rate_count += count( $stored['rates'] );
			foreach ( $stored['rates'] as $key => $rate ) {
				if ( ! is_string( $key ) || strlen( $key ) > 255 || ! $rate instanceof \WC_Shipping_Rate || 'WC_Shipping_Rate' !== get_class( $rate ) ) { self::fail(); }
				$rate_data = self::raw( $rate, 'data' ); if ( ! is_array( $rate_data ) || ( $rate_data['id'] ?? null ) !== $key ) { self::fail(); }
				$nodes = 0; self::bounded_value( $rate_data, 0, $nodes ); $nodes = 0; self::bounded_value( self::raw( $rate, 'meta_data' ), 0, $nodes );
			}
			$rate = $stored['rates'][$chosen[$index]] ?? null; if ( ! $rate instanceof \WC_Shipping_Rate ) { self::fail(); }
			$rate_data = self::raw( $rate, 'data' ); $selected[$index] = $rate; $cost += (float) self::decimal( $rate_data['cost'] ?? null );
			$taxes = $rate_data['taxes'] ?? null; if ( ! is_array( $taxes ) || count( $taxes ) > 200 ) { self::fail(); } foreach ( $taxes as $amount ) { $tax += (float) self::decimal( $amount ); }
			$package['rates'] = $stored['rates'];
		} unset( $package );
		if ( self::decimal( $cost ) !== self::decimal( $totals['shipping_total'] ) || self::decimal( $tax ) !== self::decimal( $totals['shipping_tax'] ) ) { self::fail(); }
		// These are the exact existing native session calculation, rehydrated only
		// in this request. Source/native guards still prove current applicability.
		self::write_raw( $shipping, 'packages', $packages ); self::write_raw( $cart, 'shipping_methods', $selected ); self::write_raw( $cart, 'has_calculated_shipping', true );
	}
	private static function native_objects(): array {
		$wc = $GLOBALS['woocommerce'] ?? null; if ( ! is_object( $wc ) ) { self::fail(); }
		$cart = self::raw( $wc, 'cart' ); $session = self::raw( $wc, 'session' );
		if ( ! $cart instanceof \WC_Cart || 'WC_Cart' !== get_class( $cart ) || ! $session instanceof \WC_Session_Handler || 'WC_Session_Handler' !== get_class( $session ) ) { self::fail(); }
		$items = self::raw( $cart, 'cart_contents' ); if ( ! is_array( $items ) || [] === $items || count( $items ) > 200 ) { self::fail(); } return [ $cart, $session ];
	}
	/** Woo invokes this heading hook, but excludes package_name from its native rate hash. */
	private static function retained_package_name_hook( mixed $hook ): bool {
		if ( null === $hook ) { return true; }
		if ( ! is_object( $hook ) || 'WP_Hook' !== get_class( $hook ) ) { return false; }
		$callbacks = self::raw( $hook, 'callbacks' );
		if ( [] === $callbacks ) { return true; }
		if ( ! is_array( $callbacks ) || 1 !== count( $callbacks ) || ! is_array( $callbacks[20] ?? null ) || 1 !== count( $callbacks[20] ) ) { return false; }
		$callback = reset( $callbacks[20] ); $function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
		return is_array( $function ) && array_is_list( $function ) && 2 === count( $function ) && is_object( $function[0] )
			&& \CetechDeliveryEngine\Presentation\Frontend\CartFulfilmentPackagePresentation::class === get_class( $function[0] )
			&& 'filter_package_name' === $function[1] && 4 === ( $callback['accepted_args'] ?? null );
	}
	/** The native hash creates a missing version. Reads support an existing, non-expiring native version only. */
	private static function cached_shipping_version(): void {
		foreach ( [ 'pre_transient_shipping-transient-version', 'transient_shipping-transient-version', 'pre_wp_load_alloptions', 'alloptions' ] as $name ) { $hook = $GLOBALS['wp_filter'][$name] ?? null; if ( null !== $hook && ( ! is_object( $hook ) || [] !== self::raw( $hook, 'callbacks' ) ) ) { self::fail(); } }
		foreach ( [ '_transient_shipping-transient-version', '_transient_timeout_shipping-transient-version' ] as $option ) { foreach ( [ 'pre_option_', 'option_', 'default_option_' ] as $prefix ) { $hook = $GLOBALS['wp_filter'][$prefix . $option] ?? null; if ( null !== $hook && ( ! is_object( $hook ) || [] !== self::raw( $hook, 'callbacks' ) ) ) { self::fail(); } } }
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		if ( ! is_object( $cache ) || 'WP_Object_Cache' !== get_class( $cache ) || wp_using_ext_object_cache() || wp_installing() ) { self::fail(); }
		$data = self::raw( $cache, 'cache' ); $prefix = self::raw( $cache, 'blog_prefix' ); $globals = self::raw( $cache, 'global_groups' ); $multisite = self::raw( $cache, 'multisite' );
		if ( ! is_array( $data ) || ! is_string( $prefix ) || ! is_array( $globals ) || ! is_bool( $multisite ) ) { self::fail(); }
		$all = $data['options'][( $multisite && ! isset( $globals['options'] ) ? $prefix : '' ) . 'alloptions'] ?? null;
		$version = is_array( $all ) ? ( $all['_transient_shipping-transient-version'] ?? null ) : null;
		if ( ! is_string( $version ) || 1 !== preg_match( '/\A[1-9][0-9]{9}\z/D', $version ) ) { self::fail(); }
	}
	private static function decode( mixed $raw, bool $rates = false ): mixed {
		if ( is_array( $raw ) ) { $value = $raw; }
		elseif ( is_string( $raw ) && strlen( $raw ) <= 65536 && str_starts_with( $raw, 'a:' ) ) { $value = @unserialize( $raw, [ 'allowed_classes' => $rates ? [ 'WC_Shipping_Rate' ] : false, 'max_depth' => 16 ] ); }
		else { self::fail(); }
		if ( ! is_array( $value ) ) { self::fail(); } if ( ! $rates ) { $nodes = 0; self::bounded_value( $value, 0, $nodes ); } return $value;
	}
	private static function bounded_value( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > 8 || ++$nodes > 4096 ) { self::fail(); }
		if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 255 ) ) { self::fail(); } self::bounded_value( $item, $depth + 1, $nodes ); } return; }
		if ( is_string( $value ) ) { if ( strlen( $value ) > 8192 ) { self::fail(); } return; }
		if ( null === $value || is_int( $value ) || is_bool( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) { return; } self::fail();
	}
	private static function decimal( mixed $value ): string { if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) { self::fail(); } if ( is_string( $value ) && 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', $value ) ) { self::fail(); } if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || (float) $value > 1000000000000 ) { self::fail(); } return sprintf( '%.6F', (float) $value ); }
	private static function raw( object $object, string $name ): mixed { $property = new \ReflectionProperty( $object, $name ); if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { self::fail(); } return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object ); }
	private static function write_raw( object $object, string $name, mixed $value ): void { $property = new \ReflectionProperty( $object, $name ); if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { self::fail(); } if ( method_exists( $property, 'setRawValue' ) ) { $property->setRawValue( $object, $value ); } else { $property->setValue( $object, $value ); } }
	private static function fail(): never { throw new \RuntimeException( 'Existing native cart calculation unavailable.' ); }
}
