<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\CustomerContext\CustomerFacingDeliveryPrice;
use CetechDeliveryEngine\Application\Selector\CustomerVisibleDeliveryOptionGate;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryFulfilmentCapabilities;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use PHPUnit\Framework\TestCase;

final class CustomerVisibleDeliveryOptionGateTest extends TestCase {

	public function test_capabilities_come_from_unfiltered_available_options(): void {
		$caps = ProductDeliveryFulfilmentCapabilities::from_options( [ $this->delivery( '2–3 business days' ), $this->pickup() ] );

		self::assertTrue( $caps['has_delivery'] );
		self::assertTrue( $caps['has_pickup'] );
		self::assertTrue( ProductDeliveryFulfilmentCapabilities::has_switch( $caps ) );
		self::assertSame( [ 'delivery', 'store_pickup' ], $caps['available_choices'] );
	}

	public function test_priced_delivery_with_estimate_remains_visible(): void {
		$option = $this->delivery( '1 business day' )->withCustomerPrice(
			CustomerFacingDeliveryPrice::from_quoted_amount( '10.0000', 'GHS', RateCardChargeType::FixedPerItem->value )
		);

		self::assertTrue( CustomerVisibleDeliveryOptionGate::is_selectable_pdp_card( $option ) );
		self::assertSame( [ $option ], CustomerVisibleDeliveryOptionGate::selectable_pdp_cards( [ $option ] ) );
	}

	public function test_priced_delivery_without_estimate_fails_closed(): void {
		$option = $this->delivery( null )->withCustomerPrice(
			CustomerFacingDeliveryPrice::from_quoted_amount( '10.0000', 'GHS', RateCardChargeType::FixedPerItem->value )
		);

		self::assertFalse( CustomerVisibleDeliveryOptionGate::is_selectable_pdp_card( $option ) );
		self::assertSame( [], CustomerVisibleDeliveryOptionGate::selectable_pdp_cards( [ $option ] ) );
	}

	public function test_does_not_fabricate_estimate_from_label(): void {
		$option = $this->delivery( null );

		self::assertNull( $option->estimate_text );
		self::assertStringContainsString( 'Same Day', (string) $option->delivery_offer_public_label );
		self::assertFalse( CustomerVisibleDeliveryOptionGate::has_real_estimate( $option ) );
	}

	public function test_pickup_without_delivery_estimate_remains_selectable(): void {
		$pickup = $this->pickup();

		self::assertTrue( CustomerVisibleDeliveryOptionGate::is_selectable_pdp_card( $pickup ) );
		self::assertSame( [ $pickup ], CustomerVisibleDeliveryOptionGate::selectable_pdp_cards( [ $this->delivery( null ), $pickup ] ) );
	}

	private function delivery( ?string $estimate ): ProductDeliveryOption {
		return new ProductDeliveryOption(
			null === $estimate ? 'in_store:delivery:11' : 'in_store:delivery:12',
			'in_store',
			'In Store',
			'delivery',
			'Delivery',
			null === $estimate ? 11 : 12,
			null === $estimate ? 'Same Day Delivery' : 'Standard Delivery',
			null,
			$estimate,
			true,
			null
		);
	}

	private function pickup(): ProductDeliveryOption {
		return new ProductDeliveryOption(
			'in_store:store_pickup:pickup',
			'in_store',
			'In Store',
			'store_pickup',
			'Store Pickup',
			null,
			'QA Accra Pickup',
			null,
			'Ready in 2 hours',
			true,
			null,
			'1',
			false,
			'QA Accra Pickup',
			'12 QA Independence Avenue',
			null,
			1
		);
	}
}
