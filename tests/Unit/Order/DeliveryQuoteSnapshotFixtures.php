<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;

/** Typed synthetic captured facts, never a native Woo or sealed-placement claim. */
final class DeliveryQuoteSnapshotFixtures {
	public static function envelope(): DeliveryQuoteSnapshotEnvelope {
		$groups = QuoteFixtures::context()->private_facts()['groups']; $groups[0]['service_id'] = $groups[0]['offer_id'];
		$context = QuoteFixtures::context( [ 'groups' => $groups ] ); $facts = QuoteFixtures::terms()->private_facts();
		$facts['groups'][0]['provider'] = DeliveryQuoteSnapshotEnvelope::PROFILE;
		$facts['groups'][0]['promotion']['provider'] = [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ];
		$terms = QuoteTerms::from_array( $facts ); $id = QuoteId::from_string( '11111111-1111-4111-8111-111111111111' );
		$header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $terms, QuoteFixtures::time(), [ 'issue' => hash( 'sha256', 'issue' ), 'accept' => hash( 'sha256', 'accept' ), 'invalidate' => hash( 'sha256', 'invalidate' ) ], 'legacy_fixed_base_v1', 1, QuoteFixtures::reference( $id ) );
		return DeliveryQuoteSnapshotEnvelope::from_captured( $header, $context, $terms, QuoteFixtures::time()->plus_seconds( 10 ), QuoteId::from_string( '22222222-2222-4222-8222-222222222222' ), hash( 'sha256', 'captured-context' ) );
	}
	public static function line(): array {
		return [ 'contract_version' => '1', 'snapshot_version' => '1', 'product_id' => 16, 'variation_id' => null, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 1, 'delivery_offer_public_label' => 'Historical Standard', 'delivery_offer_public_description' => 'Recorded at placement', 'estimate_text' => '3 days', 'rule_id' => 41, 'destination_zone_id' => 1, 'quantity' => 2, 'currency_code' => 'GHS', 'quoted_amount' => '12.5000', 'quote_status' => 'quoted', 'rate_card_id' => 2, 'rate_card_code' => 'HISTORICAL', 'snapshotted_at' => '2026-10-07T05:00:10+00:00', 'delivery_group_id' => 'in_warehouse|delivery|1' ];
	}
	public static function package(): array {
		return [ 'snapshot_version' => '1', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Historical Standard', 'package_total_delivery_amount' => '12.5000', 'currency_code' => 'GHS', 'destination_zone_id' => 1, 'quote_status' => 'success', 'snapshotted_at' => '2026-10-07T05:00:10+00:00', 'groups' => [ [ 'group_id' => 'in_warehouse|delivery|1', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Historical Standard', 'package_total_delivery_amount' => '12.5000', 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => 1 ] ] ];
	}
}
