<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeTaxSource;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteId,QuoteJson,QuoteMoney,QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;

/** Single quote writer. Captured facts are staged and physically verified before C07 freeze. */
final class QuoteNativeOrderStager {
	private ?\Closure $loader;
	public function __construct( private OperationConnectionFactory $factory, ?callable $fresh_loader = null ) { $this->loader = null === $fresh_loader ? null : \Closure::fromCallable( $fresh_loader ); }
	public function mapping( \WC_Order $order, QuotePlacementEvidence $evidence ): array { return $this->map_context( $order, $evidence->quote_record() ); }
	private function map_context( \WC_Order $order, QuoteStoredRow $quote ): array {
		$context = $quote->context()?->private_facts(); $items = $order->get_items( 'line_item' );
		if ( null === $context || count( $items ) > 200 || count( $items ) !== count( $context['lines'] ) || $order->get_id() < 1 ) { self::fail(); }
		$mapped = []; $claimed = [];
		foreach ( $context['lines'] as $line ) {
			$pool = []; $keyed = [];
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || $item->get_id() < 1 || $item->get_order_id( 'edit' ) !== $order->get_id() ) { self::fail(); }
				if ( isset( $claimed[$item->get_id()] ) ) { continue; }
				$key = $item->get_meta( QuoteNativeOrderFacts::META_LINE_KEY, true );
				if ( '' === $key ) { $key = $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ); }
				if ( $item->get_product_id() === $line['product_id'] && ( $item->get_variation_id() ?: null ) === $line['variation_id'] && self::quantity( $item->get_quantity() ) === $line['quantity'] ) {
					$pool[] = $item; if ( $key === $line['line_key'] ) { $keyed[] = $item; }
				} elseif ( $key === $line['line_key'] ) { self::fail(); }
			}
			$pool = [] !== $keyed ? $keyed : $pool;
			if ( 1 !== count( $pool ) ) { self::fail(); }
			$item = $pool[0]; $claimed[$item->get_id()] = true;
			$mapped[$line['component_key']][] = [ 'line_key' => $line['line_key'], 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => $line['parent_id'], 'quantity' => $line['quantity'], 'item_id' => $item->get_id() ];
		}
		$groups = []; foreach ( $mapped as $component => $lines ) { $groups[] = [ 'component_key' => $component, 'lines' => $lines ]; }
		return QuoteBinding::canonical_mapping( [ 'format_version' => 1, 'groups' => $groups ] );
	}
	public function stage( \WC_Order $order, QuotePlacementEvidence $evidence, QuoteBinding $binding, string $route ): QuoteNativeOrderStageResult {
		if ( ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.bind' ) || null === $evidence->draft_facts() || null === $evidence->tax_source_json() ) { self::fail(); }
		$quote = $evidence->quote_record(); $mapping = $this->map_context( $order, $quote );
		if ( $binding->row()['order_id'] !== $order->get_id() || $binding->mapping() !== $mapping || $binding->row()['quote_uuid'] !== $quote->header()->id()->value() || $binding->site_id() !== $quote->site_id() ) { self::fail(); }
		$draft = QuoteJson::encode( $evidence->draft_facts() ); $reference = QuoteJson::encode( $evidence->reference()->public_fields() );
		$plan = $this->plan( $order, $quote, $binding, $draft, $reference, $evidence->tax_source_json() );
		if ( QuoteNativeOrderHistory::owned( $order ) ) {
			if ( 'prepared' === $binding->state() && 1 === $binding->revision() ) { $this->complete_missing_packets( $order, $plan ); }
			else { $this->assert_native_packets( $order, $plan ); }
		} else {
			if ( 'prepared' !== $binding->state() || 1 !== $binding->revision() ) { self::fail(); }
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				foreach ( $plan['line_meta'][$item->get_id()] as $key => $value ) { $item->update_meta_data( $key, $value ); }
				$item->save();
			}
			foreach ( $plan['order_meta'] as $key => $value ) { $order->update_meta_data( $key, $value ); }
			$order->save();
		}
		return $this->capture_saved( $order, $quote, $binding, $plan );
	}
	/** Exact original prepared1 may finish absent writes, never replace any existing protected fact. */
	private function complete_missing_packets( \WC_Order $order, array $plan ): void {
		$pending = []; $objects = [ [ $order, $plan['order_meta'] ] ];
		foreach ( $order->get_items( 'line_item' ) as $item ) { $objects[] = [ $item, $plan['line_meta'][$item->get_id()] ]; }
		foreach ( $objects as [ $object, $meta ] ) {
			$missing = [];
			foreach ( $meta as $key => $value ) {
				$present = QuoteNativeOrderHistory::meta_present( $object, $key );
				if ( null === $present || true === $present && $object->get_meta( $key, true ) !== $value ) { self::fail(); }
				if ( ! $present ) { $missing[$key] = $value; }
			}
			if ( [] !== $missing ) { $pending[] = [ $object, $missing ]; }
		}
		foreach ( $pending as [ $object, $meta ] ) { foreach ( $meta as $key => $value ) { $object->add_meta_data( $key, $value, true ); } $object->save(); }
		$this->assert_native_packets( $order, $plan );
	}
	public function load_draft( \WC_Order $order, QuoteOwner $owner ): QuoteCartDraft {
		$raw = $order->get_meta( QuoteNativeOrderFacts::META_DRAFT, true ); if ( ! is_string( $raw ) ) { self::fail(); }
		$facts = QuoteJson::decode( $raw ); if ( QuoteJson::encode( $facts ) !== $raw ) { self::fail(); }
		return QuoteCartDraft::from_private_facts( $owner, $facts );
	}
	public function load_tax_source( \WC_Order $order ): QuoteNativeTaxSource {
		$raw = $order->get_meta( QuoteNativeOrderFacts::META_TAX_SOURCE, true ); if ( ! is_string( $raw ) ) { self::fail(); }
		return QuoteNativeTaxSource::from_private_json( $raw );
	}
	/** Saved retries and payment callbacks resolve captured history; this method never writes. */
	public function saved_guard( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding ): QuoteNativeOrderStageResult {
		if ( ! QuoteNativeOrderHistory::owned( $order ) ) { self::fail(); }
		$draft = $order->get_meta( QuoteNativeOrderFacts::META_DRAFT, true ); $reference = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ); $tax_source = $this->load_tax_source( $order );
		if ( ! is_string( $draft ) || ! is_string( $reference ) || $binding->mapping() !== $this->map_context( $order, $quote ) ) { self::fail(); }
		$plan = $this->plan( $order, $quote, $binding, $draft, $reference, $tax_source->to_private_json() ); $this->assert_native_packets( $order, $plan );
		return $this->capture_saved( $order, $quote, $binding, $plan );
	}
	private function capture_saved( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, array $plan ): QuoteNativeOrderStageResult {
		$fresh = null !== $this->loader ? ( $this->loader )( $order->get_id() ) : new \WC_Order( $order->get_id() );
		if ( ! $fresh instanceof \WC_Order || $fresh === $order || $fresh->get_id() !== $order->get_id() ) { self::fail(); }
		if ( method_exists( $fresh, 'read_meta_data' ) ) { $fresh->read_meta_data( true ); }
		$this->assert_native_packets( $fresh, $plan );
		if ( $this->map_context( $fresh, $quote ) !== $binding->mapping() || $this->native_facts( $fresh ) !== $plan['native'] || ! QuoteNativeOrderHistory::verify( $fresh ) ) { self::fail(); }
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$session = $this->factory->open(); $begun = false; $retired = false;
		try {
			if ( ! $session->begin() ) { self::fail(); } $begun = true;
			$probe = new QuoteNativeOrderStageResult( $quote->site_id(), $order->get_id(), $hpos, $binding->mapping(), $plan['snapshot_digest'], $plan['context_digest'], [], $fresh );
			if ( $session->site_id() !== $quote->site_id() || ! $session->validate_tables( $probe->tables( $session ) ) ) { self::fail(); }
			$physical = QuoteNativeOrderStageResult::read_physical( $session, $order->get_id(), $hpos );
			QuoteNativeOrderStageResult::assert_snapshot_rows( $physical, $plan['order_meta'], $plan['line_meta'] );
			$this->assert_physical_native( $physical, $plan['native'], $hpos );
			$result = new QuoteNativeOrderStageResult( $quote->site_id(), $order->get_id(), $hpos, $binding->mapping(), $plan['snapshot_digest'], $plan['context_digest'], $physical, $fresh );
			if ( ! $session->rollback() ) { self::fail(); } $begun = false;
			if ( ! $session->retire() ) { self::fail(); } $retired = true;
			return $result;
		} finally { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $retired && ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private function plan( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, string $draft_json, string $reference, string $tax_source_json ): array {
		$context = $quote->context(); $terms = $quote->terms(); $accepted = $quote->accepted_at();
		if ( null === $context || null === $terms || null === $accepted || 'accepted' !== $quote->state() ) { self::fail(); }
		$tax_source = QuoteNativeTaxSource::from_private_json( $tax_source_json ); if ( ! $tax_source->matches_context( $context ) ) { self::fail(); }
		$captured_at = substr( $accepted->iso_utc(), 0, 19 ) . '+00:00';
		$draft = QuoteJson::decode( $draft_json );
		try {
			\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::fields( $draft, [ 'format_version', 'owner_digest', 'currency', 'lines', 'customer_destination' ] );
			if ( 1 !== $draft['format_version'] || QuoteJson::encode( $draft ) !== $draft_json || $draft['owner_digest'] !== $quote->header()->owner()->digest() || ! is_array( $draft['lines'] ) || ! array_is_list( $draft['lines'] ) || count( $draft['lines'] ) !== count( $context->private_facts()['lines'] ) ) { self::fail(); }
			foreach ( $draft['lines'] as $line ) { \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::fields( $line, [ 'line_key', 'product_id', 'variation_id', 'quantity', 'selection', 'selection_hash', 'customer_context' ] ); }
		} catch ( \Throwable ) { self::fail(); }
		$refs = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference::from_array( QuoteJson::decode( $reference ) );
		if ( ! $refs->id()->equals( $quote->header()->id() ) || ! $quote->header()->matches_reference( $refs ) ) { self::fail(); }
		$draft_lines = []; foreach ( $draft['lines'] as $line ) { $draft_lines[$line['line_key']] = $line; }
		$group_facts = []; foreach ( $context->private_facts()['groups'] as $group ) { $group_facts[$group['component_key']] = $group; }
		$term_facts = []; foreach ( $terms->private_facts()['groups'] as $term ) { $term_facts[$term['component_key']] = $term; }
		$native = $this->native_facts( $order ); $currency = $context->private_facts()['currency']['charged'];
		if ( $native['currency'] !== $currency ) { self::fail(); }
		$this->assert_tax_lines( $native, $term_facts, $currency );
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address_2' ] as $field ) { if ( $native['address'][$field] !== ( $draft['customer_destination'][$field] ?? null ) ) { self::fail(); } }
		if ( $native['address']['address_1'] !== ( $draft['customer_destination']['address'] ?? null ) ) { self::fail(); }
		$shipping = []; $total = self::money( '0', $currency ); $tax_total = self::money( '0', $currency ); $package_groups = [];
		foreach ( $native['shipping'] as $item ) {
			if ( 'delivery_engine_selected_offer' !== $item['method_id'] || ! is_string( $item['group_id'] ) ) { self::fail(); }
			$component = NativeCartQuotePreparation::component_key( $item['group_id'] ); $term = $term_facts[$component] ?? null;
			if ( null === $term || isset( $shipping[$component] ) || $item['label'] !== $term['customer_label'] || ! self::money( $item['total'], $currency )->equals( self::money( $term['final']['amount'], $currency ) ) ) { self::fail(); }
			$rates = []; foreach ( $term['native_tax_receipt']['rates'] as $rate ) { $rates[(string) $rate['rate_id']] = self::money( $rate['amount']['amount'], $currency )->amount(); }
			$actual = []; foreach ( $item['taxes']['total'] ?? [] as $id => $amount ) { $actual[(string) $id] = self::money( $amount, $currency )->amount(); } ksort( $rates, SORT_STRING ); ksort( $actual, SORT_STRING );
			$tax = 'subtotal' === $term['native_tax_receipt']['rounding'] ? $term['tax']['amount'] : $term['native_tax_receipt']['rounded_tax']['amount'];
			if ( $actual !== $rates || ! self::money( $item['tax'], $currency )->equals( self::money( $tax, $currency ) ) ) { self::fail(); }
			$total = $total->add( self::money( $item['total'], $currency ) ); $tax_total = $tax_total->add( self::money( $item['tax'], $currency ) ); $shipping[$component] = $item;
			$package_groups[] = [ 'group_id' => $item['group_id'], 'shipping_method_id' => $item['method_id'], 'shipping_method_label' => $term['customer_label'], 'package_total_delivery_amount' => $term['final']['amount'], 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => count( $package_groups ) + 1 ];
		}
		if ( count( $shipping ) !== count( $term_facts ) || ! $total->equals( self::money( $native['shipping_total'], $currency ) ) || ! $tax_total->equals( self::money( $native['shipping_tax'], $currency ) ) ) { self::fail(); }
		$core_lines = []; $line_keys = [];
		foreach ( $binding->mapping()['groups'] as $group ) { foreach ( $group['lines'] as $member ) {
			$line = $draft_lines[$member['line_key']] ?? null; $captured = $group_facts[$group['component_key']] ?? null; $term = $term_facts[$group['component_key']] ?? null;
			if ( null === $line || null === $captured || null === $term || $member['product_id'] !== $line['product_id'] || $member['variation_id'] !== $line['variation_id'] || $member['quantity'] !== $line['quantity'] || $captured['offer_id'] !== $line['selection']['delivery_offer_id'] || 'delivery' !== $line['selection']['fulfilment_choice'] || ! ctype_digit( $member['quantity'] ) ) { self::fail(); }
			$customer = CustomerCartContext::fromArray( $line['customer_context'] );
			$historical = null === $customer ? null : DeliveryGroupIdentity::forHistorical( $line['selection'], $customer );
			if ( null === $customer || ! $customer->hasCompleteDeliveryAddress() || $historical !== $shipping[$group['component_key']]['group_id'] ) { self::fail(); }
			$snapshot = new OrderDeliveryLineSnapshot( '1', '2', $member['product_id'], $member['variation_id'], $line['selection']['fulfilment_availability'], 'delivery', $captured['offer_id'], $term['customer_label'], null, null, $line['selection']['rule_id'], $captured['destination_zone_id'], (int) $member['quantity'], $currency, $term['final']['amount'], 'quoted', null, null, $captured_at, $historical, null, null, null, $customer->contract_version, $customer->matching_location?->toArray(), $customer->delivery_address?->toArray(), $customer->matching_identity, $customer->delivery_location_identity, null );
			$core_lines[$member['item_id']] = $snapshot->toArray(); $line_keys[$member['item_id']] = $member['line_key'];
		} }
		$core_package = [ 'snapshot_version' => '2', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 1 === count( $package_groups ) ? $package_groups[0]['shipping_method_label'] : 'Multiple deliveries', 'package_total_delivery_amount' => $total->amount(), 'currency_code' => $currency, 'destination_zone_id' => 1 === count( $group_facts ) ? reset( $group_facts )['destination_zone_id'] : null, 'quote_status' => 'success', 'snapshotted_at' => $captured_at, 'groups' => $package_groups ];
		$packet = DeliveryQuoteSnapshotEnvelope::from_captured( $quote->header(), $context, $terms, $accepted, QuoteId::from_string( $binding->row()['placement_uuid'] ), str_repeat( '0', 64 ) )->private_facts(); unset( $packet['context_digest'] );
		$context_digest = QuoteNativeOrderFacts::digest( 'context', [ 'format' => 1, 'site' => $quote->site_id(), 'order' => $order->get_id(), 'mapping' => $binding->mapping(), 'quote' => $packet, 'terms' => $terms->private_facts(), 'lines' => $core_lines, 'package' => $core_package, 'native' => $native, 'draft' => $draft, 'native_tax_source' => $tax_source->private_facts() ] );
		$envelope = DeliveryQuoteSnapshotEnvelope::from_captured( $quote->header(), $context, $terms, $accepted, QuoteId::from_string( $binding->row()['placement_uuid'] ), $context_digest )->private_facts();
		$line_meta = []; foreach ( $core_lines as $id => $line ) { $line[DeliveryQuoteSnapshotEnvelope::MEMBER] = $envelope; $line_meta[$id] = [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => QuoteNativeOrderFacts::encode( $line ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '2', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '1', QuoteNativeOrderFacts::META_LINE_KEY => $line_keys[$id] ]; }
		$core_package[DeliveryQuoteSnapshotEnvelope::MEMBER] = $envelope;
		$order_meta = [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => QuoteNativeOrderFacts::encode( $core_package ), OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '2', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '1', QuoteNativeOrderFacts::META_DRAFT => $draft_json, QuoteNativeOrderFacts::META_REFERENCE => $reference, QuoteNativeOrderFacts::META_TAX_SOURCE => $tax_source_json ];
		return [ 'order_meta' => $order_meta, 'line_meta' => $line_meta, 'native' => $native, 'context_digest' => $context_digest, 'snapshot_digest' => QuoteNativeOrderFacts::digest( 'snapshot', [ 'order_meta' => $order_meta, 'line_meta' => $line_meta, 'native' => $native, 'mapping' => $binding->mapping() ] ) ];
	}
	private function assert_native_packets( \WC_Order $order, array $plan ): void {
		foreach ( $plan['order_meta'] as $key => $value ) { if ( $order->get_meta( $key, true ) !== $value ) { self::fail(); } }
		foreach ( $order->get_items( 'line_item' ) as $item ) { foreach ( $plan['line_meta'][$item->get_id()] as $key => $value ) { if ( $item->get_meta( $key, true ) !== $value ) { self::fail(); } } }
	}
	private function native_facts( \WC_Order $order ): array {
		if ( [] !== $order->get_items( 'fee' ) || [] !== $order->get_items( 'coupon' ) ) { self::fail(); }
		$key = $order->get_order_key( 'edit' ); if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 128 ) { self::fail(); }
		$facts = [ 'currency' => $order->get_currency( 'edit' ), 'customer_id' => $order->get_customer_id( 'edit' ), 'order_key' => $key, 'total' => self::decimal( $order->get_total( 'edit' ) ), 'total_tax' => self::decimal( $order->get_total_tax( 'edit' ) ), 'cart_tax' => self::decimal( $order->get_cart_tax( 'edit' ) ), 'shipping_total' => self::decimal( $order->get_shipping_total( 'edit' ) ), 'shipping_tax' => self::decimal( $order->get_shipping_tax( 'edit' ) ), 'address' => [], 'lines' => [], 'shipping' => [], 'tax' => [] ];
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $field ) { $facts['address'][$field] = $order->{'get_shipping_' . $field}( 'edit' ); }
		foreach ( $order->get_items( 'line_item' ) as $item ) { $facts['lines'][] = [ 'id' => $item->get_id(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'quantity' => self::quantity( $item->get_quantity() ), 'total' => self::decimal( $item->get_total( 'edit' ) ), 'tax' => self::decimal( $item->get_total_tax( 'edit' ) ), 'subtotal' => self::decimal( $item->get_subtotal( 'edit' ) ), 'subtotal_tax' => self::decimal( $item->get_subtotal_tax( 'edit' ) ), 'taxes' => $item->get_taxes( 'edit' ) ]; }
		foreach ( $order->get_items( 'shipping' ) as $item ) { $instance = $item->get_instance_id( 'edit' ); if ( is_string( $instance ) && ctype_digit( $instance ) ) { $instance = (int) $instance; } if ( ! is_int( $instance ) || $instance < 0 ) { self::fail(); } $facts['shipping'][] = [ 'id' => $item->get_id(), 'method_id' => $item->get_method_id( 'edit' ), 'instance_id' => $instance, 'group_id' => $item->get_meta( 'cetech_de_group_id', true ), 'label' => $item->get_name( 'edit' ), 'total' => self::decimal( $item->get_total( 'edit' ) ), 'tax' => self::decimal( $item->get_total_tax( 'edit' ) ), 'taxes' => $item->get_taxes( 'edit' ) ]; }
		foreach ( $order->get_items( 'tax' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Tax || $item->get_id() < 1 || $item->get_order_id( 'edit' ) !== $order->get_id() ) { self::fail(); }
			$rate = $item->get_rate_id( 'edit' ); $label = $item->get_label( 'edit' ); $code = $item->get_name( 'edit' ); $compound = $item->get_compound( 'edit' ); $percent = $item->get_rate_percent( 'edit' );
			if ( ! is_int( $rate ) || $rate < 1 || ! is_string( $label ) || strlen( $label ) > 512 || ! is_string( $code ) || strlen( $code ) > 512 || ! is_bool( $compound ) || null !== $percent && ! is_float( $percent ) && ! is_int( $percent ) && ! is_string( $percent ) ) { self::fail(); }
			$facts['tax'][] = [ 'id' => $item->get_id(), 'rate_id' => $rate, 'label' => $label, 'code' => $code, 'compound' => $compound, 'rate_percent' => null === $percent ? null : self::decimal( (string) $percent ), 'tax' => self::decimal( (string) $item->get_tax_total( 'edit' ) ), 'shipping_tax' => self::decimal( (string) $item->get_shipping_tax_total( 'edit' ) ) ];
		}
		foreach ( [ 'lines', 'shipping', 'tax' ] as $type ) { usort( $facts[$type], static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] ); } QuoteNativeOrderFacts::encode( $facts ); return $facts;
	}
	/** Native tax rows match accepted shipping rates and saved product tax maps; no tax engine is called. */
	private function assert_tax_lines( array $native, array $terms, string $currency ): void {
		$shipping = []; $products = []; $rounding = null; $precision = null;
		foreach ( $terms as $term ) {
			$receipt = $term['native_tax_receipt'];
			if ( null !== $rounding && ( $rounding !== $receipt['rounding'] || $precision !== $receipt['display_precision'] ) ) { self::fail(); }
			$rounding = $receipt['rounding']; $precision = $receipt['display_precision'];
			foreach ( $receipt['rates'] as $rate ) { $amount = 'per_line' === $rounding ? self::rounded( $rate['amount']['amount'], $precision ) : $rate['amount']['amount']; $shipping[$rate['rate_id']] = ( $shipping[$rate['rate_id']] ?? self::money( '0', $currency ) )->add( self::money( $amount, $currency ) ); }
		}
		foreach ( $native['lines'] as $line ) { foreach ( $line['taxes']['total'] ?? [] as $rate => $amount ) { if ( ! is_int( $rate ) && ( ! is_string( $rate ) || ! ctype_digit( $rate ) ) || (int) $rate < 1 ) { self::fail(); } $amount = self::decimal( (string) $amount ); if ( 'per_line' === $rounding ) { $amount = self::rounded( $amount, $precision ); } $products[(int) $rate] = ( $products[(int) $rate] ?? self::money( '0', $currency ) )->add( self::money( $amount, $currency ) ); } }
		$rates = array_values( array_unique( [ ...array_keys( $shipping ), ...array_keys( $products ) ] ) ); sort( $rates, SORT_NUMERIC ); $seen = []; $aggregate = self::money( '0', $currency );
		foreach ( $native['tax'] as $tax ) { $rate = $tax['rate_id']; if ( isset( $seen[$rate] ) || ! in_array( $rate, $rates, true ) || ! self::money( $tax['shipping_tax'], $currency )->equals( $shipping[$rate] ?? self::money( '0', $currency ) ) || ! self::money( $tax['tax'], $currency )->equals( $products[$rate] ?? self::money( '0', $currency ) ) ) { self::fail(); } $seen[$rate] = true; $aggregate = $aggregate->add( self::money( $tax['shipping_tax'], $currency ) )->add( self::money( $tax['tax'], $currency ) ); }
		if ( count( $seen ) !== count( $rates ) || ! self::money( self::rounded( $aggregate->amount(), $precision ), $currency )->equals( self::money( $native['total_tax'], $currency ) ) ) { self::fail(); }
	}
	private static function rounded( string $amount, int $precision ): string {
		$money = self::money( $amount, 'XXX' )->amount(); [ $whole, $fraction ] = explode( '.', $money );
		$factor = 10 ** ( 6 - $precision ); $units = (int) ( $whole . $fraction ); $rounded = intdiv( $units + intdiv( $factor, 2 ), $factor ); $digits = str_pad( (string) $rounded, $precision + 1, '0', STR_PAD_LEFT );
		return 0 === $precision ? $digits : substr( $digits, 0, -$precision ) . '.' . substr( $digits, -$precision );
	}
	private function assert_physical_native( array $physical, array $native, bool $hpos ): void {
		$meta = []; foreach ( $physical['order_meta'] as $row ) { $meta[$row['meta_key']][] = $row['meta_value']; }
		if ( $hpos ) {
			$row = $physical['order'][0]; $money = $physical['money'][0];
			if ( $row['currency'] !== $native['currency'] || $money['order_key'] !== $native['order_key'] || (int) $row['customer_id'] !== $native['customer_id'] || self::decimal( $row['total_amount'] ) !== $native['total'] || self::decimal( $row['tax_amount'] ) !== $native['cart_tax'] || self::decimal( $money['shipping_total_amount'] ) !== $native['shipping_total'] || self::decimal( $money['shipping_tax_amount'] ) !== $native['shipping_tax'] ) { self::fail(); }
			$address = array_values( array_filter( $physical['address'], static fn( array $row ): bool => 'shipping' === $row['address_type'] ) ); if ( 1 !== count( $address ) ) { self::fail(); }
			foreach ( $native['address'] as $key => $value ) { if ( ( $address[0][$key] ?? '' ) !== $value ) { self::fail(); } }
		} else {
			if ( 'shop_order' !== $physical['order'][0]['post_type'] ) { self::fail(); }
			foreach ( [ '_order_key' => $native['order_key'], '_order_currency' => $native['currency'], '_customer_user' => (string) $native['customer_id'] ] as $key => $value ) { if ( ( $meta[$key] ?? [] ) !== [ $value ] ) { self::fail(); } }
			foreach ( [ '_order_total' => 'total', '_order_tax' => 'cart_tax', '_order_shipping' => 'shipping_total', '_order_shipping_tax' => 'shipping_tax' ] as $key => $field ) { if ( 1 !== count( $meta[$key] ?? [] ) || self::decimal( $meta[$key][0] ) !== $native[$field] ) { self::fail(); } }
			foreach ( $native['address'] as $key => $value ) { $saved = $meta['_shipping_' . $key] ?? []; if ( $saved !== [ $value ] && ! ( '' === $value && [] === $saved ) ) { self::fail(); } }
		}
		$items = []; foreach ( $physical['item_meta'] as $row ) { $items[(int) $row['order_item_id']][$row['meta_key']][] = $row['meta_value']; }
		$expected_items = []; foreach ( [ 'lines' => 'line_item', 'shipping' => 'shipping', 'tax' => 'tax' ] as $source => $type ) { foreach ( $native[$source] as $item ) { $expected_items[$item['id']] = $type; } } $actual_items = [];
		foreach ( $physical['items'] as $row ) { $id = (int) $row['order_item_id']; if ( isset( $actual_items[$id] ) || (int) $row['order_id'] < 1 ) { self::fail(); } $actual_items[$id] = $row['order_item_type']; } ksort( $actual_items, SORT_NUMERIC ); ksort( $expected_items, SORT_NUMERIC ); if ( $actual_items !== $expected_items ) { self::fail(); }
		foreach ( $native['lines'] as $line ) {
			$raw = $items[$line['id']] ?? [];
			foreach ( [ '_product_id' => (string) $line['product_id'], '_variation_id' => (string) $line['variation_id'], '_qty' => $line['quantity'] ] as $key => $value ) { if ( 1 !== count( $raw[$key] ?? [] ) || ( '_qty' === $key ? self::quantity( $raw[$key][0] ) : $raw[$key][0] ) !== $value ) { self::fail(); } }
			foreach ( [ '_line_total' => 'total', '_line_tax' => 'tax', '_line_subtotal' => 'subtotal', '_line_subtotal_tax' => 'subtotal_tax' ] as $key => $field ) { if ( 1 !== count( $raw[$key] ?? [] ) || self::decimal( $raw[$key][0] ) !== $line[$field] ) { self::fail(); } }
			if ( ( $raw['_line_tax_data'] ?? [] ) !== [ serialize( $line['taxes'] ) ] ) { self::fail(); }
		}
		foreach ( $native['shipping'] as $line ) { $raw = $items[$line['id']] ?? []; foreach ( [ 'method_id' => $line['method_id'], 'instance_id' => (string) $line['instance_id'], 'cost' => $line['total'], 'total_tax' => $line['tax'] ] as $key => $value ) { if ( 1 !== count( $raw[$key] ?? [] ) || ( in_array( $key, [ 'method_id', 'instance_id' ], true ) ? $raw[$key][0] : self::decimal( $raw[$key][0] ) ) !== $value ) { self::fail(); } } if ( ( $raw['taxes'] ?? [] ) !== [ serialize( $line['taxes'] ) ] ) { self::fail(); } }
		foreach ( $native['tax'] as $line ) {
			$raw = $items[$line['id']] ?? [];
			foreach ( [ 'rate_id' => (string) $line['rate_id'], 'label' => $line['label'], 'compound' => $line['compound'] ? '1' : '' ] as $key => $value ) { if ( ( $raw[$key] ?? [] ) !== [ $value ] ) { self::fail(); } }
			foreach ( [ 'tax_amount' => 'tax', 'shipping_tax_amount' => 'shipping_tax' ] as $key => $field ) { if ( 1 !== count( $raw[$key] ?? [] ) || self::decimal( $raw[$key][0] ) !== $line[$field] ) { self::fail(); } }
			if ( null !== $line['rate_percent'] && ( 1 !== count( $raw['rate_percent'] ?? [] ) || self::decimal( $raw['rate_percent'][0] ) !== $line['rate_percent'] ) ) { self::fail(); }
			$physical_item = array_values( array_filter( $physical['items'], static fn( array $row ): bool => (int) $row['order_item_id'] === $line['id'] ) ); if ( 1 !== count( $physical_item ) || $physical_item[0]['order_item_name'] !== $line['code'] ) { self::fail(); }
		}
	}
	private static function decimal( mixed $value ): string { if ( ! is_string( $value ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,12}))?\z/D', $value, $m ) ) { self::fail(); } $fraction = rtrim( $m[2] ?? '', '0' ); return $m[1] . ( '' === $fraction ? '' : '.' . $fraction ); }
	private static function money( string $amount, string $currency ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => self::decimal( $amount ), 'currency' => $currency, 'precision' => 6 ] ); }
	private static function quantity( mixed $quantity ): string { if ( is_float( $quantity ) && is_finite( $quantity ) && floor( $quantity ) === $quantity ) { $quantity = (int) $quantity; } if ( is_int( $quantity ) ) { $quantity = (string) $quantity; } $value = self::decimal( $quantity ); if ( '0' === $value ) { self::fail(); } return $value; }
	private static function fail(): never { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
}
