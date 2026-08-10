<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationParityComparator;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class EcrRuntimeParityAndFingerprintTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private InMemoryDeliveryOfferRepository $offers;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed(
			7,
			[
				'status'            => 'active',
				'public_label'      => 'Local Delivery',
				'public_description'=> 'Door delivery',
				'route'             => 'local_delivery',
				'duration_unit'     => 'business_days',
				'default_processing_min' => 1,
				'default_processing_max' => 2,
				'default_transit_min'    => 1,
				'default_transit_max'    => 2,
				'default_final_mile_min' => 0,
				'default_final_mile_max' => 0,
			]
		);
	}

	public function test_ecr_adapter_maps_migrated_style_slice_to_runtime_rule(): void {
		$this->seed_product_config( 101, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 7 ], 0 );

		$source = new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new HardFulfilmentConstraintService( $this->offers )
			),
			new EcrToRuntimeConfigurationAdapter()
		);

		$runtime = $source->resolve( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertTrue( $runtime->result->success );
		self::assertArrayHasKey( FulfilmentAvailability::InStore->value, $runtime->result->chosen_rules );

		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];
		self::assertSame( [ 7 ], $rule->delivery_offer_ids );
		self::assertSame( 0, $rule->priority );
		self::assertSame( 10, $rule->logistics_profile_id );
		self::assertNotNull( $runtime->configuration_fingerprint );
	}

	public function test_legacy_ecr_normalized_parity_for_equivalent_config(): void {
		$legacy = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			101,
			null,
			[],
			'',
			[],
			[
				FulfilmentAvailability::InStore->value => new ResolvedProductDeliveryRule(
					5,
					ProductTargetType::Product->value,
					101,
					null,
					2,
					FulfilmentAvailability::InStore->value,
					FulfilmentChoice::Delivery->value,
					[ 7 ],
					10,
					20,
					30,
					0
				),
			],
			[],
			[],
			[],
			null
		);

		$this->seed_product_config( 101, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 7 ], 0 );
		$ecr = ( new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new PassthroughFulfilmentConstraintService()
			),
			new EcrToRuntimeConfigurationAdapter()
		) )->resolve( ProductTargetType::Product->value, 101 )->result;

		$compare = ( new RuntimeConfigurationParityComparator() )->compare_resolutions( $legacy, $ecr );
		self::assertSame( RuntimeConfigurationParityComparator::MATCH, $compare['status'], implode( "\n", $compare['differences'] ) );
	}

	public function test_customer_offer_parity_and_privacy(): void {
		$this->seed_product_config( 101, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 7 ], 100 );
		$ecr = ( new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new HardFulfilmentConstraintService( $this->offers )
			),
			new EcrToRuntimeConfigurationAdapter()
		) )->resolve( ProductTargetType::Product->value, 101 )->result;

		$builder = new ProductDeliveryOptionsBuilder( $this->offers );
		$options = $builder->buildFromResolution( $ecr );

		self::assertCount( 1, $options );
		self::assertSame( 'Local Delivery', $options[0]->delivery_offer_public_label );
		self::assertTrue( $options[0]->is_available );

		$serialized = wp_json_encode( $options[0]->toArray() );
		self::assertIsString( $serialized );
		self::assertStringNotContainsString( 'supplier', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'origin', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'logistics', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'fingerprint', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'provenance', strtolower( $serialized ) );
	}

	public function test_invalid_ecr_fails_closed_without_legacy_fallback_shape(): void {
		$this->repository->ensureGlobalScope();
		$source = new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new PassthroughFulfilmentConstraintService()
			),
			new EcrToRuntimeConfigurationAdapter()
		);

		$runtime = $source->resolve( ProductTargetType::Product->value, 101 );
		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertFalse( $runtime->result->success );
	}

	public function test_fingerprint_stable_until_ecr_config_changes(): void {
		$intent = [
			'contract_version'           => '1',
			'product_id'                 => 101,
			'variation_id'               => null,
			'display_key'                => 'in_store:delivery:7',
			'fulfilment_availability'    => 'in_store',
			'fulfilment_choice'          => 'delivery',
			'delivery_offer_id'          => 7,
			'rule_id'                    => null,
			'configuration_fingerprint'  => 'abc123',
		];

		$hash1 = CartDeliverySelectionFingerprint::fromIntent( $intent );
		$hash2 = CartDeliverySelectionFingerprint::fromIntent( $intent );
		self::assertSame( $hash1, $hash2 );

		$intent['configuration_fingerprint'] = 'changed';
		self::assertNotSame( $hash1, CartDeliverySelectionFingerprint::fromIntent( $intent ) );
	}

	public function test_session_normalize_preserves_ecr_configuration_fingerprint_for_hash_parity(): void {
		$intent = [
			'contract_version'          => '1',
			'product_id'                => 39705,
			'variation_id'              => null,
			'target_type'               => 'product',
			'target_id'                 => 39705,
			'display_key'               => 'in_warehouse:delivery:1',
			'fulfilment_availability'   => 'in_warehouse',
			'fulfilment_choice'         => 'delivery',
			'delivery_offer_id'         => 1,
			'rule_id'                   => null,
			'issued_at'                 => '2026-08-10T23:12:55+00:00',
			'configuration_fingerprint' => '48ca598548faf968808c0a6df8db9fb481a54b17e61255006672400a229f4734',
		];

		$captured_hash = CartDeliverySelectionFingerprint::fromIntent( $intent );
		$normalized    = CartDeliverySelectionSessionData::normalizeIntent( $intent );

		self::assertNotNull( $normalized );
		self::assertArrayHasKey( 'configuration_fingerprint', $normalized );
		self::assertSame(
			'48ca598548faf968808c0a6df8db9fb481a54b17e61255006672400a229f4734',
			$normalized['configuration_fingerprint']
		);
		self::assertSame(
			$captured_hash,
			CartDeliverySelectionFingerprint::fromIntent( $normalized )
		);

		$legacy = $intent;
		unset( $legacy['configuration_fingerprint'] );
		$legacy_normalized = CartDeliverySelectionSessionData::normalizeIntent( $legacy );
		self::assertNotNull( $legacy_normalized );
		self::assertArrayNotHasKey( 'configuration_fingerprint', $legacy_normalized );
		self::assertCount( 7, CartDeliverySelectionFingerprint::fingerprintParts( $legacy_normalized ) );
	}

	public function test_legacy_fingerprint_unchanged_without_ecr_component(): void {
		$intent = [
			'product_id'              => 101,
			'variation_id'            => '',
			'display_key'             => 'in_store:delivery:7',
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'       => 7,
			'rule_id'                 => 5,
		];

		$parts = CartDeliverySelectionFingerprint::fingerprintParts( $intent );
		self::assertCount( 7, $parts );
		self::assertSame(
			hash( 'sha256', implode( '|', $parts ) ),
			CartDeliverySelectionFingerprint::fromIntent( $intent )
		);
	}

	public function test_options_builder_characterization_no_silent_substitution(): void {
		$builder = new ProductDeliveryOptionsBuilder( $this->offers );
		$result  = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			101,
			null,
			[],
			'',
			[],
			[
				FulfilmentAvailability::InStore->value => new ResolvedProductDeliveryRule(
					1,
					ProductTargetType::Product->value,
					101,
					null,
					2,
					FulfilmentAvailability::InStore->value,
					FulfilmentChoice::Delivery->value,
					[ 999 ],
					null,
					null,
					null,
					100
				),
			],
			[],
			[],
			[],
			null
		);

		$options = $builder->buildFromResolution( $result );
		self::assertCount( 1, $options );
		self::assertFalse( $options[0]->is_available );
		self::assertSame( 'Delivery unavailable', $options[0]->delivery_offer_public_label );
	}

	public function test_admin_runtime_resolver_consistency_boundary(): void {
		$this->seed_product_config( 101, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 7 ], 5 );

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers )
		);

		$admin = $resolver->resolve(
			new \CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest(
				101,
				null,
				FulfilmentAvailability::InStore->value
			)
		);

		$runtime = ( new EcrProductDeliveryConfigurationSource(
			$resolver,
			new EcrToRuntimeConfigurationAdapter()
		) )->resolve( ProductTargetType::Product->value, 101 );

		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];
		self::assertSame( $admin->scalar( ConfigurationFieldKey::PRIORITY )?->value, $rule->priority );
		self::assertSame(
			$admin->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members,
			$rule->delivery_offer_ids
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function seed_product_config(
		int $product_id,
		string $availability,
		string $choice,
		array $offer_ids,
		int $priority
	): void {
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
						[ 1, 2 ]
					),
				]
			)
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					$product_id,
					$availability,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Migrated,
					null
				),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						$availability
					),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						$choice
					),
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ),
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
