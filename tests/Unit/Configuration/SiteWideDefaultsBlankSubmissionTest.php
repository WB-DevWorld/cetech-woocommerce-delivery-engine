<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Training M2 acceptance S2 (2026-10-30): the Site-wide Defaults editor saved a
 * submission with every Delivery Option unticked and a blank estimate, so every
 * inheriting product lost its delivery option. The service now refuses both,
 * the same way the product form and the CSV import do.
 */
final class SiteWideDefaultsBlankSubmissionTest extends TestCase {

	private const OFFER_ID = 11;

	private InMemoryScopedConfigurationRepository $repository;

	private SiteWideDefaultsService $defaults;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();

		$this->repository = new InMemoryScopedConfigurationRepository();
		$catalog          = new InMemoryCatalogIndex( [ 101 => [ 'label' => 'Simple Lamp', 'type' => 'simple' ] ] );
		$settings         = new SiteWideDefaultsSettings();
		$resolver         = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService(),
			$settings
		);
		$classifier       = new CatalogInheritanceClassifier( $this->repository, $catalog, $resolver, $settings );
		$this->defaults   = new SiteWideDefaultsService( $this->repository, $settings, $classifier, $resolver, $catalog );

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::profileDefault( FulfilmentAvailability::InWarehouse->value ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InWarehouse->value ),
					ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
					ConfigurationFieldKey::ESTIMATED_DELIVERY      => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '2-3 business days' ),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ self::OFFER_ID ] ),
				]
			)
		);
	}

	public function test_blank_estimate_override_is_refused_and_nothing_is_written(): void {
		$before = $this->warehouse_scope();

		try {
			$this->defaults->save_profile_defaults(
				FulfilmentAvailability::InWarehouse->value,
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ self::OFFER_ID ] ],
				]
			);
			self::fail( 'A blank override must be refused.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'Override for estimated_delivery requires a value.', $exception->getMessage() );
			self::assertStringContainsString( 'Blank does not mean inherit.', $exception->getMessage() );
		}

		$this->assert_scope_unchanged( $before );
	}

	public function test_whitespace_only_estimate_is_blank_too(): void {
		$before = $this->warehouse_scope();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Override for estimated_delivery requires a value.' );

		try {
			$this->defaults->save_profile_defaults(
				FulfilmentAvailability::InWarehouse->value,
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => "  \t" ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ self::OFFER_ID ] ],
				]
			);
		} finally {
			$this->assert_scope_unchanged( $before );
		}
	}

	public function test_delivery_only_profile_without_any_delivery_option_is_refused(): void {
		$before = $this->warehouse_scope();

		try {
			$this->defaults->save_profile_defaults(
				FulfilmentAvailability::InWarehouse->value,
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2-3 business days' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [] ],
				]
			);
			self::fail( 'An empty Delivery Option set must be refused for a delivery-only profile.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'Select at least one Delivery Option.', $exception->getMessage() );
		}

		$this->assert_scope_unchanged( $before );
	}

	public function test_missing_members_key_counts_as_an_empty_option_set(): void {
		$before = $this->warehouse_scope();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Select at least one Delivery Option.' );

		try {
			$this->defaults->save_profile_defaults(
				FulfilmentAvailability::InWarehouse->value,
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2-3 business days' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace' ],
				]
			);
		} finally {
			$this->assert_scope_unchanged( $before );
		}
	}

	public function test_valid_submission_still_saves(): void {
		$saved = $this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => ' 3-4 business days ' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ self::OFFER_ID ] ],
			]
		);

		self::assertSame( '3-4 business days', $saved->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
		self::assertSame( [ self::OFFER_ID ], $saved->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
		self::assertSame( FulfilmentChoice::Delivery->value, $saved->scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ]->value );
	}

	public function test_pickup_only_store_profile_may_have_no_delivery_option(): void {
		$saved = $this->defaults->save_profile_defaults(
			FulfilmentAvailability::InStore->value,
			[
				ConfigurationFieldKey::FULFILMENT_CHOICE  => [ 'mode' => 'override', 'value' => FulfilmentChoice::StorePickup->value ],
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => 'Same day' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [] ],
				ConfigurationFieldKey::PICKUP_LOCATION_ID => [ 'mode' => 'override', 'value' => 1 ],
			]
		);

		self::assertSame( CollectionConfigurationMode::Replace, $saved->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->mode );
		self::assertSame( [], $saved->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
		self::assertSame( 1, $saved->scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]->value );
	}

	public function test_store_profile_without_pickup_and_without_delivery_option_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Select at least one Delivery Option.' );

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InStore->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2-3 business days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [] ],
				ConfigurationFieldKey::PICKUP_LOCATION_ID => [ 'mode' => 'disable' ],
			]
		);
	}

	private function warehouse_scope(): ScopedConfiguration {
		$scope = $this->repository->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			FulfilmentAvailability::InWarehouse->value
		);
		self::assertInstanceOf( ScopedConfiguration::class, $scope );

		return $scope;
	}

	private function assert_scope_unchanged( ScopedConfiguration $before ): void {
		$after = $this->warehouse_scope();

		self::assertSame( $before->scope->config_version, $after->scope->config_version );
		self::assertSame( '2-3 business days', $after->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
		self::assertSame( [ self::OFFER_ID ], $after->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
	}
}
