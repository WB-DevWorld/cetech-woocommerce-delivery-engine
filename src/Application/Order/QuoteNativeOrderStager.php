<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation;
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteBindResult;
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuotePlacementEvidence;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteSavedOrderAuthorization;
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeTaxSource;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeShippingTaxProjection;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteSavedOrderNativeEvidence;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext, DeliveryAddress};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteId,QuoteJson,QuoteMoney,QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;

/** Single quote writer. Captured facts are staged and physically verified before C07 freeze. */
final class QuoteNativeOrderStager {
	private ?\Closure $loader;
	public function __construct( private OperationConnectionFactory $factory, ?callable $fresh_loader = null ) { $this->loader = null === $fresh_loader ? null : \Closure::fromCallable( $fresh_loader ); }
	public function mapping( \WC_Order $order, QuotePlacementEvidence $evidence ): array { if ( ! in_array( $evidence->header()->format_version(), [ 1, 2 ], true ) ) { self::fail(); } return $this->map_context( $order, $evidence->quote_record() ); }
	private function map_context( \WC_Order $order, QuoteStoredRow $quote ): array { return QuoteNativeOrderFacts::mapping( $order, $quote ); }
	/** Explicit promotion observations map a saved order only after exact native access is proved. */
	public function promotion_mapping( \WC_Order $order, FixedPromotionQuotePlacementEvidence $evidence, callable $authorize_order ): array {
		$authorization = QuoteSavedOrderAuthorization::capture( $order, $authorize_order );
		$quote = $evidence->quote_record(); $hpos = self::hpos(); $this->assert_promotion_identity( $authorization, $quote, $hpos );
		$mapping = $this->map_context( $order, $quote ); $this->assert_promotion_identity( $authorization, $quote, $hpos ); return $mapping;
	}
	/** Acknowledged prepared1 is the sole writer entry for the finite native promotion handoff. */
	public function stage_promotion( \WC_Order $order, FixedPromotionQuoteBindResult $acknowledged, QuoteCartDraft $draft, QuoteNativeTaxSource $tax_source, string $route, callable $authorize_order ): QuoteNativeOrderStageResult {
		$authorization = QuoteSavedOrderAuthorization::capture( $order, $authorize_order );
		$quote = $acknowledged->quote; $binding = $acknowledged->binding; $command = $acknowledged->command;
		if ( 'accepted' !== $acknowledged->attempt->outcome->state || true !== $acknowledged->attempt->outcome->mutation_accepted || null === $quote || null === $binding || null === $command || 'prepared' !== $binding->state() || 1 !== $binding->revision() || ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) || 3 !== $quote->header()->format_version() || ! $draft->owner()->equals( $quote->header()->owner() ) ) { self::fail(); }
		$hpos = self::hpos(); $actor = function() use ( $authorization, $quote ): void { if ( ! $authorization->unchanged() || ( $GLOBALS['blog_id'] ?? 1 ) !== $quote->site_id() ) { self::fail(); } };
		$this->assert_promotion_identity( $authorization, $quote, $hpos ); $status = $this->unpaid_status( $order ); $this->assert_promotion_identity( $authorization, $quote, $hpos );
		$check = function() use ( $authorization, $quote, $hpos, $order, $status ): void { $this->assert_promotion_identity( $authorization, $quote, $hpos ); if ( $this->unpaid_status( $order ) !== $status ) { self::fail(); } $this->assert_promotion_identity( $authorization, $quote, $hpos ); };
		$check(); $mapping = $this->map_context( $order, $quote ); $check(); $this->assert_binding( $order, $quote, $binding, $mapping ); $check();
		foreach ( $draft->private_facts()['lines'] as $line ) { if ( ! hash_equals( $line['selection_hash'], CartDeliverySelectionFingerprint::fromIntent( $line['selection'] ) ) ) { self::fail(); } $check(); }
		$plan = $this->plan( $order, $quote, $binding, QuoteJson::encode( $draft->private_facts() ), QuoteJson::encode( $command->reference()->public_fields() ), $tax_source->to_private_json() ); $check();
		$native = FixedPromotionNativeOrderEvidence::capture( $this->factory, $order, $quote, $mapping, $tax_source, $authorize_order ); $check();
		if ( null === $native || ! hash_equals( $command->order_evidence_digest(), $native->evidence_digest() ) || $this->native_facts( $order ) !== $plan['native'] ) { self::fail(); } $check();
		$write_guard = function() use ( $check, $order, $plan ): void { $check(); if ( $this->native_facts( $order ) !== $plan['native'] ) { self::fail(); } $check(); };
		$this->write_plan( $order, $binding, $plan, $write_guard, true );
		$result = $this->capture_saved( $order, $quote, $binding, $plan, $actor, $hpos, $tax_source->private_facts()['source']['selectors']['table_prefix'] ); $check(); return $result;
	}
	public function stage( \WC_Order $order, QuotePlacementEvidence $evidence, QuoteBinding $binding, string $route ): QuoteNativeOrderStageResult {
		if ( ! in_array( $evidence->header()->format_version(), [ 1, 2 ], true ) || ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.bind' ) || null === $evidence->draft_facts() || null === $evidence->tax_source_json() ) { self::fail(); }
		$quote = $evidence->quote_record(); $mapping = $this->map_context( $order, $quote );
		$this->assert_binding( $order, $quote, $binding, $mapping );
		$draft = QuoteJson::encode( $evidence->draft_facts() ); $reference = QuoteJson::encode( $evidence->reference()->public_fields() );
		$plan = $this->plan( $order, $quote, $binding, $draft, $reference, $evidence->tax_source_json() );
		$this->write_plan( $order, $binding, $plan );
		return $this->capture_saved( $order, $quote, $binding, $plan );
	}
	private function assert_binding( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, array $mapping ): void {
		if ( $binding->row()['order_id'] !== $order->get_id() || $binding->mapping() !== $mapping || $binding->row()['quote_uuid'] !== $quote->header()->id()->value() || $binding->site_id() !== $quote->site_id() ) { self::fail(); }
	}
	/** One writer serves retained formats and the explicit promotion path. */
	private function write_plan( \WC_Order $order, QuoteBinding $binding, array $plan, ?callable $guard = null, bool $missing_only = false ): void {
		$guard?->__invoke(); $owned = QuoteNativeOrderHistory::owned( $order ); $guard?->__invoke();
		if ( $missing_only || $owned ) {
			if ( 'prepared' === $binding->state() && 1 === $binding->revision() ) { $this->complete_missing_packets( $order, $plan, $guard ); }
			elseif ( ! $missing_only ) { $this->assert_native_packets( $order, $plan ); $guard?->__invoke(); }
			else { self::fail(); }
		} else {
			if ( 'prepared' !== $binding->state() || 1 !== $binding->revision() ) { self::fail(); }
			$items = $order->get_items( 'line_item' ); $guard?->__invoke();
			foreach ( $items as $item ) {
				foreach ( $plan['line_meta'][$item->get_id()] as $key => $value ) { $guard?->__invoke(); $item->update_meta_data( $key, $value ); $guard?->__invoke(); }
				$guard?->__invoke(); $item->save(); $guard?->__invoke();
			}
			foreach ( $plan['order_meta'] as $key => $value ) { $guard?->__invoke(); $order->update_meta_data( $key, $value ); $guard?->__invoke(); }
			$guard?->__invoke(); $order->save(); $guard?->__invoke();
		}
	}
	/** Exact original prepared1 may finish absent writes, never replace any existing protected fact. */
	private function complete_missing_packets( \WC_Order $order, array $plan, ?callable $guard = null ): void {
		$pending = []; $objects = [ [ $order, $plan['order_meta'] ] ];
		$items = $order->get_items( 'line_item' ); $guard?->__invoke(); foreach ( $items as $item ) { $objects[] = [ $item, $plan['line_meta'][$item->get_id()] ]; }
		foreach ( $objects as [ $object, $meta ] ) {
			$missing = [];
			foreach ( $meta as $key => $value ) {
				$present = QuoteNativeOrderHistory::meta_present( $object, $key ); $guard?->__invoke();
				if ( null === $present ) { self::fail(); }
				if ( true === $present ) { $actual = $object->get_meta( $key, true ); $guard?->__invoke(); if ( $actual !== $value ) { self::fail(); } }
				if ( ! $present ) { $missing[$key] = $value; }
			}
			if ( [] !== $missing ) { $pending[] = [ $object, $missing ]; }
		}
		foreach ( $pending as [ $object, $meta ] ) { foreach ( $meta as $key => $value ) { $guard?->__invoke(); $object->add_meta_data( $key, $value, true ); $guard?->__invoke(); } $guard?->__invoke(); $object->save(); $guard?->__invoke(); }
		$this->assert_native_packets( $order, $plan ); $guard?->__invoke();
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
		if ( ! in_array( $quote->header()->format_version(), [ 1, 2 ], true ) || ! QuoteNativeOrderHistory::owned( $order ) ) { self::fail(); }
		$draft = $order->get_meta( QuoteNativeOrderFacts::META_DRAFT, true ); $reference = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ); $tax_source = $this->load_tax_source( $order );
		if ( ! is_string( $draft ) || ! is_string( $reference ) || $binding->mapping() !== $this->map_context( $order, $quote ) ) { self::fail(); }
		$plan = $this->plan( $order, $quote, $binding, $draft, $reference, $tax_source->to_private_json() ); $this->assert_native_packets( $order, $plan );
		return $this->capture_saved( $order, $quote, $binding, $plan );
	}
	/** Read-only original promotion history, including unchanged orders that were paid later. */
	public function saved_promotion_guard( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, callable $authorize_order ): QuoteNativeOrderStageResult {
		$authorization = QuoteSavedOrderAuthorization::capture( $order, $authorize_order );
		$hpos = self::hpos(); $check = function() use ( $authorization, $quote, $hpos ): void { $this->assert_promotion_identity( $authorization, $quote, $hpos ); };
		$actor = function() use ( $authorization, $quote ): void { if ( ! $authorization->unchanged() || ( $GLOBALS['blog_id'] ?? 1 ) !== $quote->site_id() ) { self::fail(); } };
		$check(); if ( 3 !== $quote->header()->format_version() || ! QuoteNativeOrderHistory::owned( $order ) ) { self::fail(); } $check();
		$draft = $order->get_meta( QuoteNativeOrderFacts::META_DRAFT, true ); $check(); $reference = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ); $check(); $tax_source = $this->load_tax_source( $order ); $check();
		$mapping = $this->map_context( $order, $quote ); $check(); $this->assert_binding( $order, $quote, $binding, $mapping ); $check();
		if ( ! is_string( $draft ) || ! is_string( $reference ) ) { self::fail(); }
		$plan = $this->plan( $order, $quote, $binding, $draft, $reference, $tax_source->to_private_json() ); $check(); $this->assert_native_packets( $order, $plan ); $check();
		$result = $this->capture_saved( $order, $quote, $binding, $plan, $actor, $hpos, $tax_source->private_facts()['source']['selectors']['table_prefix'] ); $check(); return $result;
	}
	private function assert_promotion_identity( QuoteSavedOrderAuthorization $authorization, QuoteStoredRow $quote, bool $hpos ): void {
		if ( ! $authorization->unchanged() || ( $GLOBALS['blog_id'] ?? 1 ) !== $quote->site_id() || self::hpos() !== $hpos || ! $authorization->unchanged() ) { self::fail(); }
	}
	private function unpaid_status( \WC_Order $order ): string {
		$status = $order->get_status(); if ( ! in_array( $status, [ 'pending', 'failed', 'checkout-draft' ], true ) || $order->is_paid() || null !== $order->get_date_paid( 'edit' ) ) { self::fail(); } return $status;
	}
	private static function hpos(): bool { return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(); }
	private function capture_saved( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, array $plan, ?callable $actor = null, ?bool $expected_hpos = null, ?string $expected_prefix = null ): QuoteNativeOrderStageResult {
		$actor?->__invoke(); $order_id = $order->get_id(); $actor?->__invoke(); $fresh = null !== $this->loader ? ( $this->loader )( $order_id ) : new \WC_Order( $order_id ); $actor?->__invoke();
		if ( ! $fresh instanceof \WC_Order || $fresh === $order || $fresh->get_id() !== $order_id ) { self::fail(); }
		if ( method_exists( $fresh, 'read_meta_data' ) ) { $fresh->read_meta_data( true ); $actor?->__invoke(); }
		$this->assert_native_packets( $fresh, $plan ); $actor?->__invoke();
		if ( $this->map_context( $fresh, $quote ) !== $binding->mapping() || $this->native_facts( $fresh ) !== $plan['native'] || ! QuoteNativeOrderHistory::verify( $fresh ) ) { self::fail(); }
		$actor?->__invoke(); $hpos = self::hpos(); $actor?->__invoke(); if ( null !== $expected_hpos && $hpos !== $expected_hpos ) { self::fail(); }
		$session = $this->factory->open(); $begun = false; $retired = false; $metadata = null; $completed = false;
		try {
			if ( null !== $expected_prefix ) { $metadata = self::session_metadata( $session ); if ( $metadata['site'] !== $quote->site_id() || $metadata['prefix'] !== $expected_prefix || $session->is_retired() || $session->in_transaction() ) { self::fail(); } }
			$actor?->__invoke(); if ( ! $session->begin() ) { self::fail(); } $begun = true; $actor?->__invoke();
			if ( null !== $expected_prefix && ( ! $session->in_transaction() || $session->is_retired() ) ) { self::fail(); }
			$probe = new QuoteNativeOrderStageResult( $quote->site_id(), $order_id, $hpos, $binding->mapping(), $plan['snapshot_digest'], $plan['context_digest'], [], $fresh );
			if ( $session->site_id() !== $quote->site_id() || ! $session->validate_tables( $probe->tables( $session ) ) ) { self::fail(); }
			if ( null !== $metadata && ( self::session_metadata( $session ) !== $metadata || ! $session->in_transaction() || $session->is_retired() ) ) { self::fail(); }
			$actor?->__invoke(); $physical = QuoteNativeOrderStageResult::read_physical( $session, $order_id, $hpos ); $actor?->__invoke();
			if ( null !== $metadata && ( self::session_metadata( $session ) !== $metadata || ! $session->in_transaction() || $session->is_retired() ) ) { self::fail(); }
			QuoteNativeOrderStageResult::assert_snapshot_rows( $physical, $plan['order_meta'], $plan['line_meta'] );
			$this->assert_physical_native( $physical, $plan['native'], $hpos );
			$result = new QuoteNativeOrderStageResult( $quote->site_id(), $order_id, $hpos, $binding->mapping(), $plan['snapshot_digest'], $plan['context_digest'], $physical, $fresh );
			if ( ! $session->rollback() || null !== $expected_prefix && $session->in_transaction() ) { self::fail(); } $begun = false;
			if ( null !== $metadata && self::session_metadata( $session ) !== $metadata ) { self::fail(); }
			if ( ! $session->retire() || null !== $expected_prefix && ( ! $session->is_retired() || $session->in_transaction() ) ) { self::fail(); } $retired = true; $actor?->__invoke();
			if ( null !== $metadata && self::session_metadata( $session ) !== $metadata ) { self::fail(); }
			$completed = true; return $result;
		} finally {
			if ( null !== $expected_prefix ) { if ( ! $completed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} } }
			else { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $retired && ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } }
		}
	}
	private static function session_metadata( \CetechDeliveryEngine\Domain\Operation\OperationSession $session ): array {
		$site = $session->site_id(); $prefix = $session->table_prefix(); $charset = $session->charset_collate();
		if ( $site < 1 || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,30}\z/D', $prefix ) || strlen( $charset ) > 256 || str_contains( $charset, "\0" ) ) { self::fail(); }
		return [ 'site' => $site, 'prefix' => $prefix, 'charset' => $charset ];
	}
	private function plan( \WC_Order $order, QuoteStoredRow $quote, QuoteBinding $binding, string $draft_json, string $reference, string $tax_source_json ): array {
		$context = $quote->context(); $terms = $quote->terms(); $accepted = $quote->accepted_at();
		if ( null === $context || null === $terms || null === $accepted || 'accepted' !== $quote->state() ) { self::fail(); }
		$tax_source = QuoteNativeTaxSource::from_private_json( $tax_source_json ); if ( ! $tax_source->matches_context( $context ) ) { self::fail(); }
		$promotion = 3 === $quote->header()->format_version();
		$captured_at = substr( $accepted->iso_utc(), 0, 19 ) . '+00:00';
		$draft = QuoteJson::decode( $draft_json );
		try {
			\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::fields( $draft, [ 'format_version', 'owner_digest', 'currency', 'lines', 'customer_destination' ] );
			if ( 1 !== $draft['format_version'] || QuoteJson::encode( $draft ) !== $draft_json || $draft['owner_digest'] !== $quote->header()->owner()->digest() || ! is_array( $draft['lines'] ) || ! array_is_list( $draft['lines'] ) || count( $draft['lines'] ) !== count( $context->private_facts()['lines'] ) ) { self::fail(); }
			foreach ( $draft['lines'] as $line ) {
				\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::fields( $line, [ 'line_key', 'product_id', 'variation_id', 'quantity', 'selection', 'selection_hash', 'customer_context' ] );
			}
			if ( $promotion && ! NativeCartQuotePreparation::draft_facts_match_original( $draft, $context->base_context() ) ) { self::fail(); }
		} catch ( \Throwable ) { self::fail(); }
		$refs = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference::from_array( QuoteJson::decode( $reference ) );
		if ( ! $refs->id()->equals( $quote->header()->id() ) || ! $quote->header()->matches_reference( $refs ) ) { self::fail(); }
		$draft_lines = []; foreach ( $draft['lines'] as $line ) { $draft_lines[$line['line_key']] = $line; }
		$group_facts = []; foreach ( $context->private_facts()['groups'] as $group ) { $group_facts[$group['component_key']] = $group; }
		$term_facts = []; foreach ( $terms->private_facts()['groups'] as $term ) { $term_facts[$term['component_key']] = $term; }
		$native = $this->native_facts( $order ); $currency = $context->private_facts()['currency']['charged'];
		if ( $native['currency'] !== $currency ) { self::fail(); }
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address_2' ] as $field ) { if ( $native['address'][$field] !== ( $draft['customer_destination'][$field] ?? null ) ) { self::fail(); } }
		if ( $native['address']['address_1'] !== ( $draft['customer_destination']['address'] ?? null ) ) { self::fail(); }
		$shipping = []; $total = self::money( '0', $currency ); $tax_total = self::money( '0', $currency ); $package_groups = []; $projection = null; $native_zero_maps = [];
		foreach ( $native['shipping'] as $item ) {
			if ( 'delivery_engine_selected_offer' !== $item['method_id'] || ! is_string( $item['group_id'] ) ) { self::fail(); }
			$component = NativeCartQuotePreparation::component_key( $item['group_id'] ); $term = $term_facts[$component] ?? null;
			if ( null === $term || isset( $shipping[$component] ) || $item['label'] !== $term['customer_label'] || ! self::money( $item['total'], $currency )->equals( self::money( $term['final']['amount'], $currency ) ) ) { self::fail(); }
			$rates = []; foreach ( $term['native_tax_receipt']['rates'] as $rate ) { $rates[(string) $rate['rate_id']] = self::money( $rate['amount']['amount'], $currency )->amount(); }
			$actual = []; foreach ( $item['taxes']['total'] ?? [] as $id => $amount ) { $actual[(string) $id] = self::money( $amount, $currency )->amount(); } ksort( $rates, SORT_STRING ); ksort( $actual, SORT_STRING );
			$tax = 'subtotal' === $term['native_tax_receipt']['rounding'] ? $term['tax']['amount'] : $term['native_tax_receipt']['rounded_tax']['amount'];
			if ( $actual !== $rates ) {
				if ( ! QuoteNativeShippingTaxProjection::eligible( $term ) || ! QuoteNativeShippingTaxProjection::is_native_zero_candidate( $item['taxes']['total'] ?? [] ) ) { self::fail(); }
				$projection ??= QuoteSavedOrderNativeEvidence::prewarm_tax( $order, $quote, $tax_source );
				if ( ! $projection->accepts_zero_map( $term, $item['taxes']['total'] ?? [] ) ) { self::fail(); }
				$native_zero_maps[$component] = $projection->zero_map( $term );
			}
			if ( ! self::money( $item['tax'], $currency )->equals( self::money( $tax, $currency ) ) ) { self::fail(); }
			$total = $total->add( self::money( $item['total'], $currency ) ); $tax_total = $tax_total->add( self::money( $item['tax'], $currency ) ); $shipping[$component] = $item;
			$package_groups[] = [ 'group_id' => $item['group_id'], 'shipping_method_id' => $item['method_id'], 'shipping_method_label' => $term['customer_label'], 'package_total_delivery_amount' => $term['final']['amount'], 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => count( $package_groups ) + 1 ];
		}
		$this->assert_tax_lines( $native, $term_facts, $currency, $native_zero_maps );
		if ( count( $shipping ) !== count( $term_facts ) || ! $total->equals( self::money( $native['shipping_total'], $currency ) ) || ! $tax_total->equals( self::money( $native['shipping_tax'], $currency ) ) ) { self::fail(); }
		$promise = 2 === $quote->header()->format_version(); $estimates = []; $promise_total = null;
		if ( $promise ) {
			foreach ( $terms->promise_packet()->public_groups() as $public ) { $estimates[$public['component_key']] = $public['customer_text']; }
			foreach ( $term_facts as $term ) { $money = QuoteMoney::from_array( $term['final'] ); $promise_total = null === $promise_total ? $money : $promise_total->add( $money ); }
			if ( null === $promise_total || ! self::money( $promise_total->amount(), $currency )->equals( $total ) ) { self::fail(); }
		}
		$outer_version = $promotion ? '4' : ( $promise ? '3' : '2' ); $quote_format = $promotion ? '3' : ( $promise ? '2' : '1' );
		$core_lines = []; $line_keys = [];
		foreach ( $binding->mapping()['groups'] as $group ) { foreach ( $group['lines'] as $member ) {
			$line = $draft_lines[$member['line_key']] ?? null; $captured = $group_facts[$group['component_key']] ?? null; $term = $term_facts[$group['component_key']] ?? null;
			if ( null === $line || null === $captured || null === $term || $member['product_id'] !== $line['product_id'] || $member['variation_id'] !== $line['variation_id'] || $member['quantity'] !== $line['quantity'] || $captured['offer_id'] !== $line['selection']['delivery_offer_id'] || 'delivery' !== $line['selection']['fulfilment_choice'] || ! ctype_digit( $member['quantity'] ) ) { self::fail(); }
			$customer = CustomerCartContext::fromArray( $line['customer_context'] );
			$historical = null === $customer ? null : DeliveryGroupIdentity::forHistorical( $line['selection'], $customer );
			if ( null === $customer || ! $customer->hasCompleteDeliveryAddress() || $historical !== $shipping[$group['component_key']]['group_id'] ) { self::fail(); }
			if ( $promotion && ( $customer->delivery_offer_id !== $captured['offer_id'] || $customer->delivery_address?->identity() !== DeliveryAddress::fromInput( $draft['customer_destination'] )->identity() ) ) { self::fail(); }
			$snapshot = new OrderDeliveryLineSnapshot( '1', $outer_version, $member['product_id'], $member['variation_id'], $line['selection']['fulfilment_availability'], 'delivery', $captured['offer_id'], $term['customer_label'], null, $promise ? ( $estimates[$group['component_key']] ?? self::fail() ) : null, $line['selection']['rule_id'], $captured['destination_zone_id'], (int) $member['quantity'], $currency, $term['final']['amount'], 'quoted', null, null, $captured_at, $historical, null, null, null, $customer->contract_version, $customer->matching_location?->toArray(), $customer->delivery_address?->toArray(), $customer->matching_identity, $customer->delivery_location_identity, null );
			$core_lines[$member['item_id']] = $snapshot->toArray(); $line_keys[$member['item_id']] = $member['line_key'];
		} }
		$core_package = [ 'snapshot_version' => $outer_version, 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 1 === count( $package_groups ) ? $package_groups[0]['shipping_method_label'] : 'Multiple deliveries', 'package_total_delivery_amount' => $promise ? $promise_total->amount() : $total->amount(), 'currency_code' => $currency, 'destination_zone_id' => 1 === count( $group_facts ) ? reset( $group_facts )['destination_zone_id'] : null, 'quote_status' => 'success', 'snapshotted_at' => $captured_at, 'groups' => $package_groups ];
		$packet = DeliveryQuoteSnapshotEnvelope::from_captured( $quote->header(), $context, $terms, $accepted, QuoteId::from_string( $binding->row()['placement_uuid'] ), str_repeat( '0', 64 ) )->private_facts(); unset( $packet['context_digest'] );
		$context_digest = QuoteNativeOrderFacts::digest( 'context', [ 'format' => 1, 'site' => $quote->site_id(), 'order' => $order->get_id(), 'mapping' => $binding->mapping(), 'quote' => $packet, 'terms' => $terms->private_facts(), 'lines' => $core_lines, 'package' => $core_package, 'native' => $native, 'draft' => $draft, 'native_tax_source' => $tax_source->private_facts() ] );
		$envelope = DeliveryQuoteSnapshotEnvelope::from_captured( $quote->header(), $context, $terms, $accepted, QuoteId::from_string( $binding->row()['placement_uuid'] ), $context_digest )->private_facts();
		$line_meta = []; foreach ( $core_lines as $id => $line ) { $line[DeliveryQuoteSnapshotEnvelope::MEMBER] = $envelope; $line_meta[$id] = [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => QuoteNativeOrderFacts::encode( $line ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => $outer_version, DeliveryQuoteSnapshotEnvelope::META_FORMAT => $quote_format, QuoteNativeOrderFacts::META_LINE_KEY => $line_keys[$id] ]; }
		$core_package[DeliveryQuoteSnapshotEnvelope::MEMBER] = $envelope;
		$order_meta = [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => QuoteNativeOrderFacts::encode( $core_package ), OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => $outer_version, DeliveryQuoteSnapshotEnvelope::META_FORMAT => $quote_format, QuoteNativeOrderFacts::META_DRAFT => $draft_json, QuoteNativeOrderFacts::META_REFERENCE => $reference, QuoteNativeOrderFacts::META_TAX_SOURCE => $tax_source_json ];
		if ( $promise || $promotion ) { QuoteJson::decode( $order_meta[OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT] ); foreach ( $line_meta as $meta ) { QuoteJson::decode( $meta[OrderDeliverySnapshot::META_LINE_SNAPSHOT] ); } }
		return [ 'order_meta' => $order_meta, 'line_meta' => $line_meta, 'native' => $native, 'context_digest' => $context_digest, 'snapshot_digest' => QuoteNativeOrderFacts::digest( 'snapshot', [ 'order_meta' => $order_meta, 'line_meta' => $line_meta, 'native' => $native, 'mapping' => $binding->mapping() ] ) ];
	}
	private function assert_native_packets( \WC_Order $order, array $plan ): void {
		foreach ( $plan['order_meta'] as $key => $value ) { if ( $order->get_meta( $key, true ) !== $value ) { self::fail(); } }
		foreach ( $order->get_items( 'line_item' ) as $item ) { foreach ( $plan['line_meta'][$item->get_id()] as $key => $value ) { if ( $item->get_meta( $key, true ) !== $value ) { self::fail(); } } }
	}
	private function native_facts( \WC_Order $order ): array { return QuoteNativeOrderFacts::native( $order ); }
	/** Native tax rows match accepted shipping rates and saved product tax maps; no tax engine is called. */
	private function assert_tax_lines( array $native, array $terms, string $currency, array $native_zero_maps = [] ): void {
		$shipping = []; $products = []; $rounding = null; $precision = null;
		foreach ( $terms as $term ) {
			$receipt = $term['native_tax_receipt'];
			if ( null !== $rounding && ( $rounding !== $receipt['rounding'] || $precision !== $receipt['display_precision'] ) ) { self::fail(); }
			$rounding = $receipt['rounding']; $precision = $receipt['display_precision'];
			foreach ( $receipt['rates'] as $rate ) { $amount = 'per_line' === $rounding ? self::rounded( $rate['amount']['amount'], $precision ) : $rate['amount']['amount']; $shipping[$rate['rate_id']] = ( $shipping[$rate['rate_id']] ?? self::money( '0', $currency ) )->add( self::money( $amount, $currency ) ); }
			foreach ( $native_zero_maps[$term['component_key']] ?? [] as $rate => $_zero ) { $shipping[$rate] ??= self::money( '0', $currency ); }
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
	private function assert_physical_native( array $physical, array $native, bool $hpos ): void { QuoteNativeOrderFacts::assert_physical( $physical, $native, $hpos ); }
	private static function decimal( mixed $value ): string { if ( ! is_string( $value ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,12}))?\z/D', $value, $m ) ) { self::fail(); } $fraction = rtrim( $m[2] ?? '', '0' ); return $m[1] . ( '' === $fraction ? '' : '.' . $fraction ); }
	private static function money( string $amount, string $currency ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => self::decimal( $amount ), 'currency' => $currency, 'precision' => 6 ] ); }
	private static function fail(): never { throw new \UnexpectedValueException( 'Saved quote facts unavailable.' ); }
}
