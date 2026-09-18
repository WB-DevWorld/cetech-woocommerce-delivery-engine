<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\StorefrontGeographyEndpoint;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

final class CanonicalLocationResolverTest extends TestCase {

	public function test_woo_state_code_and_canonical_key_resolve_the_same_locality(): void {
		$geo      = new GhanaGeographyFixture();
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations );

		$from_woo = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', 'loc-accra' );
		self::assertTrue( $from_woo->hasCanonicalLocation() );
		self::assertSame( $geo->accra->id, $from_woo->location_id() );
		self::assertSame( 'canonical_key', $from_woo->resolution_source );

		$from_names = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', '' );
		self::assertTrue( $from_names->hasCanonicalLocation() );
		self::assertSame( $geo->accra->id, $from_names->location_id() );
	}

	public function test_unknown_canonical_key_does_not_fallback_to_city_name(): void {
		$geo      = new GhanaGeographyFixture();
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations );
		$resolved = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', 'not-a-real-key' );

		self::assertFalse( $resolved->hasCanonicalLocation() );
		self::assertSame( 'canonical_key_unknown', $resolved->resolution_source );
	}

	public function test_customer_item_omits_internal_ids_and_includes_woo_code(): void {
		$geo      = new GhanaGeographyFixture();
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations );
		$endpoint = new StorefrontGeographyEndpoint( $geo->locations, $resolver, new InMemoryGeographyPackRepository(), $geo->locations );
		$item     = $endpoint->customer_item( $geo->greater_accra );

		self::assertSame( 'loc-ga', $item['key'] );
		self::assertSame( 'Greater Accra', $item['name'] );
		self::assertSame( 'AA', $item['code'] );
		self::assertArrayNotHasKey( 'id', $item );
		self::assertArrayNotHasKey( 'parent', $item );
		self::assertArrayNotHasKey( 'supplier_id', $item );
		self::assertArrayNotHasKey( 'rate_card_id', $item );

		$public = $geo->accra->publicPayload();
		self::assertArrayNotHasKey( 'parent', $public );
		self::assertArrayHasKey( 'key', $public );
	}

	public function test_matching_location_country_only_remains_present(): void {
		$location = MatchingLocation::fromInput( [ 'country' => 'GH' ] );
		self::assertTrue( $location->isPresent() );
		self::assertSame( '', $location->city );
	}
}
