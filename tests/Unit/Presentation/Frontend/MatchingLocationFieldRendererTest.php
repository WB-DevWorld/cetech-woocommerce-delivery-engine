<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Frontend;

use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Presentation\Frontend\MatchingLocationFieldRenderer;
use PHPUnit\Framework\TestCase;

final class MatchingLocationFieldRendererTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
	}

	public function test_pdp_without_saved_location_does_not_select_store_base_country(): void {
		$this->stub_store_country( 'GH' );

		$html = MatchingLocationFieldRenderer::render( null, 'cetech-de-matching', false, false, false );

		self::assertStringContainsString( 'Select…', $html );
		self::assertDoesNotMatchRegularExpression( '/<option value="GH"[^>]*selected/', $html );
		self::assertStringNotContainsString( 'value="GH" selected', $html );
	}

	public function test_cart_default_still_uses_store_base_country_when_empty(): void {
		$this->stub_store_country( 'GH' );

		$html = MatchingLocationFieldRenderer::render( null, 'cetech-de-matching', false );

		self::assertMatchesRegularExpression( '/<option value="GH"[^>]*selected="selected"/', $html );
	}

	public function test_saved_browsing_location_is_prepopulated_without_base_country_fallback(): void {
		$this->stub_store_country( 'NG' );
		$location = MatchingLocation::fromInput(
			[
				'country'  => 'GH',
				'state'    => 'AA',
				'city'     => 'Accra',
				'postcode' => '00233',
			]
		);

		$html = MatchingLocationFieldRenderer::render( $location, 'cetech-de-matching', false, false, false );

		self::assertMatchesRegularExpression( '/<option value="GH"[^>]*selected="selected"/', $html );
		self::assertStringContainsString( 'value="Accra"', $html );
		self::assertStringContainsString( 'value="00233"', $html );
		self::assertDoesNotMatchRegularExpression( '/<option value="NG"[^>]*selected/', $html );
	}

	public function test_explicit_country_only_location_is_selected(): void {
		$this->stub_store_country( 'NG' );
		$location = MatchingLocation::fromInput( [ 'country' => 'GH' ] );

		self::assertTrue( $location->isPresent() );

		$html = MatchingLocationFieldRenderer::render( $location, 'cetech-de-matching', false, false, false );

		self::assertMatchesRegularExpression( '/<option value="GH"[^>]*selected="selected"/', $html );
	}

	private function stub_store_country( string $code ): void {
		$countries = new class( $code ) {
			public function __construct( private string $base ) {
			}

			public function get_base_country(): string {
				return $this->base;
			}

			/**
			 * @return array<string, string>
			 */
			public function get_countries(): array {
				return [
					'GH' => 'Ghana',
					'NG' => 'Nigeria',
				];
			}

			/**
			 * @return array<string, string>
			 */
			public function get_states( string $country ): array {
				return 'GH' === $country ? [ 'AA' => 'Greater Accra' ] : [];
			}
		};

		$GLOBALS['cetech_de_test_wc'] = (object) [ 'countries' => $countries ];
	}
}
