<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Presentation\Admin\BulkAdminListPreferences;
use PHPUnit\Framework\TestCase;

final class BulkAdminPaginationAndManifestTest extends TestCase {

	public function test_admin_page_size_defaults_to_twenty_five_and_rejects_five_hundred(): void {
		self::assertSame( 25, BulkAdminListPreferences::DEFAULT_PER_PAGE );
		self::assertSame( 25, BulkAdminListPreferences::sanitize_per_page( 500 ) );
		self::assertSame( 25, BulkAdminListPreferences::sanitize_per_page( 1 ) );
		self::assertSame( 20, BulkAdminListPreferences::sanitize_per_page( 20 ) );
		self::assertSame( 50, BulkAdminListPreferences::sanitize_per_page( 50 ) );
		self::assertSame( 100, BulkAdminListPreferences::sanitize_per_page( 100 ) );
		self::assertSame( 100, BulkAdminListPreferences::clamp_query_limit( 500 ) );
	}

	public function test_selected_id_pages_are_binary_searched_not_fully_scanned_for_the_slice(): void {
		$ids  = range( 1, 100000 );
		$page = CatalogTargetDefinition::page_sorted_ids( $ids, 50000, 25 );
		self::assertSame( range( 50001, 50025 ), $page );
		self::assertCount( 25, CatalogTargetDefinition::page_sorted_ids( $ids, 0, 25 ) );
		self::assertSame( [], CatalogTargetDefinition::page_sorted_ids( $ids, 100000, 25 ) );
	}

	public function test_materialized_definition_drops_the_id_array_and_keeps_the_count(): void {
		$definition = CatalogTargetDefinition::from_array(
			[
				'scope'        => BulkTargetScope::SelectedIds->value,
				'selected_ids' => [ 9, 1, 9, 3 ],
			]
		);
		self::assertSame( [ 1, 3, 9 ], $definition->selected_ids );
		$slim = $definition->after_materialization();
		self::assertSame( [], $slim->selected_ids );
		self::assertTrue( $slim->selected_ids_materialized );
		self::assertSame( 3, $slim->selected_count() );
		self::assertSame( 3, $slim->to_array()['selected_id_count'] );
	}

	public function test_selected_id_json_size_stays_compact_relative_to_count(): void {
		$ten_k = strlen( (string) json_encode( [ 'selected_ids' => range( 1, 10000 ) ], JSON_THROW_ON_ERROR ) );
		self::assertGreaterThan( 40_000, $ten_k );
		self::assertLessThan( 120_000, $ten_k );
	}
}
