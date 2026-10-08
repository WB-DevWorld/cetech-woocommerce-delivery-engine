<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteEnvironment,CartQuoteSessionStore,NativeCartQuotePreparation,QuoteCartDraft};
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/** Inert per-component identifiers. Prices, taxes and placement authority stay native. */
final class QuoteRateReferenceRuntime {

	public const META_PREFIX = '_cetech_de_quote_';
	public const META_QUOTE_ID = self::META_PREFIX . 'id';
	public const META_COMPONENT_HANDLE = self::META_PREFIX . 'component_handle';
	public const META_GENERATION = self::META_PREFIX . 'generation';
	public const PRIORITY = PHP_INT_MAX - 1;
	public const LOADED_PRIORITY = 900;
	private bool $registered = false;
	private ?\Closure $adoption_gate;

	public function __construct( private readonly CartQuoteEnvironment $environment, private readonly CartQuoteSessionStore $sessions, private readonly bool $mounted = false, ?callable $adoption_gate = null ) { $this->adoption_gate = null === $adoption_gate ? null : \Closure::fromCallable( $adoption_gate ); }
	private function enabled(): bool { try { return $this->mounted && ( null === $this->adoption_gate || true === ( $this->adoption_gate )() ); } catch ( \Throwable ) { return false; } }
	public function register(): void { if ( ! $this->registered && $this->enabled() ) { add_filter( 'woocommerce_package_rates', [ $this, 'filter_rates' ], self::PRIORITY, 2 ); add_action( 'woocommerce_after_calculate_totals', [ $this, 'annotate_loaded_rates' ], self::LOADED_PRIORITY, 1 ); $this->registered = true; } }

