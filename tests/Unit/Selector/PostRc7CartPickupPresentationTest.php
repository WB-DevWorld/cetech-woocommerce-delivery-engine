<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Frontend\CartFulfilmentPackagePresentation;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use CetechDeliveryEngine\Tests\Unit\Shipping\Stage8aFixedRateCardRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PostRc7CartPickupPresentationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH' => 'Ghana',
			]
		);

		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'        => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
			'cetech_de_enable_woocommerce_shipping_rate_calculation'  => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
		}
	}

	protected function tearDown(): void {
		WooCommerceCountryCatalog::override_for_tests( null );
		parent::tearDown();
	}

	public function test_structured_pickup_address_is_human_readable_never_json(): void {
		$json = wp_json_encode(
			[
				'line1'        => 'No. 31 Papafio Hills Road, Ashaley Botwe, School Junction',
				'line2'        => '',
				'city'         => 'Accra',
				'region'       => 'Greater Accra',
				'country_code' => 'GH',
				'postcode'     => '',
			]
		);

		$formatted = PickupLocationAddressFormatter::format( (string) $json );

		self::assertSame(
			'No. 31 Papafio Hills Road, Ashaley Botwe, School Junction, Accra, Greater Accra, Ghana',
			$formatted
		);
		self::assertStringNotContainsString( '{', $formatted );
		self::assertStringNotContainsString( 'line1', $formatted );
		self::assertStringNotContainsString( 'country_code', $formatted );
		self::assertStringNotContainsString( '"GH"', $formatted );
	}

	public function test_builder_and_cart_summary_never_expose_serialized_address(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$offers->seed(
			11,
			[
				'status'       => RecordStatus::Active->value,
				'public_label' => 'Standard Delivery',
				'route'        => DeliveryRoute::LocalDelivery->value,
			]
		);
		$pickups = new InMemoryPickupLocationRepository();
		$pickups->seed(
			7,
			[
				'status'                     => RecordStatus::Active->value,
				'location_name'              => 'CETECH Accra Store',
				'public_address'             => wp_json_encode(
					[
						'line1'        => 'No. 31 Papafio Hills Road, Ashaley Botwe, School Junction',
						'city'         => 'Accra',
						'region'       => 'Greater Accra',
						'country_code' => 'GH',
					]
				),
				'public_pickup_instructions' => 'Collect from the CETECH Store',
				'readiness_estimate'         => '1–2 business days',
			]
		);

		$rule = new ResolvedProductDeliveryRule(
			1,
			ProductTargetType::Product->value,
			101,
			null,
			2,
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::StorePickup->value,
			[],
			null,
			null,
			null,
			100,
			7
		);
		$result = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			101,
			null,
			[],
			'',
			[ $rule ],
			[ FulfilmentAvailability::InStore->value => $rule ],
			[],
			[],
			[],
			null
		);

		$options = ( new ProductDeliveryOptionsBuilder( $offers, $pickups ) )->buildFromResolution( $result );

		self::assertNotEmpty( $options );
		$address = (string) $options[0]->pickup_address;
		self::assertStringContainsString( 'Accra', $address );
		self::assertStringContainsString( 'Ghana', $address );
		self::assertStringNotContainsString( '{', $address );
		self::assertStringNotContainsString( 'line1', $address );

		$summary = CartDeliverySelectionCapture::buildPublicSummary( $options[0]->toArray() );
		$rows    = DeliveryPresentationLabels::format_public_summary_rows( $summary, FulfilmentChoice::StorePickup->value );
		$values  = array_column( $rows, 'value' );
		$joined  = implode( ' ', $values );
		self::assertStringNotContainsString( '{', $joined );
		self::assertStringNotContainsString( 'line1', $joined );
		self::assertContains( $address, $values );
	}

	public function test_display_time_format_repairs_json_already_in_cart_summary(): void {
		$json = '{"line1":"12 Independence Ave","city":"Accra","region":"","country_code":"GH","postcode":""}';
		$rows = DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_choice_label' => 'Store pickup',
				'delivery_offer_public_label' => 'Store pickup',
				'pickup_location_label' => 'CETECH Accra Store',
				'pickup_address' => $json,
				'estimate_text' => '1–2 business days',
			],
			FulfilmentChoice::StorePickup->value
		);

		$address_row = null;
		foreach ( $rows as $row ) {
			if ( 'Pickup address' === $row['key'] ) {
				$address_row = $row['value'];
			}
		}

		self::assertNotNull( $address_row );
		self::assertSame( '12 Independence Ave, Accra, Ghana', $address_row );
		self::assertStringNotContainsString( '{', $address_row );
	}

	public function test_pickup_group_does_not_use_customer_shipping_destination(): void {
		$packages = $this->split_mixed_cart();
		$pickup   = $packages['pickup'];
		$delivery = $packages['delivery'];
		$customer = 'Accra, Greater Accra, Ghana';

		self::assertFalse( CartFulfilmentPackagePresentation::uses_customer_shipping_destination( $pickup ) );
		self::assertTrue( CartFulfilmentPackagePresentation::uses_customer_shipping_destination( $delivery ) );

		$pickup_destination = CartFulfilmentPackagePresentation::destination( $customer, $pickup );
		self::assertNotSame( $customer, $pickup_destination );
		self::assertStringContainsString( 'Papafio Hills Road', $pickup_destination );
		self::assertStringNotContainsString( 'Shipping to', $pickup_destination );
		self::assertStringNotContainsString( '{', $pickup_destination );

		$delivery_destination = CartFulfilmentPackagePresentation::destination( $customer, $delivery );
		self::assertSame( $customer, $delivery_destination );

		$pickup_heading = CartFulfilmentPackagePresentation::heading( 'Shipment 1', $pickup );
		self::assertSame( 'Pickup at CETECH Accra Store', $pickup_heading );
		self::assertStringNotContainsString( 'Shipping', $pickup_heading );
		self::assertStringNotContainsString( 'Shipment', $pickup_heading );

		$delivery_heading = CartFulfilmentPackagePresentation::heading( 'Shipping to Accra, Greater Accra, Ghana', $delivery );
		self::assertSame( 'Shipping to Accra, Greater Accra, Ghana', $delivery_heading );
	}

	public function test_woocommerce_cart_shipping_filters_use_package_not_customer_address_arg(): void {
		$packages     = $this->split_mixed_cart();
		$presentation = new CartFulfilmentPackagePresentation();
		$customer     = 'Customer Home Accra, Greater Accra, Ghana';
		$raw_address  = [
			'country' => 'GH',
			'city'    => 'Customer Home Accra',
			'state'   => 'Greater Accra',
		];

		$pickup_heading = $presentation->filter_package_name( 'Shipment 1', 0, $packages['pickup'], 2 );
		self::assertSame( 'Pickup at CETECH Accra Store', $pickup_heading );

		$pickup_destination = $presentation->filter_formatted_destination( $customer, $raw_address );
		self::assertStringContainsString( 'Papafio Hills Road', $pickup_destination );
		self::assertStringNotContainsString( 'Customer Home Accra', $pickup_destination );
		self::assertStringNotContainsString( '{', $pickup_destination );

		$pickup_copy = $presentation->filter_shipping_to_copy( 'Shipping to %s.', 'Shipping to %s.', 'woocommerce' );
		self::assertSame( 'Pickup address: %s', $pickup_copy );

		$pickup_change = $presentation->filter_shipping_to_copy( 'Change address', 'Change address', 'woocommerce' );
		self::assertSame( '', $pickup_change );

		$delivery_heading = $presentation->filter_package_name( 'Shipment 2', 1, $packages['delivery'], 2 );
		self::assertSame( 'Shipment 2', $delivery_heading );

		$delivery_destination = $presentation->filter_formatted_destination( $customer, $raw_address );
		self::assertSame( $customer, $delivery_destination );

		$delivery_copy = $presentation->filter_shipping_to_copy( 'Shipping to %s.', 'Shipping to %s.', 'woocommerce' );
		self::assertSame( 'Shipping to %s.', $delivery_copy );

		$delivery_change = $presentation->filter_shipping_to_copy( 'Change address', 'Change address', 'woocommerce' );
		self::assertSame( 'Change address', $delivery_change );
	}

	public function test_pickup_only_package_has_zero_delivery_charge(): void {
		$pickup = $this->pickup_cart_item();
		$builder = $this->package_builder();
		$packages = $builder->split_package(
			[
				'contents'    => [ 'line-p' => $pickup ],
				'destination' => [
					'country' => 'GH',
					'city'    => 'Customer Home Accra',
				],
			]
		);

		self::assertCount( 1, $packages );
		self::assertTrue( CartFulfilmentPackagePresentation::is_pickup( $packages[0] ) );

		$calculator = $this->pickup_calculator();
		$result     = $calculator->calculate_for_package( $packages[0] );

		self::assertTrue( $result->success );
		self::assertSame( '0.0000', $result->total_amount );
		self::assertSame(
			'Pickup at CETECH Accra Store',
			CartFulfilmentPackagePresentation::heading( 'Shipping', $packages[0] )
		);
	}

	public function test_pickup_only_package_html_has_no_shipping_destination_or_change_address(): void {
		$builder  = $this->package_builder();
		$packages = $builder->split_package(
			[
				'contents'    => [ 'line-p' => $this->pickup_cart_item() ],
				'destination' => [
					'country' => 'GH',
					'city'    => 'Customer Home Accra',
					'state'   => 'Greater Accra',
				],
			]
		);

		self::assertCount( 1, $packages );
		$pickup = $packages[0];
		self::assertTrue( CartFulfilmentPackagePresentation::is_pickup( $pickup ) );
		self::assertFalse( CartFulfilmentPackagePresentation::shows_shipping_to_copy( $pickup ) );
		self::assertFalse( CartFulfilmentPackagePresentation::shows_change_address( $pickup ) );
		self::assertFalse( ( new CartFulfilmentPackagePresentation() )->filter_show_shipping_calculator( true, 0, $pickup ) );

		$out = CartFulfilmentPackagePresentation::rewrite_pickup_package_html(
			$this->wc_cart_shipping_html(
				CartFulfilmentPackagePresentation::heading( 'Shipping', $pickup ),
				'Store Pickup',
				'Customer Home Accra, Greater Accra, Ghana',
				true
			),
			$pickup
		);

		$this->assert_pickup_package_copy( $out );
	}

	public function test_mixed_cart_pickup_package_html_has_no_shipping_destination_or_change_address(): void {
		$packages = $this->split_mixed_cart();
		$pickup   = $packages['pickup'];

		self::assertFalse( CartFulfilmentPackagePresentation::shows_shipping_to_copy( $pickup ) );
		self::assertFalse( CartFulfilmentPackagePresentation::shows_change_address( $pickup ) );
		self::assertFalse( ( new CartFulfilmentPackagePresentation() )->filter_show_shipping_calculator( true, 1, $pickup ) );

		$out = CartFulfilmentPackagePresentation::rewrite_pickup_package_html(
			$this->wc_cart_shipping_html(
				CartFulfilmentPackagePresentation::heading( 'Shipment 2', $pickup ),
				'Store Pickup',
				'Customer Home Accra, Greater Accra, Ghana',
				true
			),
			$pickup
		);

		$this->assert_pickup_package_copy( $out );
	}

	public function test_mixed_cart_delivery_package_keeps_shipping_destination_and_change_address(): void {
		$packages = $this->split_mixed_cart();
		$delivery = $packages['delivery'];
		$html     = $this->wc_cart_shipping_html(
			'Shipping',
			'Standard Delivery: GHS 50',
			'Customer Home Accra, Greater Accra, Ghana',
			true
		);

		self::assertTrue( CartFulfilmentPackagePresentation::shows_shipping_to_copy( $delivery ) );
		self::assertTrue( CartFulfilmentPackagePresentation::shows_change_address( $delivery ) );
		self::assertTrue( ( new CartFulfilmentPackagePresentation() )->filter_show_shipping_calculator( true, 0, $delivery ) );
		self::assertSame( $html, CartFulfilmentPackagePresentation::rewrite_pickup_package_html( $html, $delivery ) );
		self::assertStringContainsString( 'Shipping to', $html );
		self::assertStringContainsString( 'Change address', $html );
		self::assertStringContainsString( 'Standard Delivery: GHS 50', $html );
	}

	/**
	 * @return array{pickup: array<string, mixed>, delivery: array<string, mixed>}
	 */
	private function split_mixed_cart(): array {
		$builder  = $this->package_builder();
		$packages = $builder->split_package(
			[
				'contents'    => [
					'line-p' => $this->pickup_cart_item(),
					'line-d' => $this->delivery_cart_item(),
				],
				'destination' => [
					'country' => 'GH',
					'city'    => 'Customer Home Accra',
					'state'   => 'Greater Accra',
				],
			]
		);

		$pickup   = null;
		$delivery = null;
		foreach ( $packages as $package ) {
			if ( CartFulfilmentPackagePresentation::is_pickup( $package ) ) {
				$pickup = $package;
			} else {
				$delivery = $package;
			}
		}

		self::assertNotNull( $pickup );
		self::assertNotNull( $delivery );

		return [
			'pickup'   => $pickup,
			'delivery' => $delivery,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pickup_cart_item(): array {
		$json = wp_json_encode(
			[
				'line1'        => 'No. 31 Papafio Hills Road, Ashaley Botwe, School Junction',
				'city'         => 'Accra',
				'region'       => 'Greater Accra',
				'country_code' => 'GH',
			]
		);

		return [
			'product_id'   => 101,
			'variation_id' => 0,
			'quantity'     => 1,
			'line_total'   => 19.99,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => [
				'contract_version'        => '1',
				'product_id'              => 101,
				'variation_id'            => null,
				'target_type'             => 'product',
				'target_id'               => 101,
				'display_key'             => 'in_store:store_pickup:pickup',
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::StorePickup->value,
				'delivery_offer_id'       => null,
				'rule_id'                 => null,
				'issued_at'               => '2026-08-31T00:00:00+00:00',
			],
			CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
				'fulfilment_choice_label'       => 'Store pickup',
				'delivery_offer_public_label'   => 'Store pickup',
				'pickup_location_label'         => 'CETECH Accra Store',
				'pickup_address'                => $json,
				'estimate_text'                 => '1–2 business days',
				'pickup_instructions'           => 'Collect from the CETECH Store',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delivery_cart_item(): array {
		return [
			'product_id'   => 202,
			'variation_id' => 0,
			'quantity'     => 1,
			'line_total'   => 19.99,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => [
				'contract_version'        => '1',
				'product_id'              => 202,
				'variation_id'            => null,
				'target_type'             => 'product',
				'target_id'               => 202,
				'display_key'             => 'in_store:delivery:11',
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
				'delivery_offer_id'       => 11,
				'rule_id'                 => null,
				'issued_at'               => '2026-08-31T00:00:00+00:00',
			],
			CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
				'fulfilment_choice_label'     => 'Delivery',
				'delivery_offer_public_label' => 'Standard Delivery',
				'estimate_text'               => '2–4 business days',
			],
		];
	}

	private function assert_pickup_package_copy( string $html ): void {
		self::assertStringContainsString( 'Pickup at CETECH Accra Store', $html );
		self::assertStringContainsString( 'Pickup address:', $html );
		self::assertStringContainsString( 'Papafio Hills Road', $html );
		self::assertStringContainsString( 'Store Pickup', $html );
		self::assertStringNotContainsString( 'Shipping to', $html );
		self::assertStringNotContainsString( 'Change address', $html );
		self::assertStringNotContainsString( 'Customer Home Accra', $html );
		self::assertStringNotContainsString( '{', $html );
	}

	private function wc_cart_shipping_html(
		string $heading,
		string $rate_label,
		string $formatted_destination,
		bool $change_address
	): string {
		$destination = '<p class="woocommerce-shipping-destination">Shipping to <strong>'
			. esc_html( $formatted_destination )
			. '</strong>. </p>';
		$calculator  = $change_address
			? '<form class="woocommerce-shipping-calculator" action="" method="post"><a href="#" class="shipping-calculator-button">Change address</a></form>'
			: '';

		return '<tr class="woocommerce-shipping-totals shipping"><th>'
			. esc_html( $heading )
			. '</th><td><ul id="shipping_method" class="woocommerce-shipping-methods"><li><label>'
			. esc_html( $rate_label )
			. '</label></li></ul>'
			. $destination
			. $calculator
			. '</td></tr>';
	}

	private function package_builder(): ShippingPackageBuilder {
		return new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function pickup_calculator(): SelectedOfferShippingRateCalculator {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		$zone = new class() implements \CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				return 20;
			}

			public function resolve_zone_ids( array $destination ): array {
				$id = $this->resolve_zone_id( $destination );

				return null === $id ? [] : [ $id ];
			}
		};

		$assessor = new class() implements \CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				$intent = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

				return [
					'action' => 'quote',
					'intent' => is_array( $intent ) ? $intent : [],
				];
			}
		};

		$rules = $this->createMock( \CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface::class );
		$rules->method( 'findById' )->willReturn( null );

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( $flags, new Requirements() ),
			$zone,
			$assessor,
			new \CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine(
				new Stage8aFixedRateCardRepository(
					[
						[
							'id'                   => 4,
							'internal_code'        => 'STAGE0B',
							'delivery_offer_id'    => 11,
							'destination_zone_id'  => 20,
							'logistics_profile_id' => null,
							'supplier_id'          => null,
							'origin_id'            => null,
							'charge_type'          => RateCardChargeType::FixedPerShipment->value,
							'base_amount'          => '25.00',
							'base_currency'        => 'USD',
							'priority'             => 100,
							'status'               => 'active',
						],
					]
				)
			),
			$rules,
			( new ReflectionClass( \CetechDeliveryEngine\Support\Logger::class ) )->newInstanceWithoutConstructor()
		);
	}
}
