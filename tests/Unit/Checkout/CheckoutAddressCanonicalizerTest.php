<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Checkout;

use CetechDeliveryEngine\Application\Checkout\CheckoutAddressCanonicalizer;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

final class CheckoutAddressCanonicalizerTest extends TestCase {

	public function test_woo_gh_aa_accra_resolves_to_fixture_accra_pplc(): void {
		$geo     = new GhanaGeographyFixture();
		$result  = $this->canonicalizer( $geo )->canonicalize( $this->woo_accra() );

		self::assertSame( $geo->accra->location_key, $result['canonical_location_key'] );
		self::assertSame( 'Accra', $result['city'] );
		self::assertSame( 'AA', $result['state'] );
		self::assertSame( 'QA Checkout Street Accra', $result['address_1'] );
		self::assertSame( GeographyLocationType::Locality, $geo->accra->location_type );
	}

	public function test_woo_state_code_and_label_resolve_consistently(): void {
		$geo        = new GhanaGeographyFixture();
		$canonicalizer = $this->canonicalizer( $geo );
		$from_code  = $canonicalizer->canonicalize( $this->woo_accra( [ 'state' => 'AA' ] ) );
		$from_label = $canonicalizer->canonicalize( $this->woo_accra( [ 'state' => 'Greater Accra' ] ) );

		self::assertSame( $geo->accra->location_key, $from_code['canonical_location_key'] );
		self::assertSame( $geo->accra->location_key, $from_label['canonical_location_key'] );
		self::assertSame( 'AA', $from_code['state'] );
		self::assertSame( 'Greater Accra', $from_label['state'] );
	}

	public function test_exact_accra_under_greater_accra_does_not_select_homonym(): void {
		$geo = new GhanaGeographyFixture();
		$ashanti_accra = $geo->locations->seed(
			'GH',
			GeographyLocationType::Locality,
			'Accra',
			$geo->ashanti->id,
			null,
			'loc-accra-ashanti'
		);
		$result = $this->canonicalizer( $geo )->canonicalize( $this->woo_accra() );

		self::assertSame( $geo->accra->location_key, $result['canonical_location_key'] );
		self::assertNotSame( $ashanti_accra->location_key, $result['canonical_location_key'] );
	}

	public function test_typo_acccra_does_not_resolve(): void {
		$result = $this->canonicalizer( new GhanaGeographyFixture() )->canonicalize(
			$this->woo_accra( [ 'city' => 'Acccra' ] )
		);

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertSame( 'Acccra', $result['city'] );
	}

	public function test_unknown_town_does_not_resolve(): void {
		$result = $this->canonicalizer( new GhanaGeographyFixture() )->canonicalize(
			$this->woo_accra( [ 'city' => 'Unknown Town' ] )
		);

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertSame( 'Unknown Town', $result['city'] );
	}

	public function test_ambiguous_exact_locality_does_not_resolve(): void {
		$geo = new GhanaGeographyFixture();
		$geo->locations->seed(
			'GH',
			GeographyLocationType::Locality,
			'Accra',
			$geo->greater_accra->id,
			null,
			'loc-accra-duplicate'
		);
		$result = $this->canonicalizer( $geo )->canonicalize( $this->woo_accra() );

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertSame( 'Accra', $result['city'] );
	}

	public function test_city_supplied_but_only_region_resolves_does_not_attach_false_key(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$ghana     = $locations->seed( 'GH', GeographyLocationType::Country, 'Ghana', null, null, 'loc-gh' );
		$region    = $locations->seed( 'GH', GeographyLocationType::Administrative, 'Greater Accra', $ghana->id, 1, 'loc-ga' );
		$locations->add_alias( $region->id, 'AA', 'aa' );
		$result = $this->canonicalizer_from( $locations, $this->usable_pack() )->canonicalize( $this->woo_accra() );

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertNotSame( $region->location_key, $result['canonical_location_key'] );
		self::assertSame( 'Accra', $result['city'] );
	}

	public function test_city_supplied_but_only_country_resolves_does_not_attach_false_key(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$ghana     = $locations->seed( 'GH', GeographyLocationType::Country, 'Ghana', null, null, 'loc-gh' );
		$result    = $this->canonicalizer_from( $locations, $this->usable_pack() )->canonicalize(
			$this->woo_accra( [ 'state' => '' ] )
		);

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertNotSame( $ghana->location_key, $result['canonical_location_key'] );
		self::assertSame( 'Accra', $result['city'] );
	}

