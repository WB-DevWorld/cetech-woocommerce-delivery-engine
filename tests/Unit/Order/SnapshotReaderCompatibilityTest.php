<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotJson;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

final class SnapshotReaderCompatibilityTest extends TestCase {

	public function test_fractional_identity_is_not_silently_accepted(): void {
		$payload = $this->line_payload();
		$payload['product_id'] = 16.75;
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( (string) wp_json_encode( $payload ) ) );
		self::assertSame( 'partial', $read->error );
		self::assertNull( $read->snapshot );
	}

	public function test_mixed_group_page_is_not_silently_reduced(): void {
		$payload = $this->package_payload();
		$payload['groups'][] = 'broken group';
		$read = ( new OrderDeliverySnapshotReader() )->read_package( $this->order( (string) wp_json_encode( $payload ) ) );
		self::assertSame( 'partial', $read->error );
		self::assertNull( $read->snapshot );
	}

	public function test_duplicate_object_member_is_not_last_value_wins(): void {
		$raw = (string) wp_json_encode( $this->line_payload() );
		$raw = substr( $raw, 0, -1 ) . ',"product_id":99}';
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( $raw ) );
		self::assertSame( 'malformed', $read->error );
		self::assertNull( $read->snapshot );
	}

	#[DataProvider( 'invalid_line_fields' )]
	public function test_known_line_fields_fail_truthfully_without_coercion( string $key, mixed $value ): void {
		$payload = $this->line_payload();
		$payload[$key] = $value;
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( 'partial', $read->error );
		self::assertNull( $read->snapshot );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	/** @return iterable<string, array{string, mixed}> */
	public static function invalid_line_fields(): iterable {
		yield 'trailing ID junk' => [ 'product_id', '16not-an-id' ];
		yield 'boolean ID' => [ 'product_id', true ];
		yield 'fractional numeric string ID' => [ 'product_id', '16.0' ];
		yield 'scientific ID' => [ 'product_id', '1e3' ];
		yield 'leading zero ID' => [ 'product_id', '016' ];
		yield 'overflow ID' => [ 'product_id', (string) PHP_INT_MAX . '0' ];
		yield 'zero quantity' => [ 'quantity', 0 ];
		yield 'fractional quantity' => [ 'quantity', 2.5 ];
		yield 'array optional identity' => [ 'variation_id', [] ];
		yield 'boolean optional identity' => [ 'delivery_offer_id', true ];
		yield 'array customer label' => [ 'delivery_offer_public_label', [ 'private' => 'must not be cast' ] ];
		yield 'boolean description' => [ 'delivery_offer_public_description', true ];
		yield 'integer estimate' => [ 'estimate_text', 3 ];
		yield 'invalid availability' => [ 'fulfilment_availability', 'arbitrary' ];
		yield 'rewritable availability' => [ 'fulfilment_availability', 'IN_WAREHOUSE' ];
		yield 'invalid choice' => [ 'fulfilment_choice', 'teleport' ];
		yield 'invalid quote status' => [ 'quote_status', 'paid' ];
		yield 'array currency' => [ 'currency_code', [ 'GHS' ] ];
		yield 'lowercase currency' => [ 'currency_code', 'ghs' ];
		yield 'negative money' => [ 'quoted_amount', '-15.00' ];
		yield 'scientific money' => [ 'quoted_amount', '1e3' ];
		yield 'float money' => [ 'quoted_amount', 15.5 ];
		yield 'invalid date' => [ 'snapshotted_at', '2026-02-30T00:00:00+00:00' ];
		yield 'array date' => [ 'snapshotted_at', [ '2026-09-01' ] ];
		yield 'NUL date cannot raise private exception' => [ 'snapshotted_at', "2026-09-01\0T00:00:00+00:00" ];
		yield 'malformed recorded hash' => [ 'matching_identity', 'invented-identity' ];
		yield 'array pickup id' => [ 'pickup_location_id', [ 4 ] ];
		yield 'array group identity' => [ 'delivery_group_id', [ 'group' ] ];
	}

	public function test_canonical_numeric_legacy_ids_and_real_zero_money_remain_readable(): void {
		$payload = $this->line_payload();
		$payload['product_id'] = '16';
		$payload['variation_id'] = '61';
		$payload['quantity'] = '2';
		$payload['quoted_amount'] = '0.0000';
		$payload['delivery_offer_public_label'] = '  Saved customer label  ';
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw, 1 );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( '', $read->error );
		self::assertSame( 16, $read->snapshot?->product_id );
		self::assertSame( 61, $read->snapshot?->variation_id );
		self::assertSame( 2, $read->snapshot?->quantity );
		self::assertSame( '0.0000', $read->snapshot?->quoted_amount );
		self::assertSame( '  Saved customer label  ', $read->snapshot?->delivery_offer_public_label );
		self::assertSame( 'present_valid', ( new OrderDeliverySnapshotIntegrity() )->classify_line( $read ) );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	#[DataProvider( 'mismatched_versions' )]
	public function test_version_metadata_and_selection_mismatches_are_not_reinterpreted( mixed $format, mixed $contract, mixed $meta ): void {
		$payload = $this->line_payload();
		$payload['snapshot_version'] = $format;
		$payload['contract_version'] = $contract;
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw, $meta );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( 'version_mismatch', $read->error );
		self::assertNull( $read->snapshot );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	/** @return iterable<string, array{mixed, mixed, mixed}> */
	public static function mismatched_versions(): iterable {
		yield 'unsupported snapshot format' => [ '3', '1', '3' ];
		yield 'metadata JSON mismatch' => [ '1', '1', '2' ];
		yield 'selection contract mismatch' => [ '1', '2', '1' ];
		yield 'array snapshot format' => [ [], '1', '1' ];
		yield 'fractional snapshot format' => [ 1.5, '1', '1' ];
		yield 'noncanonical snapshot format' => [ '01', '1', '1' ];
		yield 'invalid metadata is not missing' => [ '1', '1', [ '1' ] ];
		yield 'boolean metadata is not missing' => [ '1', '1', true ];
	}

	public function test_missing_and_partial_records_remain_unavailable_without_inferred_values(): void {
		$reader = new OrderDeliverySnapshotReader();
		$missing = $reader->read_line( $this->line_item( '' ) );
		self::assertFalse( $missing->has_meta );
		self::assertSame( 'missing', $missing->error );
		self::assertNull( $missing->extensions );
		$payload = $this->line_payload();
		unset( $payload['quantity'] );
		$partial = $reader->read_line( $this->line_item( (string) wp_json_encode( $payload ) ) );
		self::assertTrue( $partial->has_meta );
		self::assertSame( 'partial', $partial->error );
		self::assertNull( $partial->snapshot );
	}

	public function test_present_nonstring_meta_is_malformed_including_boolean_false(): void {
		$reader = new OrderDeliverySnapshotReader();
		foreach ( [ false, 16, [ 'private' => 'must not be cast or logged' ], new \stdClass() ] as $raw ) {
			$item = new WC_Order_Item_Product( [ 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => $raw ] ] );
			$order = new WC_Order( [ 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $raw ] ] );
			foreach ( [ $reader->read_line( $item ), $reader->read_package( $order ) ] as $read ) {
				self::assertTrue( $read->has_meta );
				self::assertSame( 'malformed', $read->error );
				self::assertNull( $read->snapshot );
			}
		}
	}

	public function test_absent_group_and_absent_quote_are_not_fabricated_for_old_v1(): void {
		$payload = $this->line_payload();
		unset( $payload['delivery_group_id'], $payload['quoted_amount'] );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( (string) wp_json_encode( $payload ), '' ) );
		self::assertSame( '', $read->error );
		self::assertNull( $read->snapshot?->delivery_group_id );
		self::assertNull( $read->snapshot?->quoted_amount );
		self::assertSame( 'quote_missing', ( new OrderDeliverySnapshotIntegrity() )->classify_line( $read ) );
		self::assertSame( [], $read->extensions?->customer_facts() );
		self::assertSame( 'not_recorded', $read->extensions?->get( 'return_policy' )->status );
	}

	#[DataProvider( 'malformed_json' )]
	public function test_unambiguous_json_object_is_required_without_rewriting( string $raw ): void {
		$item = $this->line_item( $raw );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( 'malformed', $read->error );
		self::assertNull( $read->snapshot );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	/** @return iterable<string, array{string}> */
	public static function malformed_json(): iterable {
		yield 'truncated JSON' => [ '{"snapshot_version":"1"' ];
		yield 'JSON list root' => [ '[{"snapshot_version":"1"}]' ];
		yield 'JSON scalar root' => [ '"snapshot"' ];
		yield 'JSON null root' => [ 'null' ];
		yield 'escaped duplicate name' => [ '{"product_id":16,"\u0070roduct_id":99}' ];
		yield 'nested duplicate unknown optional' => [ '{"unknown_optional":{"x":1,"\u0078":2}}' ];
		yield 'invalid UTF8' => [ "{\"label\":\"\xff\"}" ];
	}

	public function test_json_byte_and_depth_bounds_refuse_without_partial_decoding(): void {
		$raw = (string) wp_json_encode( $this->line_payload() );
		$at_bound = $raw . str_repeat( ' ', OrderDeliverySnapshotJson::MAX_BYTES - strlen( $raw ) );
		self::assertSame( '', ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( $at_bound ) )->error );
		$too_large = $this->line_item( $at_bound . ' ' );
		self::assertSame( 'malformed', ( new OrderDeliverySnapshotReader() )->read_line( $too_large )->error );
		self::assertSame( $at_bound . ' ', $too_large->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		$deep = substr( $raw, 0, -1 ) . ',"unknown_optional":' . str_repeat( '[', 33 ) . '0' . str_repeat( ']', 33 ) . '}';
		self::assertSame( 'malformed', ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( $deep ) )->error );
	}

	#[DataProvider( 'invalid_packages' )]
	public function test_malformed_package_facts_do_not_become_partial_success( string $kind ): void {
		$payload = $this->package_payload();
		switch ( $kind ) {
			case 'status': $payload['quote_status'] = 'paid'; break;
			case 'money': $payload['package_total_delivery_amount'] = '-1.00'; break;
			case 'currency': $payload['currency_code'] = []; break;
			case 'mixed': $payload['groups'][] = 'broken'; break;
			case 'map': $payload['groups'] = [ 'not-a-list' => $payload['groups'][0] ]; break;
			case 'empty-object': $payload['groups'] = new \stdClass(); break;
			case 'duplicate': $payload['groups'][] = $payload['groups'][0]; break;
			case 'label': $payload['groups'][0]['shipping_method_label'] = []; break;
			case 'bool': $payload['groups'][0]['is_pickup'] = 'false'; break;
			case 'index': $payload['groups'][0]['display_index'] = 1.5; break;
			case 'negative-index': $payload['groups'][0]['display_index'] = -1; break;
			case 'choice': $payload['groups'][0]['fulfilment_choice'] = 'teleport'; break;
			case 'pickup-mismatch': $payload['groups'][0]['is_pickup'] = true; break;
			case 'group-money': $payload['groups'][0]['package_total_delivery_amount'] = 15.0; break;
		}
		$raw = (string) wp_json_encode( $payload );
		$order = $this->order( $raw );
		$read = ( new OrderDeliverySnapshotReader() )->read_package( $order );
		self::assertSame( 'partial', $read->error );
		self::assertNull( $read->snapshot );
		self::assertSame( $raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
	}

	/** @return iterable<string, array{string}> */
	public static function invalid_packages(): iterable {
		foreach ( [ 'status', 'money', 'currency', 'mixed', 'map', 'empty-object', 'duplicate', 'label', 'bool', 'index', 'negative-index', 'choice', 'pickup-mismatch', 'group-money' ] as $kind ) {
			yield $kind => [ $kind ];
		}
	}

	public function test_package_group_bound_never_returns_a_truncated_winner(): void {
		$payload = $this->package_payload();
		$template = $payload['groups'][0];
		$payload['groups'] = [];
		for ( $i = 1; $i <= 1000; ++$i ) {
			$group = $template;
			$group['group_id'] = 'historical-group-' . $i;
			$payload['groups'][] = $group;
		}
		$reader = new OrderDeliverySnapshotReader();
		$read = $reader->read_package( $this->order( (string) wp_json_encode( $payload ) ) );
		self::assertSame( '', $read->error );
		self::assertCount( 1000, $read->snapshot?->groups ?? [] );
		$template['group_id'] = 'historical-group-1001';
		$payload['groups'][] = $template;
		$read = $reader->read_package( $this->order( (string) wp_json_encode( $payload ) ) );
		self::assertSame( 'partial', $read->error );
		self::assertNull( $read->snapshot );
	}

	public function test_v2_destination_recipient_pickup_and_variation_facts_stay_historical(): void {
		$payload = $this->v2_payload();
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw, '2' );
		$reader = new OrderDeliverySnapshotReader();
		$first = $reader->read_line( $item );
		$before = $GLOBALS['cetech_de_test_options'] ?? [];
		try {
			$GLOBALS['cetech_de_test_options'] = [ 'cetech_de_schema_version' => 8, 'cetech_de_configuration_version' => 999, 'enable_order_delivery_snapshot_persistence' => false ];
			$again = $reader->read_line( $item );
			self::assertSame( '', $again->error );
			self::assertSame( $first->snapshot?->toArray(), $again->snapshot?->toArray() );
			self::assertSame( 61, $again->snapshot?->variation_id );
			self::assertSame( $payload['delivery_address'], $again->snapshot?->delivery_address );
			self::assertSame( $payload['matching_location'], $again->snapshot?->matching_location );
			self::assertSame( $payload['delivery_location_identity'], $again->snapshot?->delivery_location_identity );
			self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		} finally { $GLOBALS['cetech_de_test_options'] = $before; }
		$pickup = $payload;
		$pickup['fulfilment_availability'] = 'in_store';
		$pickup['fulfilment_choice'] = 'store_pickup';
		$pickup['delivery_offer_id'] = null;
		$pickup['quoted_amount'] = null;
		$pickup['quote_status'] = 'selection_only';
		$pickup['delivery_group_id'] = 'in_store|store_pickup|p4|pickup';
		foreach ( [ 'matching_location', 'delivery_address', 'matching_identity', 'delivery_location_identity' ] as $key ) { $pickup[$key] = null; }
		$pickup['pickup_location_id'] = 4;
		$read = $reader->read_line( $this->line_item( (string) wp_json_encode( $pickup ), '2' ) );
		self::assertSame( '', $read->error );
		self::assertSame( 4, $read->snapshot?->pickup_location_id );
		self::assertNull( $read->snapshot?->delivery_address );
		self::assertSame( 'in_store|store_pickup|p4|pickup', $read->snapshot?->delivery_group_id );
		foreach ( [ 'customer_context_version', 'matching_location', 'delivery_address', 'matching_identity', 'delivery_location_identity', 'pickup_location_id' ] as $key ) { unset( $payload[$key] ); }
		$unrecorded = $reader->read_line( $this->line_item( (string) wp_json_encode( $payload ), '2' ) );
		self::assertSame( '', $unrecorded->error );
		self::assertNull( $unrecorded->snapshot?->delivery_address );
		self::assertNull( $unrecorded->snapshot?->customer_context_version );
	}

	#[DataProvider( 'invalid_v2_contexts' )]
	public function test_recorded_v2_context_is_typed_without_rebuilding_from_live_normalizers( string $kind, string $expected ): void {
		$payload = $this->v2_payload();
		switch ( $kind ) {
			case 'context-version': $payload['customer_context_version'] = 2; break;
			case 'context-array': $payload['customer_context_version'] = [ 1 ]; break;
			case 'matching-list': $payload['matching_location'] = [ 'GH' ]; break;
			case 'matching-field': $payload['matching_location']['country'] = []; break;
			case 'address-field': unset( $payload['delivery_address']['address_1'] ); break;
			case 'recipient': $payload['delivery_address']['recipient'] = 'Ama'; break;
			case 'recipient-field': $payload['delivery_address']['recipient']['phone'] = [ 'secret' ]; break;
			case 'identity': $payload['delivery_location_identity'] = [ 'secret' ]; break;
		}
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw, '2' );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( $expected, $read->error );
		self::assertNull( $read->snapshot );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	/** @return iterable<string, array{string, string}> */
	public static function invalid_v2_contexts(): iterable {
		yield 'unsupported context contract' => [ 'context-version', 'version_mismatch' ];
		foreach ( [ 'context-array', 'matching-list', 'matching-field', 'address-field', 'recipient', 'recipient-field', 'identity' ] as $kind ) { yield $kind => [ $kind, 'partial' ]; }
	}

	public function test_optional_unknown_and_known_malformed_extensions_do_not_erase_valid_core_facts(): void {
		$payload = $this->line_payload();
		$payload['unrecognized_future_field'] = [ 'private' => 'unknown-private-field' ];
		$payload['extensions'] = [
			'unknown_future_kind' => [ 'version' => 19, 'required' => false, 'data' => [ 'private' => 'unknown-private-extension' ] ],
			'return_policy' => [ 'version' => 1, 'required' => false, 'data' => [ 'customer_text' => 'no recorded policy identity' ] ],
			'promise' => [ 'version' => 99, 'required' => false, 'data' => [ 'future' => 'unknown version' ] ],
		];
		$raw = (string) wp_json_encode( $payload );
		$item = $this->line_item( $raw );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( '', $read->error );
		self::assertSame( '15.0000', $read->snapshot?->quoted_amount );
		self::assertSame( 'malformed', $read->extensions?->get( 'return_policy' )->status );
		self::assertSame( 'ignored_optional', $read->extensions?->get( 'promise' )->status );
		self::assertSame( [], $read->extensions?->customer_facts() );
		$diagnostics = (string) wp_json_encode( $read->extensions?->diagnostics() );
		self::assertStringNotContainsString( 'unknown_future_kind', $diagnostics );
		self::assertStringNotContainsString( 'unknown-private', $diagnostics );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	public function test_recorded_optional_policy_remains_real_historical_text_and_detached(): void {
		$payload = $this->package_payload();
		$payload['extensions'] = [ 'return_policy' => [ 'version' => 1, 'required' => false, 'data' => [
			'reference' => [ 'id' => 'fixture.return-policy-7', 'version' => 'v3', 'content_hash' => hash( 'sha256', 'Recorded return policy text' ) ],
			'customer_text' => 'Recorded return policy text',
		] ] ];
		$raw = (string) wp_json_encode( $payload );
		$order = $this->order( $raw );
		$read = ( new OrderDeliverySnapshotReader() )->read_package( $order );
		self::assertSame( '', $read->error );
		self::assertSame( 'recorded', $read->extensions?->get( 'return_policy' )->status );
		self::assertSame( [ 'return_policy' => [ 'customer_text' => 'Recorded return policy text' ] ], $read->extensions?->customer_facts() );
		$copy = $read->extensions?->get( 'return_policy' )->facts?->internal_facts();
		$copy['customer_text'] = 'Current replacement must not change history';
		self::assertSame( 'Recorded return policy text', $read->extensions?->get( 'return_policy' )->facts?->internal_facts()['customer_text'] ?? null );
		self::assertSame( $raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
	}

	#[DataProvider( 'mandatory_extensions' )]
	public function test_unknown_or_uncertain_mandatory_semantics_never_pass_as_old_format( mixed $extensions ): void {
		$reader = new OrderDeliverySnapshotReader();
		$payload = $this->line_payload();
		$payload['extensions'] = $extensions;
		$line = $reader->read_line( $this->line_item( (string) wp_json_encode( $payload ) ) );
		self::assertSame( 'version_mismatch', $line->error );
		self::assertNull( $line->snapshot );
		self::assertFalse( $line->extensions?->required_semantics_supported() );
		$payload = $this->package_payload();
		$payload['extensions'] = $extensions;
		$package = $reader->read_package( $this->order( (string) wp_json_encode( $payload ) ) );
		self::assertSame( 'version_mismatch', $package->error );
		self::assertNull( $package->snapshot );
	}

	/** @return iterable<string, array{mixed}> */
	public static function mandatory_extensions(): iterable {
		yield 'unknown required' => [ [ 'future_kind' => [ 'version' => 1, 'required' => true, 'data' => [] ] ] ];
		yield 'known required writer is not activated' => [ [ 'return_policy' => [ 'version' => 1, 'required' => true, 'data' => [] ] ] ];
		yield 'nonboolean required' => [ [ 'return_policy' => [ 'version' => 1, 'required' => 'false', 'data' => [] ] ] ];
		yield 'required flag missing' => [ [ 'future_kind' => [ 'version' => 1, 'data' => [] ] ] ];
		yield 'extension null container' => [ null ];
		yield 'extension empty JSON array container' => [ [] ];
	}

	public function test_empty_extension_object_is_absent_not_empty_customer_policies(): void {
		$payload = $this->line_payload();
		$payload['extensions'] = new \stdClass();
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $this->line_item( (string) wp_json_encode( $payload ) ) );
		self::assertSame( '', $read->error );
		self::assertTrue( $read->extensions?->required_semantics_supported() );
		self::assertSame( [], $read->extensions?->customer_facts() );
		self::assertSame( 'not_recorded', $read->extensions?->get( 'quote_policy' )->status );
	}

	public function test_reader_and_historical_shipment_use_saved_facts_with_no_mutating_crud_or_live_product(): void {
		$line_raw = (string) wp_json_encode( $this->line_payload() );
		$package_raw = (string) wp_json_encode( $this->package_payload() );
		$item = $this->getMockBuilder( WC_Order_Item_Product::class )->setConstructorArgs( [ [ 'id' => 501, 'name' => 'Saved Widget', 'meta' => [
			OrderDeliverySnapshot::META_LINE_SNAPSHOT => $line_raw, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '1',
		] ] ] )->onlyMethods( [ 'update_meta_data', 'add_meta_data', 'delete_meta_data', 'save', 'get_product' ] )->getMock();
		foreach ( [ 'update_meta_data', 'add_meta_data', 'delete_meta_data', 'save', 'get_product' ] as $method ) { $item->expects( self::never() )->method( $method ); }
		$order = $this->getMockBuilder( WC_Order::class )->setConstructorArgs( [ [ 'id' => 91, 'items' => [ $item ], 'shipping_items' => [ new WC_Order_Item_Shipping( [
			'total' => '15.0000', 'method_id' => 'delivery_engine_selected_offer', 'meta' => [ 'cetech_de_group_id' => 'in_warehouse|delivery|1' ],
		] ) ], 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $package_raw, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '1' ] ] ] )->onlyMethods( [ 'update_meta_data', 'delete_meta_data', 'save' ] )->getMock();
		foreach ( [ 'update_meta_data', 'delete_meta_data', 'save' ] as $method ) { $order->expects( self::never() )->method( $method ); }
		$context = ( new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() ) )->from_order( $order );
		$plan = ( new HistoricalShipmentPlanner() )->plan( $context );
		self::assertTrue( $plan->ok );
		self::assertSame( 'Historical Standard', $plan->plans[0]->delivery_offer_public_label );
		self::assertSame( '15.0000', $plan->plans[0]->customer_paid_shipping_amount );
		self::assertSame( 2, $plan->plans[0]->rate_card_id );
		self::assertSame( $line_raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertSame( $package_raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
	}

	public function test_integrity_does_not_call_unknown_status_or_invalid_money_valid(): void {
		$integrity = new OrderDeliverySnapshotIntegrity();
		$unknown = new OrderDeliveryPackageSnapshot( '1', null, null, '15.00', 'GHS', null, 'unknown', '2026-09-01T00:00:00+00:00' );
		self::assertSame( 'partial', $integrity->classify_package( new OrderDeliveryPackageReadResult( true, $unknown, '', '1' ) ) );
		$invalid = new OrderDeliveryPackageSnapshot( '1', null, null, '-1.00', 'GHS', null, 'success', '2026-09-01T00:00:00+00:00' );
		self::assertSame( 'partial', $integrity->classify_package( new OrderDeliveryPackageReadResult( true, $invalid, '', '1' ) ) );
		$missing = new OrderDeliveryPackageSnapshot( '1', null, null, null, 'GHS', null, 'success', '2026-09-01T00:00:00+00:00' );
		self::assertSame( 'quote_missing', $integrity->classify_package( new OrderDeliveryPackageReadResult( true, $missing, '', '1' ) ) );
		$invalid_line = new OrderDeliveryLineSnapshot( '1', '1', 16, null, 'in_warehouse', 'delivery', 1, null, null, null, null, null, 1, 'GHS', 'not-money', 'quoted', null, null, '2026-09-01T00:00:00+00:00' );
		self::assertSame( 'partial', $integrity->classify_line( new OrderDeliveryLineReadResult( true, $invalid_line, '', '1' ) ) );
	}

	/** @return array<string, mixed> */
	private function v2_payload(): array {
		$ctx = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		return array_merge( $this->line_payload(), [
			'snapshot_version' => '2', 'variation_id' => 61, 'customer_context_version' => 1,
			'matching_location' => $ctx->matching_location?->toArray(), 'delivery_address' => $ctx->delivery_address?->toArray(),
			'matching_identity' => $ctx->matching_identity, 'delivery_location_identity' => $ctx->delivery_location_identity, 'pickup_location_id' => null,
		] );
	}

	/** @return array<string, mixed> */
	private function line_payload(): array {
		return [
			'contract_version' => '1',
			'snapshot_version' => '1',
			'product_id' => 16,
			'variation_id' => null,
			'fulfilment_availability' => 'in_warehouse',
			'fulfilment_choice' => 'delivery',
			'delivery_offer_id' => 1,
			'delivery_offer_public_label' => 'Historical Standard',
			'delivery_offer_public_description' => 'Recorded at placement',
			'estimate_text' => '3 days',
			'rule_id' => 41,
			'destination_zone_id' => 1,
			'quantity' => 2,
			'currency_code' => 'GHS',
			'quoted_amount' => '15.0000',
			'quote_status' => 'quoted',
			'rate_card_id' => 2,
			'rate_card_code' => 'HISTORICAL',
			'snapshotted_at' => '2026-09-01T00:00:00+00:00',
			'delivery_group_id' => 'in_warehouse|delivery|1',
		];
	}

	/** @return array<string, mixed> */
	private function package_payload(): array {
		return [
			'snapshot_version' => '1',
			'shipping_method_id' => 'delivery_engine_selected_offer',
			'shipping_method_label' => 'Historical Standard',
			'package_total_delivery_amount' => '15.0000',
			'currency_code' => 'GHS',
			'destination_zone_id' => 1,
			'quote_status' => 'success',
			'snapshotted_at' => '2026-09-01T00:00:00+00:00',
			'groups' => [ [
				'group_id' => 'in_warehouse|delivery|1',
				'shipping_method_id' => 'delivery_engine_selected_offer',
				'shipping_method_label' => 'Historical Standard',
				'package_total_delivery_amount' => '15.0000',
				'fulfilment_choice' => 'delivery',
				'is_pickup' => false,
				'display_index' => 1,
			] ],
		];
	}

	private function line_item( string $raw, mixed $version = '1' ): WC_Order_Item_Product {
		return new WC_Order_Item_Product( [
			'id' => 501,
			'name' => 'Historical Widget',
			'meta' => [
				OrderDeliverySnapshot::META_LINE_SNAPSHOT => $raw,
				OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => $version,
			],
		] );
	}

	private function order( string $raw, mixed $version = '1' ): WC_Order {
		return new WC_Order( [
			'id' => 91,
			'meta' => [
				OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $raw,
				OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => $version,
			],
		] );
	}
}
