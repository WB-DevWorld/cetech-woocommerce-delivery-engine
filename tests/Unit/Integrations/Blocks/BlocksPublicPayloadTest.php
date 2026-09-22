<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload;
use PHPUnit\Framework\TestCase;

final class BlocksPublicPayloadTest extends TestCase {

	public function test_cart_item_payload_exposes_public_selection_only(): void {
		$cart_item = [
			'product_id'   => 11,
			'variation_id' => 0,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => [
				'contract_version'         => '1',
				'product_id'               => 11,
				'variation_id'             => null,
				'target_type'              => 'product',
				'target_id'                => 11,
				'display_key'              => 'in_store:delivery:44',
				'fulfilment_availability'  => 'in_store',
				'fulfilment_choice'        => 'delivery',
				'delivery_offer_id'        => 44,
				'rule_id'                  => null,
				'issued_at'                => '2026-08-31T00:00:00+00:00',
			],
			CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
				'delivery_offer_public_label' => 'Standard Delivery',
				'estimate_text'               => '2–4 business days',
				'pickup_location_label'       => null,
				'pickup_address'              => null,
				'pickup_instructions'         => null,
			],
			'supplier_id' => 99,
			'origin_id'   => 12,
		];

		$payload = BlocksPublicPayload::cart_item( $cart_item );

