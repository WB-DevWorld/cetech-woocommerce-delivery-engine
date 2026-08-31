<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;

final class PostRc7FulfilmentCorrectnessTest extends TestCase {

	private InMemoryDeliveryOfferRepository $offers;

	private InMemoryPickupLocationRepository $pickups;

	private ProductDeliveryOptionsBuilder $builder;

	protected function setUp(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
			eval(
				'class WC_Shipping_Method {
					public string $id = "";
					public int $instance_id = 0;
					public string $title = "";
					public string $method_title = "";
					public string $method_description = "";
					public string $tax_status = "";
					public array $supports = [];
					public array $instance_form_fields = [];
					public function init_form_fields(): void {}
					public function init_settings(): void {}
					public function get_option( string $key, $default_value = "" ) { return $default_value; }
					public function process_admin_options(): void {}
				}'
			);
		}

		$this->offers  = new InMemoryDeliveryOfferRepository();
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->offers->seed( 11, $this->offer( DeliveryRoute::LocalDelivery->value, 'Standard Delivery' ) );
		$this->offers->seed( 12, $this->offer( DeliveryRoute::StorePickup->value, 'Store Pickup' ) );
		$this->offers->seed( 13, $this->offer( DeliveryRoute::Air->value, 'Air Shipping' ) );
		$this->offers->seed( 14, $this->offer( DeliveryRoute::Sea->value, 'Sea Shipping' ) );
		$this->offers->seed( 15, $this->offer( DeliveryRoute::LocalDelivery->value, 'Air Shipping', 'airshipping' ) );
		$this->pickups->seed(
			1,
			[
				'status'                     => RecordStatus::Active->value,
				'location_name'              => 'Main showroom',
				'public_address'             => '12 Independence Ave',
				'public_pickup_instructions' => 'Bring your order number',
				'readiness_estimate'         => 'Ready in 2 hours',
			]
		);
		$this->builder = new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups );
	}

	public function test_in_store_delivery_only_exposes_local_and_not_pickup(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 11 ] )
		);

		self::assertSame( [ 'Standard Delivery' ], $this->labels( $options ) );
		self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $options ) );
		self::assertSame( 'in_store:delivery:11', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options ) );
	}

	public function test_in_store_pickup_only_uses_ecr_location_not_delivery_option(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::StorePickup->value,
				[],
				1
			)
		);

		self::assertSame( [ FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertSame( 'in_store:store_pickup:pickup', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options ) );
		self::assertSame( 'Main showroom', $options[0]->pickup_location_label );
		self::assertSame( 'Ready in 2 hours', $options[0]->estimate_text );
		self::assertNull( $options[0]->delivery_offer_id );
	}

	public function test_in_store_both_via_ecr_location_defaults_to_delivery(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::Delivery->value,
				[ 11 ],
				1
			)
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertSame( 'in_store:delivery:11', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options, FulfilmentChoice::Delivery->value ) );
		self::assertTrue( $options[0]->is_default );
		self::assertFalse( $options[1]->is_default );
	}

	public function test_in_store_default_pickup_when_location_configured_and_valid(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::StorePickup->value,
				[ 11 ],
				1
			)
		);

		self::assertSame( 'in_store:store_pickup:pickup', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options, FulfilmentChoice::StorePickup->value ) );
		self::assertTrue( $options[1]->is_default );
	}

	public function test_pickup_enabled_without_valid_location_fails_closed(): void {
		$missing = $this->builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::StorePickup->value,
				[ 11 ],
				99
			)
		);
		self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $missing ) );
		self::assertNotContains( FulfilmentChoice::StorePickup->value, $this->choices( $missing ) );

		$this->pickups->seed(
			2,
			[
				'status'        => RecordStatus::Inactive->value,
				'location_name' => 'Closed store',
			]
		);
		$inactive = $this->builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::Delivery->value,
				[ 11 ],
				2
			)
		);
		self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $inactive ) );
	}

	public function test_store_pickup_is_not_treated_as_a_delivery_option(): void {
		$profile = \CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry::get( FulfilmentAvailability::InStore->value );
		self::assertNotNull( $profile );
		$filtered = \CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility::filter_offers(
			[
				[ 'id' => 11, 'route' => DeliveryRoute::LocalDelivery->value ],
				[ 'id' => 12, 'route' => DeliveryRoute::StorePickup->value ],
			],
			$profile
		);
		self::assertSame( [ 11 ], array_column( $filtered, 'id' ) );
		self::assertNotContains(
			DeliveryRoute::StorePickup->value,
			\CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility::allowed_routes( $profile )
		);
	}

	public function test_default_cannot_reference_a_disabled_method(): void {
		$selection = \CetechDeliveryEngine\Application\Configuration\InStoreMethodSelection::from_posted(
			[ 'delivery' ],
			FulfilmentChoice::StorePickup->value,
			[ 11 ],
			0,
			$this->offers
		);
		self::assertSame( FulfilmentChoice::Delivery->value, $selection->default_choice );
		self::assertFalse( $selection->pickup_enabled );

		$pickup_only = \CetechDeliveryEngine\Application\Configuration\InStoreMethodSelection::from_posted(
			[ 'store_pickup' ],
			FulfilmentChoice::Delivery->value,
			[],
			1,
			$this->offers
		);
		self::assertSame( FulfilmentChoice::StorePickup->value, $pickup_only->default_choice );
		self::assertSame( [], $pickup_only->validate( $this->pickups ) );

		$pickup_without_location = \CetechDeliveryEngine\Application\Configuration\InStoreMethodSelection::from_posted(
			[ 'store_pickup' ],
			FulfilmentChoice::StorePickup->value,
			[],
			0,
			$this->offers
		);
		$errors = $pickup_without_location->validate( $this->pickups );
		self::assertNotSame( [], $errors );
		self::assertStringContainsString( 'valid active Pickup Location', implode( ' ', $errors ) );
	}

	public function test_in_store_delivery_plus_pickup_exposes_both_and_defaults_to_delivery(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 11, 12 ] )
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertSame( 'in_store:delivery:11', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options, FulfilmentChoice::Delivery->value ) );
		self::assertTrue( $options[0]->is_default );
		self::assertFalse( $options[1]->is_default );
		self::assertSame( 'Main showroom', $options[1]->pickup_location_label );
		self::assertSame( 'Ready in 2 hours', $options[1]->estimate_text );
		self::assertSame( 'Bring your order number', $options[1]->pickup_instructions );
	}

	public function test_in_store_staff_default_pickup_still_exposes_delivery(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::StorePickup->value, [ 11, 12 ] )
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertSame( 'in_store:store_pickup:pickup', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options, FulfilmentChoice::StorePickup->value ) );
	}

	public function test_switching_to_pickup_uses_pickup_group_and_zero_charge_identity(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 11, 12 ] )
		);
		$pickup = $options[1];
		$intent = [
			'fulfilment_availability' => $pickup->fulfilment_availability,
			'fulfilment_choice'       => $pickup->fulfilment_choice,
			'delivery_offer_id'       => $pickup->delivery_offer_id,
		];
		$group = DeliveryGroupIdentity::fromIntent( $intent );

		self::assertNotNull( $group );
		self::assertTrue( DeliveryGroupIdentity::is_pickup_group( (string) $group ) );
		self::assertNull( $pickup->delivery_offer_id );
		self::assertSame( 'in_store|store_pickup|pickup', $group );
	}

	public function test_switching_back_to_delivery_restores_delivery_display_key(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 11, 12 ] )
		);
		$groups = ProductDeliveryOptionsBuilder::groupByChoice( $options );

		self::assertCount( 1, $groups[ FulfilmentChoice::Delivery->value ] );
		self::assertSame( 'in_store:delivery:11', $groups[ FulfilmentChoice::Delivery->value ][0]->display_key );
		self::assertNotNull( $groups[ FulfilmentChoice::Delivery->value ][0]->estimate_text );
		self::assertNull( $groups[ FulfilmentChoice::StorePickup->value ][0]->delivery_offer_id );
	}

	public function test_international_cannot_expose_store_pickup_or_local_including_misrouted_air_label(): void {
		$config = $this->constrained_config(
			FulfilmentAvailability::InternationalFulfilment->value,
			FulfilmentChoice::Delivery->value,
			[ 11, 12, 13, 15 ]
		);

		self::assertSame( [ 13 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 13 ] )
		);
		self::assertSame( [ 'Air Shipping' ], $this->labels( $options ) );
		self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $options ) );
		self::assertSame( 'international_fulfilment:delivery:13', ProductDeliveryOptionsBuilder::defaultDisplayKey( $options ) );
	}

	public function test_international_air_only_sea_only_and_air_plus_sea(): void {
		$air = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 13 ] )
		);
		$sea = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 14 ] )
		);
		$both = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 13, 14 ] )
		);

		self::assertSame( [ 'Air Shipping' ], $this->labels( $air ) );
		self::assertTrue( $air[0]->is_default );
		self::assertSame( [ 'Sea Shipping' ], $this->labels( $sea ) );
		self::assertTrue( $sea[0]->is_default );
		self::assertSame( [ 'Air Shipping', 'Sea Shipping' ], $this->labels( $both ) );
		self::assertSame( '', ProductDeliveryOptionsBuilder::defaultDisplayKey( $both ) );
		self::assertFalse( $both[0]->is_default );
		self::assertFalse( $both[1]->is_default );
	}

	public function test_in_warehouse_remains_delivery_only_local(): void {
		$config = $this->constrained_config(
			FulfilmentAvailability::InWarehouse->value,
			FulfilmentChoice::Delivery->value,
			[ 11, 12, 13 ]
		);
		self::assertSame( [ 11 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::Delivery->value, [ 11 ] )
		);
		self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $options ) );
		self::assertSame( [ 'Standard Delivery' ], $this->labels( $options ) );
	}

	public function test_invalid_hard_constraint_combinations_fail_closed(): void {
		$international_pickup = $this->constrained_config(
			FulfilmentAvailability::InternationalFulfilment->value,
			FulfilmentChoice::StorePickup->value,
			[ 13 ]
		);
		self::assertSame( EffectiveFieldState::Invalid, $international_pickup->state );
		self::assertSame( EffectiveFieldState::Invalid, $international_pickup->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );

		$in_store_air = $this->constrained_config(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::Delivery->value,
			[ 11, 13, 14 ]
		);
		self::assertSame( [ 11 ], $in_store_air->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$warehouse_pickup = $this->constrained_config(
			FulfilmentAvailability::InWarehouse->value,
			FulfilmentChoice::StorePickup->value,
			[ 11 ]
		);
		self::assertSame( EffectiveFieldState::Invalid, $warehouse_pickup->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );

		$missing_location = $this->constrained_config(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::Delivery->value,
			[ 11 ],
			99
		);
		self::assertSame( EffectiveFieldState::Invalid, $missing_location->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( EffectiveFieldState::Valid, $missing_location->state );
		self::assertSame( [ 11 ], $missing_location->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$international_location = $this->constrained_config(
			FulfilmentAvailability::InternationalFulfilment->value,
			FulfilmentChoice::Delivery->value,
			[ 13 ],
			1
		);
		self::assertSame( EffectiveFieldState::Invalid, $international_location->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( EffectiveFieldState::Invalid, $international_location->state );

		$pickup_only_missing = $this->constrained_config(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::StorePickup->value,
			[],
			99
		);
		self::assertSame( EffectiveFieldState::Invalid, $pickup_only_missing->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( EffectiveFieldState::Invalid, $pickup_only_missing->state );
	}

	public function test_selected_choice_survives_cart_summary_and_pickup_details(): void {
		$options = $this->builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 11, 12 ] )
		);
		$pickup  = $options[1];
		$summary = CartDeliverySelectionCapture::buildPublicSummary( $pickup->toArray() );

		self::assertSame( 'Store pickup', $summary['delivery_offer_public_label'] );
		self::assertSame( 'Main showroom', $summary['pickup_location_label'] );
		self::assertSame( 'Bring your order number', $summary['pickup_instructions'] );

		$restored = CartDeliverySelectionSessionData::normalizeSummary( $summary );
		self::assertNotNull( $restored );
		self::assertSame( 'Main showroom', $restored['pickup_location_label'] );

		$delivery_summary = CartDeliverySelectionCapture::buildPublicSummary( $options[0]->toArray() );
		self::assertSame( 'Standard Delivery', $delivery_summary['delivery_offer_public_label'] );
		self::assertNull( $delivery_summary['pickup_location_label'] );
	}

	public function test_managed_package_without_de_rate_does_not_expose_native_methods(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		$integration = new SelectedOfferShippingIntegration(
			new ShippingRateCalculationGate( $flags, new Requirements() )
		);
		$native = new class() {
			public function get_method_id(): string {
				return 'flat_rate';
			}
		};
		$pickup = new class() {
			public function get_method_id(): string {
				return 'local_pickup';
			}
		};

		$filtered = $integration->filter_managed_package_rates(
			[
				'flat_rate:1'    => $native,
				'local_pickup:1' => $pickup,
			],
			[
				DeliveryGroupIdentity::PACKAGE_META_KEY => [
					'managed'  => true,
					'group_id' => 'international_fulfilment|delivery|13',
				],
			]
		);

		self::assertSame( [], $filtered );
	}

	public function test_unmanaged_package_keeps_native_methods(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		$integration = new SelectedOfferShippingIntegration(
			new ShippingRateCalculationGate( $flags, new Requirements() )
		);
		$native = new class() {
			public function get_method_id(): string {
				return 'flat_rate';
			}
		};
		$rates = [ 'flat_rate:1' => $native ];

		$filtered = $integration->filter_managed_package_rates( $rates, [ 'contents' => [] ] );

		self::assertSame( $rates, $filtered );
		self::assertArrayNotHasKey( SelectedOfferShippingMethod::METHOD_ID, $filtered );
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function resolution( string $availability, string $choice, array $offer_ids, ?int $pickup_location_id = null ): ProductRuleResolutionResult {
		$rule = new ResolvedProductDeliveryRule(
			1,
			ProductTargetType::Product->value,
			101,
			null,
			2,
			$availability,
			$choice,
			$offer_ids,
			null,
			null,
			null,
			100,
			$pickup_location_id
		);

		return new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			101,
			null,
			[],
			'',
			[ $rule ],
			[ $availability => $rule ],
			[],
			[],
			[],
			null
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function constrained_config( string $availability, string $choice, array $offer_ids, ?int $pickup_location_id = null ): \CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration {
		$repository = new InMemoryScopedConfigurationRepository();
		$scalars    = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
				$availability
			),
			ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_CHOICE,
				$choice
			),
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
			ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
			ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
		];
		if ( null !== $pickup_location_id && $pickup_location_id > 0 ) {
			$scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = ScalarFieldInstruction::override(
				ConfigurationFieldKey::PICKUP_LOCATION_ID,
				$pickup_location_id
			);
		}

		$repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Global,
					0,
					$availability,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				$scalars,
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						$offer_ids
					),
				]
			)
		);

		$resolver = new EffectiveConfigurationResolver(
			$repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers, $this->pickups )
		);

		return $resolver->resolve( new EffectiveConfigurationRequest( 101, null, $availability ) );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<string>
	 */
	private function labels( array $options ): array {
		return array_map(
			static fn ( ProductDeliveryOption $option ): string => (string) $option->delivery_offer_public_label,
			$options
		);
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<string>
	 */
	private function choices( array $options ): array {
		return array_values(
			array_unique(
				array_map(
					static fn ( ProductDeliveryOption $option ): string => $option->fulfilment_choice,
					$options
				)
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function offer( string $route, string $label, string $code = '' ): array {
		return [
			'status'                 => RecordStatus::Active->value,
			'public_label'           => $label,
			'public_description'     => '',
			'route'                  => $route,
			'internal_code'          => '' !== $code ? $code : sanitize_key( $label ),
			'duration_unit'          => 'business_days',
			'default_processing_min' => 1,
			'default_processing_max' => 2,
			'default_transit_min'    => 1,
			'default_transit_max'    => 2,
			'default_final_mile_min' => 0,
			'default_final_mile_max' => 0,
		];
	}
}
