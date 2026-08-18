<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use PHPUnit\Framework\TestCase;

final class ShipmentStatusTest extends TestCase {

	public function test_machine_codes_are_stable(): void {
		self::assertSame(
			[
				'awaiting_fulfilment',
				'processing',
				'dispatched',
				'in_transit',
				'delayed',
				'delivered',
				'cancelled',
			],
			ShipmentStatus::values()
		);
	}

	public function test_comparisons_use_machine_codes_not_labels(): void {
		self::assertSame( 'dispatched', ShipmentStatus::Dispatched->value );
		self::assertNotSame( ShipmentStatus::Dispatched->label(), ShipmentStatus::Dispatched->value );
		self::assertSame( ShipmentStatus::Dispatched, ShipmentStatus::tryFromMachineCode( 'dispatched' ) );
		self::assertNull( ShipmentStatus::tryFromMachineCode( 'Dispatched' ) );
		self::assertNull( ShipmentStatus::tryFromMachineCode( __( 'Dispatched', 'cetech-woocommerce-delivery-engine' ) ) );
		self::assertNull( ShipmentStatus::tryFromMachineCode( 'Awaiting fulfilment' ) );
	}

	public function test_labels_are_presentation_only(): void {
		foreach ( ShipmentStatus::cases() as $status ) {
			self::assertNotSame( $status->label(), $status->value );
			self::assertDoesNotMatchRegularExpression( '/\s/', $status->value );
		}
	}
}
