<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts;

/** Original acknowledged object, allowing only Woo's native payment-method save. */
final readonly class PolicyNativePaymentLocalBinding implements \JsonSerializable {
	private const OWNERS = [ 'WC_Data', 'WC_Abstract_Order', 'WC_Order', 'WC_Order_Item', 'WC_Order_Item_Product', 'WC_Order_Item_Shipping', 'WC_Order_Item_Tax', 'WC_Meta_Data' ];
	private function __construct( private \WC_Order $order, private string $digest, private string $native_digest, private mixed $site, private ?string $logging_digest = null ) {}
	public static function capture( \WC_Order $order ): self { return new self( $order, self::digest( $order ), self::digest( $order, true ), $GLOBALS['blog_id'] ?? null ); }
	/** Only the typed, observed native logger row may differ in this Classic attempt. */
	public static function capture_logging( \WC_Order $order, PolicyNativeCheckoutLoggingEvidence $logging ): self { return new self( $order, self::digest( $order ), self::digest( $order, true ), $GLOBALS['blog_id'] ?? null, self::digest( $order, false, $logging ) ); }
	public function logging_unchanged( PolicyNativeCheckoutLoggingEvidence $logging ): bool { try { return null !== $this->logging_digest && ( $GLOBALS['blog_id'] ?? null ) === $this->site && hash_equals( $this->logging_digest, self::digest( $this->order, false, $logging ) ); } catch ( \Throwable ) { return false; } }
	public function unchanged(): bool { try { return ( $GLOBALS['blog_id'] ?? null ) === $this->site && hash_equals( $this->digest, self::digest( $this->order ) ); } catch ( \Throwable ) { return false; } }
	/** Classic reloads the same native coordinates; no object identity is transferred. */
	public function same_native_facts( \WC_Order $order, ?PolicyNativeCheckoutLoggingEvidence $logging = null ): bool { try { return ( $GLOBALS['blog_id'] ?? null ) === $this->site && hash_equals( $this->native_digest, self::digest( $order, true, $logging ) ); } catch ( \Throwable ) { return false; } }
	public function payment_matches( string $id, string $title ): bool {
		try { $data = self::effective( $this->order ); return ( $data['payment_method'] ?? null ) === $id && ( $data['payment_method_title'] ?? null ) === $title; } catch ( \Throwable ) { return false; }
	}
	private static function digest( \WC_Order $order, bool $native = false, ?PolicyNativeCheckoutLoggingEvidence $logging = null ): string { $nodes = 0; $seen = []; $index = null === $logging ? null : $logging->admitted_index( $order ); $facts = self::value( $order, 0, $nodes, $seen, $order, $native, $index ); $hash = EmergencyCheckoutFacts::bounded_hash( $facts ); if ( null === $hash ) { throw new \RuntimeException( 'Native payment continuation unavailable.' ); } return $hash; }
	private static function value( mixed $value, int $depth, int &$nodes, array &$seen, \WC_Order $root, bool $native, ?int $logging_index = null ): mixed {
		if ( ++$nodes > 262144 || $depth > 24 || is_resource( $value ) ) { throw new \RuntimeException( 'Native payment continuation unavailable.' ); }
		if ( is_array( $value ) ) { $out = []; foreach ( $value as $key => $child ) { $out[$key] = self::value( $child, $depth + 1, $nodes, $seen, $root, $native, $logging_index ); } return $out; }
		if ( ! is_object( $value ) ) { return $value; }
		$class = get_class( $value );
		if ( in_array( $class, [ 'DateTime', 'DateTimeImmutable', 'WC_DateTime' ], true ) ) { return [ 'date_type' => $class, 'date' => self::value( (array) $value, $depth + 1, $nodes, $seen, $root, $native ) ]; }
		if ( ! $value instanceof \WC_Order && ! $value instanceof \WC_Order_Item_Product && ! $value instanceof \WC_Order_Item_Shipping && ! is_a( $value, 'WC_Order_Item_Tax', false ) ) { throw new \RuntimeException( 'Unsupported native payment continuation object.' ); }
		$id = spl_object_id( $value ); if ( isset( $seen[$id] ) ) { return [ 'reference' => $native ? true : $id ]; } $seen[$id] = true;
		$data = self::effective( $value );
		if ( $value === $root ) {
			if ( array_key_exists( 'date_modified', $data ) && null !== $data['date_modified'] && ( ! is_object( $data['date_modified'] ) || 'WC_DateTime' !== get_class( $data['date_modified'] ) ) ) { throw new \RuntimeException( 'Native payment date unavailable.' ); }
			unset( $data['payment_method'], $data['payment_method_title'], $data['date_modified'] );
		}
		$native_class = $native && $value === $root && in_array( $class, [ 'WC_Order', 'Automattic\\WooCommerce\\Admin\\Overrides\\Order' ], true ) ? 'WC_Order' : $class;
		$out = [ 'class' => $native_class, 'data' => self::value( $data, $depth + 1, $nodes, $seen, $root, $native ) ]; if ( ! $native ) { $out = [ 'object' => $id, ...$out ]; }
		// Pending native setters are separately fenced: recursive save folding
		// must never conceal removal from an unsaved line tax/address map.
		$changes = self::property( $value, 'changes', false ); if ( null !== $changes ) { if ( ! is_array( $changes ) ) { throw new \RuntimeException( 'Native payment changes unavailable.' ); } if ( $value === $root ) { unset( $changes['payment_method'], $changes['payment_method_title'], $changes['date_modified'] ); } $out['changes'] = self::value( $changes, $depth + 1, $nodes, $seen, $root, $native ); }
		$native_id = self::property( $value, 'id', false ); if ( null !== $native_id ) { $out['id'] = $native_id; }
		$meta = self::property( $value, 'meta_data', false );
		if ( null !== $meta ) {
			if ( ! is_array( $meta ) ) { throw new \RuntimeException( 'Native payment metadata unavailable.' ); }
			$out['meta'] = [];
			foreach ( $meta as $index => $member ) { if ( $value === $root && null !== $logging_index && $index === $logging_index ) { continue; } if ( ! $member instanceof \WC_Meta_Data || 'WC_Meta_Data' !== get_class( $member ) ) { throw new \RuntimeException( 'Native payment metadata unavailable.' ); } $current = self::property( $member, 'current_data' ); $data = self::property( $member, 'data' ); if ( ! is_array( $current ) || ! is_array( $data ) ) { throw new \RuntimeException( 'Native payment metadata maps unavailable.' ); } if ( $native ) { $current = self::native_meta( $current ); $data = self::native_meta( $data ); } $out['meta'][$index] = [ 'class' => get_class( $member ), 'data' => self::value( self::policy_meta( $current ), $depth + 1, $nodes, $seen, $root, $native ), 'original' => self::value( self::policy_meta( $data ), $depth + 1, $nodes, $seen, $root, $native ) ]; if ( ! $native ) { $out['meta'][$index]['object'] = spl_object_id( $member ); } }
		}
		$items = self::property( $value, 'items', false );
		if ( null !== $items ) {
			if ( ! is_array( $items ) ) { throw new \RuntimeException( 'Native payment items unavailable.' ); }
			if ( $native && $value === $root ) {
				// Woo fills this associative map in getter order. Its five native
				// groups retain exact membership, item order, IDs and raw contents.
				$groups = [ 'line_items', 'shipping_lines', 'tax_lines', 'fee_lines', 'coupon_lines' ];
				if ( [] !== array_diff( array_keys( $items ), $groups ) ) { throw new \RuntimeException( 'Unsupported native payment item group.' ); }
				$ordered = []; foreach ( $groups as $group ) { if ( array_key_exists( $group, $items ) ) { $ordered[$group] = $items[$group]; } } $items = $ordered;
			}
			$out['items'] = self::value( $items, $depth + 1, $nodes, $seen, $root, $native );
		}
		return $out;
	}
	/** Native add/save appends id; native reload reads id first. No row coordinate is normalized. */
	private static function native_meta( array $row ): array {
		if ( count( $row ) !== 3 || array_diff( [ 'id', 'key', 'value' ], array_keys( $row ) ) ) { throw new \RuntimeException( 'Native payment metadata maps unavailable.' ); }
		return [ 'id' => $row['id'], 'key' => $row['key'], 'value' => $row['value'] ];
	}
	/** Only owned bounded policy bodies are compacted; every metadata coordinate remains exact. */
	private static function policy_meta( array $row ): array {
		$key = $row['key'] ?? null;
		if ( ! is_string( $key ) || ! str_starts_with( $key, '_cetech_de_policy_' ) ) { return $row; }
		$maximum = match ( $key ) {
			'_cetech_de_policy_packet' => 327680,
			'_cetech_de_policy_manifest' => 65536,
			'_cetech_de_policy_line_key' => 256,
			'_cetech_de_policy_format', '_cetech_de_policy_manifest_format' => 32,
			default => throw new \RuntimeException( 'Unknown native policy metadata.' ),
		};
		$value = $row['value'] ?? null;
		if ( ! is_string( $value ) || strlen( $value ) > $maximum ) { throw new \RuntimeException( 'Native policy metadata unavailable.' ); }
		$row['value'] = [ 'policy_value_type' => 'string', 'policy_value_bytes' => strlen( $value ), 'policy_value_sha256' => hash( 'sha256', $value ) ];
		return $row;
	}
	private static function effective( object $object ): array { $data = self::property( $object, 'data' ); $changes = self::property( $object, 'changes', false ); if ( ! is_array( $data ) || null !== $changes && ! is_array( $changes ) ) { throw new \RuntimeException( 'Native payment data unavailable.' ); } return array_replace_recursive( $data, $changes ?? [] ); }
	private static function property( object $object, string $name, bool $required = true ): mixed {
		$r = new \ReflectionObject( $object ); while ( ! $r->hasProperty( $name ) && false !== $r->getParentClass() ) { $r = $r->getParentClass(); }
		if ( ! $r->hasProperty( $name ) ) { if ( ! $required ) { return null; } throw new \RuntimeException( 'Native payment data unavailable.' ); }
		$p = $r->getProperty( $name ); if ( ! in_array( $p->getDeclaringClass()->getName(), self::OWNERS, true ) || $p->isStatic() || ! $p->isInitialized( $object ) ) { throw new \RuntimeException( 'Unsupported native payment data.' ); }
		return method_exists( $p, 'getRawValue' ) ? $p->getRawValue( $object ) : $p->getValue( $object );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Native payment continuation is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native payment continuation is private.' ); }
}
