<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Destination;

use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use PHPUnit\Framework\TestCase;

final class WooCommerceCountryCatalogTest extends TestCase {

	protected function tearDown(): void {
		WooCommerceCountryCatalog::override_for_tests( null );
		parent::tearDown();
	}

	public function test_woocommerce_country_source_contains_normal_country_codes_and_names(): void {
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH'         => 'Ghana',
				'NG'         => 'Nigeria',
				'GB'         => 'United Kingdom (UK)',
				'US'         => 'United States (US)',
				'DE'         => 'Germany',
				'CN'         => 'China',
				'Africa'     => 'Africa',
				'Everywhere' => 'Everywhere',
			]
		);

		$options = WooCommerceCountryCatalog::options();

		self::assertSame( 'Ghana', $options['GH'] );
		self::assertSame( 'Nigeria', $options['NG'] );
		self::assertSame( 'United Kingdom (UK)', $options['GB'] );
		self::assertSame( 'United States (US)', $options['US'] );
		self::assertSame( 'Germany', $options['DE'] );
		self::assertSame( 'China', $options['CN'] );
		self::assertArrayNotHasKey( 'Everywhere', $options );
		self::assertArrayNotHasKey( 'Africa', $options );
		self::assertTrue( WooCommerceCountryCatalog::has( 'gb' ) );
		self::assertFalse( WooCommerceCountryCatalog::has( 'Everywhere' ) );
	}

	public function test_existing_iso_code_resolves_to_human_readable_label(): void {
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH' => 'Ghana',
				'GB' => 'United Kingdom (UK)',
			]
		);

		self::assertSame( 'Ghana', WooCommerceCountryCatalog::label( 'gh' ) );
		self::assertSame( 'United Kingdom (UK)', WooCommerceCountryCatalog::label( 'GB' ) );
		self::assertSame( 'NG', WooCommerceCountryCatalog::label( 'NG' ) );
	}

	public function test_continent_and_everywhere_keys_are_not_stored_as_country_options(): void {
		WooCommerceCountryCatalog::override_for_tests(
			[
				'Africa'     => 'Africa',
				'Everywhere' => 'Everywhere',
				'GH'         => 'Ghana',
			]
		);

		$options = WooCommerceCountryCatalog::options();

		self::assertSame( [ 'GH' => 'Ghana' ], $options );
	}

	public function test_canonical_iso2_maps_labels_and_codes(): void {
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH' => 'Ghana',
				'NG' => 'Nigeria',
				'GB' => 'United Kingdom (UK)',
				'US' => 'United States (US)',
				'DE' => 'Germany',
				'CN' => 'China',
			]
		);

		self::assertSame( 'DE', WooCommerceCountryCatalog::canonical_iso2( 'Germany' ) );
		self::assertSame( 'DE', WooCommerceCountryCatalog::canonical_iso2( 'de' ) );
		self::assertSame( 'GH', WooCommerceCountryCatalog::canonical_iso2( 'Ghana' ) );
		self::assertSame( 'NG', WooCommerceCountryCatalog::canonical_iso2( 'Nigeria' ) );
		self::assertSame( 'GB', WooCommerceCountryCatalog::canonical_iso2( 'United Kingdom' ) );
		self::assertSame( 'US', WooCommerceCountryCatalog::canonical_iso2( 'United States' ) );
		self::assertSame( 'CN', WooCommerceCountryCatalog::canonical_iso2( 'China' ) );
		self::assertSame( 'Africa', WooCommerceCountryCatalog::canonical_iso2( 'Africa' ) );
	}
}
