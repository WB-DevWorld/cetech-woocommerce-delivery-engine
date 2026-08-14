<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\InMemorySiteWideDefaultsPolicy;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
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
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\PreviewVariationsEndpoint;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use PHPUnit\Framework\TestCase;

final class Stage13CR3RepairTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;

	private EffectiveConfigurationResolver $resolver;

	private OperationalReadinessAssessor $assessor;

	private InMemoryCatalogIndex $catalog;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();

		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->catalog    = new InMemoryCatalogIndex(
			[
				39717 => [
					'label'      => 'FLAIROC Delivery Engine Variable QA Product',
					'type'       => 'variable',
					'variations' => [
						39718 => 'Variation A',
						39719 => 'Variation B',
					],
				],
			]
		);

		$offers = new InMemoryDeliveryOfferRepository();
		$offers->seed(
			11,
			[
				'id'            => 11,
				'public_label'  => 'FLAIROC QA Standard Delivery',
				'route'         => DeliveryRoute::LocalDelivery->value,
				'status'        => RecordStatus::Active->value,
			]
		);
		$offers->seed(
			21,
			[
				'id'           => 21,
				'public_label' => 'Air Shipping',
				'route'        => DeliveryRoute::Air->value,
				'status'       => RecordStatus::Active->value,
			]
		);
		$offers->seed(
			22,
			[
				'id'           => 22,
				'public_label' => 'Sea Shipping',
				'route'        => DeliveryRoute::Sea->value,
				'status'       => RecordStatus::Active->value,
			]
		);
		$offers->seed(
			31,
			[
				'id'           => 31,
				'public_label' => 'Store Pickup',
				'route'        => DeliveryRoute::StorePickup->value,
				'status'       => RecordStatus::Active->value,
			]
		);

		$this->resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $offers ),
			new InMemorySiteWideDefaultsPolicy( null, false, [] )
		);
		$this->assessor = new OperationalReadinessAssessor( $this->resolver );
	}

	public function test_wizard_rejects_empty_delivery_option_selection_for_all_profiles(): void {
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			$errors = SetupWizardPage::validate_fulfilment_default_selection( $profile, [] );
			self::assertSame(
				[ 'Select at least one Delivery Option before continuing.' ],
				$errors,
				$profile->key
			);
			self::assertSame( [], SetupWizardPage::validate_fulfilment_default_selection( $profile, [ 11 ] ) );
		}
	}

	public function test_wizard_continue_advances_from_warehouse_to_in_store(): void {
		self::assertSame(
			[ 'step' => 3, 'profile_index' => 1 ],
			SetupWizardPage::continue_redirect_args( 3, 0, 3 )
		);
		self::assertNotSame(
			[ 'step' => 4, 'profile_index' => 0 ],
			SetupWizardPage::continue_redirect_args( 3, 0, 3 )
		);
	}

	public function test_international_compatibility_offers_only_air_and_sea(): void {
		$intl = FulfilmentProfileRegistry::get( FulfilmentAvailability::InternationalFulfilment->value );
		self::assertNotNull( $intl );

		$offers = [
			[ 'id' => 11, 'route' => DeliveryRoute::LocalDelivery->value, 'public_label' => 'Local' ],
			[ 'id' => 21, 'route' => DeliveryRoute::Air->value, 'public_label' => 'Air Shipping' ],
			[ 'id' => 22, 'route' => DeliveryRoute::Sea->value, 'public_label' => 'Sea Shipping' ],
			[ 'id' => 31, 'route' => DeliveryRoute::StorePickup->value, 'public_label' => 'Store Pickup' ],
		];

		$filtered = DeliveryOptionCompatibility::filter_offers( $offers, $intl );
		$ids      = array_column( $filtered, 'id' );

		self::assertSame( [ 21, 22 ], $ids );
		self::assertSame(
			[ DeliveryRoute::Air->value, DeliveryRoute::Sea->value ],
			DeliveryOptionCompatibility::allowed_routes( $intl )
		);
	}

	public function test_international_guided_empty_state_copy_present_in_wizard_source(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/SetupWizardPage.php' );
		self::assertStringContainsString( 'No international shipping options yet', $source );
		self::assertStringContainsString( 'Create Air or Sea Shipping option', $source );
		self::assertStringContainsString( 'International shipping options', $source );
		self::assertStringContainsString( 'cetech-de-wizard-defaults', $source );
		self::assertStringContainsString( 'validate_fulfilment_default_selection', $source );
	}

	public function test_preview_variations_endpoint_returns_human_readable_variations(): void {
		$endpoint = new PreviewVariationsEndpoint(
			$this->catalog,
			new ScopedConfigurationAuthorization( static fn (): bool => true )
		);

		$payload = $endpoint->build_payload( 39717 );
		self::assertTrue( $payload['variable'] );
		self::assertCount( 2, $payload['variations'] );
		self::assertSame( 39718, $payload['variations'][0]['id'] );
		self::assertSame( 'Variation A', $payload['variations'][0]['label'] );

		$simple = $endpoint->build_payload( 0 );
		self::assertFalse( $simple['variable'] );
		self::assertSame( [], $simple['variations'] );
	}

	public function test_variable_parent_stage6_profile_slice_is_ready_on_stage13_root(): void {
		$this->seed_flairoc_variable_parent_fixture();

		$parent_all = $this->assessor->assess( 39717 );
		self::assertTrue( $parent_all->is_ready, (string) $parent_all->reason );
		self::assertNull( $parent_all->reason );

		$parent_root = $this->assessor->assess( 39717, null, ConfigurationScope::DEFAULT_SLICE_KEY );
		self::assertTrue( $parent_root->is_ready, (string) $parent_root->reason );

		$parent_profile = $this->assessor->assess( 39717, null, FulfilmentAvailability::InWarehouse->value );
		self::assertTrue( $parent_profile->is_ready, (string) $parent_profile->reason );

		$offer_field = $this->assessor->assess_field_for(
			39717,
			null,
			ConfigurationFieldKey::DELIVERY_OFFER_IDS,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);
		self::assertTrue( $offer_field->is_ready, (string) $offer_field->reason );

		$resolved = $this->resolver->resolve(
			new EffectiveConfigurationRequest( 39717, null, ConfigurationScope::DEFAULT_SLICE_KEY )
		);
		self::assertSame( [ 11 ], $resolved->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( EffectiveFieldState::Valid, $resolved->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
	}

	public function test_variation_priority_only_inherits_product_delivery_options(): void {
		$this->seed_flairoc_variable_parent_fixture();

		$variation = $this->assessor->assess( 39717, 39719 );
		self::assertTrue( $variation->is_ready, (string) $variation->reason );

		$resolved = $this->resolver->resolve(
			new EffectiveConfigurationRequest( 39717, 39719, ConfigurationScope::DEFAULT_SLICE_KEY )
		);
		self::assertSame( [ 11 ], $resolved->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( 50, (int) $resolved->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 'product', $resolved->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->provenance->source_label );
	}

	private function seed_flairoc_variable_parent_fixture(): void {
		// Incomplete site-wide defaults: no useful global profile offers.
		$this->repository->ensureGlobalScope();

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					39717,
					FulfilmentAvailability::InWarehouse->value,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						FulfilmentAvailability::InWarehouse->value
					),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						FulfilmentChoice::Delivery->value
					),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 11 ]
					),
				]
			)
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Variation,
					39719,
					ConfigurationScope::DEFAULT_SLICE_KEY,
					39717,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						50
					),
				],
				[]
			)
		);
	}
}
