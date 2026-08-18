<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentIdentity;
use PHPUnit\Framework\TestCase;

final class ShipmentIdentityTest extends TestCase {

	public function test_idempotency_key_is_order_and_group(): void {
		$identity = new ShipmentIdentity( 39724, 'international|delivery|12' );

		self::assertSame( '39724|international|delivery|12', $identity->idempotency_key() );
		self::assertSame(
			'39724|international|delivery|12',
			ShipmentIdentity::key( 39724, 'international|delivery|12' )
		);
	}

	public function test_create_binds_identity_key(): void {
		$shipment = Shipment::create( 10, 'air|delivery|4' );

		self::assertSame( '10|air|delivery|4', $shipment->idempotency_key );
		self::assertSame( ShipmentIdentity::stable_shipment_number( 10, 'air|delivery|4' ), $shipment->shipment_number );
		self::assertSame( 10, $shipment->identity()->order_id );
	}

	public function test_constructor_rejects_mismatched_idempotency_key(): void {
		$shipment = Shipment::create( 10, 'air|delivery|4' );
		$values   = array_values( get_object_vars( $shipment ) );
		$values[3] = 'wrong-key';

		$this->expectException( \InvalidArgumentException::class );

		new Shipment( ...$values );
	}
}
