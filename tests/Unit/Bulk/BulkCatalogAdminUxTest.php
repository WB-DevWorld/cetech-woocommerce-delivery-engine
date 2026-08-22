<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Presentation\Admin\BulkCatalogAdminChoices;
use PHPUnit\Framework\TestCase;

final class BulkCatalogAdminUxTest extends TestCase {

	public function test_product_search_ids_merge_with_advanced_id_paste(): void {
		$ids = BulkCatalogAdminChoices::merge_product_ids(
			[
				'selected_product_ids' => [ '12', 12, 0, 'abc', 34 ],
				'product_ids'          => "34\n56, 78",
			]
		);

		self::assertSame( [ 12, 34, 56, 78 ], $ids );
	}

	public function test_offer_selector_members_merge_with_advanced_codes(): void {
		$members = BulkCatalogAdminChoices::merge_offer_members(
			[
				'offer_ids'           => [ '12', 'air_shipping' ],
				'offer_ids_advanced'  => 'sea_shipping, 12',
			]
		);

		self::assertSame( [ 12, 'air_shipping', 'sea_shipping' ], $members );
	}

	public function test_sku_only_selected_scope_becomes_matching_filters(): void {
		self::assertSame(
			BulkTargetScope::MatchingFilters->value,
			BulkCatalogAdminChoices::resolve_target_scope( BulkTargetScope::SelectedIds->value, [], [ 'SKU-1' ] )
		);
		self::assertSame(
			BulkTargetScope::SelectedIds->value,
			BulkCatalogAdminChoices::resolve_target_scope( BulkTargetScope::SelectedIds->value, [ 9 ], [ 'SKU-1' ] )
		);
	}

	public function test_private_selectors_are_empty_without_capability(): void {
		$choices = new BulkCatalogAdminChoices(
			null,
			null,
			null,
			new class {
				/** @return list<array<string, mixed>> */
				public function list( array $criteria = [] ): array {
					return [ [ 'id' => 7, 'internal_name' => 'Hidden Supplier' ] ];
				}
			},
			new class {
				/** @return list<array<string, mixed>> */
				public function list( array $criteria = [] ): array {
					return [ [ 'id' => 8, 'internal_name' => 'Hidden Origin' ] ];
				}
			}
		);

		self::assertFalse( $choices->can_view_private_sources() );
		self::assertSame( [], $choices->suppliers() );
		self::assertSame( [], $choices->origins() );
	}

	public function test_delivery_option_selector_uses_public_labels_not_raw_ids(): void {
		$choices = new BulkCatalogAdminChoices(
			new class {
				/**
				 * @return list<array<string, mixed>>
				 */
				public function page_after( int $after_id, int $limit = 100 ): array {
					if ( $after_id > 0 ) {
						return [];
					}

					return [
						[ 'id' => 3, 'public_label' => 'Air Shipping', 'internal_code' => 'air' ],
					];
				}
			}
		);

		self::assertSame( [ 3 => 'Air Shipping' ], $choices->delivery_options() );
	}

	public function test_normal_catalog_source_does_not_use_raw_id_first_labels(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/BulkToolsPage.php' );
		$source = preg_replace( '#/\*.*?\*/#s', '', $source ) ?? $source;
		$source = preg_replace(
			'#<details class="cetech-de-technical-details">.*?</details>#s',
			'',
			$source
		) ?? $source;
		$source = preg_replace(
			'#AdminPageLayout::open_technical_details\([^;]*\);.*?AdminPageLayout::close_technical_details\(\);#s',
			'',
			$source
		) ?? $source;

		foreach ( BulkCatalogAdminChoices::forbidden_normal_catalog_phrases() as $phrase ) {
			self::assertStringNotContainsString(
				$phrase,
				$source,
				'Normal Catalog workflow still contains technical phrase: ' . $phrase
			);
		}

		self::assertStringContainsString( 'Search and select products', $source );
		self::assertStringContainsString( 'Product-specific fulfilment setting', $source );
		self::assertStringContainsString( 'Current fulfilment', $source );
		self::assertStringContainsString( 'Configuration source', $source );
		self::assertStringContainsString( 'Uses Site-wide Defaults', $source );
		self::assertStringContainsString( 'Has Product Exception', $source );
		self::assertStringContainsString( 'Inherits product', $source );
		self::assertStringContainsString( 'cetech-de-product-search', $source );
		self::assertStringContainsString( 'data-reveal-fulfilment', $source );
		self::assertStringContainsString( 'data-reveal-offers', $source );
		self::assertStringContainsString( 'restore Site-wide inheritance', $source );
	}

	public function test_advanced_id_fallback_prefers_labelled_selector(): void {
		self::assertSame( 44, BulkCatalogAdminChoices::first_positive_id( '44', 99 ) );
		self::assertSame( 99, BulkCatalogAdminChoices::first_positive_id( 0, '99' ) );
		self::assertSame( 0, BulkCatalogAdminChoices::first_positive_id( '', '' ) );
	}
}
