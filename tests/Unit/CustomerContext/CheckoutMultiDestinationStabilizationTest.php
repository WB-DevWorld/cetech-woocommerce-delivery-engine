<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WC_Product;

/**
 * Issue #29 — checkout "Use my checkout address" must not flatten multi-destination carts.
 */
final class CheckoutMultiDestinationStabilizationTest extends TestCase {

	private LocationOfferQuoteProbe $probe;

	private RateQuoteEngine $quotes;

	private ApplyCustomerContextToEligibleLinesService $service;

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

		$cards = new InMemoryQuoteRateCardRepository(
			[
				$this->card( 4, 10, 20, '50.00' ),
				$this->card( 5, 10, 21, '35.00' ),
				$this->card( 6, 10, 22, '30.00' ),
				$this->card( 7, 10, 30, '80.00' ),
				$this->card( 8, 30, 30, '80.00' ),
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
			new CartCustomerContextMutationService()
		);
	}

	public function test_a_heterogeneous_gh_and_accra_incomplete_lines_are_not_flattened_to_checkout_accra(): void {
		$gh    = $this->named_line( 16, 'Line GH', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) );
		$accra = $this->named_line( 16, 'Line Accra', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$contents = [
			'gh'    => $gh,
			'accra' => $accra,
		];

		$gh_before    = CustomerCartContext::fromCartItem( $contents['gh'] );
		$accra_before = CustomerCartContext::fromCartItem( $contents['accra'] );
		self::assertSame( 'GH', $gh_before?->matching_location?->country_identity );
		self::assertSame( '', $gh_before?->matching_location?->city );
		self::assertSame( 'Accra', $accra_before?->matching_location?->city );
		self::assertSame( '30.00', $this->quote_amount( $gh_before, 10 ) );
		self::assertSame( '50.00', $this->quote_amount( $accra_before, 10 ) );

		$summary = $this->policy->summarize_cart( $contents );
		self::assertTrue( $summary['multi_destination'] );
		self::assertTrue( $summary['heterogeneous_incomplete_destinations'] );
		self::assertFalse( $summary['can_apply_checkout_address'] );
		self::assertSame( 2, $summary['incomplete_delivery'] );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'gh' ), $summary['first_incomplete_anchor'] );

		$outcome = $this->service->applyCheckoutAddressToIncomplete( $contents, $this->checkout_accra() );

		self::assertTrue( $outcome['blocked'] );
		self::assertSame( [], $outcome['updated'] );
		self::assertSame( [ 'gh', 'accra' ], array_keys( $outcome['contents'] ) );
		self::assertSame( 1, (int) $outcome['contents']['gh']['quantity'] );
		self::assertSame( 1, (int) $outcome['contents']['accra']['quantity'] );
		self::assertSame( $contents['gh'][ CustomerCartContext::CART_KEY ], $outcome['contents']['gh'][ CustomerCartContext::CART_KEY ] );
		self::assertSame( $contents['accra'][ CustomerCartContext::CART_KEY ], $outcome['contents']['accra'][ CustomerCartContext::CART_KEY ] );

		$gh_after    = CustomerCartContext::fromCartItem( $outcome['contents']['gh'] );
		$accra_after = CustomerCartContext::fromCartItem( $outcome['contents']['accra'] );
		self::assertSame( $gh_before?->matching_identity, $gh_after?->matching_identity );
		self::assertSame( $accra_before?->matching_identity, $accra_after?->matching_identity );
		self::assertFalse( $gh_after?->hasCompleteDeliveryAddress() );
		self::assertFalse( $accra_after?->hasCompleteDeliveryAddress() );
		self::assertSame( '30.00', $this->quote_amount( $gh_after, 10 ) );
		self::assertSame( '50.00', $this->quote_amount( $accra_after, 10 ) );
		self::assertNotSame(
			(string) DeliveryGroupIdentity::fromCartItem( $outcome['contents']['gh'] ),
			(string) DeliveryGroupIdentity::fromCartItem( $outcome['contents']['accra'] )
		);
	}

	public function test_b_two_different_complete_addresses_never_change(): void {
		$accra  = $this->named_line( 101, 'Chair Accra', PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) ) );
		$kumasi = $this->named_line( 202, 'Lamp Kumasi', PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryKumasi() ) );
		$contents = [
			'accra'  => $accra,
			'kumasi' => $kumasi,
		];

		$outcome = $this->service->applyCheckoutAddressToIncomplete( $contents, $this->checkout_accra() );

		self::assertFalse( $outcome['blocked'] );
		self::assertSame( [], $outcome['updated'] );
		self::assertSame( '12 Boundary Rd', CustomerCartContext::fromCartItem( $outcome['contents']['accra'] )?->delivery_address?->address_1 );
		self::assertSame( '7 Lake Rd', CustomerCartContext::fromCartItem( $outcome['contents']['kumasi'] )?->delivery_address?->address_1 );
		self::assertSame( $contents['accra'][ CustomerCartContext::CART_KEY ], $outcome['contents']['accra'][ CustomerCartContext::CART_KEY ] );
		self::assertSame( $contents['kumasi'][ CustomerCartContext::CART_KEY ], $outcome['contents']['kumasi'][ CustomerCartContext::CART_KEY ] );
	}

	public function test_c_incomplete_line_with_no_matching_destination_can_take_checkout_address(): void {
		$empty = $this->named_line( 101, 'No destination', PerItemContextFixtures::emptyMatchingContext( 10 ) );
		$outcome = $this->service->applyCheckoutAddressToIncomplete(
			[ 'empty' => $empty ],
			$this->checkout_accra()
		);

		self::assertFalse( $outcome['blocked'] );
		self::assertNotEmpty( $outcome['updated'] );
		$updated = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );
		self::assertTrue( $updated?->hasCompleteDeliveryAddress() );
		self::assertSame( 'Accra', $updated?->matching_location?->city );
		self::assertSame( 'Checkout Street 1', $updated?->delivery_address?->address_1 );
		self::assertSame( '50.00', $this->quote_amount( $updated, 10 ) );
		self::assertSame( 1, (int) $this->find_line( $outcome['contents'], 101 )['quantity'] );
	}

	public function test_d_compatible_incomplete_matching_is_completed_without_changing_geography(): void {
		$line = $this->named_line( 101, 'Accra matching', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$before = CustomerCartContext::fromCartItem( $line );
		self::assertSame( '50.00', $this->quote_amount( $before, 10 ) );

		$summary = $this->policy->summarize_cart( [ 'accra' => $line ] );
		self::assertTrue( $summary['can_apply_checkout_address'] );
		self::assertFalse( $summary['heterogeneous_incomplete_destinations'] );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'accra' ), $summary['first_incomplete_anchor'] );

		$outcome = $this->service->applyCheckoutAddressToIncomplete(
			[ 'accra' => $line ],
			$this->checkout_accra()
		);

		self::assertFalse( $outcome['blocked'] );
		self::assertNotEmpty( $outcome['updated'] );
		$after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );
		self::assertTrue( $after?->hasCompleteDeliveryAddress() );
		self::assertSame( $before?->matching_identity, $after?->matching_identity );
		self::assertSame( 'Accra', $after?->matching_location?->city );
		self::assertSame( 'Checkout Street 1', $after?->delivery_address?->address_1 );
		self::assertSame( '50.00', $this->quote_amount( $after, 10 ) );
		self::assertSame( 1, array_sum( array_map( static fn ( array $item ): int => (int) ( $item['quantity'] ?? 0 ), $outcome['contents'] ) ) );
	}

	public function test_e_mixed_pickup_and_delivery_leaves_pickup_untouched(): void {
		$delivery = $this->named_line( 101, 'Chair', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$pickup   = $this->named_line(
			303,
			'Radio Pickup',
			CustomerCartContext::pickup( 4 ),
			$this->intent( 303, null, 'in_store', 'store_pickup' )
		);

		$outcome = $this->service->applyCheckoutAddressToIncomplete(
			[ 'delivery' => $delivery, 'pickup' => $pickup ],
			$this->checkout_accra()
		);

		$pickup_after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 303 ) );
		self::assertTrue( $pickup_after?->isPickup() );
		self::assertSame( 4, $pickup_after?->pickup_location_id );
		self::assertSame( $pickup[ CustomerCartContext::CART_KEY ], $this->find_line( $outcome['contents'], 303 )[ CustomerCartContext::CART_KEY ] );
		$delivery_after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );
		self::assertTrue( $delivery_after?->hasCompleteDeliveryAddress() );
	}

	public function test_f_international_and_local_never_cross_convert(): void {
		$local = $this->named_line( 101, 'Local Accra', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$intl  = $this->named_line(
			404,
			'Intl Boston',
			PerItemContextFixtures::incompleteContext( 30, PerItemContextFixtures::matchingBoston() ),
			$this->intent( 404, 30, FulfilmentAvailability::InternationalFulfilment->value, 'delivery' )
		);
		$empty_local = $this->named_line( 202, 'Empty local', PerItemContextFixtures::emptyMatchingContext( 10 ) );

		$local_us = $this->service->applyCheckoutAddressToIncomplete(
			[ 'local' => $local ],
			$this->checkout_boston()
		);
		self::assertSame( [], $local_us['updated'] );
		self::assertSame( 'Accra', CustomerCartContext::fromCartItem( $local_us['contents']['local'] )?->matching_location?->city );

		$intl_accra = $this->service->applyCheckoutAddressToIncomplete(
			[ 'intl' => $intl ],
			$this->checkout_accra()
		);
		self::assertSame( [], $intl_accra['updated'] );
		self::assertSame( 'Boston', CustomerCartContext::fromCartItem( $intl_accra['contents']['intl'] )?->matching_location?->city );

		$empty_us = $this->service->applyCheckoutAddressToIncomplete(
			[ 'empty' => $empty_local ],
			$this->checkout_boston()
		);
		self::assertSame( [], $empty_us['updated'] );
		self::assertFalse( CustomerCartContext::fromCartItem( $empty_us['contents']['empty'] )?->hasMatchingLocation() );
	}

	public function test_g_cart_keys_and_quantities_remain_correct_after_blocked_flatten(): void {
		$a = $this->named_line( 16, 'A', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ), $this->intent( 16, 10 ), 2 );
		$b = $this->named_line( 16, 'B', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ), $this->intent( 16, 10 ), 3 );
		$contents = [ 'a' => $a, 'b' => $b ];
		$before_keys = array_keys( $contents );
		$before_qty  = 5;

		$outcome = $this->service->applyCheckoutAddressToIncomplete( $contents, $this->checkout_accra() );

		self::assertSame( $before_keys, array_keys( $outcome['contents'] ) );
		self::assertSame(
			$before_qty,
			array_sum( array_map( static fn ( array $item ): int => (int) ( $item['quantity'] ?? 0 ), $outcome['contents'] ) )
		);
		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( $outcome['contents']['a'] ),
			CartLineCustomerIdentity::cartIdFromItem( $outcome['contents']['b'] )
		);
	}

	public function test_h_delivery_charges_do_not_change_when_heterogeneous_action_is_refused(): void {
		$gh    = $this->named_line( 16, 'GH', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) );
		$accra = $this->named_line( 16, 'Accra', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$before = [
			'30.00',
			'50.00',
		];
		$outcome = $this->service->applyCheckoutAddressToIncomplete(
			[ 'gh' => $gh, 'accra' => $accra ],
			$this->checkout_accra()
		);
		$after = [
			$this->quote_amount( CustomerCartContext::fromCartItem( $outcome['contents']['gh'] ), 10 ),
			$this->quote_amount( CustomerCartContext::fromCartItem( $outcome['contents']['accra'] ), 10 ),
		];
		self::assertSame( $before, $after );
		self::assertNotSame( '50.00', $after[0] );
	}

	public function test_i_destination_truth_stays_on_selected_matching_locations(): void {
		$gh    = $this->named_line( 16, 'GH', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) );
		$accra = $this->named_line( 16, 'Accra', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$outcome = $this->service->applyCheckoutAddressToIncomplete(
			[ 'gh' => $gh, 'accra' => $accra ],
			$this->checkout_accra()
		);

		$gh_ctx    = CustomerCartContext::fromCartItem( $outcome['contents']['gh'] );
		$accra_ctx = CustomerCartContext::fromCartItem( $outcome['contents']['accra'] );
		self::assertSame( 'GH', $gh_ctx?->publicLocalityLabel() );
		self::assertSame( 'Accra', $accra_ctx?->publicLocalityLabel() );
		self::assertSame( 'GH', $gh_ctx?->toWcPackageDestination()['country'] );
		self::assertSame( '', $gh_ctx?->toWcPackageDestination()['city'] );
		self::assertSame( 'Accra', $accra_ctx?->toWcPackageDestination()['city'] );
		self::assertNotSame(
			(string) DeliveryGroupIdentity::fromCartItem( $outcome['contents']['gh'] ),
			(string) DeliveryGroupIdentity::fromCartItem( $outcome['contents']['accra'] )
		);
	}

	public function test_j_classic_checkout_regression_alignment_and_incomplete_validation(): void {
		$accra = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$one   = [
			'a' => PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra ),
			'b' => PerItemContextFixtures::cartItem( $this->intent( 202, 10 ), $accra ),
		];
		self::assertFalse( $this->policy->summarize_cart( $one )['multi_destination'] );
		self::assertNotNull( $this->policy->shared_complete_delivery_address( $one ) );

		$GLOBALS['cetech_de_test_wc'] = (object) [
			'cart' => new class( $one ) {
				/**
				 * @param array<string, array<string, mixed>> $contents
				 */
				public function __construct( private array $contents ) {
				}

				public function get_cart(): array {
					return $this->contents;
				}
			},
		];
		$aligned = $this->policy->maybe_align_posted_shipping( [ 'shipping_country' => 'US' ] );
		self::assertSame( 'GH', $aligned['shipping_country'] );
		self::assertSame( '12 Boundary Rd', $aligned['shipping_address_1'] );

		$incomplete = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() )
		);
		$incomplete['data'] = new WC_Product( [ 'id' => 101, 'name' => 'QA Chair', 'type' => 'simple' ] );
		$result = $this->invoke_context( $incomplete );
		self::assertIsArray( $result );
		self::assertStringContainsString( 'complete delivery address', (string) ( $result['message'] ?? '' ) );
	}

	public function test_classic_and_blocks_copy_hide_destructive_bulk_action(): void {
		self::assertSame(
			'These items are going to different destinations. Add a delivery address for each item. Your selected destinations will be kept.',
			CustomerStorefrontCopy::heterogeneous_incomplete_destinations()
		);

		$policy = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Checkout/CheckoutAddressPolicy.php' );
		self::assertStringContainsString( 'can_apply_checkout_address', $policy );
		self::assertStringContainsString( 'heterogeneous_incomplete_destinations', $policy );
		self::assertStringContainsString( 'CustomerStorefrontCopy::heterogeneous_incomplete_destinations()', $policy );

		$blocks = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Integrations/Blocks/BlocksStoreApiExtension.php' );
		self::assertStringContainsString( 'heterogeneous_destinations', $blocks );
		self::assertStringContainsString( "\$notices['can_apply_checkout_address']", $blocks );

		$handler = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Integrations/Blocks/BlocksCartContextCommandHandler.php' );
		self::assertStringContainsString( 'applyCheckoutAddressToIncomplete', $handler );
		self::assertStringContainsString( "[] !== ( \$outcome['updated'] ?? [] )", $handler );

		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/frontend/blocks-checkout.js' );
		self::assertStringContainsString( 'can_apply_checkout_address', $js );
	}

	public function test_delivery_areas_copy_names_priority_then_specificity_without_changing_matcher(): void {
		$areas = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );
		self::assertStringContainsString( 'configured priority first (lower number first)', $areas );
		self::assertStringContainsString( 'then geographic specificity when priority is equal', $areas );
		self::assertStringContainsString( 'then a stable tie-break', $areas );
		self::assertStringNotContainsString( 'A more-specific area such as a city is used first.', $areas );

		$matcher = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Destination/DestinationZoneMatcher.php' );
		self::assertStringContainsString( 'ordered by configured priority, then', $matcher );
		self::assertStringContainsString( 'geographic specificity', $matcher );
		self::assertStringContainsString( 'deterministic name/code tie-break', $matcher );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function checkout_accra(): array {
		return [
			'country'   => 'GH',
			'state'     => 'AA',
			'city'      => 'Accra',
			'postcode'  => 'GA-123',
			'address_1' => 'Checkout Street 1',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function checkout_boston(): array {
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
		return new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				$city    = strtolower( (string) ( $destination['city'] ?? '' ) );
				$country = strtoupper( (string) ( $destination['country'] ?? '' ) );
				if ( in_array( $city, [ 'accra', 'east legon', 'spintex' ], true ) ) {
					return [ 20 ];
				}
				if ( 'kumasi' === $city ) {
					return [ 21 ];
				}
				if ( 'boston' === $city || 'US' === $country ) {
					return [ 30 ];
				}
				if ( '' === $city && 'GH' === $country ) {
					return [ 22 ];
				}

				return [];
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
	 * @param array<string, mixed> $item
	 *
	 * @return array{status: string, message: string}|null
	 */
	private function invoke_context( array $item ): ?array {
		$cls    = new ReflectionClass( CheckoutDeliverySelectionValidator::class );
		$obj    = $cls->newInstanceWithoutConstructor();
		$prop   = $cls->getProperty( 'quote_probe' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( $obj, $this->probe );
		$method = $cls->getMethod( 'validate_customer_context' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}

		/** @var array{status: string, message: string}|null $result */
		$result = $method->invoke( $obj, $item );

		return $result;
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
