<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\LegacyConfigurationMigrator;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LegacyMigrationMappingTest extends TestCase {

	public function test_populated_scalar_and_falsey_values_map_explicitly(): void {
		$mapper = new LegacyProductRuleMigrationMapper();

		$result = $mapper->map_row(
			[
				'id'                      => 1,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 10,
				'fulfilment_availability' => 'in_store',
				'fulfilment_choice'       => 'delivery',
				'logistics_profile_id'    => 5,
				'supplier_id'             => null,
				'origin_id'               => 0,
				'priority'                => 0,
				'status'                  => RecordStatus::Active->value,
			],
			[ 3, 1, 3 ]
		);

		self::assertSame( 'migrate', $result['action'] );
		$config = $result['configuration'];
		self::assertNotNull( $config );
		self::assertSame( ConfigurationScopeType::Product, $config->scope->scope_type );
		self::assertSame( 'in_store', $config->scope->slice_key );
		self::assertSame( ScalarConfigurationMode::Override, $config->scalars[ ConfigurationFieldKey::PRIORITY ]->mode );
		self::assertSame( 0, $config->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame( ScalarConfigurationMode::Disable, $config->scalars[ ConfigurationFieldKey::SUPPLIER_ID ]->mode );
		self::assertSame( ScalarConfigurationMode::Disable, $config->scalars[ ConfigurationFieldKey::ORIGIN_ID ]->mode );
		self::assertSame( ScalarConfigurationMode::Override, $config->scalars[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ]->mode );
		self::assertSame( CollectionConfigurationMode::Replace, $config->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->mode );
		self::assertSame( [ 3, 1 ], $config->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
	}

	public function test_empty_offer_ids_become_replace_empty_not_inherit(): void {
		$mapper = new LegacyProductRuleMigrationMapper();

		$result = $mapper->map_row(
			[
				'id'                      => 2,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 11,
				'fulfilment_availability' => 'in_store',
				'fulfilment_choice'       => 'store_pickup',
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
			[]
		);

		$config = $result['configuration'];
		self::assertNotNull( $config );
		$offers = $config->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ];
		self::assertSame( CollectionConfigurationMode::Replace, $offers->mode );
		self::assertSame( [], $offers->members );
	}

	public function test_variation_requires_parent_and_category_is_quarantined(): void {
		$mapper = new LegacyProductRuleMigrationMapper();

		$category = $mapper->map_row(
			[
				'id'                      => 3,
				'target_type'             => ProductTargetType::Category->value,
				'target_id'               => 99,
				'fulfilment_availability' => 'in_store',
				'fulfilment_choice'       => 'delivery',
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
			[ 1 ]
		);

		self::assertSame( 'quarantine', $category['action'] );
		self::assertSame( LegacyProductRuleMigrationMapper::QUARANTINE_CATEGORY, $category['reason'] );

		$variation = $mapper->map_row(
			[
				'id'                      => 4,
				'target_type'             => ProductTargetType::Variation->value,
				'target_id'               => 44,
				'fulfilment_availability' => 'international_fulfilment',
				'fulfilment_choice'       => 'delivery',
				'priority'                => 50,
				'status'                  => RecordStatus::Inactive->value,
			],
			[ 7 ],
			22
		);

		self::assertSame( 'migrate', $variation['action'] );
		$config = $variation['configuration'];
		self::assertNotNull( $config );
		self::assertSame( ConfigurationScopeType::Variation, $config->scope->scope_type );
		self::assertSame( 22, $config->scope->parent_product_id );
		self::assertSame( RecordStatus::Inactive, $config->scope->status );
	}

	public function test_compatibility_matrix_covers_all_legacy_columns(): void {
		$matrix = LegacyProductRuleMigrationMapper::compatibility_matrix();
		$fields = array_column( $matrix, 'legacy_field' );

		foreach (
			[
				'fulfilment_availability',
				'fulfilment_choice',
				'delivery_offer_ids (JSON list)',
				'delivery_offer_ids null/empty',
				'logistics_profile_id positive',
				'logistics_profile_id null/0',
				'supplier_id positive',
				'supplier_id null/0',
				'origin_id positive',
				'origin_id null/0',
				'priority (including 0)',
				'status',
				'target_type=category',
				'target_type=product',
				'target_type=variation',
				'internal_notes',
			] as $required
		) {
			self::assertContains( $required, $fields );
		}
	}

	public function test_migrator_is_idempotent_and_preserves_legacy_fixture(): void {
		$legacy_rows = [
			[
				'id'                      => 10,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 100,
				'fulfilment_availability' => 'in_store',
				'fulfilment_choice'       => 'delivery',
				'delivery_offer_ids'      => '[1,2]',
				'logistics_profile_id'    => null,
				'supplier_id'             => null,
				'origin_id'               => null,
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
			[
				'id'                      => 11,
				'target_type'             => ProductTargetType::Category->value,
				'target_id'               => 5,
				'fulfilment_availability' => 'in_store',
				'fulfilment_choice'       => 'delivery',
				'delivery_offer_ids'      => '[9]',
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
		];

		$legacy = new class( $legacy_rows ) implements ProductDeliveryRuleRepositoryInterface {
			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {
			}

			public function findById( int $id ): ?array {
				return null;
			}

			public function findByTarget( string $target_type, int $target_id ): array {
				return [];
			}

			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}

			public function list( array $filters = [] ): array {
				return $this->rows;
			}

			public function listActive( array $filters = [] ): array {
				return $this->rows;
			}

			public function findActiveByTargets( array $targets ): array {
				return [];
			}

			public function save( array $data ): int {
				return 0;
			}

			public function deactivate( int $id ): bool {
				return false;
			}

			public function hardDelete( int $id ): bool {
				return false;
			}

			public function count_all(): int {
				return count( $this->rows );
			}

			public function countBySupplierId( int $supplier_id ): int {
				return 0;
			}

			public function countByOriginId( int $origin_id ): int {
				return 0;
			}

			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}
		};

		$repository = new InMemoryScopedConfigurationRepository();
		$logger     = new Logger();
		$migrator   = new LegacyConfigurationMigrator(
			$legacy,
			$repository,
			new LegacyProductRuleMigrationMapper(),
			$logger
		);

		$first  = $migrator->migrate();
		$second = $migrator->migrate();

		self::assertSame( [ 10 ], $first['migrated'] );
		self::assertSame( 11, $first['quarantined'][0]['legacy_rule_id'] );
		self::assertSame( [], $second['migrated'] );
		self::assertSame( [ 10 ], $second['skipped_existing'] );
		self::assertCount( 1, $repository->findByScope( ConfigurationScopeType::Product, 100 ) );
		self::assertSame( 1, $repository->findByLegacyRuleId( 10 )?->scope->config_version );
		self::assertSame( $legacy_rows, $legacy->list() );
	}
}
