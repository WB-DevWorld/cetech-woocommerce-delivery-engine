<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class ConfigurationVersioningTest extends TestCase {

	public function test_new_configuration_starts_at_version_one(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$saved      = $repository->ensureGlobalScope();

		self::assertSame( 1, $saved->scope->config_version );
		self::assertSame( 1, $repository->getGlobalVersion() );
	}

	public function test_semantic_change_increments_version(): void {
		$repository = new InMemoryScopedConfigurationRepository();

		$first = $repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					7,
					'in_store',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						1
					),
				]
			)
		);

		$second = $repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					7,
					'in_store',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						2
					),
				]
			)
		);

		self::assertSame( 1, $first->scope->config_version );
		self::assertSame( 2, $second->scope->config_version );
	}

	public function test_identical_write_does_not_increment_version(): void {
		$repository = new InMemoryScopedConfigurationRepository();

		$payload = new ScopedConfiguration(
			new ConfigurationScope(
				null,
				ConfigurationScopeType::Product,
				8,
				'in_store',
				null,
				RecordStatus::Active,
				1,
				ConfigurationSource::Native,
				null
			),
			[
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::PRIORITY,
					5
				),
			]
		);

		$first  = $repository->saveScopedConfiguration( $payload );
		$second = $repository->saveScopedConfiguration( $payload );

		self::assertSame( 1, $first->scope->config_version );
		self::assertSame( 1, $second->scope->config_version );
		self::assertSame( $first->scope->id, $second->scope->id );
	}

	public function test_product_update_does_not_mutate_unrelated_global_or_variation_versions(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$global     = $repository->ensureGlobalScope();

		$variation = $repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Variation,
					90,
					'in_store',
					80,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						1
					),
				]
			)
		);

		$repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					80,
					'in_store',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						3
					),
				]
			)
		);

		$repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					80,
					'in_store',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::PRIORITY,
						4
					),
				]
			)
		);

		$global_after    = $repository->getGlobalConfiguration();
		$variation_after = $repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 90, 'in_store' );

		self::assertNotNull( $global_after );
		self::assertNotNull( $variation_after );
		self::assertSame( $global->scope->config_version, $global_after->scope->config_version );
		self::assertSame( $variation->scope->config_version, $variation_after->scope->config_version );
	}
}
