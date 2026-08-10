<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class ScopedConfigurationStorageTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
	}

	public function test_global_configuration_persist_and_read(): void {
		$saved = $this->repository->ensureGlobalScope();
		$read  = $this->repository->getGlobalConfiguration();

		self::assertNotNull( $read );
		self::assertSame( ConfigurationScopeType::Global, $read->scope->scope_type );
		self::assertSame( 0, $read->scope->scope_id );
		self::assertSame( $saved->scope->id, $read->scope->id );
		self::assertSame( 1, $read->scope->config_version );
	}

	public function test_product_and_variation_scopes_persist_and_read(): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					101,
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
						0
					),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[]
					),
				]
			)
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Variation,
					202,
					'in_store',
					101,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[
					ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::disable(
						ConfigurationFieldKey::SUPPLIER_ID
					),
				]
			)
		);

		$read_product   = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, 'in_store' );
		$read_variation = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' );

		self::assertNotNull( $read_product );
		self::assertNotNull( $read_variation );
		self::assertSame( 0, $read_product->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame(
			ScalarConfigurationMode::Disable,
			$read_variation->scalars[ ConfigurationFieldKey::SUPPLIER_ID ]->mode
		);
		self::assertSame(
			CollectionConfigurationMode::Replace,
			$read_product->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->mode
		);
		self::assertSame( [], $read_product->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
		self::assertCount( 1, $this->repository->findByParentProductId( 101 ) );
	}

	public function test_replace_empty_distinct_from_inherit_round_trip(): void {
		$replace = $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					11,
					'slice_a',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[]
					),
				]
			)
		);

		$inherit = $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					11,
					'slice_b',
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS
					),
				]
			)
		);

		self::assertNotSame(
			$replace->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->fingerprint(),
			$inherit->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->fingerprint()
		);
	}

	public function test_scope_uniqueness_same_slice_updates_same_row(): void {
		$first = $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					55,
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
						10
					),
				]
			)
		);

		$second = $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					55,
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
						20
					),
				]
			)
		);

		self::assertSame( $first->scope->id, $second->scope->id );
		self::assertSame( 2, $second->scope->config_version );
		self::assertCount( 1, $this->repository->findByScope( ConfigurationScopeType::Product, 55 ) );
	}

	public function test_unknown_field_key_rejected(): void {
		$this->expectException( InvalidConfigurationException::class );

		ScalarFieldInstruction::override( 'not_a_real_field', 1 );
	}

	public function test_invalid_product_id_rejected(): void {
		$this->expectException( InvalidConfigurationException::class );

		new ConfigurationScope(
			null,
			ConfigurationScopeType::Product,
			0,
			'',
			null,
			RecordStatus::Active,
			1,
			ConfigurationSource::Native,
			null
		);
	}
}
