<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;

final class CustomerStorefrontCopyTest extends TestCase {

	public function test_incomplete_address_uses_item_count(): void {
		self::assertSame(
			'Complete the delivery address for 1 item before placing your order.',
			CustomerStorefrontCopy::incomplete_address( 1 )
		);
		self::assertSame(
			'Complete the delivery address for 2 items before placing your order.',
			CustomerStorefrontCopy::incomplete_address( 2 )
		);
	}

	public function test_delivery_to_and_cart_summary_are_compact(): void {
		self::assertSame( 'Delivery to Accra', CustomerStorefrontCopy::delivery_to( 'Accra' ) );

		$summary = CustomerStorefrontCopy::cart_line_summary(
			'delivery',
			[
				'delivery_offer_public_label' => 'QA Express Local',
				'estimate_text'               => 'Estimated 1–2 business days',
			],
			'Accra'
		);

		self::assertSame( 'QA Express Local', $summary['title'] );
		self::assertSame( 'Accra · 1–2 business days', $summary['meta'] );
		self::assertFalse( $summary['is_pickup'] );
	}

	public function test_pickup_summary_does_not_repeat_delivery_labels(): void {
		$summary = CustomerStorefrontCopy::cart_line_summary(
			'store_pickup',
			[
				'pickup_location_label' => 'QA Accra Pickup',
				'estimate_text'           => 'Ready in 2 hours',
			],
			''
		);

		self::assertSame( 'Store Pickup', $summary['kicker'] );
		self::assertSame( 'QA Accra Pickup', $summary['title'] );
		self::assertSame( 'Ready in 2 hours', $summary['meta'] );
	}

	public function test_delivery_plan_groups_by_locality(): void {
		$accra = PerItemContextFixtures::cartItem(
			[
				'contract_version'        => '1',
				'product_id'              => 101,
				'variation_id'            => 0,
				'target_type'              => 'product',
				'target_id'                => 101,
				'display_key'              => 'in_warehouse:delivery:10',
				'fulfilment_availability'  => 'in_warehouse',
				'fulfilment_choice'        => 'delivery',
				'delivery_offer_id'       => 10,
				'rule_id'                 => null,
				'issued_at'               => '2026-09-03T00:00:00+00:00',
			],
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) )
		);
		$accra[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] = [
			'delivery_offer_public_label' => 'QA Express Local',
			'estimate_text'               => '1–2 business days',
			'fulfilment_choice_label'     => 'Delivery',
		];
		$accra['data'] = new class {
			public function get_name(): string {
				return 'QA In Store Lamp';
			}
		};

		$plan = CustomerStorefrontCopy::delivery_plan( [ 'a' => $accra ] );

		self::assertCount( 1, $plan );
		self::assertSame( 'Accra', $plan[0]['heading'] );
		self::assertSame( 'QA In Store Lamp', $plan[0]['lines'][0]['product'] );
		self::assertStringContainsString( 'QA Express Local', $plan[0]['lines'][0]['estimate'] );
	}
}