		self::assertSame( 'delivery', $payload['fulfilment_choice'] );
		self::assertSame( 'in_store', $payload['fulfilment_availability'] );
		self::assertSame( 'Standard Delivery', $payload['delivery_option_label'] );
		self::assertSame( '2–4 business days', $payload['estimate_text'] );
		self::assertFalse( $payload['is_pickup'] );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
		self::assertArrayNotHasKey( 'supplier_id', $payload );
		self::assertArrayNotHasKey( 'origin_id', $payload );
		self::assertArrayNotHasKey( 'delivery_offer_id', $payload );
	}

	public function test_pickup_package_does_not_use_customer_shipping_destination(): void {
		$package = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'               => true,
				'group_id'              => 'in_store|store_pickup|pickup',
				'is_pickup'             => true,
				'offer_public_label'    => 'Store pickup',
				'pickup_location_label' => 'Main showroom',
				'pickup_address'        => '12 Harbour Street',
				'estimate_text'         => 'Ready in 2 hours',
				'supplier_id'           => 7,
			],
		];

		$payload = BlocksPublicPayload::package( $package, 0 );

		self::assertTrue( $payload['is_pickup'] );
		self::assertTrue( $payload['managed'] );
		self::assertFalse( $payload['uses_customer_shipping_destination'] );
		self::assertTrue( $payload['charge_is_zero'] );
		self::assertSame( 'Main showroom', $payload['pickup_location_label'] );
		self::assertSame( '12 Harbour Street', $payload['pickup_address'] );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
		self::assertArrayNotHasKey( 'group_id', $payload );
		self::assertArrayNotHasKey( 'supplier_id', $payload );
	}

	public function test_mixed_delivery_and_pickup_packages_remain_distinct(): void {
		$delivery = BlocksPublicPayload::package(
			[
				DeliveryGroupIdentity::PACKAGE_META_KEY => [
					'managed'            => true,
					'is_pickup'          => false,
					'offer_public_label' => 'Standard Delivery',
					'estimate_text'      => '2–4 business days',
					'supplier_id'        => 3,
				],
			],
			0
		);
		$pickup = BlocksPublicPayload::package(
			[
				DeliveryGroupIdentity::PACKAGE_META_KEY => [
					'managed'               => true,
					'is_pickup'             => true,
					'offer_public_label'    => 'Store pickup',
					'pickup_location_label' => 'Main showroom',
					'pickup_address'        => '12 Harbour Street',
					'estimate_text'         => 'Ready in 2 hours',
					'supplier_id'           => 3,
				],
			],
			1
		);

		self::assertFalse( $delivery['is_pickup'] );
		self::assertTrue( $delivery['uses_customer_shipping_destination'] );
		self::assertFalse( $delivery['charge_is_zero'] );
		self::assertTrue( $pickup['is_pickup'] );
		self::assertFalse( $pickup['uses_customer_shipping_destination'] );
		self::assertTrue( $pickup['charge_is_zero'] );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $delivery ) );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $pickup ) );
	}

	public function test_international_air_and_sea_choice_labels_are_public_only(): void {
		$air = BlocksPublicPayload::cart_item(
			[
				'product_id' => 21,
				CartDeliverySelectionCapture::CART_SELECTION_KEY => [
					'contract_version'         => '1',
					'product_id'               => 21,
					'variation_id'             => null,
					'target_type'              => 'product',
					'target_id'                => 21,
					'display_key'              => 'international:delivery:88',
					'fulfilment_availability'  => 'international',
					'fulfilment_choice'        => 'delivery',
					'delivery_offer_id'        => 88,
					'rule_id'                  => null,
					'issued_at'                => '2026-08-31T00:00:00+00:00',
				],
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
					'delivery_offer_public_label' => 'Air shipping',
					'estimate_text'               => '5–8 business days',
				],
			]
		);
		$sea = BlocksPublicPayload::cart_item(
			[
				'product_id' => 21,
				CartDeliverySelectionCapture::CART_SELECTION_KEY => [
					'contract_version'         => '1',
					'product_id'               => 21,
					'variation_id'             => null,
					'target_type'              => 'product',
					'target_id'                => 21,
					'display_key'              => 'international:delivery:89',
					'fulfilment_availability'  => 'international',
					'fulfilment_choice'        => 'delivery',
					'delivery_offer_id'        => 89,
					'rule_id'                  => null,
					'issued_at'                => '2026-08-31T00:00:00+00:00',
				],
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
					'delivery_offer_public_label' => 'Sea shipping',
					'estimate_text'               => '4–8 weeks',
				],
			]
		);

		self::assertSame( 'international', $air['fulfilment_availability'] );
		self::assertSame( 'Air shipping', $air['delivery_option_label'] );
		self::assertSame( 'Sea shipping', $sea['delivery_option_label'] );
		self::assertFalse( $air['is_pickup'] );
		self::assertArrayNotHasKey( 'display_key', $air );
		self::assertArrayNotHasKey( 'delivery_offer_id', $sea );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $air ) );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $sea ) );
	}

	public function test_in_warehouse_standard_delivery_is_not_pickup(): void {
		$payload = BlocksPublicPayload::package(
			[
				DeliveryGroupIdentity::PACKAGE_META_KEY => [
					'managed'            => true,
					'is_pickup'          => false,
					'offer_public_label' => 'Local delivery',
					'estimate_text'      => 'Same day',
					'origin_id'          => 4,
				],
			],
			0
		);
		$line = BlocksPublicPayload::cart_item(
			[
				'product_id' => 31,
				CartDeliverySelectionCapture::CART_SELECTION_KEY => [
					'contract_version'         => '1',
					'product_id'               => 31,
					'variation_id'             => null,
					'target_type'              => 'product',
					'target_id'                => 31,
					'display_key'              => 'in_warehouse:delivery:12',
					'fulfilment_availability'  => 'in_warehouse',
					'fulfilment_choice'        => 'delivery',
					'delivery_offer_id'        => 12,
					'rule_id'                  => null,
					'issued_at'                => '2026-08-31T00:00:00+00:00',
				],
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
					'delivery_offer_public_label' => 'Local delivery',
					'estimate_text'               => 'Same day',
				],
			]
		);

		self::assertSame( 'in_warehouse', $line['fulfilment_availability'] );
		self::assertFalse( $payload['is_pickup'] );
		self::assertTrue( $payload['uses_customer_shipping_destination'] );
		self::assertFalse( $payload['charge_is_zero'] );
		self::assertArrayNotHasKey( 'origin_id', $payload );
	}

	public function test_strip_forbidden_removes_internal_keys(): void {
		$clean = BlocksPublicPayload::strip_forbidden(
			[
				'label'        => 'Standard Delivery',
				'group_id'     => 'secret',
				'cetech_de_group_id' => 'secret',
				'supplier'     => 'hidden',
				'nested'       => [ 'origin' => 'x', 'ok' => 'yes' ],
			]
		);

		self::assertSame( 'Standard Delivery', $clean['label'] );
		self::assertArrayNotHasKey( 'group_id', $clean );
		self::assertArrayNotHasKey( 'supplier', $clean );
		self::assertSame( [ 'ok' => 'yes' ], $clean['nested'] );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $clean ) );
	}

	public function test_needs_reselection_hides_stale_labels_and_exposes_recoverable_options(): void {
		$cart_item = [
			'key'          => 'abc123',
			'product_id'   => 11,
			'variation_id' => 0,
			'data'         => new \WC_Product( [ 'id' => 11, 'type' => 'simple', 'name' => 'Cable' ] ),
			CartDeliverySelectionCapture::CART_SELECTION_KEY => [
				'contract_version'         => '1',
				'product_id'               => 11,
				'variation_id'             => null,
				'target_type'              => 'product',
				'target_id'                => 11,
				'display_key'              => 'in_store:delivery:44',
				'fulfilment_availability'  => 'in_store',
				'fulfilment_choice'        => 'delivery',
				'delivery_offer_id'        => 44,
				'rule_id'                  => null,
				'issued_at'                => '2026-08-31T00:00:00+00:00',
			],
			CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
				'delivery_offer_public_label' => 'Stale Option A',
				'estimate_text'               => 'yesterday',
			],
			CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY => true,
		];

		$payload = BlocksPublicPayload::cart_item( $cart_item, null, null, 'abc123' );

		self::assertTrue( $payload['needs_reselection'] );
		self::assertFalse( $payload['selection_valid'] );
		self::assertNull( $payload['delivery_option_label'] );
		self::assertNull( $payload['estimate_text'] );
		self::assertSame( 'Cable', $payload['product_name'] );
		self::assertSame( 'abc123', $payload['cart_item_key'] );
		self::assertNull( $payload['ui_anchor'] );
		self::assertFalse( $payload['can_edit_context'] );
		self::assertStringContainsString( 'Cable', (string) $payload['reselection_message'] );
		self::assertStringContainsString( 'do not need to remove', strtolower( (string) $payload['reselection_message'] ) );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
	}
}
