<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** A native route checks exact-order access first; this receipt fences its loaded identity without IO. */
final readonly class QuoteSavedOrderAuthorization implements \JsonSerializable {
	private function __construct( private \WC_Order $order, private object $user, private string $fingerprint ) {}
	public static function capture( \WC_Order $order, callable $authorize ): self {
		if ( true !== $authorize( $order ) || ! is_object( $GLOBALS['current_user'] ?? null ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); }
		$user = $GLOBALS['current_user']; $self = new self( $order, $user, self::fingerprint( $order, $user ) );
		if ( true !== $authorize( $order ) || ! $self->unchanged() ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } return $self;
	}
	public function unchanged(): bool { try { return $this->user === ( $GLOBALS['current_user'] ?? null ) && hash_equals( $this->fingerprint, self::fingerprint( $this->order, $this->user ) ); } catch ( \Throwable ) { return false; } }
	private static function fingerprint( \WC_Order $order, object $user ): string {
		$data = self::raw( $order, 'data' ); $changes = self::raw( $order, 'changes' ); $id = self::raw( $order, 'id' ); $user_id = self::raw( $user, 'ID' ); $site = $GLOBALS['blog_id'] ?? 1;
		if ( ! is_array( $data ) || ! is_array( $changes ) || ! is_int( $id ) || $id < 1 || ! is_int( $user_id ) || $user_id < 0 || ! is_int( $site ) || $site < 1 ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); }
		$data = array_replace( $data, $changes ); $customer = $data['customer_id'] ?? null; $key = $data['order_key'] ?? null;
		if ( ! is_int( $customer ) || $customer < 0 || ! is_string( $key ) || '' === $key || strlen( $key ) > 128 ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); }
		$cookies = []; foreach ( [ 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ] as $constant ) { if ( defined( $constant ) ) { $name = constant( $constant ); $value = $_COOKIE[$name] ?? null; if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > 4096 ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } $cookies[$constant] = null === $value ? null : hash( 'sha256', $value ); } }
		$request_keys = []; foreach ( [ 'query' => $_GET, 'post' => $_POST ] as $source => $request ) { $request_key = $request['key'] ?? null; if ( null !== $request_key && ( ! is_string( $request_key ) || strlen( $request_key ) > 128 ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } $request_keys[$source] = $request_key; }
		$caps = []; foreach ( [ 'caps', 'allcaps', 'roles' ] as $property ) { $reflection = new \ReflectionObject( $user ); if ( $reflection->hasProperty( $property ) ) { $caps[$property] = self::raw( $user, $property ); $nodes = 0; self::primitive( $caps[$property], 0, $nodes ); } }
		$native = $GLOBALS['woocommerce'] ?? null; $session = null; if ( is_object( $native ) ) { $reflection = new \ReflectionObject( $native ); if ( $reflection->hasProperty( 'session' ) ) { $session = self::raw( $native, 'session' ); } }
		$session_facts = null; if ( $session instanceof \WC_Session_Handler ) { if ( \WC_Session_Handler::class !== get_class( $session ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } $session_facts = [ 'object' => spl_object_id( $session ) ]; foreach ( [ '_customer_id', '_has_cookie', '_session_expiration', '_session_expiring' ] as $property ) { $session_facts[$property] = self::raw( $session, $property ); $nodes = 0; self::primitive( $session_facts[$property], 0, $nodes ); } $session_data = self::raw( $session, '_data' ); if ( ! is_array( $session_data ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } $session_facts['continuation'] = $session_data['cetech_de_quote_payment_continuation'] ?? null; $nodes = 0; self::primitive( $session_facts['continuation'], 0, $nodes ); }
		return hash( 'sha256', json_encode( [ 'site' => $site, 'order_class' => get_class( $order ), 'order_object' => spl_object_id( $order ), 'order_id' => $id, 'customer_id' => $customer, 'order_key' => $key, 'user_class' => get_class( $user ), 'user_id' => $user_id, 'user_object' => spl_object_id( $user ), 'capabilities' => $caps, 'cookies' => $cookies, 'request_keys' => $request_keys, 'native_object' => is_object( $native ) ? spl_object_id( $native ) : null, 'native_session' => $session_facts ], JSON_THROW_ON_ERROR ) );
	}
	private static function primitive( mixed $value, int $depth, int &$nodes ): void { if ( ++$nodes > 512 || $depth > 8 || ! is_array( $value ) && ! is_string( $value ) && ! is_int( $value ) && ! is_bool( $value ) && null !== $value || is_string( $value ) && strlen( $value ) > 4096 ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } if ( is_array( $value ) ) { foreach ( $value as $child ) { self::primitive( $child, $depth + 1, $nodes ); } } }
	public function jsonSerialize(): never { throw new \LogicException( 'Saved quote authorization is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Saved quote authorization is private.' ); }
	private static function raw( object $object, string $name ): mixed { $reflection = new \ReflectionObject( $object ); while ( $reflection && ! $reflection->hasProperty( $name ) ) { $reflection = $reflection->getParentClass(); } if ( ! $reflection ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } $property = $reflection->getProperty( $name ); if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); } return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object ); }
}
