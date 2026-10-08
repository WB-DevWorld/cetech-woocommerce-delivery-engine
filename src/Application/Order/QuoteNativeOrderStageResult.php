<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementSavedEvidenceGuard;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Detached saved physical facts. SQL verification performs no native getter or filter. */
final readonly class QuoteNativeOrderStageResult implements QuotePlacementSavedEvidenceGuard, \JsonSerializable {
	public function __construct( private int $site, private int $id, private bool $hpos, private array $coordinates, private string $snapshot, private string $context, private array $physical, private \WC_Order $fresh ) {}
	public function order_id(): int { return $this->id; }
	public function mapping(): array { return $this->coordinates; }
	public function manifest_digest(): string { return QuoteBinding::manifest_digest( $this->coordinates ); }
	public function snapshot_digest(): string { return $this->snapshot; }
	public function context_digest(): string { return $this->context; }
	public function fresh_order(): \WC_Order { return $this->fresh; }
	public function guard(): self { return $this; }
	public function tables( OperationSession $session ): array {
		$p = $session->table_prefix();
		return array_merge( [ $p . 'woocommerce_order_items', $p . 'woocommerce_order_itemmeta' ], $this->hpos ? [ $p . 'wc_orders', $p . 'wc_orders_meta', $p . 'wc_order_addresses', $p . 'wc_order_operational_data' ] : [ $p . 'posts', $p . 'postmeta' ] );
	}
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		try {
			$row = $binding->row();
			if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $this->site || $binding->site_id() !== $this->site || $row['order_id'] !== $this->id
				|| $binding->mapping() !== $this->coordinates || $row['snapshot_digest'] !== $this->snapshot || $row['context_digest'] !== $this->context ) { return false; }
			return $this->physical === self::read_physical( $session, $this->id, $this->hpos, true );
		} catch ( \Throwable ) { return false; }
	}
	/** Outside capture and lock-time comparison share exactly these finite local SQL reads. */
	public static function read_physical( OperationSession $session, int $id, bool $hpos, bool $current = false ): array {
		if ( $id < 1 ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
		$p = $session->table_prefix();
		$queries = [
			'items' => $session->prepare( "SELECT order_item_id,order_item_type,order_item_name,order_id FROM `{$p}woocommerce_order_items` WHERE order_id=%d ORDER BY order_item_id LIMIT 601", $id ),
			'item_meta' => $session->prepare( "SELECT m.order_item_id,m.meta_key,m.meta_value FROM `{$p}woocommerce_order_itemmeta` m INNER JOIN `{$p}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d AND (LEFT(m.meta_key,11)='_cetech_de_' OR m.meta_key='cetech_de_group_id' OR m.meta_key IN ('_product_id','_variation_id','_qty','_line_total','_line_tax','_line_subtotal','_line_subtotal_tax','_line_tax_data','method_id','instance_id','cost','total_tax','taxes','rate_id','label','compound','tax_amount','shipping_tax_amount','rate_percent')) ORDER BY m.order_item_id,m.meta_key,m.meta_value LIMIT 12001", $id ),
		];
		if ( $hpos ) {
			$queries['order'] = $session->prepare( "SELECT id,status,currency,total_amount,tax_amount,customer_id FROM `{$p}wc_orders` WHERE id=%d LIMIT 2", $id );
			$queries['money'] = $session->prepare( "SELECT order_id,order_key,shipping_total_amount,shipping_tax_amount FROM `{$p}wc_order_operational_data` WHERE order_id=%d LIMIT 2", $id );
			$queries['address'] = $session->prepare( "SELECT order_id,address_type,country,state,city,postcode,address_1,address_2 FROM `{$p}wc_order_addresses` WHERE order_id=%d AND address_type IN ('shipping','billing') ORDER BY address_type LIMIT 3", $id );
			$queries['order_meta'] = $session->prepare( "SELECT meta_key,meta_value FROM `{$p}wc_orders_meta` WHERE order_id=%d AND (LEFT(meta_key,11)='_cetech_de_' OR meta_key='is_vat_exempt') ORDER BY meta_key,meta_value LIMIT 513", $id );
		} else {
			$queries['order'] = $session->prepare( "SELECT ID,post_type,post_status FROM `{$p}posts` WHERE ID=%d LIMIT 2", $id );
			$queries['order_meta'] = $session->prepare( "SELECT meta_key,meta_value FROM `{$p}postmeta` WHERE post_id=%d AND (LEFT(meta_key,11)='_cetech_de_' OR meta_key='is_vat_exempt' OR meta_key IN ('_order_key','_order_currency','_order_total','_order_tax','_order_shipping','_order_shipping_tax','_customer_user','_shipping_country','_shipping_state','_shipping_city','_shipping_postcode','_shipping_address_1','_shipping_address_2','_billing_country','_billing_state','_billing_city','_billing_postcode','_billing_address_1','_billing_address_2')) ORDER BY meta_key,meta_value LIMIT 513", $id );
		}
		$facts = [];
		foreach ( $queries as $key => $sql ) {
			$rows = $session->get_results( $sql . ( $current ? ' FOR UPDATE' : '' ) );
			if ( false === $rows || count( $rows ) >= ( 'items' === $key ? 601 : ( 'item_meta' === $key ? 12001 : ( 'address' === $key ? 3 : ( 'order_meta' === $key ? 513 : 2 ) ) ) ) ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
			if ( 'order' === $key && 1 !== count( $rows ) || 'money' === $key && 1 !== count( $rows ) || 'items' === $key && [] === $rows ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
			$facts[$key] = array_map( static function( array $row ): array { $out = []; foreach ( $row as $name => $value ) { if ( ! is_string( $name ) || null !== $value && ! is_scalar( $value ) ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); } $out[$name] = null === $value ? null : (string) $value; } return $out; }, $rows );
		}
		QuoteNativeOrderFacts::encode( $facts ); return $facts;
	}
	/** Physical meta multiplicity and bytes must equal the planned immutable packets. */
	public static function assert_snapshot_rows( array $physical, array $order_meta, array $line_meta ): void {
		$orders = []; foreach ( $physical['order_meta'] as $row ) { $orders[$row['meta_key']][] = $row['meta_value']; }
		foreach ( $order_meta as $key => $value ) { if ( ( $orders[$key] ?? [] ) !== [ (string) $value ] ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); } }
		$lines = []; foreach ( $physical['item_meta'] as $row ) { $lines[(int) $row['order_item_id']][$row['meta_key']][] = $row['meta_value']; }
		foreach ( $line_meta as $id => $meta ) { foreach ( $meta as $key => $value ) { if ( ( $lines[$id][$key] ?? [] ) !== [ (string) $value ] ) { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); } } }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Saved placement evidence is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Saved placement evidence is private.' ); }
}
