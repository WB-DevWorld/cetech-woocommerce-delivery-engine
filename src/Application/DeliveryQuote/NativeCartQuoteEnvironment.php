<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;

/** Native loaded draft first; all source/product/tax work belongs to the admitted preparation. */
final class NativeCartQuoteEnvironment implements CartQuoteEnvironment {
	private NativeCartQuotePreparation $preparation;
	private QuotePreparationAccess $current_access;
	private ?CartQuoteRateProjection $rate_projection = null;
	public function __construct( OperationConnectionFactory $factory, ?QuotePreparationAccess $current_access = null ) { $this->preparation = new NativeCartQuotePreparation( $factory ); $this->current_access = $current_access ?? new NativeQuotePreparationAccess( $factory ); }
	/** Trusted composition only; replacement cannot change a captured native lifecycle. */
	public function set_rate_projection( CartQuoteRateProjection $projection ): void {
		if ( null !== $this->rate_projection && $this->rate_projection !== $projection ) { throw new \LogicException( 'Cart quote rate projection is already installed.' ); }
		$this->rate_projection = $projection;
	}
	public function draft(): ?QuoteCartDraft {
		try {
			[ $owner, $identity, $wc ] = self::loaded_owner(); $cart = self::raw( $wc, 'cart' );
			if ( ! $cart instanceof \WC_Cart ) { self::fail(); }
			$currency = self::loaded_option( 'woocommerce_currency' ); $precision = self::loaded_option( 'woocommerce_price_num_decimals' );
			if ( ! is_string( $currency ) || ( ! is_int( $precision ) && ( ! is_string( $precision ) || 1 !== preg_match( '/\A[0-6]\z/D', $precision ) ) ) ) { self::fail(); }
			$items = self::raw( $cart, 'cart_contents' ); $customer = self::raw( $wc, 'customer' );
			$data = self::raw( $customer, 'data' ); $changes = self::raw( $customer, 'changes' );
			if ( ! is_array( $items ) || ! is_array( $data ) || ! is_array( $changes ) || ! is_array( $data['shipping'] ?? null ) || ( isset( $changes['shipping'] ) && ! is_array( $changes['shipping'] ) ) ) { self::fail(); }
			$shipping = array_replace( $data['shipping'], $changes['shipping'] ?? [] ); $destination = [];
			foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $field ) { $destination['address_1' === $field ? 'address' : $field] = $shipping[$field] ?? ''; }
			return QuoteCartDraft::from_loaded_cart( $owner, $items, $destination, $currency, (int) $precision, $identity );
		} catch ( \Throwable ) { return null; }
	}
	public function authorize( QuoteOwner $owner, string $operation ): bool {
		try { return in_array( $operation, [ 'delivery_quote.issue', 'delivery_quote.read', 'delivery_quote.accept', 'delivery_quote.invalidate', 'delivery_quote.session', 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ], true ) && $owner->equals( self::loaded_owner()[0] ); } catch ( \Throwable ) { return false; }
	}
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture {
		if ( ! $this->same_draft( $draft ) || ! $this->authorize( $draft->owner(), 'delivery_quote.issue' ) ) { self::fail(); }
		// The caller already owns the early preparation lease. Native totals are never
		// initialized by draft(), current(), or an unadmitted transport request.
		NativeCartQuoteShipping::prepare_after_admission();
		if ( ! $this->same_draft( $draft ) ) { self::fail(); }
		$prepared = $this->preparation->prepare( $draft );
		if ( ! $this->same_draft( $draft ) || ! $this->authorize( $draft->owner(), 'delivery_quote.issue' ) ) { self::fail(); } return $prepared;
	}
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence {
		try {
			if ( ! $this->same_draft( $draft ) || ! $this->preparation->matches_original( $original, $header, $draft ) || ! $this->authorize( $draft->owner(), 'delivery_quote.read' ) ) { return null; }
			$revision = $this->current_access->observe( $draft->owner() ); if ( null === $revision ) { return null; }
			// Restore an exact existing native calculation, never calculate new rates.
			NativeCartQuoteShipping::restore_cached_calculation();
			// Inert handles belong to the restored packet before its raw fence is captured.
			$this->rate_projection?->project_loaded_rates();
			$evidence = $this->preparation->evidence( $original, $header, $draft );
			return null !== $evidence && $this->same_draft( $draft ) && $this->authorize( $draft->owner(), 'delivery_quote.read' ) && $this->current_access->confirm( $draft->owner(), $revision ) ? $evidence : null;
		} catch ( \Throwable ) { return null; }
	}
	private function same_draft( QuoteCartDraft $draft ): bool { $current = $this->draft(); return null !== $current && $draft->owner()->equals( $current->owner() ) && hash_equals( $draft->draft_digest(), $current->draft_digest() ); }

	/** Reads already initialized native identity only. No WC/WP getter, filter, lazy load or SQL. */
	private static function loaded_owner(): array {
		$wc = $GLOBALS['woocommerce'] ?? null; $user = $GLOBALS['current_user'] ?? null; $site = $GLOBALS['blog_id'] ?? 1;
		if ( ! is_object( $wc ) || ! is_object( $user ) || ! is_int( $site ) || $site < 1 ) { self::fail(); }
		$session = self::raw( $wc, 'session' ); $customer = self::raw( $wc, 'customer' ); $user_id = self::raw( $user, 'ID' );
		if ( ! $session instanceof \WC_Session_Handler || 'WC_Session_Handler' !== get_class( $session ) || ! $customer instanceof \WC_Customer || ! is_int( $user_id ) || $user_id < 0 ) { self::fail(); }
		$id = self::raw( $session, '_customer_id' ); $has_cookie = self::raw( $session, '_has_cookie' );
		if ( ! is_string( $id ) || '' === $id || strlen( $id ) > 128 || ! is_bool( $has_cookie ) || ( 0 === $user_id && ! $has_cookie ) || ( $user_id > 0 && (string) $user_id !== $id ) ) { self::fail(); }
		$filters = $GLOBALS['wp_filter'] ?? []; if ( ! is_array( $filters ) ) { self::fail(); } $salt_hook = $filters['salt'] ?? null;
		if ( null !== $salt_hook && ( ! is_object( $salt_hook ) || [] !== self::raw( $salt_hook, 'callbacks' ) ) ) { self::fail(); }
		$salt = null;
		if ( function_exists( 'wp_salt' ) ) { $cached = ( new \ReflectionFunction( 'wp_salt' ) )->getStaticVariables(); $salt = $cached['cached_salts']['auth'] ?? null; }
		if ( null === $salt && defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) && is_string( AUTH_KEY ) && is_string( AUTH_SALT ) && 'put your unique phrase here' !== AUTH_KEY && 'put your unique phrase here' !== AUTH_SALT ) { $salt = AUTH_KEY . AUTH_SALT; }
		if ( ! is_string( $salt ) || strlen( $salt ) < 16 || strlen( $salt ) > 4096 ) { self::fail(); }
		$token = '';
		if ( $user_id > 0 ) {
			if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! is_string( LOGGED_IN_COOKIE ) || ! is_string( $_COOKIE[LOGGED_IN_COOKIE] ?? null ) || strlen( $_COOKIE[LOGGED_IN_COOKIE] ) > 4096 ) { self::fail(); }
			$parts = explode( '|', $_COOKIE[LOGGED_IN_COOKIE] ); if ( 4 !== count( $parts ) || '' === $parts[2] || strlen( $parts[2] ) > 256 ) { self::fail(); } $token = $parts[2];
		}
		$identity = QuoteNativeContextIdentity::from_private_key( $salt );
		$owner = QuoteOwner::from_array( [ 'site_id' => $site, 'kind' => $user_id > 0 ? 'customer' : 'guest', 'principal_hash' => hash_hmac( 'sha256', 'native-quote-principal-v1:' . $site . ':' . ( $user_id > 0 ? 'u' . $user_id : 'g' . $id ), $salt ), 'session_hash' => hash_hmac( 'sha256', 'native-quote-session-v1:' . $site . ':' . $id . ':' . $token, $salt ), 'key_epoch' => $identity->key_epoch() ] );
		return [ $owner, $identity, $wc ];
	}
	private static function loaded_option( string $name ): mixed {
		$cache = $GLOBALS['wp_object_cache'] ?? null; if ( ! is_object( $cache ) || 'WP_Object_Cache' !== get_class( $cache ) ) { self::fail(); }
		$data = self::raw( $cache, 'cache' ); $prefix = self::raw( $cache, 'blog_prefix' ); $globals = self::raw( $cache, 'global_groups' ); $multisite = self::raw( $cache, 'multisite' );
		if ( ! is_array( $data ) || ! is_string( $prefix ) || ! is_array( $globals ) || ! is_bool( $multisite ) ) { self::fail(); }
		$key_prefix = $multisite && ! isset( $globals['options'] ) ? $prefix : ''; $group = $data['options'] ?? null;
		if ( ! is_array( $group ) ) { self::fail(); } $all = $group[$key_prefix . 'alloptions'] ?? null;
		if ( is_array( $all ) && array_key_exists( $name, $all ) ) { return $all[$name]; }
		if ( array_key_exists( $key_prefix . $name, $group ) ) { return $group[$key_prefix . $name]; } self::fail();
	}
	private static function raw( object $object, string $name ): mixed {
		$reflection = new \ReflectionObject( $object ); if ( ! $reflection->hasProperty( $name ) ) { self::fail(); } $property = $reflection->getProperty( $name );
		if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { self::fail(); } return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object );
	}
	private static function fail(): never { throw new \RuntimeException( 'Cart quote unavailable.' ); }
}
