<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
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
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class HardFulfilmentConstraintServiceTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private InMemoryDeliveryOfferRepository $offers;
	private int $writes_before = 0;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 1, [ 'route' => DeliveryRoute::Air->value, 'status' => 'active' ] );
		$this->offers->seed( 2, [ 'route' => DeliveryRoute::Sea->value, 'status' => 'active' ] );
		$this->offers->seed( 3, [ 'route' => DeliveryRoute::LocalDelivery->value, 'status' => 'active' ] );
	}

	public function test_international_rejects_pickup_and_keeps_air_sea(): void {
		$this->seed_slice(
			FulfilmentAvailability::InternationalFulfilment->value,
			FulfilmentChoice::StorePickup->value,
			[ 1, 2, 3 ]
		);

		$config = $this->resolver()->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InternationalFulfilment->value )
		);

		self::assertSame( EffectiveFieldState::Invalid, $config->state );
		self::assertContains( ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED, $config->reason_codes );
		self::assertSame(
			EffectiveFieldState::Invalid,
			$config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state
		);
		self::assertSame( [ 1, 2 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertContains( ConfigurationReasonCode::CONSTRAINT_ROUTE_FILTERED, $config->reason_codes );
	}

	public function test_in_store_filters_air_sea_and_allows_pickup(): void {
		$this->seed_slice(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::StorePickup->value,
			[ 1, 2, 3 ]
		);

		$config = $this->resolver()->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( FulfilmentChoice::StorePickup->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->value );
		self::assertSame( [ 3 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_in_warehouse_rejects_pickup_and_air_sea(): void {
		$this->seed_slice(
			FulfilmentAvailability::InWarehouse->value,
			FulfilmentChoice::Delivery->value,
			[ 1, 3 ]
		);

		$config = $this->resolver()->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InWarehouse->value )
		);

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( [ 3 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_hard_constraints_beat_lower_scope_air_override_on_in_store(): void {
		$this->save_global(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::Delivery->value,
			[ 3 ]
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					101,
					FulfilmentAvailability::InStore->value,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_AVAILABILITY ),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_CHOICE ),
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::inherit( ConfigurationFieldKey::PRIORITY ),
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::LOGISTICS_PROFILE_ID ),
					ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::SUPPLIER_ID ),
					ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::ORIGIN_ID ),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 1, 3 ]
					),
				]
			)
		);

		$config = $this->resolver()->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( [ 3 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_constraints_do_not_persist_or_audit(): void {
		$this->seed_slice(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::Delivery->value,
			[ 1, 3 ]
		);
		$before = $this->repository->findByScope( ConfigurationScopeType::Product, 101 );
		$version_before = $before[0]->scope->config_version;
		$stored_members = $before[0]->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members;

		$this->resolver()->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		$after = $this->repository->findByScope( ConfigurationScopeType::Product, 101 );
		self::assertSame( $version_before, $after[0]->scope->config_version );
		self::assertSame( $stored_members, $after[0]->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
	}

	public function test_passthrough_still_available_for_harness_comparison(): void {
		$this->seed_slice(
			FulfilmentAvailability::InStore->value,
			FulfilmentChoice::Delivery->value,
			[ 1, 3 ]
		);

		$passthrough = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);

		$config = $passthrough->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( [ 1, 3 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	private function resolver(): EffectiveConfigurationResolver {
		return new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers )
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function seed_slice( string $availability, string $choice, array $offer_ids ): void {
		$this->save_global( $availability, $choice, $offer_ids );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					101,
					$availability,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_AVAILABILITY ),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_CHOICE ),
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::inherit( ConfigurationFieldKey::PRIORITY ),
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::LOGISTICS_PROFILE_ID ),
					ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::SUPPLIER_ID ),
					ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::ORIGIN_ID ),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function save_global( string $availability, string $choice, array $offer_ids ): void {
		$this->repository->ensureGlobalScope();
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::global(),
				[
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
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						$offer_ids
					),
				]
			)
		);
	}
}
