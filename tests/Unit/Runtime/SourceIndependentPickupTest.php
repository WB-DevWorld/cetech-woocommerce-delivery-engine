<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

/**
 * COR-022 (decision D8): Store Pickup is gated on an assigned, active Pickup Location,
 * not on the In Store fulfilment type. Items without an assignment behave exactly as before.
 */
final class SourceIndependentPickupTest extends TestCase {

	private const ACTIVE_LOCATION   = 1;
	private const INACTIVE_LOCATION = 2;
	private const MISSING_LOCATION  = 99;

	private InMemoryScopedConfigurationRepository $repository;
	private InMemoryDeliveryOfferRepository $offers;
	private InMemoryPickupLocationRepository $pickups;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 1, [ 'route' => DeliveryRoute::Air->value, 'status' => 'active', 'public_label' => 'Air Shipping', 'internal_name' => 'Air Shipping' ] );
		$this->offers->seed( 3, [ 'route' => DeliveryRoute::LocalDelivery->value, 'status' => 'active', 'public_label' => 'Standard Delivery', 'internal_name' => 'Standard Delivery' ] );
		$this->offers->seed( 4, [ 'route' => DeliveryRoute::StorePickup->value, 'status' => 'active', 'public_label' => 'Store Pickup', 'internal_name' => 'Store Pickup' ] );
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->pickups->seed( self::ACTIVE_LOCATION, [ 'status' => RecordStatus::Active->value, 'location_name' => 'Main showroom', 'public_address' => '12 Independence Ave', 'readiness_estimate' => 'Ready in 2 hours' ] );
		$this->pickups->seed( self::INACTIVE_LOCATION, [ 'status' => RecordStatus::Inactive->value, 'location_name' => 'Closed kiosk' ] );
	}

	// ---- configuration constraints -------------------------------------------------------------

	public function test_in_warehouse_slice_with_assigned_active_location_allows_pickup(): void {
		$config = $this->resolve_slice( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::StorePickup->value, [ 3, 4 ], self::ACTIVE_LOCATION );

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( EffectiveFieldState::Valid, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
		self::assertSame( FulfilmentChoice::StorePickup->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->value );
		self::assertSame( EffectiveFieldState::Valid, $config->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( self::ACTIVE_LOCATION, (int) $config->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->value );
		self::assertNotContains( ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED, $config->reason_codes );
		// Route policy is untouched: store_pickup-route offers are still filtered out of a warehouse slice.
		self::assertSame( [ 3 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_international_slice_with_assigned_active_location_allows_pickup(): void {
		$config = $this->resolve_slice( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 1, 3 ], self::ACTIVE_LOCATION );

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( EffectiveFieldState::Valid, $config->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( [ 1 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_non_store_slice_without_assignment_behaves_as_before(): void {
		$config = $this->resolve_slice( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::StorePickup->value, [ 3 ], null );

		self::assertSame( EffectiveFieldState::Invalid, $config->state );
		self::assertSame( EffectiveFieldState::Invalid, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
		self::assertContains( ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED, $config->reason_codes );
	}

	public function test_non_store_slice_with_inactive_or_missing_location_still_fails_closed(): void {
		foreach ( [ self::INACTIVE_LOCATION, self::MISSING_LOCATION ] as $location_id ) {
			$config = $this->resolve_slice( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::Delivery->value, [ 3 ], $location_id );

			self::assertSame( EffectiveFieldState::Invalid, $config->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state, "location $location_id" );
			self::assertContains( ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED, $config->reason_codes, "location $location_id" );
			self::assertSame( EffectiveFieldState::Invalid, $config->state, "location $location_id" );
		}
	}

	public function test_disabled_pickup_field_on_non_store_slice_does_not_grant_pickup(): void {
		$config = $this->resolve_slice( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::StorePickup->value, [ 3 ], null, true );

		self::assertSame( EffectiveFieldState::Disabled, $config->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( EffectiveFieldState::Invalid, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
	}

	public function test_in_store_behaviour_is_unchanged(): void {
		$with_location = $this->resolve_slice( FulfilmentAvailability::InStore->value, FulfilmentChoice::StorePickup->value, [ 3, 4 ], self::ACTIVE_LOCATION );
		self::assertSame( EffectiveFieldState::Valid, $with_location->state );
		self::assertSame( [ 3, 4 ], $with_location->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$missing = $this->resolve_slice( FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 3 ], self::MISSING_LOCATION );
		self::assertSame( EffectiveFieldState::Invalid, $missing->scalar( ConfigurationFieldKey::PICKUP_LOCATION_ID )?->state );
		self::assertSame( EffectiveFieldState::Valid, $missing->state, 'an invalid location must not fail-close a valid Delivery configuration' );
	}

	// ---- customer options -----------------------------------------------------------------------

	public function test_warehouse_item_with_assigned_location_offers_delivery_and_pickup(): void {
		$options = $this->builder()->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::Delivery->value, [ 3 ], self::ACTIVE_LOCATION )
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		$pickup = $options[1];
		self::assertSame( self::ACTIVE_LOCATION, $pickup->pickup_location_id );
		self::assertSame( 'Main showroom', $pickup->pickup_location_label );
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $pickup->fulfilment_availability );
		self::assertTrue( $options[0]->is_default, 'delivery stays the default when the rule says Delivery' );
		self::assertFalse( $pickup->is_default );
	}

	public function test_warehouse_item_defaults_to_pickup_when_the_rule_prefers_it(): void {
		$options = $this->builder()->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::StorePickup->value, [ 3 ], self::ACTIVE_LOCATION )
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertTrue( $options[1]->is_default );
	}

	public function test_warehouse_item_without_assignment_offers_delivery_only(): void {
		foreach ( [ null, self::INACTIVE_LOCATION, self::MISSING_LOCATION ] as $location_id ) {
			$options = $this->builder()->buildFromResolution(
				$this->resolution( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::Delivery->value, [ 3 ], $location_id )
			);

			self::assertSame( [ FulfilmentChoice::Delivery->value ], $this->choices( $options ), 'location ' . var_export( $location_id, true ) );
		}
	}

	public function test_international_item_with_assigned_location_offers_pickup_next_to_air(): void {
		$options = $this->builder()->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::StorePickup->value, [ 1 ], self::ACTIVE_LOCATION )
		);

		self::assertSame( [ FulfilmentChoice::Delivery->value, FulfilmentChoice::StorePickup->value ], $this->choices( $options ) );
		self::assertSame( 'Air Shipping', $options[0]->delivery_offer_public_label );
		self::assertTrue( $options[1]->is_default );
	}

	public function test_customize_view_offers_the_pickup_location_control_for_every_fulfilment_type(): void {
		$view = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/StaffDeliveryCustomizeView.php' );

		self::assertStringNotContainsString( 'if ( $profile?->pickup_allowed )', $view );
		self::assertStringContainsString( 'data-cetech-de-source-independent-pickup', $view );
	}

	// ---- helpers --------------------------------------------------------------------------------

	/**
	 * @param list<int> $offer_ids
	 */
	private function resolve_slice( string $availability, string $choice, array $offer_ids, ?int $pickup_location_id, bool $disable_pickup = false ): EffectiveConfiguration {
		$scalars = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, $availability ),
			ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, $choice ),
			ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
		];
		if ( $disable_pickup ) {
			$scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = ScalarFieldInstruction::disable( ConfigurationFieldKey::PICKUP_LOCATION_ID );
		} elseif ( null !== $pickup_location_id ) {
			$scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ] = ScalarFieldInstruction::override( ConfigurationFieldKey::PICKUP_LOCATION_ID, $pickup_location_id );
		}

		$this->repository->ensureGlobalScope();
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, $availability, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				$scalars,
				[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, $offer_ids ) ]
			)
		);

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers, $this->pickups )
		);

		return $resolver->resolve( new EffectiveConfigurationRequest( 101, null, $availability ) );
	}

	private function builder(): ProductDeliveryOptionsBuilder {
		return new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups );
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function resolution( string $availability, string $choice, array $offer_ids, ?int $pickup_location_id ): ProductRuleResolutionResult {
		$rule = new ResolvedProductDeliveryRule( 1, ProductTargetType::Product->value, 101, null, 2, $availability, $choice, $offer_ids, null, null, null, 100, $pickup_location_id );

		return new ProductRuleResolutionResult( true, null, ProductTargetType::Product->value, 101, null, [], '', [ $rule ], [ $availability => $rule ], [], [], [], null );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 * @return list<string>
	 */
	private function choices( array $options ): array {
		return array_values( array_unique( array_map( static fn ( ProductDeliveryOption $option ): string => $option->fulfilment_choice, $options ) ) );
	}
}
