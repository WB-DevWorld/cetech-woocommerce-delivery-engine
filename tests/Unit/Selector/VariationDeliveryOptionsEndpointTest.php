<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\VariationDeliveryOptionsEndpoint;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Tests\Unit\Runtime\FixedVariationRelationshipInspector;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;

final class VariationDeliveryOptionsEndpointTest extends TestCase {

	private const PARENT_ID    = 202;
	private const VARIATION_ID = 303;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options']     = [];
		$GLOBALS['cetech_de_test_wc_products'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
	}

	// -------------------------------------------------------------------------
	// Valid parent + variation → ok
	// -------------------------------------------------------------------------

	public function test_valid_parent_and_variation_returns_ok_with_options(): void {
		$offers = $this->seeded_offers();
		$source = $this->source_returning_success( self::VARIATION_ID, offer_id: 7 );

		$endpoint = $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] ),
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( $offers )
		);

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );

		self::assertSame( 'ok', $payload['status'] );
		self::assertSame( self::PARENT_ID, $payload['product_id'] );
		self::assertSame( self::VARIATION_ID, $payload['variation_id'] );
		self::assertNotEmpty( $payload['options'] );
	}

	public function test_valid_response_excludes_private_fields(): void {
		$offers = $this->seeded_offers();
		$source = $this->source_returning_success( self::VARIATION_ID, offer_id: 7 );

		$endpoint = $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] ),
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( $offers )
		);

		$payload    = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );
		$serialized = (string) wp_json_encode( $payload );

		self::assertStringNotContainsString( 'supplier', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'origin', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'fingerprint', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'provenance', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'rate_card', strtolower( $serialized ) );
	}

	// -------------------------------------------------------------------------
	// Missing / zero IDs
	// -------------------------------------------------------------------------

	public function test_zero_product_id_returns_error(): void {
		$endpoint = $this->minimal_endpoint();
		$payload  = $endpoint->build_payload( 0, self::VARIATION_ID );

		self::assertSame( 'error', $payload['status'] );
		self::assertSame( 0, $payload['product_id'] );
	}

	public function test_zero_variation_id_returns_error(): void {
		$endpoint = $this->minimal_endpoint();
		$payload  = $endpoint->build_payload( self::PARENT_ID, 0 );

		self::assertSame( 'error', $payload['status'] );
	}

	public function test_both_ids_zero_returns_error(): void {
		$endpoint = $this->minimal_endpoint();
		$payload  = $endpoint->build_payload( 0, 0 );

		self::assertSame( 'error', $payload['status'] );
	}

	// -------------------------------------------------------------------------
	// Relationship validation
	// -------------------------------------------------------------------------

	public function test_variation_belonging_to_another_parent_returns_error(): void {
		// Inspector knows variation 303 belongs to parent 999, not 202.
		$inspector = new FixedVariationRelationshipInspector( [ self::VARIATION_ID => 999 ] );
		$endpoint  = $this->endpoint( inspector: $inspector );

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );

		self::assertSame( 'error', $payload['status'] );
	}

	public function test_nonexistent_variation_id_returns_error(): void {
		// Inspector has no mapping for variation 999.
		$inspector = new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] );
		$endpoint  = $this->endpoint( inspector: $inspector );

		$payload = $endpoint->build_payload( self::PARENT_ID, 999 );

		self::assertSame( 'error', $payload['status'] );
	}

	// -------------------------------------------------------------------------
	// Invalid / unresolved configuration → unavailable
	// -------------------------------------------------------------------------

	public function test_failed_configuration_resolution_returns_unavailable(): void {
		$inspector = new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] );
		$source    = $this->source_returning_failure( self::VARIATION_ID );
		$endpoint  = $this->endpoint( inspector: $inspector, source: $source );

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );

		self::assertSame( 'unavailable', $payload['status'] );
		self::assertEmpty( $payload['options'] );
	}

	// -------------------------------------------------------------------------
	// No options → unavailable
	// -------------------------------------------------------------------------

	public function test_no_available_options_returns_unavailable(): void {
		$inspector = new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] );
		// Source returns success but empty chosen_rules → options builder returns [].
		$source = $this->source_returning_empty_success( self::VARIATION_ID );
		// Use a real but empty offers repo so the builder has nothing to find.
		$endpoint = $this->endpoint(
			inspector: $inspector,
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( new InMemoryDeliveryOfferRepository() )
		);

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );

		self::assertSame( 'unavailable', $payload['status'] );
	}

	// -------------------------------------------------------------------------
	// Options contain expected public keys
	// -------------------------------------------------------------------------

	public function test_ok_payload_option_has_expected_public_keys(): void {
		$offers = $this->seeded_offers();
		$source = $this->source_returning_success( self::VARIATION_ID, offer_id: 7 );

		$endpoint = $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] ),
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( $offers )
		);

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID );
		$option  = $payload['options'][0] ?? [];

		self::assertArrayHasKey( 'display_key', $option );
		self::assertArrayHasKey( 'fulfilment_availability_label', $option );
		self::assertArrayHasKey( 'fulfilment_choice_label', $option );
		self::assertArrayHasKey( 'delivery_offer_public_label', $option );
		self::assertArrayHasKey( 'is_available', $option );
		self::assertArrayHasKey( 'price_amount', $option );
		self::assertArrayHasKey( 'price_currency', $option );
		self::assertArrayHasKey( 'price_text', $option );
		self::assertArrayHasKey( 'price_basis', $option );
		// Private fields must NOT be present.
		self::assertArrayNotHasKey( 'supplier_id', $option );
		self::assertArrayNotHasKey( 'origin_id', $option );
		self::assertArrayNotHasKey( 'logistics_profile_id', $option );
	}

	public function test_missing_location_returns_need_location_when_delivery_requires_it(): void {
		$offers = $this->seeded_offers();
		$source = $this->source_returning_success( self::VARIATION_ID, offer_id: 7 );
		$endpoint = $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] ),
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( $offers ),
			location_options: $this->location_options( [] )
		);

		$payload = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID, null );

		self::assertSame( 'need_location', $payload['status'] );
		self::assertSame( [], $payload['options'] );
	}

	public function test_location_recomputes_options_for_matching_geography(): void {
		$offers = $this->seeded_offers();
		$source = $this->source_returning_success( self::VARIATION_ID, offer_id: 7 );
		$endpoint = $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] ),
			source: $source,
			builder: new ProductDeliveryOptionsBuilder( $offers ),
			location_options: $this->location_options( [ 7 ] )
		);

		$accra = MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => 'GA-123' ] );
		$lagos = MatchingLocation::fromInput( [ 'country' => 'NG', 'city' => 'Lagos' ] );

		$ok = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID, $accra );
		$no = $endpoint->build_payload( self::PARENT_ID, self::VARIATION_ID, $lagos );

		self::assertSame( 'ok', $ok['status'] );
		self::assertNotEmpty( $ok['options'] );
		self::assertArrayHasKey( 'estimate_line', $ok['options'][0] );
		self::assertStringStartsWith( 'Estimated delivery:', (string) $ok['options'][0]['estimate_line'] );
		self::assertStringNotContainsString( 'Estimated delivery: Estimated', (string) $ok['options'][0]['estimate_line'] );
		self::assertSame( 'unavailable', $no['status'] );
	}

	// -------------------------------------------------------------------------
	// Factories / helpers
	// -------------------------------------------------------------------------

	private function minimal_endpoint(): VariationDeliveryOptionsEndpoint {
		return $this->endpoint(
			inspector: new FixedVariationRelationshipInspector( [] )
		);
	}

	private function endpoint(
		FixedVariationRelationshipInspector $inspector,
		?ProductDeliveryConfigurationSourceInterface $source = null,
		?ProductDeliveryOptionsBuilder $builder = null,
		?LocationAwareDeliveryOptions $location_options = null
	): VariationDeliveryOptionsEndpoint {
		if ( null === $source ) {
			$source = $this->source_returning_failure( self::VARIATION_ID );
		}

		if ( null === $builder ) {
			$builder = new ProductDeliveryOptionsBuilder( new InMemoryDeliveryOfferRepository() );
		}

		return new VariationDeliveryOptionsEndpoint(
			new FeatureFlags(),
			new Requirements(),
			$source,
			$builder,
			$inspector,
			$location_options
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function location_options( array $offer_ids ): LocationAwareDeliveryOptions {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				$city = strtolower( (string) ( $destination['city'] ?? '' ) );

				return 'accra' === $city ? [ 20 ] : [];
			}
		};

		$cards = [];
		foreach ( $offer_ids as $i => $offer_id ) {
			$cards[] = [
				'id'                   => $i + 1,
				'internal_code'        => 'OFFER' . $offer_id,
				'delivery_offer_id'    => $offer_id,
				'destination_zone_id'  => 20,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '15.00',
				'base_currency'        => function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'USD',
				'priority'             => 100,
				'status'               => 'active',
			];
		}

		return new LocationAwareDeliveryOptions(
			new LocationOfferQuoteProbe(
				$zone,
				new RateQuoteEngine( new InMemoryQuoteRateCardRepository( $cards ) )
			)
		);
	}

	private function seeded_offers(): InMemoryDeliveryOfferRepository {
		$repo = new InMemoryDeliveryOfferRepository();
		$repo->seed(
			7,
			[
				'status'                 => 'active',
				'public_label'           => 'Local Delivery',
				'public_description'     => 'Door delivery',
				'route'                  => 'local_delivery',
				'duration_unit'          => 'business_days',
				'default_processing_min' => 1,
				'default_processing_max' => 2,
				'default_transit_min'    => 1,
				'default_transit_max'    => 2,
				'default_final_mile_min' => 0,
				'default_final_mile_max' => 0,
			]
		);

		return $repo;
	}

	private function source_returning_success( int $variation_id, int $offer_id ): ProductDeliveryConfigurationSourceInterface {
		$result = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Variation->value,
			$variation_id,
			null,
			[],
			'ECR test source',
			[],
			[
				FulfilmentAvailability::InStore->value => new ResolvedProductDeliveryRule(
					0,
					ProductTargetType::Variation->value,
					$variation_id,
					null,
					3,
					FulfilmentAvailability::InStore->value,
					FulfilmentChoice::Delivery->value,
					[ $offer_id ],
					10,
					20,
					30,
					5
				),
			],
			[],
			[],
			[],
			null
		);

		return new class( $result ) implements ProductDeliveryConfigurationSourceInterface {
			public function __construct( private ProductRuleResolutionResult $result ) {
			}

			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution( $this->result, RuntimeConfigurationSource::ECR, 'fp_' . $target_id );
			}
		};
	}

	private function source_returning_failure( int $variation_id ): ProductDeliveryConfigurationSourceInterface {
		$result = ProductRuleResolutionResult::failure(
			ProductTargetType::Variation->value,
			$variation_id,
			'test_failure'
		);

		return new class( $result ) implements ProductDeliveryConfigurationSourceInterface {
			public function __construct( private ProductRuleResolutionResult $result ) {
			}

			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution( $this->result, RuntimeConfigurationSource::ECR );
			}
		};
	}

	private function source_returning_empty_success( int $variation_id ): ProductDeliveryConfigurationSourceInterface {
		// Success=true but no chosen_rules → options builder will return [].
		$result = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Variation->value,
			$variation_id,
			null,
			[],
			'empty test',
			[],
			[], // no chosen_rules
			[],
			[],
			[],
			'No rules matched.'
		);

		return new class( $result ) implements ProductDeliveryConfigurationSourceInterface {
			public function __construct( private ProductRuleResolutionResult $result ) {
			}

			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution( $this->result, RuntimeConfigurationSource::ECR, 'fp' );
			}
		};
	}
}
