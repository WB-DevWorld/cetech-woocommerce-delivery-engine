<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteMoney,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/** Saved accepted terms are revalidated without a cart, provider capture, repricing or TTL renewal. */
final class QuoteSavedOrderNativeEvidence {
	public function __construct( private OperationConnectionFactory $factory ) {}
	public function capture( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, QuoteCartDraft $draft, QuoteSavedOrderAuthorization $authorization, QuotePlacementSavedEvidenceGuard $saved, QuoteNativeTaxSource $original_tax ): ?QuoteCartCurrentEvidence {
		try {
			if ( ! $authorization->unchanged() || ! self::hooks_supported() || null === $quote->context() || null === $quote->terms() || ! $quote->header()->owner()->equals( $draft->owner() ) || ! $original_tax->matches_context( $quote->context() ) ) { return null; }
			$hooks = QuoteNativeWooSource::capture_hook_fence( $original_tax->private_facts()['source']['selectors']['option_names'] );
			$context = $quote->context()->base_context();
			$source = ( new LegacyQuoteNativeSourcePreparer( $this->factory ) )->prepare_saved( $draft->owner(), $context, $draft, [ $authorization, 'unchanged' ] );
			$physical = $this->native_tax( $order, $quote, $binding, $context, $original_tax, $hooks );
			if ( ! $authorization->unchanged() || ! self::hooks_supported() || ! $hooks->hooks_unchanged() || ! $source->local_state_unchanged() ) { return null; }
			return new QuoteCartCurrentEvidence( $quote->context(), new LegacyQuoteCaptureGuard( $source->guard(), new QuoteSavedOrderNativeTaxGuard( $draft->owner(), $context, $physical, $authorization, $saved, $binding, $hooks ) ) );
		} catch ( \Throwable ) { return null; }
	}
	/** Extra native saved-order tax filters are unknown effects, alongside the retained finite policy. */
	public static function hooks_supported(): bool {
		try {
			if ( ! QuoteNativeWooSource::supports_current_hooks() ) { return false; }
			$hooks = [ 'woocommerce_order_is_vat_exempt', 'woocommerce_order_get_items_tax_classes', 'woocommerce_order_item_get_tax_class', 'woocommerce_order_item_get_tax_status', 'woocommerce_order_item_get_method_id', 'woocommerce_matched_tax_rates', 'sanitize_text_field', 'woocommerce_format_postcode' ];
			foreach ( [ 'shipping', 'billing' ] as $address ) { foreach ( [ 'country', 'state', 'city', 'postcode' ] as $field ) { $hooks[] = 'woocommerce_order_get_' . $address . '_' . $field; } }
			return QuoteNativeWooSource::hooks_absent( $hooks );
		} catch ( \Throwable ) { return false; }
	}
	/** One native matcher read outside owned SQL; no protected order packet is needed. */
	public static function prewarm_tax( \WC_Order $order, QuoteStoredRow $quote, QuoteNativeTaxSource $original_tax, ?QuoteNativeWooSource $hooks = null ): QuoteNativeShippingTaxProjection {
		$context = $quote->context();
		if ( ! in_array( get_class( $order ), [ 'WC_Order', 'Automattic\\WooCommerce\\Admin\\Overrides\\Order' ], true ) || null === $context || null === $quote->terms() || ! self::hooks_supported() || ! $original_tax->matches_context( $context ) ) { self::fail(); }
		$hooks ??= QuoteNativeWooSource::capture_hook_fence( $original_tax->private_facts()['source']['selectors']['option_names'] );
		if ( ! $hooks->hooks_unchanged() ) { self::fail(); }
		$currency = $order->get_currency( 'edit' ); $facts = $context->private_facts(); $precision = wc_get_price_decimals();
		if ( $currency !== $facts['currency']['charged'] || get_option( 'woocommerce_currency' ) !== $facts['currency']['base'] || $precision !== $facts['currency']['precision'] || [] !== $order->get_items( 'fee' ) || [] !== $order->get_items( 'coupon' ) ) { self::fail(); }
		$class = get_option( 'woocommerce_shipping_tax_class', 'inherit' );
		if ( 'inherit' === $class ) { $classes = $order->get_items_tax_classes(); if ( ! is_array( $classes ) || [] === $classes ) { self::fail(); } if ( in_array( '', $classes, true ) ) { $class = ''; } elseif ( 1 === count( array_unique( $classes ) ) ) { $class = (string) reset( $classes ); } else { self::fail(); } }
		if ( ! is_string( $class ) ) { self::fail(); }
		$location = $order->get_taxable_location(); if ( ! is_array( $location ) ) { self::fail(); }
		$location_tuple = []; foreach ( [ 'country', 'state', 'postcode', 'city' ] as $name ) { if ( ! is_string( $location[$name] ?? null ) ) { self::fail(); } $location_tuple[] = $location[$name]; }
		$exempt = 'yes' === $order->get_meta( 'is_vat_exempt', true ); $enabled = wc_tax_enabled(); $rounding = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? 'subtotal' : 'per_line';
		$identity = QuoteNativeContextIdentity::from_server(); $location_digest = $identity->tax_location_digest( $quote->site_id(), $location_tuple );
		$terms = []; foreach ( $quote->terms()->private_facts()['groups'] as $term ) { $receipt = $term['native_tax_receipt']; if ( $receipt['exempt'] !== $exempt || $receipt['tax_class'] !== $class || $receipt['location_digest'] !== $location_digest || $receipt['rounding'] !== $rounding || $receipt['display_precision'] !== $precision ) { self::fail(); } $terms[$term['component_key']] = $term; }
		$instances = []; $shipping = $order->get_items( 'shipping' ); if ( count( $shipping ) !== count( $terms ) ) { self::fail(); }
		$members = []; foreach ( $shipping as $item ) { if ( ! $item instanceof \WC_Order_Item_Shipping || SelectedOfferShippingMethod::METHOD_ID !== $item->get_method_id( 'edit' ) ) { self::fail(); } $group = $item->get_meta( 'cetech_de_group_id', true ); $component = is_string( $group ) && '' !== $group ? NativeCartQuotePreparation::component_key( $group ) : null; if ( null === $component || ! isset( $terms[$component] ) || isset( $members[$component] ) ) { self::fail(); } $members[$component] = $item; $instance = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec::integer( $item->get_instance_id( 'edit' ), 0 ); $instances[$instance] = $instance; }
		$names = QuoteNativeWooSource::OPTIONS; foreach ( $instances as $instance ) { $names[] = $instance > 0 ? 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_' . $instance . '_settings' : 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_settings'; }
		sort( $names, SORT_STRING ); sort( $instances, SORT_NUMERIC ); $selectors = $original_tax->private_facts()['source']['selectors']; if ( $selectors['option_names'] !== $names || $selectors['method_instance_ids'] !== array_values( $instances ) || $selectors['tax_class'] !== $class || $selectors['customer_id'] !== $order->get_customer_id( 'edit' ) || $selectors['site_id'] !== $quote->site_id() ) { self::fail(); }
		$before = QuoteNativeReceiptGuard::read_physical( $selectors );
		$effective = [ 'currency' => $currency, 'precision' => $precision, 'exempt' => $exempt, 'tax_class' => $class, 'location_digest' => $location_digest, 'rounding' => $rounding, 'tax_enabled' => $enabled ]; if ( ! $original_tax->with_current( $before, $effective )->matches_context( $context ) ) { self::fail(); }
		foreach ( $before['option_rows'] as $row ) { $value = get_option( $row['option_name'], null ); $storage = is_array( $value ) || ( is_string( $value ) && is_serialized( $value, false ) ) ? serialize( $value ) : (string) $value; if ( $storage !== $row['option_value'] ) { self::fail(); } }
		foreach ( $terms as $component => $term ) {
			$item = $members[$component];
			$instance = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec::integer( $item->get_instance_id( 'edit' ), 0 ); $name = $instance > 0 ? 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_' . $instance . '_settings' : 'woocommerce_' . SelectedOfferShippingMethod::METHOD_ID . '_settings'; $settings = get_option( $name, [] ); if ( ! is_array( $settings ) || ( $settings['tax_status'] ?? 'taxable' ) !== $term['native_tax_receipt']['tax_status'] ) { self::fail(); }
			if ( $instance > 0 ) { $found = false; foreach ( $before['method_rows'] as $row ) { if ( (int) $row['instance_id'] === $instance && $row['method_id'] === SelectedOfferShippingMethod::METHOD_ID && '1' === $row['is_enabled'] ) { $found = true; } } if ( ! $found ) { self::fail(); } }
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' ); wp_cache_delete( 'tax-rate-classes', 'taxes' ); \WC_Tax::init(); $rates = \WC_Tax::find_shipping_rates( $location + [ 'tax_class' => $class ] ); if ( ! is_array( $rates ) || count( $rates ) > 200 ) { self::fail(); }
		$after = QuoteNativeReceiptGuard::read_physical( $selectors );
		if ( ! self::hooks_supported() || ! $hooks->hooks_unchanged() || \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $before ) !== \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $after ) ) { self::fail(); }
		return QuoteNativeShippingTaxProjection::from_verified_native( $original_tax, $context, $after, $effective, $rates );
	}
	private function native_tax( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, QuoteContext $context, QuoteNativeTaxSource $original_tax, ?QuoteNativeWooSource $hooks = null ): array {
		$hooks ??= QuoteNativeWooSource::capture_hook_fence( $original_tax->private_facts()['source']['selectors']['option_names'] );
		$projection = self::prewarm_tax( $order, $quote, $original_tax, $hooks );
		$source = $original_tax->private_facts(); $enabled = $source['tax_enabled']; $exempt = $source['exempt']; $rounding = $source['rounding']; $rates = $projection->native_rates();
		$terms = []; foreach ( $quote->terms()->private_facts()['groups'] as $term ) { $terms[$term['component_key']] = $term; }
		// saved_guard already loaded and physically verified these same native line objects.
		$line_items = $order->get_items( 'line_item' ); $order_id = $order->get_id(); $expected = [];
		foreach ( $binding->mapping()['groups'] as $group ) { foreach ( $group['lines'] as $line ) { $id = $line['item_id']; if ( isset( $expected[$id] ) ) { self::fail(); } $expected[$id] = true; } }
		if ( ! is_array( $line_items ) || [] === $line_items || count( $line_items ) > 200 || count( $line_items ) !== count( $expected ) || $binding->row()['order_id'] !== $order_id ) { self::fail(); }
		foreach ( $line_items as $id => $item ) { if ( ! is_int( $id ) || $id < 1 || ! isset( $expected[$id] ) || ! $item instanceof \WC_Order_Item_Product || 'WC_Order_Item_Product' !== get_class( $item ) || $item->get_id() !== $id || $item->get_order_id( 'edit' ) !== $order_id ) { self::fail(); } }
		$by_group = []; foreach ( $binding->mapping()['groups'] as $group ) { $line = $group['lines'][0]; $item = $line_items[$line['item_id']]; $snapshot = ( new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader() )->read_line( $item )->snapshot; if ( null === $snapshot || null === $snapshot->delivery_group_id || isset( $by_group[$snapshot->delivery_group_id] ) ) { self::fail(); } $by_group[$snapshot->delivery_group_id] = $group['component_key']; }
		$members = []; foreach ( $order->get_items( 'shipping' ) as $item ) { $group = $item->get_meta( 'cetech_de_group_id', true ); $component = is_string( $group ) ? ( $by_group[$group] ?? null ) : null; if ( null === $component || isset( $members[$component] ) ) { self::fail(); } $members[$component] = $item; }
		foreach ( $terms as $component => $term ) {
			$item = $members[$component] ?? null; if ( null === $item || ! self::money_equal( $item->get_total( 'edit' ), $term['final'] ) ) { self::fail(); }
			$taxes = $item->get_taxes( 'edit' ); $stored = is_array( $taxes ) ? ( $taxes['total'] ?? null ) : null; if ( ! is_array( $stored ) || count( $stored ) > 200 ) { self::fail(); }
			$fresh = $enabled && ! $exempt && 'taxable' === $term['native_tax_receipt']['tax_status'] && (float) $term['final']['amount'] > 0 ? \WC_Tax::calc_shipping_tax( (float) $term['final']['amount'], $rates ) : [];
			$expected = []; foreach ( $term['native_tax_receipt']['rates'] as $rate ) { $expected[(string) $rate['rate_id']] = self::decimal( $rate['amount']['amount'] ); } $a = self::taxes( $stored ); $b = self::taxes( $fresh ); ksort( $expected, SORT_STRING ); $item_tax = 'subtotal' === $rounding ? $term['tax'] : $term['native_tax_receipt']['rounded_tax'];
			$matches = $expected === $a && $expected === $b;
			if ( ! $matches && QuoteNativeShippingTaxProjection::eligible( $term ) ) { $matches = $projection->accepts_zero_map( $term, $stored ) && $projection->accepts_zero_map( $term, $fresh ); }
			if ( ! $matches || ! self::money_equal( $item->get_total_tax( 'edit' ), $item_tax ) ) { self::fail(); }
		}
		$after = QuoteNativeReceiptGuard::read_physical( $projection->physical()['selectors'] ); if ( ! self::hooks_supported() || ! $hooks->hooks_unchanged() || \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $projection->physical() ) !== \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode( $after ) ) { self::fail(); } return $after;
	}
	private static function money_equal( mixed $amount, array $expected ): bool { try { if ( ! is_string( $amount ) ) { return false; } $precision = max( $expected['precision'], self::scale( $amount ) ); if ( $precision > 6 ) { return false; } return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $expected['currency'], 'precision' => $precision ] )->equals( QuoteMoney::from_array( array_replace( $expected, [ 'precision' => $precision ] ) ) ); } catch ( \Throwable ) { return false; } }
	private static function taxes( array $values ): array { $out = []; foreach ( $values as $id => $amount ) { if ( ( ! is_int( $id ) && ( ! is_string( $id ) || ! ctype_digit( $id ) ) ) || (int) $id < 1 ) { self::fail(); } $out[(string) $id] = self::decimal( wc_format_decimal( $amount, false ) ); } ksort( $out, SORT_STRING ); return $out; }
	private static function decimal( string $amount ): string { QuoteNativeState::decimal( $amount ); return str_contains( $amount, '.' ) ? rtrim( rtrim( $amount, '0' ), '.' ) : $amount; }
	private static function scale( string $amount ): int { $point = strpos( $amount, '.' ); return false === $point ? 0 : strlen( $amount ) - $point - 1; }
	private static function fail(): never { throw new \RuntimeException( 'Saved quote native evidence unavailable.' ); }
}
