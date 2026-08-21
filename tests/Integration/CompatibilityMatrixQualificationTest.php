<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\Diagnostics\DiagnosticSeverity;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\FeaturesCompatibility;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * RC.6 compatibility matrix: fail-closed quotes, native methods, region matching, diagnostics.
 */
final class CompatibilityMatrixQualificationTest extends TestCase {

	protected function setUp(): void {
		LifecycleHarness::reset();
	}

	public function test_flags_off_do_not_emit_delivery_engine_rates(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$gate  = new ShippingRateCalculationGate( $flags, new Requirements() );

		self::assertFalse( $gate->is_runtime_active() );
	}

	public function test_missing_rate_card_does_not_become_free_shipping(): void {
		$engine = new RateQuoteEngine( $this->empty_rate_cards() );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertNull( $result->amount );
		self::assertSame( RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD, $result->error_code );
	}

	public function test_currency_mismatch_does_not_become_free_shipping(): void {
		$engine = new RateQuoteEngine( $this->cards( [
			[
				'id'                  => 1,
				'delivery_offer_id'   => 10,
				'destination_zone_id' => 20,
				'charge_type'         => RateCardChargeType::FixedPerShipment->value,
				'base_amount'         => '40.00',
				'base_currency'       => 'GHS',
				'status'              => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertNull( $result->amount );
	}

	public function test_region_code_and_label_are_equivalent_for_same_country_only(): void {
		$matcher = new RegionCodeLabelMatcher(
			new class() implements WooCommerceStateCatalogInterface {
				public function states_for_country( string $country_code ): array {
					if ( 'GH' !== strtoupper( $country_code ) ) {
						return [];
					}

					return [ 'AA' => 'Greater Accra' ];
				}
			}
		);

		self::assertTrue( $matcher->matches( 'GH', 'AA', 'Greater Accra' ) );
		self::assertTrue( $matcher->matches( 'GH', 'Greater Accra', 'AA' ) );
		self::assertFalse( $matcher->matches( 'NG', 'AA', 'Greater Accra' ) );
	}

	public function test_shipping_method_registers_once_and_preserves_native_methods(): void {
		if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
			eval(
				'class WC_Shipping_Method {
					public string $id = "";
					public int $instance_id = 0;
					public function init_form_fields(): void {}
					public function init_settings(): void {}
					public function get_option( string $key, $default_value = "" ) { return $default_value; }
					public function process_admin_options(): void {}
				}'
			);
		}

		$integration = new SelectedOfferShippingIntegration(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() )
		);

		$native = [
			'flat_rate'     => 'WC_Shipping_Flat_Rate',
			'free_shipping' => 'WC_Shipping_Free_Shipping',
			'local_pickup'  => 'WC_Shipping_Local_Pickup',
			'custom_ship'   => 'Custom_Third_Party_Method',
		];

		$once  = $integration->register_shipping_method( $native );
		$twice = $integration->register_shipping_method( $once );

		self::assertCount( 1, array_filter( array_keys( $twice ), static fn ( string $id ): bool => SelectedOfferShippingMethod::METHOD_ID === $id ) );
		self::assertArrayHasKey( 'flat_rate', $twice );
		self::assertArrayHasKey( 'free_shipping', $twice );
		self::assertArrayHasKey( 'local_pickup', $twice );
		self::assertArrayHasKey( 'custom_ship', $twice );
		self::assertSame( 'Custom_Third_Party_Method', $twice['custom_ship'] );
	}

	public function test_flags_off_do_not_strip_native_package_rates(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$integration = new SelectedOfferShippingIntegration(
			new ShippingRateCalculationGate( $flags, new Requirements() )
		);

		$native = new class() {
			public function get_method_id(): string {
				return 'flat_rate';
			}
		};

		$filtered = $integration->filter_managed_package_rates(
			[ 'flat_rate:1' => $native ],
			[ 'contents' => [] ]
		);

		self::assertArrayHasKey( 'flat_rate:1', $filtered );
	}

	public function test_incomplete_configuration_emits_actionable_diagnostics(): void {
		$checker = new ConfigurationHealthChecker(
			$this->empty_repo( LogisticsProfileRepositoryInterface::class ),
			$this->empty_repo( DeliveryOfferRepositoryInterface::class ),
			$this->empty_repo( DestinationZoneRepositoryInterface::class ),
			$this->empty_repo( DestinationRuleRepositoryInterface::class ),
			$this->empty_repo( PickupLocationRepositoryInterface::class ),
			$this->empty_repo( SupplierRepositoryInterface::class ),
			$this->empty_repo( OriginRepositoryInterface::class ),
			$this->empty_repo( RateCardRepositoryInterface::class ),
			$this->empty_repo( ProductDeliveryRuleRepositoryInterface::class ),
			new ProductTargetResolver( new Requirements() ),
			new FeatureFlags()
		);

		$result = $checker->run();
		$codes  = array_map( static fn ( $item ): string => $item->code, $result['diagnostics'] );

		self::assertContains( 'zero_destination_zones', $codes );
		self::assertContains( 'zero_rate_cards', $codes );
		self::assertContains( 'configuration_tables_missing', $codes );
		self::assertGreaterThan( 0, $result['summary']['warning'] + $result['summary']['error'] );

		foreach ( $result['diagnostics'] as $item ) {
			if ( in_array( $item->code, [ 'zero_destination_zones', 'zero_rate_cards', 'configuration_tables_missing' ], true ) ) {
				self::assertNotSame( '', trim( $item->message ) );
				self::assertTrue(
					DiagnosticSeverity::Warning === $item->severity || DiagnosticSeverity::Error === $item->severity
				);
			}
		}
	}

	public function test_hpos_declaration_hook_is_idempotent(): void {
		FeaturesCompatibility::register_hpos_declaration( CETECH_DE_FILE );
		FeaturesCompatibility::register_hpos_declaration( CETECH_DE_FILE );

		self::assertTrue( FeaturesCompatibility::hpos_declaration_attempted() );
		$before = count( $GLOBALS['cetech_de_test_actions']['before_woocommerce_init'] ?? [] );
		self::assertSame( 1, $before );
	}

	/**
	 * @param class-string $interface
	 */
	private function empty_repo( string $interface ): object {
		$repo = $this->createMock( $interface );
		$repo->method( 'list' )->willReturn( [] );
		if ( method_exists( $interface, 'count_all' ) ) {
			$repo->method( 'count_all' )->willReturn( 0 );
		}
		if ( method_exists( $interface, 'listByZoneId' ) ) {
			$repo->method( 'listByZoneId' )->willReturn( [] );
		}
		if ( method_exists( $interface, 'listActive' ) ) {
			$repo->method( 'listActive' )->willReturn( [] );
		}
		if ( method_exists( $interface, 'listActiveForQuoteMatch' ) ) {
			$repo->method( 'listActiveForQuoteMatch' )->willReturn( [] );
		}

		return $repo;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function cards( array $rows ): RateCardRepositoryInterface {
		return new class( $rows ) implements RateCardRepositoryInterface {
			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {
			}

			public function findById( int $id ): ?array {
				return null;
			}

			public function findByCode( string $code ): ?array {
				return null;
			}

			public function save( array $data ): int {
				return 0;
			}

			public function list( array $criteria = [] ): array {
				return $this->rows;
			}

			public function softDelete( int $id ): bool {
				return false;
			}

			public function hardDelete( int $id ): bool {
				return false;
			}

			public function count_all(): int {
				return count( $this->rows );
			}

			public function countByDeliveryOfferId( int $delivery_offer_id ): int {
				return 0;
			}

			public function countByDestinationZoneId( int $destination_zone_id ): int {
				return 0;
			}

			public function countOrderSnapshotReferences( int $rate_card_id ): int {
				return 0;
			}

			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}

			public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array {
				$out = [];
				foreach ( $this->rows as $row ) {
					if (
						(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
						&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
						&& strtoupper( (string) ( $row['base_currency'] ?? '' ) ) === strtoupper( $currency_code )
						&& 'active' === (string) ( $row['status'] ?? '' )
					) {
						$out[] = $row;
					}
				}

				return $out;
			}
		};
	}

	private function empty_rate_cards(): RateCardRepositoryInterface {
		return $this->cards( [] );
	}
}
