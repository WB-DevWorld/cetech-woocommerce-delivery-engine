<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class LegacyDestinationCoverageMigratorTest extends TestCase {

	public function test_single_location_and_same_level_or_and_idempotence(): void {
		$geo    = new GhanaGeographyFixture();
		$zones  = new InMemoryDestinationZoneRepository();
		$rules  = new InMemoryDestinationRuleRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save(
			[
				'id'            => 1,
				'internal_name' => 'Accra',
				'status'        => RecordStatus::Active->value,
			]
		);
		$zones->save(
			[
				'id'            => 2,
				'internal_name' => 'Towns',
				'status'        => RecordStatus::Active->value,
			]
		);
		$zones->save(
			[
				'id'            => 3,
				'internal_name' => 'Unknown city',
				'status'        => RecordStatus::Active->value,
			]
		);
		$rules->replaceForZone(
			1,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::Region->value, 'rule_value' => 'Greater Accra' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Accra' ],
			]
		);
		$rules->replaceForZone(
			2,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::Region->value, 'rule_value' => 'Greater Accra' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Accra' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Tema' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Madina' ],
			]
		);
		$rules->replaceForZone(
			3,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::Region->value, 'rule_value' => 'Greater Accra' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Not A Real Town' ],
			]
		);

		$migrator = new LegacyDestinationCoverageMigrator(
			$zones,
			$rules,
			$groups,
			$geo->locations,
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);

		$first  = $migrator->migrate();
		$second = $migrator->migrate();

		self::assertSame( 3, $first['converted'] );
		self::assertSame( 3, $second['skipped'] );
		self::assertGreaterThanOrEqual( 1, $first['review_required'] );

		$accra = $groups->list_by_zone( 1 )[0];
		self::assertFalse( $accra->review_required );
		self::assertSame( CoverageMode::SelectedDescendants, $accra->mode );
		self::assertSame( $geo->greater_accra->id, $accra->root_location_id );
		self::assertCount( 1, $accra->members );

		$or_group = $groups->list_by_zone( 2 )[0];
		self::assertTrue( $or_group->review_required );
		self::assertSame( 'duplicate_legacy_same_level', $or_group->legacy_migration['reason'] );
		self::assertCount( 3, $or_group->members );

		$materialized = $groups->list_by_zone( 3 )[0];
		self::assertFalse( $materialized->review_required );
		self::assertSame( RecordStatus::Active, $materialized->status );
		self::assertNotNull( $geo->locations->find_exact_child( 'GH', $geo->greater_accra->id, 'not a real town', \CetechDeliveryEngine\Domain\Enum\GeographyLocationType::Locality ) );
		self::assertNotEmpty( $rules->listByZoneId( 3 ) );
	}
}
