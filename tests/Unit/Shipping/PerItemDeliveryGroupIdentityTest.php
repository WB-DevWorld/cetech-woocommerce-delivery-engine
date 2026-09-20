<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;

final class PerItemDeliveryGroupIdentityTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function deliveryIntent( int $offer_id ): array {
		return [
			'fulfilment_availability' => 'in_warehouse',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'      => $offer_id,
		];
	}

	/**
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $item
	 *
	 * @return array<string, mixed>
	 */
	private function cart( array $intent, CustomerCartContext $context, bool $reselect = false ): array {
		$item = PerItemContextFixtures::cartItem( $intent, $context );
		if ( $reselect ) {
			$item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		}

		return $item;
	}

	public function test_same_offer_two_destinations_are_two_groups(): void {
		$a = $this->cart(
			$this->deliveryIntent( 1 ),
			PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() )
		);
		$b = $this->cart(
			$this->deliveryIntent( 1 ),
			PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliverySpintex() )
		);

		self::assertNotSame( DeliveryGroupIdentity::fromCartItem( $a ), DeliveryGroupIdentity::fromCartItem( $b ) );
		self::assertSame( DeliveryGroupIdentity::fromCartItem( $a ), DeliveryGroupIdentity::fromCartItem( $a ) );
	}

	public function test_same_destination_two_offers_are_two_groups(): void {
		$address = PerItemContextFixtures::deliveryEastLegon();
		$a       = $this->cart( $this->deliveryIntent( 1 ), PerItemContextFixtures::deliveryContext( 1, $address ) );
		$b       = $this->cart( $this->deliveryIntent( 2 ), PerItemContextFixtures::deliveryContext( 2, $address ) );

		self::assertNotSame( DeliveryGroupIdentity::fromCartItem( $a ), DeliveryGroupIdentity::fromCartItem( $b ) );
	}

	public function test_different_pickups_are_two_groups(): void {
		$intent = [
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'store_pickup',
			'delivery_offer_id'      => null,
		];
		$a = $this->cart( $intent, CustomerCartContext::pickup( 1 ) );
		$b = $this->cart( $intent, CustomerCartContext::pickup( 2 ) );

		self::assertNotSame( DeliveryGroupIdentity::fromCartItem( $a ), DeliveryGroupIdentity::fromCartItem( $b ) );
		self::assertTrue( DeliveryGroupIdentity::is_pickup_group( (string) DeliveryGroupIdentity::fromCartItem( $a ) ) );
		self::assertStringContainsString( '|p1|pickup', (string) DeliveryGroupIdentity::fromCartItem( $a ) );
		self::assertStringContainsString( '|p2|pickup', (string) DeliveryGroupIdentity::fromCartItem( $b ) );
	}

	public function test_pickup_group_is_not_a_delivery_charge_group(): void {
		$intent = [
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'store_pickup',
		];
		$id = DeliveryGroupIdentity::fromCartItem( $this->cart( $intent, CustomerCartContext::pickup( 4 ) ) );

		self::assertNotNull( $id );
		self::assertTrue( DeliveryGroupIdentity::is_pickup_group( $id ) );
		self::assertSame( 'pickup', DeliveryGroupIdentity::parse( $id )['destination'] );
	}

	public function test_runtime_reselect_never_becomes_historical_paid_group(): void {
		$ctx    = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$intent = $this->deliveryIntent( 1 );
		$runtime = DeliveryGroupIdentity::fromCartItem( $this->cart( $intent, $ctx, true ) );
		$historical = DeliveryGroupIdentity::forHistorical( $intent, $ctx );

		self::assertNotNull( $runtime );
		self::assertNotNull( $historical );
		self::assertTrue( DeliveryGroupIdentity::has_runtime_reselect( $runtime ) );
		self::assertStringContainsString( '|reselect', $runtime );
		self::assertStringNotContainsString( 'reselect', $historical );
		self::assertSame( $historical, DeliveryGroupIdentity::stripRuntimeSuffix( $runtime ) );
		self::assertDoesNotMatchRegularExpression( '/Boundary|Ama|0244/i', $historical );
	}

	public function test_builder_and_calculator_share_the_same_identity(): void {
		$item = $this->cart(
			$this->deliveryIntent( 1 ),
			PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() )
		);

		self::assertSame(
			DeliveryGroupIdentity::fromCartItem( $item ),
			DeliveryGroupIdentity::fromIntentAndContext( $this->deliveryIntent( 1 ), CustomerCartContext::fromCartItem( $item ), false )
		);
		self::assertNotSame(
			DeliveryGroupIdentity::fromIntent( $this->deliveryIntent( 1 ) ),
			DeliveryGroupIdentity::fromCartItem( $item )
		);
	}

	public function test_incomplete_pickup_fails_closed(): void {
		$intent = [
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'store_pickup',
		];

		self::assertNull(
			DeliveryGroupIdentity::fromCartItem( $this->cart( $intent, CustomerCartContext::pickup( null ) ) )
		);
	}

	public function test_worst_case_length_fits_schema_five_column(): void {
		self::assertLessThanOrEqual( DeliveryGroupIdentity::COLUMN_LENGTH, DeliveryGroupIdentity::worstCaseLength( true ) );
		self::assertSame( 6, (int) \CetechDeliveryEngine\Core\Versioning\SchemaVersion::TARGET );
	}
}
