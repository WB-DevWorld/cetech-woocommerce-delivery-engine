<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\CustomerContext\LocationIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use PHPUnit\Framework\TestCase;

final class CustomerContextDomainTest extends TestCase {

	public function test_matching_location_normalization(): void {
		$location = PerItemContextFixtures::matchingAccra();

		self::assertSame( 'GH', $location->country );
		self::assertSame( 'GH', $location->country_identity );
		self::assertSame( 'AA', $location->state_identity );
		self::assertSame( 'accra', $location->city_identity );
		self::assertSame( 'GA-123', $location->postcode );
		self::assertSame( 'Accra', $location->city );
	}

	public function test_matching_identity_is_deterministic(): void {
		$a = MatchingLocation::fromInput( [ 'country' => 'gh', 'state' => 'aa', 'city' => 'Accra', 'postcode' => 'ga-123' ] );
		$b = MatchingLocation::fromInput( [ 'postcode' => 'GA-123', 'city' => '  Accra  ', 'state' => 'AA', 'country' => 'GH' ] );

		self::assertSame( $a->identity(), $b->identity() );
		self::assertSame( 64, strlen( $a->identity() ) );
	}

	public function test_delivery_address_normalization(): void {
		$address = PerItemContextFixtures::deliveryEastLegon( '  12   Boundary Rd ' );

		self::assertSame( '12 Boundary Rd', $address->address_1 );
		self::assertSame( '12 boundary rd', $address->address_1_identity );
		self::assertTrue( $address->isComplete() );
	}

	public function test_delivery_location_identity_is_deterministic(): void {
		$a = DeliveryAddress::fromInput( PerItemContextFixtures::eastLegonAddress() );
		$b = DeliveryAddress::fromInput(
			array_merge(
				PerItemContextFixtures::eastLegonAddress(),
				[ 'address_1' => '12 BOUNDARY RD', 'first_name' => 'Different' ]
			)
		);

		self::assertSame( $a->identity(), $b->identity() );
	}

	public function test_name_does_not_alter_destination_identity(): void {
		$a = DeliveryAddress::fromInput( PerItemContextFixtures::eastLegonAddress() );
		$b = DeliveryAddress::fromInput( array_merge( PerItemContextFixtures::eastLegonAddress(), [ 'first_name' => 'Kojo', 'last_name' => 'Boateng' ] ) );

		self::assertSame( $a->identity(), $b->identity() );
	}

	public function test_phone_does_not_alter_destination_identity(): void {
		$a = DeliveryAddress::fromInput( PerItemContextFixtures::eastLegonAddress() );
		$b = DeliveryAddress::fromInput( array_merge( PerItemContextFixtures::eastLegonAddress(), [ 'phone' => '999' ] ) );

		self::assertSame( $a->identity(), $b->identity() );
	}

	public function test_company_does_not_alter_destination_identity(): void {
		$a = DeliveryAddress::fromInput( PerItemContextFixtures::eastLegonAddress() );
		$b = DeliveryAddress::fromInput( array_merge( PerItemContextFixtures::eastLegonAddress(), [ 'company' => 'Other Ltd' ] ) );

		self::assertSame( $a->identity(), $b->identity() );
	}

	public function test_street_change_alters_delivery_location_identity(): void {
		$a = PerItemContextFixtures::deliveryEastLegon( '12 Boundary Rd' );
		$b = PerItemContextFixtures::deliveryEastLegon( '99 Boundary Rd' );

		self::assertNotSame( $a->identity(), $b->identity() );
	}

	public function test_pickup_package_destination_is_empty(): void {
		$ctx = CustomerCartContext::pickup( 4 );
		$dest = $ctx->toWcPackageDestination();
		self::assertSame( '', $dest['country'] );
		self::assertSame( '', $dest['address'] );
	}

	public function test_delivery_package_destination_uses_context_not_street_for_matching_fields(): void {
		$address = PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' );
		$dest    = CustomerCartContext::delivery( 10, $address->matching, $address )->toWcPackageDestination();
		self::assertSame( 'GH', $dest['country'] );
		self::assertSame( 'Accra', $dest['city'] );
		self::assertSame( '12 Boundary Rd', $dest['address'] );
	}

	public function test_city_and_postcode_change_alters_matching_identity(): void {
		$accra   = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => 'GA-123' ] );
		$spintex = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Spintex', 'postcode' => 'GA-111' ] );

		self::assertNotSame( $accra->identity(), $spintex->identity() );
	}

	public function test_hashes_and_group_ids_contain_no_raw_pii(): void {
		$address = PerItemContextFixtures::deliveryEastLegon();
		$hash    = $address->identity();
		$context = CustomerCartContext::delivery( 1, $address->matching, $address );

		self::assertFalse( LocationIdentity::containsRawPii( $hash ) );
		self::assertStringNotContainsString( 'Boundary', $hash );
		self::assertStringNotContainsString( 'Ama', $hash );
		self::assertStringNotContainsString( '0244', $hash );
		self::assertStringNotContainsString( 'Boundary', $context->cartLocationIdentitySegment() );
		self::assertStringStartsWith( 'd', $context->cartLocationIdentitySegment() );
	}
}
