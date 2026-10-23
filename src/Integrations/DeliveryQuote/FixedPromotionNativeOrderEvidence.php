<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteOrderEvidenceGuard, FixedPromotionQuoteProvider, NativeCartQuotePreparation, QuoteNativeContextIdentity, QuoteNativeShippingTaxProjection, QuoteNativeTaxSource, QuoteSavedOrderAuthorization, QuoteSavedOrderNativeEvidence};
use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderFacts, QuoteNativeOrderStageResult};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteMoney, QuoteStoredRow, QuoteStorageCodec};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};

/** Detached native candidate proof. Snapshot packets have their own saved-order guard. */
final readonly class FixedPromotionNativeOrderEvidence implements FixedPromotionQuoteOrderEvidenceGuard, \JsonSerializable {

	private function __construct( private array $facts, private string $digest ) {}

	/** Authorization and native prewarming precede the dedicated read-only SQL owner. */
	public static function capture( OperationConnectionFactory $factory, \WC_Order $order, QuoteStoredRow $accepted3, array $canonical_mapping, QuoteNativeTaxSource $original_tax_source, callable $authorize_order ): ?self {
		$session = null; $closed = false;
		try {
			$authorization = QuoteSavedOrderAuthorization::capture( $order, $authorize_order );
			$context = $accepted3->context(); $terms = $accepted3->terms(); $header = $accepted3->header();
			if ( ( $GLOBALS['blog_id'] ?? 1 ) !== $accepted3->site_id() || 'accepted' !== $accepted3->state() || 2 !== $accepted3->revision() || 3 !== $header->format_version() || FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() || null === $context || null === $terms || ! $original_tax_source->matches_context( $context ) ) { self::fail(); }
			$mapping = QuoteBinding::canonical_mapping( $canonical_mapping );
			if ( $mapping !== $canonical_mapping || QuoteNativeOrderFacts::mapping( $order, $accepted3 ) !== $mapping ) { self::fail(); }
			$id = $order->get_id(); $native = QuoteNativeOrderFacts::native( $order ); $status = self::native_status( $order );
			$selectors = $original_tax_source->private_facts()['source']['selectors'];
			if ( $id < 1 || $selectors['site_id'] !== $accepted3->site_id() || $selectors['customer_id'] !== $native['customer_id'] || $native['currency'] !== $context->private_facts()['currency']['charged'] ) { self::fail(); }
			$identity = self::destination_identity( $accepted3, $native );
			self::assert_shipping( $order, $accepted3, $native, $original_tax_source );
			if ( ! $authorization->unchanged() ) { self::fail(); }

			$session = $factory->open(); $metadata = self::metadata( $session );
			if ( $session->is_retired() || $session->in_transaction() || $metadata['site'] !== $accepted3->site_id() || $metadata['prefix'] !== $selectors['table_prefix'] || ! $session->begin() || ! $session->in_transaction() || ! $session->validate_tables( [ $metadata['prefix'] . 'options' ] ) ) { self::fail(); }
			$mode = self::mode( $session, $metadata['prefix'], false ); $hpos = $mode['hpos'];
			if ( ! $session->validate_tables( self::participants( $metadata['prefix'], $hpos ) ) || self::metadata( $session ) !== $metadata ) { self::fail(); }
			$physical = self::native_projection( QuoteNativeOrderStageResult::read_physical( $session, $id, $hpos ) );
			QuoteNativeOrderFacts::assert_physical( $physical, $native, $hpos );
			self::assert_shipping_rows( $physical, $native );
			$lifecycle = self::lifecycle( $session, $metadata['prefix'], $id, $hpos, false ); self::assert_lifecycle( $physical, $lifecycle, $id, $hpos, $status );
			if ( self::metadata( $session ) !== $metadata || $session->is_retired() || ! $session->in_transaction() || ! $authorization->unchanged() ) { self::fail(); }
			$facts = [ 'format_version' => 1, 'quote' => $accepted3->row(), 'owner' => $header->owner()->facts(), 'mapping' => $mapping, 'order_id' => $id, 'metadata' => $metadata, 'mode' => $mode, 'native' => $native, 'physical' => $physical, 'lifecycle' => $lifecycle, 'native_identity' => $identity, 'tax_source' => $original_tax_source->private_facts() ];
			// Successful capture always acknowledges both read-only termination steps.
			if ( ! $session->rollback() || $session->in_transaction() || ! $session->retire() || ! $session->is_retired() ) { self::fail(); }
			$closed = true;
			if ( self::metadata( $session ) !== $metadata || $session->in_transaction() || ! $session->is_retired() || true !== $authorize_order( $order ) || ! $authorization->unchanged() || ( $GLOBALS['blog_id'] ?? 1 ) !== $metadata['site'] || QuoteNativeOrderFacts::mapping( $order, $accepted3 ) !== $mapping || QuoteNativeOrderFacts::native( $order ) !== $native || self::native_status( $order ) !== $status || self::destination_identity( $accepted3, $native ) !== $identity || ! $authorization->unchanged() || self::metadata( $session ) !== $metadata || $session->in_transaction() || ! $session->is_retired() ) { self::fail(); }
			QuoteNativeOrderFacts::encode( $facts );
			return new self( $facts, QuoteNativeOrderFacts::digest( 'promotion-native-candidate', $facts ) );
		} catch ( \Throwable ) { return null; }
		finally {
			// Cleanup does not depend on potentially failing metadata accessors.
			if ( null !== $session && ! $closed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} }
		}
	}

	public function evidence_digest(): string { return $this->digest; }
	public function tables( OperationSession $session ): array {
		if ( self::metadata( $session ) !== $this->facts['metadata'] ) { self::fail(); }
		return self::participants( $this->facts['metadata']['prefix'], $this->facts['mode']['hpos'] );
	}
	/** Only current SQL and detached primitives execute while the caller owns locks. */
	public function verify( OperationSession $session, QuoteStoredRow $accepted, QuoteBinding $candidate ): bool {
		try {
			$metadata = $this->facts['metadata']; $row = $candidate->row();
			if ( $session->is_retired() || ! $session->in_transaction() || self::metadata( $session ) !== $metadata || $accepted->row() !== $this->facts['quote'] || $row['site_id'] !== $metadata['site'] || $row['order_id'] !== $this->facts['order_id'] || $row['quote_uuid'] !== $accepted->header()->id()->value() || $candidate->mapping() !== $this->facts['mapping'] || 'prepared' !== $candidate->state() || 1 !== $candidate->revision() || null !== $row['snapshot_digest'] || null !== $row['context_digest'] || null !== $row['verified_at'] || null !== $row['sealed_at'] || ! $session->validate_tables( $this->tables( $session ) ) ) { return false; }
			if ( self::mode( $session, $metadata['prefix'], true ) !== $this->facts['mode'] || self::metadata( $session ) !== $metadata ) { return false; }
			$hpos = $this->facts['mode']['hpos']; $id = $this->facts['order_id'];
			$physical = self::native_projection( QuoteNativeOrderStageResult::read_physical( $session, $id, $hpos, true ) );
			$lifecycle = self::lifecycle( $session, $metadata['prefix'], $id, $hpos, true );
			self::assert_lifecycle( $physical, $lifecycle, $id, $hpos );
			return $physical === $this->facts['physical'] && $lifecycle === $this->facts['lifecycle'] && self::metadata( $session ) === $metadata && ! $session->is_retired() && $session->in_transaction();
		} catch ( \Throwable ) { return false; }
	}

	private static function metadata( OperationSession $session ): array {
		$site = $session->site_id(); $prefix = $session->table_prefix(); $charset = $session->charset_collate();
		if ( $site < 1 || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,30}\z/D', $prefix ) || strlen( $charset ) > 256 || str_contains( $charset, "\0" ) ) { self::fail(); }
		return [ 'site' => $site, 'prefix' => $prefix, 'charset' => $charset ];
	}
	private static function participants( string $prefix, bool $hpos ): array {
		return array_merge( [ $prefix . 'options', $prefix . 'woocommerce_order_items', $prefix . 'woocommerce_order_itemmeta' ], $hpos ? [ $prefix . 'wc_orders', $prefix . 'wc_orders_meta', $prefix . 'wc_order_addresses', $prefix . 'wc_order_operational_data' ] : [ $prefix . 'posts', $prefix . 'postmeta' ] );
	}
	private static function mode( OperationSession $session, string $prefix, bool $current ): array {
		$rows = self::rows( $session, "SELECT option_id,option_name,option_value FROM `{$prefix}options` WHERE option_name='woocommerce_custom_orders_table_enabled' ORDER BY option_id LIMIT 2", 2, $current );
		if ( [] !== $rows && ( 'woocommerce_custom_orders_table_enabled' !== ( $rows[0]['option_name'] ?? null ) || ! in_array( $rows[0]['option_value'] ?? null, [ 'yes', 'no' ], true ) ) ) { self::fail(); }
		return [ 'hpos' => [] !== $rows && 'yes' === $rows[0]['option_value'], 'rows' => $rows ];
	}
	private static function native_projection( array $physical ): array {
		foreach ( [ 'item_meta', 'order_meta' ] as $part ) { $physical[$part] = array_values( array_filter( $physical[$part], static fn( array $row ): bool => ! str_starts_with( $row['meta_key'], '_cetech_de_' ) ) ); }
		return $physical;
	}
	private static function assert_shipping_rows( array $physical, array $native ): void {
		foreach ( $native['shipping'] as $shipping ) {
			$items = array_values( array_filter( $physical['items'], static fn( array $row ): bool => (string) $shipping['id'] === $row['order_item_id'] ) );
			$groups = array_values( array_filter( $physical['item_meta'], static fn( array $row ): bool => (string) $shipping['id'] === $row['order_item_id'] && 'cetech_de_group_id' === $row['meta_key'] ) );
			if ( 1 !== count( $items ) || $items[0]['order_item_name'] !== $shipping['label'] || 1 !== count( $groups ) || $groups[0]['meta_value'] !== $shipping['group_id'] ) { self::fail(); }
		}
	}
	private static function lifecycle( OperationSession $session, string $prefix, int $id, bool $hpos, bool $current ): array {
		if ( $hpos ) {
			$order = self::rows( $session, $session->prepare( "SELECT id,type,parent_order_id FROM `{$prefix}wc_orders` WHERE id=%d LIMIT 2", $id ), 2, $current );
			$paid = self::rows( $session, $session->prepare( "SELECT order_id,date_paid_gmt FROM `{$prefix}wc_order_operational_data` WHERE order_id=%d LIMIT 2", $id ), 2, $current );
		} else {
			$order = self::rows( $session, $session->prepare( "SELECT ID,post_type,post_parent FROM `{$prefix}posts` WHERE ID=%d LIMIT 2", $id ), 2, $current );
			$paid = self::rows( $session, $session->prepare( "SELECT meta_key,meta_value FROM `{$prefix}postmeta` WHERE post_id=%d AND meta_key IN ('_date_paid','_paid_date') ORDER BY meta_key,meta_value LIMIT 5", $id ), 5, $current );
		}
		return [ 'order' => $order, 'paid' => $paid ];
	}
	private static function rows( OperationSession $session, string $sql, int $limit, bool $current ): array {
		$rows = $session->get_results( $sql . ( $current ? ' FOR UPDATE' : '' ) );
		if ( false === $rows || count( $rows ) >= $limit || ! array_is_list( $rows ) ) { self::fail(); }
		foreach ( $rows as &$row ) { if ( ! is_array( $row ) ) { self::fail(); } foreach ( $row as &$value ) { if ( null !== $value && ! is_scalar( $value ) ) { self::fail(); } $value = null === $value ? null : (string) $value; } unset( $value ); } unset( $row );
		return $rows;
	}
	private static function assert_lifecycle( array $physical, array $lifecycle, int $id, bool $hpos, ?string $native_status = null ): void {
		if ( 1 !== count( $lifecycle['order'] ) ) { self::fail(); } $row = $lifecycle['order'][0]; $saved = $physical['order'][0];
		$parent_key = $hpos ? 'parent_order_id' : 'post_parent';
		if ( ! array_key_exists( $parent_key, $row ) || QuoteStorageCodec::integer( $row[$hpos ? 'id' : 'ID'] ?? null ) !== $id || QuoteStorageCodec::integer( $saved[$hpos ? 'id' : 'ID'] ?? null ) !== $id || 'shop_order' !== ( $row[$hpos ? 'type' : 'post_type'] ?? null ) || 0 !== QuoteStorageCodec::integer( $hpos && null === $row[$parent_key] ? 0 : $row[$parent_key], 0 ) ) { self::fail(); }
		$status = $saved[$hpos ? 'status' : 'post_status'] ?? null;
		if ( ! in_array( $status, [ 'wc-pending', 'wc-failed', 'wc-checkout-draft' ], true ) || null !== $native_status && 'wc-' . $native_status !== $status ) { self::fail(); }
		if ( $hpos ) {
			if ( 1 !== count( $lifecycle['paid'] ) || QuoteStorageCodec::integer( $lifecycle['paid'][0]['order_id'] ?? null ) !== $id || ! array_key_exists( 'date_paid_gmt', $lifecycle['paid'][0] ) || ! self::empty_paid( $lifecycle['paid'][0]['date_paid_gmt'] ) ) { self::fail(); }
		} else {
			$seen = []; foreach ( $lifecycle['paid'] as $paid ) { $key = $paid['meta_key'] ?? null; if ( ! in_array( $key, [ '_date_paid', '_paid_date' ], true ) || isset( $seen[$key] ) || ! array_key_exists( 'meta_value', $paid ) || ! self::empty_paid( $paid['meta_value'] ) ) { self::fail(); } $seen[$key] = true; }
		}
		foreach ( $physical['items'] as $item ) { if ( QuoteStorageCodec::integer( $item['order_id'] ) !== $id ) { self::fail(); } }
	}
	private static function empty_paid( mixed $value ): bool { return null === $value || '' === $value || '0' === $value || '0000-00-00 00:00:00' === $value; }
	private static function native_status( \WC_Order $order ): string {
		$status = $order->get_status(); if ( ! in_array( $status, [ 'pending', 'failed', 'checkout-draft' ], true ) || $order->is_paid() || null !== $order->get_date_paid( 'edit' ) ) { self::fail(); } return $status;
	}
	private static function destination_identity( QuoteStoredRow $quote, array $native ): array {
		$identity = QuoteNativeContextIdentity::from_server(); $destination = $quote->context()->private_facts()['destination'];
		$epoch = $identity->key_epoch(); $digest = $identity->destination_digest( $quote->site_id(), $native['address'] );
		if ( 'full' !== $destination['kind'] || $epoch !== $quote->header()->owner()->key_epoch() || $epoch !== $destination['key_epoch'] || ! hash_equals( $destination['digest'], $digest ) ) { self::fail(); }
		return [ 'key_epoch' => $epoch, 'destination_digest' => $digest ];
	}

	/** The compiled final is already discounted. This proof never recalculates it. */
	private static function assert_shipping( \WC_Order $order, QuoteStoredRow $quote, array $native, QuoteNativeTaxSource $source ): void {
		$currency = $native['currency']; $terms = []; foreach ( $quote->terms()->private_facts()['groups'] as $term ) { $terms[$term['component_key']] = $term; }
		$seen = []; $shipping = self::money( '0', $currency ); $tax = self::money( '0', $currency ); $projection = null; $zero_maps = [];
		foreach ( $native['shipping'] as $item ) {
			if ( 'delivery_engine_selected_offer' !== $item['method_id'] || ! is_string( $item['group_id'] ) ) { self::fail(); }
			$component = NativeCartQuotePreparation::component_key( $item['group_id'] ); $term = $terms[$component] ?? null;
			if ( null === $term || isset( $seen[$component] ) || $item['label'] !== $term['customer_label'] || ! self::money( $item['total'], $currency )->equals( self::money( $term['final']['amount'], $currency ) ) ) { self::fail(); }
			$expected = []; foreach ( $term['native_tax_receipt']['rates'] as $rate ) { $expected[(string) $rate['rate_id']] = self::money( $rate['amount']['amount'], $currency )->amount(); }
			$actual = self::tax_map( $item['taxes']['total'] ?? null, $currency ); ksort( $expected, SORT_STRING );
			if ( $expected !== $actual ) {
				if ( ! QuoteNativeShippingTaxProjection::eligible( $term ) || ! QuoteNativeShippingTaxProjection::is_native_zero_candidate( $item['taxes']['total'] ) ) { self::fail(); }
				$projection ??= QuoteSavedOrderNativeEvidence::prewarm_tax( $order, $quote, $source );
				if ( ! $projection->accepts_zero_map( $term, $item['taxes']['total'] ) ) { self::fail(); } $zero_maps[$component] = $projection->zero_map( $term );
			}
			$expected_tax = 'subtotal' === $term['native_tax_receipt']['rounding'] ? $term['tax']['amount'] : $term['native_tax_receipt']['rounded_tax']['amount'];
			if ( ! self::money( $item['tax'], $currency )->equals( self::money( $expected_tax, $currency ) ) ) { self::fail(); }
			$shipping = $shipping->add( self::money( $item['total'], $currency ) ); $tax = $tax->add( self::money( $item['tax'], $currency ) ); $seen[$component] = true;
		}
		if ( count( $seen ) !== count( $terms ) || ! $shipping->equals( self::money( $native['shipping_total'], $currency ) ) || ! $tax->equals( self::money( $native['shipping_tax'], $currency ) ) ) { self::fail(); }
		self::assert_tax_totals( $native, $terms, $currency, $zero_maps );
	}
	private static function assert_tax_totals( array $native, array $terms, string $currency, array $zero_maps ): void {
		$shipping = []; $products = []; $rounding = null; $precision = null;
		foreach ( $terms as $term ) {
			$receipt = $term['native_tax_receipt']; if ( null !== $rounding && ( $rounding !== $receipt['rounding'] || $precision !== $receipt['display_precision'] ) ) { self::fail(); } $rounding = $receipt['rounding']; $precision = $receipt['display_precision'];
			foreach ( $receipt['rates'] as $rate ) { $amount = 'per_line' === $rounding ? self::rounded( $rate['amount']['amount'], $precision ) : $rate['amount']['amount']; $shipping[$rate['rate_id']] = ( $shipping[$rate['rate_id']] ?? self::money( '0', $currency ) )->add( self::money( $amount, $currency ) ); }
			foreach ( $zero_maps[$term['component_key']] ?? [] as $rate => $_zero ) { $shipping[$rate] ??= self::money( '0', $currency ); }
		}
		foreach ( $native['lines'] as $line ) { foreach ( self::tax_map( $line['taxes']['total'] ?? null, $currency ) as $rate => $amount ) { if ( 'per_line' === $rounding ) { $amount = self::rounded( $amount, $precision ); } $products[$rate] = ( $products[$rate] ?? self::money( '0', $currency ) )->add( self::money( $amount, $currency ) ); } }
		$rates = array_unique( [ ...array_keys( $shipping ), ...array_keys( $products ) ] ); $seen = []; $aggregate = self::money( '0', $currency );
		foreach ( $native['tax'] as $item ) { $rate = $item['rate_id']; if ( isset( $seen[$rate] ) || ! in_array( $rate, $rates, true ) || ! self::money( $item['shipping_tax'], $currency )->equals( $shipping[$rate] ?? self::money( '0', $currency ) ) || ! self::money( $item['tax'], $currency )->equals( $products[$rate] ?? self::money( '0', $currency ) ) ) { self::fail(); } $seen[$rate] = true; $aggregate = $aggregate->add( self::money( $item['shipping_tax'], $currency ) )->add( self::money( $item['tax'], $currency ) ); }
		if ( count( $seen ) !== count( $rates ) || ! self::money( self::rounded( $aggregate->amount(), $precision ), $currency )->equals( self::money( $native['total_tax'], $currency ) ) ) { self::fail(); }
	}
	private static function tax_map( mixed $map, string $currency ): array {
		if ( ! is_array( $map ) || count( $map ) > 200 ) { self::fail(); } $out = [];
		foreach ( $map as $id => $amount ) { $id = QuoteStorageCodec::integer( $id ); $out[$id] = self::money( $amount, $currency )->amount(); } ksort( $out, SORT_STRING ); return $out;
	}
	private static function money( string $amount, string $currency ): QuoteMoney { if ( str_contains( $amount, '.' ) ) { $amount = rtrim( rtrim( $amount, '0' ), '.' ); } return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => 6 ] ); }
	private static function rounded( string $amount, int $precision ): string {
		[ $whole, $fraction ] = explode( '.', self::money( $amount, 'XXX' )->amount() ); $factor = 10 ** ( 6 - $precision ); $units = (int) ( $whole . $fraction ); $digits = str_pad( (string) intdiv( $units + intdiv( $factor, 2 ), $factor ), $precision + 1, '0', STR_PAD_LEFT );
		return 0 === $precision ? $digits : substr( $digits, 0, -$precision ) . '.' . substr( $digits, -$precision );
	}
	private static function fail(): never { throw new \UnexpectedValueException( 'Native promotion order evidence unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native promotion order evidence is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native promotion order evidence is private.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Native promotion order evidence requires native capture.' ); }
	public function __debugInfo(): array { return [ 'private_native_promotion_order' => 'redacted' ]; }
}
