<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Checkout;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressCanonicalizer;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;
use CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class CheckoutCanonicalCheckoutAddressTest extends TestCase {

	private GhanaGeographyFixture $geo;

	private CheckoutAddressCanonicalizer $canonicalizer;

	private ApplyCustomerContextToEligibleLinesService $service;

	private LocationOfferQuoteProbe $probe;

	private RateQuoteEngine $quotes;

	private CheckoutAddressPolicy $policy;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'        => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
		}

		if ( ! function_exists( 'wc_get_base_location' ) ) {
			eval( 'function wc_get_base_location(): array { return array( "country" => "GH", "state" => "AA" ); }' );
		}

		$this->geo = new GhanaGeographyFixture();
		$packs     = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Ready->value,
				'checksum'     => 'pack-checksum',
			]
		);
		$this->canonicalizer = new CheckoutAddressCanonicalizer(
			new CanonicalLocationResolver( $this->geo->locations, $this->geo->locations, $packs ),
			$this->catalog()
		);

		$cards = new InMemoryQuoteRateCardRepository(
			[
				$this->card( 4, 10, 20, '50.00' ),
				$this->card( 5, 10, 21, '35.00' ),
				$this->card( 6, 10, 22, '30.00' ),
				$this->card( 7, 10, 30, '80.00' ),
			]
		);
		$this->quotes = new RateQuoteEngine( $cards );
		$this->probe  = new LocationOfferQuoteProbe( $this->zone_resolver(), $this->quotes );
		$this->service = new ApplyCustomerContextToEligibleLinesService(
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			$this->probe
		);
		$this->policy = new CheckoutAddressPolicy(
			new FeatureFlags(),
			new Requirements(),
			$this->service,
			new CartCustomerContextMutationService(),
			$this->canonicalizer
		);
	}

	protected function tearDown(): void {
		foreach ( [ 'shipping_country', 'shipping_state', 'shipping_city', 'shipping_postcode', 'shipping_address_1', 'ship_to_different_address' ] as $key ) {
			unset( $_POST[ $key ] );
		}
		parent::tearDown();
	}

	public function test_canonical_accra_incomplete_line_completes_from_woo_checkout_address(): void {
		$line   = $this->named_line( 101, 'Accra matching', $this->incomplete_accra() );
		$before = CustomerCartContext::fromCartItem( $line );
		self::assertFalse( $before?->hasCompleteDeliveryAddress() );
		self::assertSame( $this->geo->accra->location_key, $before?->matching_location?->canonical_location_key );
		self::assertSame( '50.00', $this->quote_amount( $before, 10 ) );

		$summary = $this->policy->summarize_cart( [ 'accra' => $line ] );
		self::assertTrue( $summary['can_apply_checkout_address'] );
		self::assertFalse( $summary['heterogeneous_incomplete_destinations'] );

		$outcome = $this->apply_checkout( [ 'accra' => $line ], $this->woo_accra() );
		$after   = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );

		self::assertFalse( $outcome['blocked'] );
		self::assertNotEmpty( $outcome['updated'] );
		self::assertTrue( $after?->hasCompleteDeliveryAddress() );
		self::assertSame( $before?->matching_identity, $after?->matching_identity );
		self::assertSame( $this->geo->accra->location_key, $after?->matching_location?->canonical_location_key );
		self::assertSame( 'Accra', $after?->matching_location?->city );
		self::assertSame( 'QA Checkout Street Accra', $after?->delivery_address?->address_1 );
		self::assertSame( '50.00', $this->quote_amount( $after, 10 ) );
		self::assertNotSame( '30.00', $this->quote_amount( $after, 10 ) );
	}

	public function test_canonical_accra_line_rejects_tema_checkout_address(): void {
		$line     = $this->named_line( 101, 'Accra matching', $this->incomplete_accra() );
		$before   = $line[ CustomerCartContext::CART_KEY ];
		$outcome  = $this->apply_checkout( [ 'accra' => $line ], $this->woo_tema() );
		$after    = CustomerCartContext::fromCartItem( $outcome['contents']['accra'] );

		self::assertFalse( $outcome['blocked'] );
		self::assertSame( [], $outcome['updated'] );
		self::assertNotEmpty( $outcome['preserved'] );
		self::assertSame( $before, $outcome['contents']['accra'][ CustomerCartContext::CART_KEY ] );
		self::assertFalse( $after?->hasCompleteDeliveryAddress() );
		self::assertSame( $this->geo->accra->location_key, $after?->matching_location?->canonical_location_key );
		self::assertSame( '', $after?->delivery_address?->address_1 ?? '' );
	}

	public function test_heterogeneous_accra_and_tema_remain_blocked(): void {
		$contents = [
			'accra' => $this->named_line( 101, 'Accra', $this->incomplete_accra() ),
			'tema'  => $this->named_line( 202, 'Tema', $this->incomplete_tema() ),
		];
		$summary = $this->policy->summarize_cart( $contents );
		self::assertTrue( $summary['heterogeneous_incomplete_destinations'] );
		self::assertFalse( $summary['can_apply_checkout_address'] );

		$outcome = $this->apply_checkout( $contents, $this->woo_accra() );
		self::assertTrue( $outcome['blocked'] );
		self::assertSame( [], $outcome['updated'] );
		self::assertFalse( CustomerCartContext::fromCartItem( $outcome['contents']['accra'] )?->hasCompleteDeliveryAddress() );
		self::assertFalse( CustomerCartContext::fromCartItem( $outcome['contents']['tema'] )?->hasCompleteDeliveryAddress() );
	}

	public function test_complete_address_is_preserved(): void {
		$complete = PerItemContextFixtures::deliveryContext(
			10,
			PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' )
		);
		$line     = $this->named_line( 101, 'Complete Accra', $complete );
		$outcome  = $this->apply_checkout( [ 'accra' => $line ], $this->woo_accra() );

		self::assertSame( [], $outcome['updated'] );
		self::assertSame( '12 Boundary Rd', CustomerCartContext::fromCartItem( $outcome['contents']['accra'] )?->delivery_address?->address_1 );
	}

	public function test_pickup_is_preserved(): void {
		$delivery = $this->named_line( 101, 'Accra', $this->incomplete_accra() );
		$pickup   = $this->named_line(
			303,
			'Radio Pickup',
			CustomerCartContext::pickup( 4 ),
			$this->intent( 303, null, 'in_store', 'store_pickup' )
		);
		$outcome = $this->apply_checkout( [ 'delivery' => $delivery, 'pickup' => $pickup ], $this->woo_accra() );

		self::assertTrue( CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 303 ) )?->isPickup() );
		self::assertSame( 4, CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 303 ) )?->pickup_location_id );
		self::assertSame( $pickup[ CustomerCartContext::CART_KEY ], $this->find_line( $outcome['contents'], 303 )[ CustomerCartContext::CART_KEY ] );
		self::assertTrue( CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) )?->hasCompleteDeliveryAddress() );
	}

	public function test_local_and_international_boundary_is_unchanged(): void {
		$local = $this->named_line( 101, 'Local Accra', $this->incomplete_accra() );
		$intl  = $this->named_line(
			404,
			'Intl Boston',
			PerItemContextFixtures::incompleteContext( 30, PerItemContextFixtures::matchingBoston() ),
			$this->intent( 404, 30, FulfilmentAvailability::InternationalFulfilment->value, 'delivery' )
		);

		$local_us = $this->apply_checkout( [ 'local' => $local ], $this->woo_boston() );
		self::assertSame( [], $local_us['updated'] );
		self::assertSame( 'Accra', CustomerCartContext::fromCartItem( $local_us['contents']['local'] )?->matching_location?->city );

		$intl_accra = $this->apply_checkout( [ 'intl' => $intl ], $this->woo_accra() );
		self::assertSame( [], $intl_accra['updated'] );
		self::assertSame( 'Boston', CustomerCartContext::fromCartItem( $intl_accra['contents']['intl'] )?->matching_location?->city );
	}

	public function test_empty_matching_recovers_with_canonical_key_when_pack_usable(): void {
		$empty   = $this->named_line( 101, 'No destination', PerItemContextFixtures::emptyMatchingContext( 10 ) );
		$outcome = $this->apply_checkout( [ 'empty' => $empty ], $this->woo_accra() );
		$updated = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );

		self::assertFalse( $outcome['blocked'] );
		self::assertNotEmpty( $outcome['updated'] );
		self::assertTrue( $updated?->hasCompleteDeliveryAddress() );
		self::assertSame( $this->geo->accra->location_key, $updated?->matching_location?->canonical_location_key );
		self::assertSame( 'Accra', $updated?->matching_location?->city );
		self::assertSame( 'QA Checkout Street Accra', $updated?->delivery_address?->address_1 );
		self::assertSame( '50.00', $this->quote_amount( $updated, 10 ) );
	}

	public function test_quote_probe_still_required_after_canonical_equivalence(): void {
		$failing = new ApplyCustomerContextToEligibleLinesService(
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			new LocationOfferQuoteProbe(
				new class() implements PackageDestinationZoneResolverInterface {
					public function resolve_zone_id( array $destination ): ?int {
						return null;
					}

					public function resolve_zone_ids( array $destination ): array {
						unset( $destination );

						return [];
					}
				},
				$this->quotes
			)
		);
		$line    = $this->named_line( 101, 'Accra matching', $this->incomplete_accra() );
		$before  = $line[ CustomerCartContext::CART_KEY ];
		$outcome = $failing->applyCheckoutAddressToIncomplete(
			[ 'accra' => $line ],
			$this->canonicalizer->canonicalize( $this->woo_accra() )
		);

		self::assertSame( [], $outcome['updated'] );
		self::assertNotEmpty( $outcome['skipped'] );
		self::assertSame( $before, $outcome['contents']['accra'][ CustomerCartContext::CART_KEY ] );
		self::assertFalse( CustomerCartContext::fromCartItem( $outcome['contents']['accra'] )?->hasCompleteDeliveryAddress() );
	}

	public function test_classic_and_blocks_read_the_same_canonicalized_checkout_address(): void {
		$_POST['shipping_country']   = 'GH';
		$_POST['shipping_state']     = 'AA';
		$_POST['shipping_city']      = 'Accra';
		$_POST['shipping_postcode']  = '';
		$_POST['shipping_address_1'] = 'QA Checkout Street Accra';

		$read = $this->policy->read_checkout_shipping_address();
		self::assertSame( $this->geo->accra->location_key, $read['canonical_location_key'] );
		self::assertSame( 'Accra', $read['city'] );
		self::assertSame( 'QA Checkout Street Accra', $read['address_1'] );

		$handler = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Integrations/Blocks/BlocksCartContextCommandHandler.php' );
		self::assertStringContainsString( 'ACTION_APPLY_CHECKOUT_ADDRESS', $handler );
		self::assertStringContainsString( 'read_checkout_shipping_address()', $handler );
		self::assertStringContainsString( 'applyCheckoutAddressToIncomplete', $handler );
	}

	public function test_store_api_public_payload_omits_canonical_key_and_identities(): void {
		$item    = $this->named_line( 101, 'Accra matching', $this->incomplete_accra() );
		$payload = BlocksPublicPayload::cart_item( $item, null, null, 'accra' );
		$encoded = (string) wp_json_encode( $payload );

		self::assertArrayNotHasKey( 'canonical_location_key', $payload['matching_location'] ?? [] );
		self::assertSame( 'Accra', $payload['matching_location']['city'] ?? null );
		self::assertStringNotContainsString( $this->geo->accra->location_key, $encoded );
		self::assertStringNotContainsString( 'canonical_location_key', $encoded );
		self::assertStringNotContainsString( 'matching_identity', $encoded );
		self::assertStringNotContainsString( 'delivery_location_identity', $encoded );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 * @param array<string, mixed>                $woo
	 *
	 * @return array<string, mixed>
	 */
	private function apply_checkout( array $contents, array $woo ): array {
		return $this->service->applyCheckoutAddressToIncomplete(
			$contents,
			$this->canonicalizer->canonicalize( $woo )
		);
	}

	private function incomplete_accra(): CustomerCartContext {
		return PerItemContextFixtures::incompleteContext(
			10,
			MatchingLocation::fromInput(
				[
					'country'                => 'GH',
					'state'                  => 'AA',
					'city'                   => 'Accra',
					'postcode'               => '',
					'canonical_location_key' => $this->geo->accra->location_key,
				]
			)
		);
	}

	private function incomplete_tema(): CustomerCartContext {
		return PerItemContextFixtures::incompleteContext(
			10,
			MatchingLocation::fromInput(
				[
					'country'                => 'GH',
					'state'                  => 'AA',
					'city'                   => 'Tema',
					'postcode'               => '',
					'canonical_location_key' => $this->geo->tema->location_key,
				]
			)
		);
	}

	/**
	 * @param array<string, mixed> $override
	 *
	 * @return array<string, mixed>
	 */
	private function woo_accra( array $override = [] ): array {
		return array_merge(
			[
				'country'   => 'GH',
				'state'     => 'AA',
				'city'      => 'Accra',
				'postcode'  => '',
				'address_1' => 'QA Checkout Street Accra',
			],
			$override
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function woo_tema(): array {
		return [
			'country'   => 'GH',
			'state'     => 'AA',
			'city'      => 'Tema',
			'postcode'  => '',
			'address_1' => 'QA Tema Checkout Street',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function woo_boston(): array {
		return [
			'country'   => 'US',
			'state'     => 'MA',
			'city'      => 'Boston',
			'postcode'  => '02108',
			'address_1' => '1 Beacon St',
		];
	}

	/**
	 * @param array<string, mixed>|null $intent
	 *
	 * @return array<string, mixed>
	 */
	private function named_line( int $product_id, string $name, CustomerCartContext $context, ?array $intent = null, int $quantity = 1 ): array {
		$item         = PerItemContextFixtures::cartItem( $intent ?? $this->intent( $product_id, $context->delivery_offer_id ?? 10 ), $context, $quantity );
		$item['data'] = new WC_Product( [ 'id' => $product_id, 'name' => $name, 'type' => 'simple' ] );

		return $item;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent( int $product_id, ?int $offer_id, string $availability = 'in_warehouse', string $choice = 'delivery' ): array {
		$suffix = null === $offer_id ? 'pickup' : (string) $offer_id;

		return [
			'contract_version'        => '1',
			'product_id'              => $product_id,
			'variation_id'            => 0,
			'target_type'             => 'product',
			'target_id'               => $product_id,
			'display_key'             => $availability . ':' . $choice . ':' . $suffix,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-02T00:00:00+00:00',
		];
	}

	private function quote_amount( ?CustomerCartContext $context, int $offer_id ): string {
		self::assertInstanceOf( CustomerCartContext::class, $context );
		$zone_ids = $this->probe->zone_ids( $context->toWcPackageDestination() );
		self::assertNotEmpty( $zone_ids );
		$currency = new CurrencyCode( $this->currency() );
		foreach ( $zone_ids as $zone_id ) {
			$result = $this->quotes->quote( new RateQuoteRequest( $offer_id, $zone_id, 1, $currency ) );
			if ( $result->success && null !== $result->amount ) {
				return number_format( (float) $result->amount->amount(), 2, '.', '' );
			}
		}

		self::fail( 'Missing quote for offer ' . $offer_id );
	}

	private function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'USD';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card( int $id, int $offer_id, int $zone_id, string $amount ): array {
		return [
			'id'                  => $id,
			'delivery_offer_id'   => $offer_id,
			'destination_zone_id' => $zone_id,
			'charge_type'         => RateCardChargeType::FixedPerShipment->value,
			'base_amount'         => $amount,
			'base_currency'       => $this->currency(),
			'priority'            => 100,
			'status'              => 'active',
		];
	}

	private function zone_resolver(): PackageDestinationZoneResolverInterface {
		$accra = $this->geo->accra->location_key;
		$tema  = $this->geo->tema->location_key;
		$ga    = $this->geo->greater_accra->location_key;

		return new class( $accra, $tema, $ga ) implements PackageDestinationZoneResolverInterface {
			public function __construct(
				private string $accra_key,
				private string $tema_key,
				private string $ga_key
			) {
			}

			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				$key     = (string) ( $destination['canonical_location_key'] ?? '' );
				$city    = strtolower( (string) ( $destination['city'] ?? '' ) );
				$country = strtoupper( (string) ( $destination['country'] ?? '' ) );
				if ( $key === $this->accra_key || 'accra' === $city ) {
					return [ 20 ];
				}
				if ( $key === $this->tema_key || 'tema' === $city ) {
					return [ 21 ];
				}
				if ( $key === $this->ga_key ) {
					return [ 22 ];
				}
				if ( 'boston' === $city || 'US' === $country ) {
					return [ 30 ];
				}

				return [];
			}
		};
	}

	private function catalog(): WooCommerceStateCatalogInterface {
		return new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				return 'GH' === strtoupper( trim( $country_code ) )
					? [ 'AA' => 'Greater Accra', 'AH' => 'Ashanti' ]
					: [];
			}
		};
	}

	private function always_valid_validator(): ProductDeliverySelectionValidatorInterface {
		return new class() implements ProductDeliverySelectionValidatorInterface {
			public function validate( int $product_id, ?int $variation_id, string $display_key ): ProductDeliverySelectionValidationResult {
				$parts  = explode( ':', $display_key );
				$choice = $parts[1] ?? 'delivery';
				$offer  = ( $parts[2] ?? '' ) === 'pickup' ? null : (int) ( $parts[2] ?? 10 );
				$option = new ProductDeliveryOption(
					$display_key,
					$parts[0] ?? 'in_warehouse',
					'Availability',
					$choice,
					'Choice',
					$offer,
					'QA Local Standard',
					null,
					'2-4 days',
					true,
					null
				);
				$intent = new ProductDeliverySelectionIntent(
					'1',
					$product_id,
					$variation_id,
					'product',
					$product_id,
					$display_key,
					$parts[0] ?? 'in_warehouse',
					$choice,
					$offer,
					1,
					'2026-09-02T00:00:00+00:00'
				);

				return ProductDeliverySelectionValidationResult::valid( $option, $intent );
			}
		};
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>
	 */
	private function find_line( array $contents, int $product_id ): array {
		foreach ( $contents as $item ) {
			if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) {
				return $item;
			}
		}

		self::fail( 'Missing cart line for product ' . $product_id );
	}
}
