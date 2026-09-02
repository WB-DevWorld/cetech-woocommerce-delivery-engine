<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\CustomerContext\ClassicPdpContextPayload;
use CetechDeliveryEngine\Application\CustomerContext\CustomerBrowsingLocationStore;
use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WC_Product;

final class ClassicPerItemCustomerUxTest extends TestCase {

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
	}

	public function test_delivery_requires_matching_location_when_configured(): void {
		$filter = new LocationAwareDeliveryOptions( $this->probe() );
		$delivery = $this->option( 'in_warehouse:delivery:10', FulfilmentChoice::Delivery->value, 10 );

		self::assertTrue( $filter->delivery_requires_matching_location( [ $delivery ] ) );
		self::assertSame( [], $filter->filter( [ $delivery ], null, $this->currency() ) );
		self::assertNotEmpty( $filter->filter( [ $delivery ], PerItemContextFixtures::matchingAccra(), $this->currency() ) );
	}

	public function test_pickup_does_not_require_shipping_destination(): void {
		$filter = new LocationAwareDeliveryOptions( $this->probe() );
		$pickup  = $this->option( 'in_store:store_pickup:pickup', FulfilmentChoice::StorePickup->value, null, 4 );

		self::assertFalse( $filter->delivery_requires_matching_location( [ $pickup ] ) );
		self::assertCount( 1, $filter->filter( [ $pickup ], null, $this->currency() ) );
	}

	public function test_location_determines_available_delivery_options(): void {
		$filter   = new LocationAwareDeliveryOptions( $this->probe() );
		$delivery = $this->option( 'in_warehouse:delivery:10', FulfilmentChoice::Delivery->value, 10 );

		self::assertNotEmpty( $filter->filter( [ $delivery ], PerItemContextFixtures::matchingAccra(), $this->currency() ) );
		self::assertSame( [], $filter->filter( [ $delivery ], MatchingLocation::fromInput( [ 'country' => 'NG', 'city' => 'Lagos' ] ), $this->currency() ) );
	}

	public function test_international_option_is_not_converted_by_local_location_filter(): void {
		$filter = new LocationAwareDeliveryOptions( $this->probe() );
		$intl   = $this->option( 'international_fulfilment:delivery:30', FulfilmentChoice::Delivery->value, 30 );
		$filtered = $filter->filter( [ $intl ], PerItemContextFixtures::matchingAccra(), $this->currency() );

		self::assertSame( [], $filtered );
		self::assertSame( FulfilmentAvailability::InternationalFulfilment->value, $intl->fulfilment_availability );
	}

	public function test_warehouse_local_offer_quotes_only_for_matching_zone(): void {
		$filter   = new LocationAwareDeliveryOptions( $this->probe() );
		$delivery = $this->option( 'in_warehouse:delivery:10', FulfilmentChoice::Delivery->value, 10 );

		self::assertNotEmpty( $filter->filter( [ $delivery ], PerItemContextFixtures::matchingAccra(), $this->currency() ) );
		self::assertNotEmpty( $filter->filter( [ $delivery ], PerItemContextFixtures::matchingKumasi(), $this->currency() ) );
	}

	public function test_browsing_location_store_does_not_rewrite_cart_lines(): void {
		$session = new class() {
			/** @var array<string, mixed> */
			public array $data = [];

			public function get( string $key ) {
				return $this->data[ $key ] ?? null;
			}

			public function set( string $key, $value ): void {
				$this->data[ $key ] = $value;
			}
		};
		$GLOBALS['cetech_de_test_wc'] = (object) [ 'session' => $session ];

		$store = new CustomerBrowsingLocationStore();
		$store->save( PerItemContextFixtures::matchingAccra() );
		$line = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingKumasi() )
		);

		$store->save( PerItemContextFixtures::matchingAccra() );
		$context = CustomerCartContext::fromCartItem( $line );

		self::assertSame( 'Kumasi', $context?->matching_location?->city );
		self::assertSame( 'Accra', $store->get()?->city );
	}

	public function test_use_for_all_updates_eligible_delivery_and_skips_pickup_and_international(): void {
		$accra = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$chair = PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra );
		$chair['data'] = new WC_Product( [ 'id' => 101, 'name' => 'QA Chair', 'type' => 'simple' ] );

		$lamp_ctx = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingKumasi() );
		$lamp     = PerItemContextFixtures::cartItem( $this->intent( 202, 10 ), $lamp_ctx );
		$lamp['data'] = new WC_Product( [ 'id' => 202, 'name' => 'QA Lamp', 'type' => 'simple' ] );

		$pickup = PerItemContextFixtures::cartItem(
			$this->intent( 303, null, 'in_store', 'store_pickup' ),
			CustomerCartContext::pickup( 4 )
		);
		$pickup['data'] = new WC_Product( [ 'id' => 303, 'name' => 'QA Radio Pickup', 'type' => 'simple' ] );

		$radio_intent = $this->intent( 404, 30, FulfilmentAvailability::InternationalFulfilment->value, 'delivery' );
		$radio        = PerItemContextFixtures::cartItem(
			$radio_intent,
			PerItemContextFixtures::incompleteContext( 30, MatchingLocation::fromInput( [ 'country' => 'US', 'city' => 'Boston' ] ) )
		);
		$radio['data'] = new WC_Product( [ 'id' => 404, 'name' => 'QA Radio', 'type' => 'simple' ] );

		$contents = [
			'chair' => $chair,
			'lamp'  => $lamp,
			'pick'  => $pickup,
			'radio' => $radio,
		];

		$service = new ApplyCustomerContextToEligibleLinesService(
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			$this->probe()
		);

		$outcome = $service->applyDeliveryLocation( $contents, 'chair', $accra );
		$names_updated = array_map( static fn ( array $row ): string => $row['name'], $outcome['updated'] );
		$skip_reasons = array_map( static fn ( array $row ): string => $row['name'] . ':' . $row['reason'], $outcome['skipped'] );

		self::assertContains( 'QA Chair', $names_updated );
		self::assertContains( 'QA Lamp', $names_updated );
		self::assertTrue( str_contains( implode( ' ', $skip_reasons ), 'QA Radio Pickup' ) );
		self::assertTrue( str_contains( implode( ' ', $skip_reasons ), 'International' ) );

		$lamp_after = CustomerCartContext::fromCartItem( $outcome['contents']['lamp'] ?? $this->find_line( $outcome['contents'], 202 ) );
		self::assertTrue( $lamp_after?->hasCompleteDeliveryAddress() );
		$pickup_after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 303 ) );
		self::assertTrue( $pickup_after?->isPickup() );
		$radio_after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 404 ) );
		self::assertSame( 'Boston', $radio_after?->matching_location?->city );
	}

	public function test_failed_line_unchanged_when_quote_missing(): void {
		$accra = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$chair = PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra );
		$other = PerItemContextFixtures::cartItem(
			$this->intent( 202, 10 ),
			PerItemContextFixtures::incompleteContext( 10, MatchingLocation::fromInput( [ 'country' => 'NG', 'city' => 'Lagos' ] ) )
		);
		$other['data'] = new WC_Product( [ 'id' => 202, 'name' => 'QA Other', 'type' => 'simple' ] );

		$lagos_requested = PerItemContextFixtures::deliveryContext(
			10,
			\CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress::fromInput(
				[
					'country'   => 'NG',
					'city'      => 'Lagos',
					'address_1' => '1 Broad St',
				]
			)
		);

		$service = new ApplyCustomerContextToEligibleLinesService(
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			$this->probe()
		);

		$before = $other[ CustomerCartContext::CART_KEY ];
		$outcome = $service->applyDeliveryLocation( [ 'chair' => $chair, 'other' => $other ], 'chair', $lagos_requested );
		self::assertNotEmpty( $outcome['skipped'] );
		self::assertSame( $before, $outcome['contents']['other'][ CustomerCartContext::CART_KEY ] );
	}

	public function test_checkout_complete_one_address_and_multi_address_policy(): void {
		$policy = new CheckoutAddressPolicy(
			new FeatureFlags(),
			new Requirements(),
			new ApplyCustomerContextToEligibleLinesService(
				new CartCustomerContextMutationService(),
				$this->always_valid_validator(),
				$this->probe()
			),
			new CartCustomerContextMutationService()
		);

		$accra = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$one   = [
			'a' => PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra ),
			'b' => PerItemContextFixtures::cartItem( $this->intent( 202, 10 ), $accra ),
		];
		$summary_one = $policy->summarize_cart( $one );
		self::assertFalse( $summary_one['multi_destination'] );
		self::assertSame( 0, $summary_one['incomplete_delivery'] );
		self::assertNotNull( $policy->shared_complete_delivery_address( $one ) );

		$kumasi = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryKumasi() );
		$multi  = [
			'a' => PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra ),
			'b' => PerItemContextFixtures::cartItem( $this->intent( 202, 10 ), $kumasi ),
		];
		$summary_multi = $policy->summarize_cart( $multi );
		self::assertTrue( $summary_multi['multi_destination'] );
		self::assertNull( $policy->shared_complete_delivery_address( $multi ) );

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
		$aligned = $policy->maybe_align_posted_shipping( [ 'shipping_country' => 'US' ] );
		self::assertSame( 'GH', $aligned['shipping_country'] );
		self::assertSame( '12 Boundary Rd', $aligned['shipping_address_1'] );
	}

	public function test_incomplete_delivery_fails_and_explicit_checkout_address_completes_eligible_only(): void {
		$incomplete = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() )
		);
		$incomplete['data'] = new WC_Product( [ 'id' => 101, 'name' => 'QA Chair', 'type' => 'simple' ] );

		$complete = PerItemContextFixtures::cartItem(
			$this->intent( 202, 10 ),
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryKumasi() )
		);
		$pickup = PerItemContextFixtures::cartItem(
			$this->intent( 303, null, 'in_store', 'store_pickup' ),
			CustomerCartContext::pickup( 4 )
		);

		$checkout = $this->checkout_context_validator( $this->probe() );
		$incomplete_result = $this->invoke_context( $checkout, $incomplete );
		self::assertIsArray( $incomplete_result );
		self::assertStringContainsString( 'complete delivery address', (string) ( $incomplete_result['message'] ?? '' ) );

		$complete_result = $this->invoke_context( $checkout, $complete );
		self::assertNull( $complete_result );

		$pickup_result = $this->invoke_context( $checkout, $pickup );
		self::assertNull( $pickup_result );

		$service = new ApplyCustomerContextToEligibleLinesService(
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			$this->probe()
		);
		$outcome = $service->applyCheckoutAddressToIncomplete(
			[ 'inc' => $incomplete, 'done' => $complete, 'pick' => $pickup ],
			[
				'country'   => 'GH',
				'state'     => 'AA',
				'city'      => 'Accra',
				'postcode'  => 'GA-123',
				'address_1' => 'Checkout Street 1',
			]
		);

		$updated = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 101 ) );
		self::assertTrue( $updated?->hasCompleteDeliveryAddress() );
		self::assertSame( 'Checkout Street 1', $updated?->delivery_address?->address_1 );

		$untouched = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 202 ) );
		self::assertSame( '7 Lake Rd', $untouched?->delivery_address?->address_1 );
		$pickup_after = CustomerCartContext::fromCartItem( $this->find_line( $outcome['contents'], 303 ) );
		self::assertTrue( $pickup_after?->isPickup() );
	}

	public function test_invalid_destination_fails_closed(): void {
		$item = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::deliveryContext(
				10,
				\CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress::fromInput(
					[
						'country'   => 'NG',
						'city'      => 'Lagos',
						'address_1' => '1 Broad St',
					]
				)
			)
		);
		$item['data'] = new WC_Product( [ 'id' => 101, 'name' => 'QA Chair', 'type' => 'simple' ] );
		$result = $this->invoke_context( $this->checkout_context_validator( $this->probe() ), $item );
		self::assertIsArray( $result );
		self::assertStringContainsString( 'not available', (string) ( $result['message'] ?? '' ) );
	}

	public function test_pickup_incomplete_fails_closed(): void {
		$item = PerItemContextFixtures::cartItem(
			$this->intent( 303, null, 'in_store', 'store_pickup' ),
			CustomerCartContext::pickup( null )
		);
		$item['data'] = new WC_Product( [ 'id' => 303, 'name' => 'QA Lamp', 'type' => 'simple' ] );
		$result = $this->invoke_context( $this->checkout_context_validator( $this->probe() ), $item );
		self::assertIsArray( $result );
		self::assertStringContainsString( 'pickup location', (string) ( $result['message'] ?? '' ) );
	}

	public function test_v1_line_without_customer_context_is_not_failed_by_context_rules(): void {
		$item = [
			'product_id' => 101,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->intent( 101, 10 ),
		];
		self::assertNull( $this->invoke_context( $this->checkout_context_validator( $this->probe() ), $item ) );
	}

	public function test_privacy_raw_address_absent_from_keys_group_ids_html_and_logs(): void {
		$ctx  = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$item = PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $ctx );
		$key  = CartLineCustomerIdentity::cartIdFromItem( $item );
		$group = (string) DeliveryGroupIdentity::fromCartItem( $item );

		self::assertStringNotContainsString( '12 Boundary', $key );
		self::assertStringNotContainsString( 'Ama', $key );
		self::assertStringNotContainsString( '0244000000', $key );
		self::assertStringNotContainsString( '12 Boundary', $group );
		self::assertStringNotContainsString( 'Ama', $group );

		$editor_src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartCustomerContextEditorRenderer.php' );
		self::assertStringNotContainsString( 'data-group-id', $editor_src );
		self::assertStringNotContainsString( 'data-address_1', $editor_src );
		self::assertStringNotContainsString( 'matching_identity', $editor_src );

		$logger = ( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( Logger::class, 'sanitize_context' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}
		$clean = $method->invoke(
			$logger,
			[
				'first_name' => 'Ama',
				'phone'      => '0244000000',
				'address_1'  => '12 Boundary Rd',
				'offer_id'   => 10,
			]
		);
		self::assertArrayNotHasKey( 'first_name', $clean );
		self::assertArrayNotHasKey( 'phone', $clean );
		self::assertArrayNotHasKey( 'address_1', $clean );
		self::assertArrayNotHasKey( 'recipient', $clean );
		self::assertSame( 10, $clean['offer_id'] );
	}

	public function test_cart_editor_markup_exposes_quantity_split_and_use_for_all(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartCustomerContextEditorRenderer.php' );
		self::assertStringContainsString( 'Apply to all %d items', $src );
		self::assertStringContainsString( 'Move some quantity', $src );
		self::assertStringContainsString( 'Use this address for all eligible delivery items', $src );
		self::assertStringContainsString( 'Change delivery', $src );
		self::assertStringContainsString( 'Change pickup', $src );
		self::assertStringContainsString( '-apply-all', $src );
		self::assertStringContainsString( '-apply-split', $src );
		self::assertStringNotContainsString( 'data-group-id', $src );
		self::assertStringNotContainsString( 'per-destination tax', $src );
		self::assertStringNotContainsString( 'per destination tax', $src );
	}

	public function test_editor_context_from_post_builds_delivery_and_pickup_without_mutating_arrays(): void {
		$service = new \CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService(
			new FeatureFlags(),
			new Requirements(),
			new CartCustomerContextMutationService(),
			$this->always_valid_validator(),
			new ApplyCustomerContextToEligibleLinesService(
				new CartCustomerContextMutationService(),
				$this->always_valid_validator(),
				$this->probe()
			)
		);

		$_POST = [
			CartDeliverySelectionCapture::POST_FIELD => 'in_warehouse:delivery:10',
			'cetech_de_matching_country'               => 'GH',
			'cetech_de_matching_city'                 => 'Accra',
			'cetech_de_address_1'                     => '12 Boundary Rd',
		];
		$delivery_item = PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$delivery      = $service->context_from_post( $delivery_item );
		self::assertTrue( $delivery?->isDelivery() );
		self::assertTrue( $delivery?->hasCompleteDeliveryAddress() );
		self::assertSame( '12 Boundary Rd', $delivery?->delivery_address?->address_1 );

		$_POST = [
			CartDeliverySelectionCapture::POST_FIELD => 'in_store:store_pickup:pickup',
			'cetech_de_pickup_location_id'            => '4',
		];
		$pickup_item = PerItemContextFixtures::cartItem( $this->intent( 303, null, 'in_store', 'store_pickup' ), CustomerCartContext::pickup( 4 ) );
		$pickup      = $service->context_from_post( $pickup_item );
		self::assertTrue( $pickup?->isPickup() );
		self::assertSame( 4, $pickup?->pickup_location_id );
		unset( $_POST );
	}

	public function test_delivery_and_pickup_and_distinct_pickup_locations_stay_separate(): void {
		$delivery = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) )
		);
		$pickup_a = PerItemContextFixtures::cartItem(
			$this->intent( 202, null, 'in_store', 'store_pickup' ),
			CustomerCartContext::pickup( 4 )
		);
		$pickup_b = PerItemContextFixtures::cartItem(
			$this->intent( 202, null, 'in_store', 'store_pickup' ),
			CustomerCartContext::pickup( 5 )
		);

		$g_delivery = (string) DeliveryGroupIdentity::fromCartItem( $delivery );
		$g_a       = (string) DeliveryGroupIdentity::fromCartItem( $pickup_a );
		$g_b       = (string) DeliveryGroupIdentity::fromCartItem( $pickup_b );

		self::assertNotSame( $g_delivery, $g_a );
		self::assertNotSame( $g_a, $g_b );
		self::assertTrue( DeliveryGroupIdentity::is_pickup_group( $g_a ) );
		self::assertFalse( DeliveryGroupIdentity::is_pickup_group( $g_delivery ) );
	}

	public function test_capture_classic_delivery_requires_location_pickup_does_not(): void {
		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$_POST   = [];
		$matching = ( new \ReflectionMethod( CartDeliverySelectionCapture::class, 'read_submitted_matching_location' ) );
		if ( \PHP_VERSION_ID < 80500 ) {
			$matching->setAccessible( true );
		}
		self::assertNull( $matching->invoke( $capture ) );

		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_COUNTRY ] = 'GH';
		$_POST[ CartDeliverySelectionCapture::POST_MATCHING_CITY ]     = 'Accra';
		$location = $matching->invoke( $capture );
		self::assertInstanceOf( MatchingLocation::class, $location );
		self::assertSame( 'Accra', $location->city );
		unset( $_POST );
	}

	public function test_submitted_pdp_payload_wins_over_stale_matching_fields(): void {
		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$matching = new ReflectionMethod( CartDeliverySelectionCapture::class, 'read_submitted_matching_location' );
		$key     = new ReflectionMethod( CartDeliverySelectionCapture::class, 'read_submitted_display_key' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$matching->setAccessible( true );
			$key->setAccessible( true );
		}

		$_POST = [
			CartDeliverySelectionCapture::POST_MATCHING_COUNTRY  => 'GH',
			CartDeliverySelectionCapture::POST_MATCHING_STATE    => 'AA',
			CartDeliverySelectionCapture::POST_MATCHING_CITY     => 'Accra',
			CartDeliverySelectionCapture::POST_MATCHING_POSTCODE => 'GA-123',
			CartDeliverySelectionCapture::POST_FIELD             => 'in_warehouse:delivery:10',
			ClassicPdpContextPayload::POST_FIELD                 => wp_json_encode(
				[
					'matching_location' => [
						'country'  => 'GH',
						'state'    => 'AH',
						'city'     => 'Kumasi',
						'postcode' => 'AK-000',
					],
					'display_key'       => 'in_warehouse:delivery:10',
					'fulfilment_choice' => 'delivery',
				]
			),
		];

		$location = $matching->invoke( $capture );
		self::assertInstanceOf( MatchingLocation::class, $location );
		self::assertSame( 'Kumasi', $location->city );
		self::assertSame( 'in_warehouse:delivery:10', $key->invoke( $capture ) );
		unset( $_POST );
	}

	public function test_browsing_default_is_not_read_as_submitted_matching_location(): void {
		$session = new class() {
			/** @var array<string, mixed> */
			public array $data = [];

			public function get( string $key ) {
				return $this->data[ $key ] ?? null;
			}

			public function set( string $key, $value ): void {
				$this->data[ $key ] = $value;
			}
		};
		$GLOBALS['cetech_de_test_wc'] = (object) [ 'session' => $session ];
		( new CustomerBrowsingLocationStore() )->save( PerItemContextFixtures::matchingAccra() );

		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$matching = new ReflectionMethod( CartDeliverySelectionCapture::class, 'read_submitted_matching_location' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$matching->setAccessible( true );
		}
		$_POST = [];
		self::assertNull( $matching->invoke( $capture ) );
		unset( $_POST, $GLOBALS['cetech_de_test_wc'] );
	}

	public function test_stale_display_key_cannot_validate_against_wrong_destination(): void {
		$filter   = new LocationAwareDeliveryOptions( $this->probe() );
		$delivery = $this->option( 'in_warehouse:delivery:10', FulfilmentChoice::Delivery->value, 10 );

		self::assertNotEmpty( $filter->filter( [ $delivery ], PerItemContextFixtures::matchingAccra(), $this->currency() ) );
		self::assertSame( [], $filter->filter( [ $delivery ], MatchingLocation::fromInput( [ 'country' => 'NG', 'city' => 'Lagos' ] ), $this->currency() ) );
	}

	public function test_context_exists_before_cart_id_and_second_destination_is_distinct(): void {
		$intent = $this->intent( 16, 10 );
		$accra  = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() );
		$kumasi = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingKumasi() );

		$accra_data = $accra->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] );
		$kumasi_data = $kumasi->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] );

		$without = CartLineCustomerIdentity::generateCartId( 16, 0, [], [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] );
		$accra_id = CartLineCustomerIdentity::generateCartId( 16, 0, [], $accra_data );
		$kumasi_id = CartLineCustomerIdentity::generateCartId( 16, 0, [], $kumasi_data );

		self::assertNotSame( $without, $accra_id );
		self::assertNotSame( $accra_id, $kumasi_id );
		self::assertStringNotContainsString( 'Accra', $accra_id );
		self::assertStringNotContainsString( 'Kumasi', $kumasi_id );
	}

	public function test_same_complete_destination_consolidates(): void {
		$ctx = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$a   = PerItemContextFixtures::cartItem( $this->intent( 16, 10 ), $ctx, 1 );
		$b   = PerItemContextFixtures::cartItem( $this->intent( 16, 10 ), $ctx, 1 );

		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $a ), CartLineCustomerIdentity::cartIdFromItem( $b ) );
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

	private function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'USD';
	}

	private function option( string $key, string $choice, ?int $offer_id, ?int $pickup_id = null ): ProductDeliveryOption {
		return new ProductDeliveryOption(
			$key,
			explode( ':', $key )[0],
			'Availability',
			$choice,
			'Choice',
			$offer_id,
			$choice === FulfilmentChoice::StorePickup->value ? 'Store pickup' : 'QA Local Standard',
			null,
			'2-4 days',
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			$pickup_id ? 'QA Accra Pickup' : null,
			null,
			null,
			$pickup_id
		);
	}

	private function probe(): LocationOfferQuoteProbe {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				$city = strtolower( (string) ( $destination['city'] ?? '' ) );
				if ( in_array( $city, [ 'accra', 'east legon', 'spintex' ], true ) ) {
					return [ 20 ];
				}
				if ( 'kumasi' === $city ) {
					return [ 21 ];
				}

				return [];
			}
		};

		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'GHS';

		return new LocationOfferQuoteProbe(
			$zone,
			new RateQuoteEngine(
				new InMemoryQuoteRateCardRepository(
					[
						[
							'id'                  => 4,
							'delivery_offer_id'   => 10,
							'destination_zone_id' => 20,
							'charge_type'         => RateCardChargeType::FixedPerShipment->value,
							'base_amount'         => '15.00',
							'base_currency'       => $currency,
							'priority'           => 100,
							'status'              => 'active',
						],
						[
							'id'                  => 5,
							'delivery_offer_id'   => 10,
							'destination_zone_id' => 21,
							'charge_type'         => RateCardChargeType::FixedPerShipment->value,
							'base_amount'         => '35.00',
							'base_currency'       => $currency,
							'priority'           => 100,
							'status'              => 'active',
						],
					]
				)
			)
		);
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

	private function checkout_context_validator( LocationOfferQuoteProbe $probe ): CheckoutDeliverySelectionValidator {
		$cls = new ReflectionClass( CheckoutDeliverySelectionValidator::class );
		$obj = $cls->newInstanceWithoutConstructor();
		$prop = $cls->getProperty( 'quote_probe' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( $obj, $probe );

		return $obj;
	}

	/**
	 * @param array<string, mixed> $item
	 *
	 * @return array{status: string, message: string}|null
	 */
	private function invoke_context( CheckoutDeliverySelectionValidator $validator, array $item ): ?array {
		$method = new \ReflectionMethod( CheckoutDeliverySelectionValidator::class, 'validate_customer_context' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}

		/** @var array{status: string, message: string}|null $result */
		$result = $method->invoke( $validator, $item );

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