	public function test_no_usable_pack_keeps_legacy_text_address(): void {
		$geo    = new GhanaGeographyFixture();
		$result = $this->canonicalizer( $geo, new InMemoryGeographyPackRepository() )->canonicalize( $this->woo_accra() );

		self::assertSame( '', $result['canonical_location_key'] );
		self::assertSame( 'GH', $result['country'] );
		self::assertSame( 'AA', $result['state'] );
		self::assertSame( 'Accra', $result['city'] );
		self::assertSame( 'QA Checkout Street Accra', $result['address_1'] );
	}

	public function test_valid_incoming_canonical_key_is_retained(): void {
		$geo    = new GhanaGeographyFixture();
		$result = $this->canonicalizer( $geo )->canonicalize(
			$this->woo_accra( [ 'canonical_location_key' => $geo->accra->location_key ] )
		);

		self::assertSame( $geo->accra->location_key, $result['canonical_location_key'] );
	}

	public function test_invalid_and_mismatching_canonical_keys_are_rejected(): void {
		$geo          = new GhanaGeographyFixture();
		$canonicalizer = $this->canonicalizer( $geo );

		$unknown = $canonicalizer->canonicalize(
			$this->woo_accra( [ 'canonical_location_key' => 'not-a-real-key', 'city' => 'Unknown Town' ] )
		);
		self::assertSame( '', $unknown['canonical_location_key'] );

		$mismatch = $canonicalizer->canonicalize(
			$this->woo_accra( [ 'canonical_location_key' => $geo->tema->location_key, 'city' => 'Accra' ] )
		);
		self::assertNotSame( $geo->tema->location_key, $mismatch['canonical_location_key'] );
		self::assertSame( $geo->accra->location_key, $mismatch['canonical_location_key'] );

		$region = $canonicalizer->canonicalize(
			$this->woo_accra( [ 'canonical_location_key' => $geo->greater_accra->location_key ] )
		);
		self::assertNotSame( $geo->greater_accra->location_key, $region['canonical_location_key'] );
		self::assertSame( $geo->accra->location_key, $region['canonical_location_key'] );
	}

	public function test_plugin_wires_canonicalizer_into_checkout_address_policy(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Bootstrap/Plugin.php' );

		self::assertStringContainsString( 'CheckoutAddressCanonicalizer::class', $plugin );
		self::assertStringContainsString( 'CanonicalLocationResolver::class', $plugin );
		self::assertStringContainsString( 'WooCommerceStateCatalogInterface::class', $plugin );
		self::assertStringContainsString( 'new CheckoutAddressPolicy(', $plugin );
	}

	/**
	 * @param array<string, mixed> $override
	 *
	 * @return array<string, mixed>
	 */
	private function woo_accra( array $override = [] ): array {
		return array_merge(
			[
				'country'    => 'GH',
				'state'      => 'AA',
				'city'       => 'Accra',
				'postcode'   => '',
				'address_1'  => 'QA Checkout Street Accra',
				'address_2'  => '',
				'first_name' => '',
				'last_name'  => '',
				'company'    => '',
				'phone'      => '',
			],
			$override
		);
	}

	private function canonicalizer( GhanaGeographyFixture $geo, ?InMemoryGeographyPackRepository $packs = null ): CheckoutAddressCanonicalizer {
		return $this->canonicalizer_from( $geo->locations, $packs ?? $this->usable_pack() );
	}

	private function canonicalizer_from(
		InMemoryCanonicalLocationRepository $locations,
		InMemoryGeographyPackRepository $packs
	): CheckoutAddressCanonicalizer {
		return new CheckoutAddressCanonicalizer(
			new CanonicalLocationResolver( $locations, $locations, $packs ),
			$this->catalog()
		);
	}

	private function usable_pack(): InMemoryGeographyPackRepository {
		$packs = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'      => GeographyPackStatus::Ready->value,
				'checksum'     => 'pack-checksum',
			]
		);

		return $packs;
	}

	private function catalog(): WooCommerceStateCatalogInterface {
		return new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				if ( 'GH' !== strtoupper( trim( $country_code ) ) ) {
					return [];
				}

				return [
					'AA' => 'Greater Accra',
					'AH' => 'Ashanti',
				];
			}
		};
	}
}