	/** Cache hits skip package_rates. Decorate the already-loaded exact native packet. */
	public function annotate_loaded_rates( mixed $cart ): void {
		if ( ! $this->enabled() ) { return; }
		try {
			$wc = $GLOBALS['woocommerce'] ?? null;
			if ( ! is_object( $cart ) || 'WC_Cart' !== get_class( $cart ) || ! is_object( $wc ) || self::raw( $wc, 'cart' ) !== $cart || ! class_exists( 'WC_Shipping', false ) ) { return; }
			$singleton = new \ReflectionProperty( 'WC_Shipping', '_instance' );
			if ( ! $singleton->isStatic() ) { return; } $shipping = $singleton->getValue( null );
			if ( ! is_object( $shipping ) || 'WC_Shipping' !== get_class( $shipping ) ) { return; }
			$property = new \ReflectionProperty( $shipping, 'packages' );
			if ( $property->isStatic() || ! $property->isInitialized( $shipping ) || 'WC_Shipping' !== $property->getDeclaringClass()->getName() ) { return; }
			$packages = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $shipping ) : $property->getValue( $shipping );
			if ( ! is_array( $packages ) || count( $packages ) > 200 ) { return; }
			foreach ( $packages as $index => $package ) { if ( ! is_array( $package ) || ! is_array( $package['rates'] ?? null ) ) { return; } $packages[$index]['rates'] = $this->filter_rates( $package['rates'], $package ); }
			$property->setValue( $shipping, $packages );
		} catch ( \Throwable ) { return; }
	}

	/** No totals, source capture, rate getter, session write or durable quote operation. */
	public function filter_rates( array $rates, array $package ): array {
		if ( ! $this->enabled() ) { return $rates; }
		if ( count( $rates ) > 200 ) { return []; }
		try {
			$fields = $this->reference_fields( $package ); $prepared = [];
			foreach ( $rates as $key => $rate ) {
				if ( ! is_object( $rate ) || 'WC_Shipping_Rate' !== get_class( $rate ) ) { return []; }
				$property = new \ReflectionProperty( $rate, 'meta_data' );
				if ( $property->isStatic() || ! $property->isInitialized( $rate ) || 'WC_Shipping_Rate' !== $property->getDeclaringClass()->getName() ) { return []; }
				$meta = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $rate ) : $property->getValue( $rate );
				if ( ! is_array( $meta ) || count( $meta ) > 200 ) { return []; }
				foreach ( array_keys( $meta ) as $name ) { if ( is_string( $name ) && ( str_starts_with( $name, self::META_PREFIX ) || str_starts_with( $name, 'cetech_de_quote_' ) ) ) { unset( $meta[$name] ); } }
				$data_property = new \ReflectionProperty( $rate, 'data' );
				if ( $data_property->isStatic() || ! $data_property->isInitialized( $rate ) || 'WC_Shipping_Rate' !== $data_property->getDeclaringClass()->getName() ) { return []; }
				$data = method_exists( $data_property, 'getRawValue' ) ? $data_property->getRawValue( $rate ) : $data_property->getValue( $rate );
				$group = $package[DeliveryGroupIdentity::PACKAGE_META_KEY]['group_id'] ?? null;
				if ( null !== $fields && is_array( $data ) && ( $data['method_id'] ?? null ) === SelectedOfferShippingMethod::METHOD_ID && is_string( $data['id'] ?? null ) && $data['id'] === $key && ( $meta['cetech_de_group_id'] ?? null ) === $group ) {
					$meta[self::META_QUOTE_ID] = $fields['quote_id']; $meta[self::META_COMPONENT_HANDLE] = $fields['component_handle']; $meta[self::META_GENERATION] = $fields['generation'];
				}
				$prepared[] = [ $property, $rate, $meta ];
			}
			// Keep each exact native rate object; never replace or copy its money packet.
			foreach ( $prepared as [ $property, $rate, $meta ] ) { $property->setValue( $rate, $meta ); }
			return $rates;
		} catch ( \Throwable ) { return []; }
	}

	private function reference_fields( array $package ): ?array {
		try {
			if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) { return null; }
			$group = $package[DeliveryGroupIdentity::PACKAGE_META_KEY]['group_id'] ?? null;
			if ( ! is_string( $group ) || '' === $group || strlen( $group ) > DeliveryGroupIdentity::COLUMN_LENGTH || DeliveryGroupIdentity::has_runtime_reselect( $group ) ) { return null; }
			$draft = $this->environment->draft();
			if ( null === $draft || ! $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return null; }
			$envelope = $this->sessions->load( $draft->owner() ); $now = QuoteTime::now();
			if ( null === $envelope || ! $envelope->owner()->equals( $draft->owner() ) || ! in_array( $envelope->phase(), [ 'issued', 'confirmed' ], true ) || $envelope->expires_at() <= intdiv( $now->epoch_microseconds(), 1000000 ) || ! hash_equals( $envelope->preparation()->draft_digest(), $draft->draft_digest() ) ) { return null; }
			$header = $envelope->header(); $original = $envelope->original_issue();
			if ( null === $header || null === $original || ! $header->valid_at( $now ) || ! $this->package_matches( $package, $draft, $original->context()->private_facts(), $group ) ) { return null; }
			$component = NativeCartQuotePreparation::component_key( $group );
			foreach ( $envelope->rate_references() as $reference ) {
				if ( ! $reference->matches( $header, $component, $envelope->generation() ) ) { continue; }
				$current = $this->environment->draft();
				return null !== $current && $current->owner()->equals( $draft->owner() ) && hash_equals( $current->draft_digest(), $draft->draft_digest() ) && $this->environment->authorize( $draft->owner(), 'delivery_quote.read' ) ? $reference->public_fields() : null;
			}
			return null;
		} catch ( \Throwable ) { return null; }
	}

	/** Exact line keys and captured quantities; labels and duplicate SKUs are not identities. */
	private function package_matches( array $package, QuoteCartDraft $draft, array $context, string $group ): bool {
		$contents = $package['contents'] ?? null; $destination = $package['destination'] ?? null;
		if ( ! is_array( $contents ) || [] === $contents || count( $contents ) > 200 || ! is_array( $destination ) ) { return false; }
		$facts = $draft->private_facts(); $expected_destination = [];
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address', 'address_2' ] as $field ) { $value = $destination[$field] ?? ( 'address' === $field ? ( $destination['address_1'] ?? '' ) : '' ); if ( ! is_string( $value ) || strlen( $value ) > 512 ) { return false; } $expected_destination[$field] = $value; }
		$nodes = 0; if ( self::closed( $expected_destination, 0, $nodes ) !== $facts['customer_destination'] ) { return false; }
		$component = NativeCartQuotePreparation::component_key( $group ); $expected_keys = null;
		foreach ( $context['groups'] as $row ) { if ( $row['component_key'] === $component ) { $expected_keys = $row['line_keys']; break; } }
		$keys = array_keys( $contents ); sort( $keys, SORT_STRING );
		if ( null === $expected_keys || $keys !== $expected_keys ) { return false; }
		$draft_lines = []; foreach ( $facts['lines'] as $line ) { $draft_lines[$line['line_key']] = $line; }
		$context_lines = []; foreach ( $context['lines'] as $line ) { $context_lines[$line['line_key']] = $line; }
		foreach ( $contents as $key => $item ) {
			$line = $draft_lines[$key] ?? null; $captured = $context_lines[$key] ?? null;
			if ( ! is_array( $item ) || null === $line || null === $captured || ! is_int( $item['quantity'] ?? null ) || ( $item['product_id'] ?? null ) !== $line['product_id'] || ( $item['variation_id'] ?? 0 ) !== ( $line['variation_id'] ?? 0 ) || (string) $item['quantity'] !== $line['quantity'] || $captured['product_id'] !== $line['product_id'] || $captured['variation_id'] !== $line['variation_id'] || $captured['quantity'] !== $line['quantity'] || $captured['component_key'] !== $component || ( $item[CartDeliverySelectionCapture::CART_HASH_KEY] ?? null ) !== $line['selection_hash'] || DeliveryGroupIdentity::fromCartItem( $item ) !== $group ) { return false; }
			$raw_selection = $item[CartDeliverySelectionCapture::CART_SELECTION_KEY] ?? null; $customer = $item[CustomerCartContext::CART_KEY] ?? null;
			if ( ! is_array( $raw_selection ) || ! is_array( $customer ) ) { return false; }
			foreach ( $line['selection'] as $field => $value ) { if ( ( $raw_selection[$field] ?? null ) !== $value ) { return false; } }
			unset( $customer['recipient'] ); if ( is_array( $customer['delivery_address'] ?? null ) ) { unset( $customer['delivery_address']['recipient'] ); }
			$nodes = 0; if ( self::closed( $customer, 0, $nodes ) !== $line['customer_context'] ) { return false; }
		}
		return true;
	}

	private static function closed( mixed $value, int $depth, int &$nodes ): mixed {
		if ( $depth > 8 || ++$nodes > 1024 ) { throw new \RuntimeException( 'Rate reference unavailable.' ); }
		if ( is_array( $value ) ) { $out = []; foreach ( $value as $key => $item ) { if ( ! is_int( $key ) && ! is_string( $key ) ) { throw new \RuntimeException( 'Rate reference unavailable.' ); } $out[$key] = self::closed( $item, $depth + 1, $nodes ); } if ( ! array_is_list( $out ) ) { ksort( $out, SORT_STRING ); } return $out; }
		if ( is_string( $value ) && strlen( $value ) <= 2048 || is_int( $value ) || is_bool( $value ) || null === $value ) { return $value; }
		throw new \RuntimeException( 'Rate reference unavailable.' );
	}
	private static function raw( object $object, string $name ): mixed { $property = new \ReflectionProperty( $object, $name ); if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { throw new \RuntimeException( 'Rate reference unavailable.' ); } return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object ); }
}
