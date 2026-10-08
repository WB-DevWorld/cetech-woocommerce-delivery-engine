<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation,QuoteCurrentEvidenceGuard,QuoteNativeTaxSource,QuotePlacementEvidence};
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope,OrderDeliverySnapshot,OrderDeliverySnapshotBuilder,OrderDeliverySnapshotGate,OrderDeliverySnapshotPersister,QuoteNativeOrderFacts,QuoteNativeOrderHistory,QuoteNativeOrderStageResult,QuoteNativeOrderStager};
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteHeader,QuoteJson,QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtures,LegacyQuoteProviderFixtures,QuoteFixtures,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Contract/readback models only. Native HPOS and checkout execution have separate qualification. */
final class QuoteNativeOrderStagerTest extends TestCase {
	private function fixture(): array {
		$draft = CartQuoteFixtures::draft(); $customer = CustomerCartContext::fromArray( $draft->private_facts()['lines'][0]['customer_context'] );
		$tax_source = QuoteNativeTaxSource::from_private_facts( [ 'format_version' => 1, 'digest_version' => 2, 'currency' => 'GHS', 'precision' => 2, 'exempt' => true, 'tax_class' => '', 'location_digest' => QuoteFixtures::digest( 'tax_location' ), 'rounding' => 'per_line', 'tax_enabled' => false, 'source' => [ 'option_rows' => [], 'tax_rows' => [], 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [], 'customer_rows' => [], 'selectors' => [ 'option_names' => [ 'woocommerce_currency' ], 'tax_class' => '', 'method_instance_ids' => [ 0 ], 'session_key' => 'fixture-session', 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_' ] ] ] );
		$group = DeliveryGroupIdentity::forHistorical( $draft->private_facts()['lines'][0]['selection'], $customer ); $component = NativeCartQuotePreparation::component_key( $group );
		$data = LegacyQuoteProviderFixtures::context()->private_facts(); $data['tax']['context_digest'] = $tax_source->digest(); $data['destination']['key_epoch'] = $draft->owner()->key_epoch(); $data['lines'][0]['component_key'] = $component; $data['groups'][0]['component_key'] = $component; $context = QuoteContext::from_array( $data );
		$terms = LegacyQuoteProviderFixtures::terms()->private_facts(); $terms['groups'][0]['component_key'] = $component; $terms['groups'][0]['native_tax_receipt']['context_digest'] = $tax_source->digest(); $terms = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms::from_array( $terms );
		$old = QuoteStorageFixtures::quote( state: 'accepted' ); $id = $old->header()->id(); $ref = QuoteFixtures::reference( $id );
		$header = QuoteHeader::issue( $id, $draft->owner(), $context, $terms, QuoteFixtures::time(), $old->header()->namespace_hashes(), 'legacy_fixed_base_v1', 1, $ref );
		$row = array_replace( $old->row(), [ 'profile_code' => 'legacy_fixed_base_v1', 'owner_digest' => $draft->owner()->digest(), 'principal_hash' => $draft->owner()->facts()['principal_hash'], 'header_json' => $header->to_private_json(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'private_body_json' => QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ) ] );
		$quote = QuoteStoredRow::from_row( $row );
		$guard = new class implements QuoteCurrentEvidenceGuard { public function tables( OperationSession $s ): array { return []; } public function verify( OperationSession $s, QuoteOwner $o, QuoteContext $c ): bool { return true; } };
		$evidence = new QuotePlacementEvidence( $quote, $ref, $header, $context, $guard, static fn(): bool => true, QuoteFixtures::time()->plus_seconds( 2 ), $draft, native_tax_source: $tax_source );
		$line = new QuoteStageLine( 901, 100 ); $shipping = new QuoteStageShipping( 902, $group ); $order = new QuoteStageOrder( $line, $shipping ); $factory = new QuoteStageFactory( $order );
		$stager = new QuoteNativeOrderStager( $factory, static fn(): \WC_Order => clone $factory->order );
		$binding = QuoteStorageFixtures::binding( $quote );
		return [ $stager, $order, $evidence, $binding, $factory ];
	}
	public function test_stages_captured_facts_and_verifies_saved_bytes_before_freeze(): void {
		[ $stager, $order, $evidence, $binding, $factory ] = $this->fixture();
		$result = $stager->stage( $order, $evidence, $binding, 'classic' );
		self::assertTrue( QuoteNativeOrderHistory::verify( $result->fresh_order() ) );
		$packet = json_decode( $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ), true );
		self::assertSame( $result->context_digest(), $packet['delivery_quote']['context_digest'] ); self::assertSame( '12.500000', $packet['package_total_delivery_amount'] );
		self::assertSame( substr( $evidence->accepted_at()->iso_utc(), 0, 19 ) . '+00:00', $packet['snapshotted_at'] ); self::assertSame( $binding->mapping(), $result->mapping() );
		self::assertSame( 1, $factory->rollbacks ); self::assertSame( 1, $factory->retirements );
	}
	public function test_read_only_replay_never_changes_original_snapshots_or_timestamps(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $first = $stager->stage( $order, $evidence, $binding, 'classic' );
		$raw = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ); $saves = $order->saves;
		$again = $stager->stage( $order, $evidence, $binding, 'order_pay' );
		self::assertSame( $raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ); self::assertSame( $saves, $order->saves ); self::assertSame( $first->snapshot_digest(), $again->snapshot_digest() );
	}
	public function test_original_prepared_retry_completes_only_missing_packets(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $first = $stager->stage( $order, $evidence, $binding, 'classic' );
		$line = $order->lines[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		$order->delete_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ); $order->delete_meta_data( QuoteNativeOrderFacts::META_REFERENCE ); $order->delete_meta_data( QuoteNativeOrderFacts::META_TAX_SOURCE );
		$again = $stager->stage( $order, $evidence, $binding, 'classic' );
		self::assertSame( $line, $order->lines[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) ); self::assertSame( $first->snapshot_digest(), $again->snapshot_digest() );
	}
	public function test_stage_readback_requires_known_rollback_and_retirement_acknowledgement(): void {
		foreach ( [ 'refuse_rollback', 'refuse_retire' ] as $mode ) {
			[ $stager, $order, $evidence, $binding, $factory ] = $this->fixture(); $factory->$mode = true;
			try { $stager->stage( $order, $evidence, $binding, 'classic' ); self::fail( 'Unknown readback acknowledgement was accepted.' ); } catch ( \UnexpectedValueException ) { self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); }
		}
	}
	public function test_malformed_mandatory_packet_is_refused_without_overwrite(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, '1' ); $order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, '{"delivery_quote":null}' );
		try { $stager->stage( $order, $evidence, $binding, 'classic' ); self::fail( 'Malformed mandatory history was adopted.' ); } catch ( \UnexpectedValueException ) {}
		self::assertSame( '{"delivery_quote":null}', $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ); self::assertSame( 0, $order->saves );
	}
	public function test_ambiguous_physical_item_mapping_is_never_guessed(): void {
		[ $stager, $order, $evidence ] = $this->fixture(); $order->lines[] = new QuoteStageLine( 903, 100 );
		$this->expectException( \UnexpectedValueException::class ); $stager->mapping( $order, $evidence );
	}
	public function test_changed_native_money_denies_without_writing(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $order->shipping[0]->amount = '0';
		try { $stager->stage( $order, $evidence, $binding, 'classic' ); self::fail( 'Changed native money was staged.' ); } catch ( \UnexpectedValueException ) {}
		self::assertSame( 0, $order->saves ); self::assertFalse( QuoteNativeOrderHistory::owned( $order ) );
	}
	public function test_saved_duplicate_snapshot_row_denies_acknowledgement(): void {
		[ $stager, $order, $evidence, $binding, $factory ] = $this->fixture(); $factory->duplicate_packet = true;
		$this->expectException( \UnexpectedValueException::class ); $stager->stage( $order, $evidence, $binding, 'store_api' );
	}
	public function test_final_guard_uses_current_sql_and_detects_late_saved_mutation(): void {
		[ $stager, $order, $evidence, $binding, $factory ] = $this->fixture(); $result = $stager->stage( $order, $evidence, $binding, 'classic' );
		$verified = QuoteBinding::from_row( array_replace( $binding->row(), [ 'snapshot_digest' => $result->snapshot_digest(), 'context_digest' => $result->context_digest(), 'revision' => 2, 'verified_at' => $evidence->accepted_at()->sql() ] ), $evidence->quote_record() );
		$session = $factory->open(); $session->begin(); self::assertTrue( $result->verify( $session, $verified ) );
		self::assertNotEmpty( $factory->sql ); foreach ( array_slice( $factory->sql, -4 ) as $sql ) { self::assertStringEndsWith( ' FOR UPDATE', $sql ); }
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, 'late mutation' ); self::assertFalse( $result->verify( $session, $verified ) );
	}
	public function test_paid_callback_preserves_captured_history_without_repricing(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $first = $stager->stage( $order, $evidence, $binding, 'classic' ); $order->state = 'processing';
		self::assertTrue( QuoteNativeOrderHistory::verify( $order ) ); $history = $stager->saved_guard( $order, $evidence->quote_record(), $binding ); self::assertSame( $first->context_digest(), $history->context_digest() ); self::assertSame( $first->snapshot_digest(), $history->snapshot_digest() );
	}
	public function test_late_physical_zero_fee_and_tax_changes_cannot_hide_behind_unchanged_totals(): void {
		[ $stager, $order, $evidence, $binding, $factory ] = $this->fixture(); $result = $stager->stage( $order, $evidence, $binding, 'classic' );
		$verified = QuoteBinding::from_row( array_replace( $binding->row(), [ 'snapshot_digest' => $result->snapshot_digest(), 'context_digest' => $result->context_digest(), 'revision' => 2, 'verified_at' => $evidence->accepted_at()->sql() ] ), $evidence->quote_record() );
		$session = $factory->open(); $session->begin(); self::assertTrue( $result->verify( $session, $verified ) );
		$factory->extra_items = [ [ 'order_item_id' => '904', 'order_item_type' => 'fee', 'order_item_name' => 'Zero fee', 'order_id' => '100' ] ]; self::assertFalse( $result->verify( $session, $verified ) );
		$factory->extra_items = []; $factory->extra_item_meta = [ [ 'order_item_id' => '903', 'meta_key' => 'shipping_tax_amount', 'meta_value' => '0.01' ] ]; self::assertFalse( $result->verify( $session, $verified ) );
	}
	public function test_unsupported_saved_fee_refuses_before_any_snapshot_write(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $order->fees = [ new \stdClass() ];
		try { $stager->stage( $order, $evidence, $binding, 'classic' ); self::fail( 'Unsupported zero fee was staged.' ); } catch ( \UnexpectedValueException ) { self::assertSame( 0, $order->saves ); }
	}
	public function test_quote_owned_history_requires_every_product_line_packet(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $stager->stage( $order, $evidence, $binding, 'classic' );
		$order->lines[] = new QuoteStageLine( 903, 100 ); self::assertFalse( QuoteNativeOrderHistory::verify( $order ) );
	}
	public function test_corrupt_escaped_mandatory_member_still_owns_history(): void {
		$order = new \WC_Order( [ 'id' => 1, 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"deli\\u0076ery_quote":BROKEN}' ] ] );
		self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); self::assertFalse( QuoteNativeOrderHistory::verify( $order ) );
	}
	public function test_original_reference_and_draft_are_private_and_immutable(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $stager->stage( $order, $evidence, $binding, 'classic' );
		self::assertSame( QuoteJson::encode( $evidence->reference()->public_fields() ), $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ) );
		self::assertSame( $evidence->draft_facts(), QuoteJson::decode( $order->get_meta( QuoteNativeOrderFacts::META_DRAFT, true ) ) );
		$order->update_meta_data( QuoteNativeOrderFacts::META_DRAFT, '{"format_version":9}' ); $this->expectException( \UnexpectedValueException::class ); $stager->saved_guard( $order, $evidence->quote_record(), $binding );
	}
	public function test_original_native_tax_source_is_exact_private_and_mandatory_for_saved_admission(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $stager->stage( $order, $evidence, $binding, 'classic' );
		self::assertSame( $evidence->tax_source_json(), $order->get_meta( QuoteNativeOrderFacts::META_TAX_SOURCE, true ) ); self::assertTrue( $stager->load_tax_source( $order )->matches_context( $evidence->context() ) );
		$order->delete_meta_data( QuoteNativeOrderFacts::META_TAX_SOURCE ); self::assertTrue( QuoteNativeOrderHistory::verify( $order ) );
		$this->expectException( \InvalidArgumentException::class ); $stager->saved_guard( $order, $evidence->quote_record(), $binding );
	}
	public function test_changed_tax_definition_refuses_even_when_native_money_is_unchanged(): void {
		[ $stager, $order, $evidence, $binding ] = $this->fixture(); $stager->stage( $order, $evidence, $binding, 'classic' ); $saves = $order->saves;
		$source = $evidence->tax_source_facts(); $source['source']['option_rows'][] = [ 'option_id' => '7', 'option_name' => 'woocommerce_currency', 'option_value' => 'GHS', 'autoload' => 'no' ]; $order->update_meta_data( QuoteNativeOrderFacts::META_TAX_SOURCE, QuoteNativeTaxSource::from_private_facts( $source )->to_private_json() );
		try { $stager->saved_guard( $order, $evidence->quote_record(), $binding ); self::fail( 'Changed original tax evidence was admitted.' ); } catch ( \UnexpectedValueException ) { self::assertSame( $saves, $order->saves ); }
	}
	public function test_legacy_persister_never_downgrades_unknown_quote_marker(): void {
		$order = new \WC_Order( [ 'id' => 1, 'meta' => [ DeliveryQuoteSnapshotEnvelope::META_FORMAT => '999', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"delivery_quote":{"format":999}}' ] ] );
		$builder = $this->getMockBuilder( OrderDeliverySnapshotBuilder::class )->disableOriginalConstructor()->onlyMethods( [ 'build_line_snapshot', 'build_package_snapshot' ] )->getMock(); $builder->expects( self::never() )->method( 'build_package_snapshot' );
		$flags = new \CetechDeliveryEngine\Bootstrap\FeatureFlags(); $requirements = new \CetechDeliveryEngine\Core\Requirements();
		$gate = new OrderDeliverySnapshotGate( $flags, $requirements, new \CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate( $flags, $requirements ) );
		$persister = new OrderDeliverySnapshotPersister( $gate, $builder, new Logger() ); $persister->handle_order_created( $order );
		self::assertSame( '{"delivery_quote":{"format":999}}', $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
	}
}

final class QuoteStageLine extends \WC_Order_Item_Product {
	public function __construct( int $id, private int $order_id ) { parent::__construct( [ 'id' => $id, 'product_id' => 10, 'quantity' => 2 ] ); }
	public function get_order_id( string $context = 'view' ): int { return $this->order_id; }
	public function get_total( string $context = 'view' ): string { return '25'; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_subtotal( string $context = 'view' ): string { return '25'; }
	public function get_subtotal_tax( string $context = 'view' ): string { return '0'; }
	public function get_taxes( string $context = 'view' ): array { return [ 'total' => [], 'subtotal' => [] ]; }
}
final class QuoteStageShipping extends \WC_Order_Item_Shipping {
	public string $amount = '12.50';
	public function __construct( private int $id, string $group ) { parent::__construct( [ 'method_id' => 'delivery_engine_selected_offer', 'meta' => [ 'cetech_de_group_id' => $group ] ] ); }
	public function get_id(): int { return $this->id; }
	public function get_instance_id( string $context = 'view' ): int { return 0; }
	public function get_total(): string { return $this->amount; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_name( string $context = 'view' ): string { return 'Fixture delivery'; }
	public function get_taxes( string $context = 'view' ): array { return [ 'total' => [] ]; }
}
final class QuoteStageOrder extends \WC_Order {
	public array $lines; public array $shipping; public array $fees = []; public int $saves = 0; public string $state = 'pending';
	public function __construct( QuoteStageLine $line, QuoteStageShipping $shipping ) { parent::__construct( [ 'id' => 100 ] ); $this->lines = [ $line ]; $this->shipping = [ $shipping ]; }
	public function __clone() { $this->lines = array_map( static fn( object $o ): object => clone $o, $this->lines ); $this->shipping = array_map( static fn( object $o ): object => clone $o, $this->shipping ); }
	public function get_items( string $type = '' ): array { return match ( $type ) { 'shipping' => $this->shipping, 'fee' => $this->fees, 'coupon', 'tax' => [], default => $this->lines }; }
	public function get_currency( string $context = 'view' ): string { return 'GHS'; }
	public function get_customer_id( string $context = 'view' ): int { return 0; }
	public function get_order_key( string $context = 'view' ): string { return 'wc_order_staging_synthetic'; }
	public function get_total( string $context = 'view' ): string { return '37.50'; }
	public function get_total_tax( string $context = 'view' ): string { return '0'; }
	public function get_cart_tax( string $context = 'view' ): string { return '0'; }
	public function get_shipping_total( string $context = 'view' ): string { return '12.50'; }
	public function get_shipping_tax( string $context = 'view' ): string { return '0'; }
	public function get_shipping_country( string $context = 'view' ): string { return 'GH'; }
	public function get_shipping_state( string $context = 'view' ): string { return 'AA'; }
	public function get_shipping_city( string $context = 'view' ): string { return 'Accra'; }
	public function get_shipping_postcode( string $context = 'view' ): string { return '00001'; }
	public function get_shipping_address_1( string $context = 'view' ): string { return 'PRIVATE-Q05-ADDRESS'; }
	public function get_shipping_address_2( string $context = 'view' ): string { return ''; }
	public function save(): void { ++$this->saves; parent::save(); }
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->update_meta_data( $key, $value ); }
}
final class QuoteStageFactory implements OperationConnectionFactory {
	public int $rollbacks = 0; public int $retirements = 0; public array $sql = []; public bool $duplicate_packet = false; public bool $refuse_rollback = false; public bool $refuse_retire = false;
	public array $extra_items = []; public array $extra_item_meta = [];
	public function __construct( public QuoteStageOrder $order ) {}
	public function open(): OperationSession { return new QuoteStageSession( $this ); }
}
final class QuoteStageSession implements OperationSession {
	private bool $active = false;
	public function __construct( private QuoteStageFactory $f ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'wp_'; } public function charset_collate(): string { return ''; }
	public function begin(): bool { $this->active = true; return true; } public function commit(): OperationCommitResult { throw new \LogicException(); }
	public function rollback(): bool { $this->active = false; ++$this->f->rollbacks; return ! $this->f->refuse_rollback; } public function retire(): bool { ++$this->f->retirements; return ! $this->f->refuse_retire; } public function is_retired(): bool { return false; } public function in_transaction(): bool { return $this->active; }
	public function validate_tables( array $names ): bool { return $this->active; } public function query( string $sql ): int|false { throw new \LogicException(); }
	public function get_row( string $sql ): array|null|false { return $this->get_results( $sql )[0] ?? null; }
	public function get_results( string $sql ): array|false {
		$this->f->sql[] = $sql; $o = $this->f->order;
		if ( str_contains( $sql, 'woocommerce_order_itemmeta' ) ) {
			$rows = [];
			foreach ( $o->lines as $item ) { $meta = [ '_product_id' => '10', '_variation_id' => '0', '_qty' => '2', '_line_total' => '25', '_line_tax' => '0', '_line_subtotal' => '25', '_line_subtotal_tax' => '0', '_line_tax_data' => serialize( $item->get_taxes() ) ]; foreach ( [ OrderDeliverySnapshot::META_LINE_SNAPSHOT,OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,DeliveryQuoteSnapshotEnvelope::META_FORMAT,QuoteNativeOrderFacts::META_LINE_KEY ] as $key ) { $meta[$key] = $item->get_meta( $key, true ); } foreach ( $meta as $key => $value ) { $rows[] = [ 'order_item_id' => (string) $item->get_id(), 'meta_key' => $key, 'meta_value' => $value ]; } }
			foreach ( $o->shipping as $item ) { foreach ( [ 'method_id' => 'delivery_engine_selected_offer','instance_id' => '0','cost' => $item->amount,'total_tax' => '0','taxes' => serialize( $item->get_taxes() ),'cetech_de_group_id' => $item->get_meta( 'cetech_de_group_id', true ) ] as $key => $value ) { $rows[] = [ 'order_item_id' => (string) $item->get_id(),'meta_key' => $key,'meta_value' => $value ]; } }
			return [ ...$rows, ...$this->f->extra_item_meta ];
		}
		if ( str_contains( $sql, 'woocommerce_order_items' ) ) { return [ [ 'order_item_id' => '901','order_item_type' => 'line_item','order_item_name' => 'Product','order_id' => '100' ], [ 'order_item_id' => '902','order_item_type' => 'shipping','order_item_name' => 'Fixture delivery','order_id' => '100' ], ...$this->f->extra_items ]; }
		if ( str_contains( $sql, '`wp_posts`' ) ) { return [ [ 'ID' => '100','post_type' => 'shop_order','post_status' => 'wc-' . $o->state ] ]; }
		if ( str_contains( $sql, '`wp_postmeta`' ) ) {
			$meta = [ '_order_key' => 'wc_order_staging_synthetic','_order_currency' => 'GHS','_order_total' => '37.50','_order_tax' => '0','_order_shipping' => '12.50','_order_shipping_tax' => '0','_customer_user' => '0','_shipping_country' => 'GH','_shipping_state' => 'AA','_shipping_city' => 'Accra','_shipping_postcode' => '00001','_shipping_address_1' => 'PRIVATE-Q05-ADDRESS','_shipping_address_2' => '' ];
			foreach ( [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION,DeliveryQuoteSnapshotEnvelope::META_FORMAT,QuoteNativeOrderFacts::META_DRAFT,QuoteNativeOrderFacts::META_REFERENCE,QuoteNativeOrderFacts::META_TAX_SOURCE ] as $key ) { $meta[$key] = $o->get_meta( $key, true ); } $rows = []; foreach ( $meta as $key => $value ) { $rows[] = [ 'meta_key' => $key,'meta_value' => $value ]; } if ( $this->f->duplicate_packet ) { $rows[] = [ 'meta_key' => OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,'meta_value' => $o->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ]; } return $rows;
		} return false;
	}
	public function prepare( string $sql, mixed ...$args ): string { $i = 0; return preg_replace_callback( '/%d/', static function() use ( $args, &$i ): string { return (string) $args[$i++]; }, $sql ); }
	public function errno(): int { return 0; } public function insert_id(): int { return 0; }
}
