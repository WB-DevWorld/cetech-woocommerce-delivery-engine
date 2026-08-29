<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAdminCopy;
use CetechDeliveryEngine\Presentation\Admin\BulkJobItemResultPresenter;
use CetechDeliveryEngine\Presentation\Admin\BulkJobTargetLabelResolver;
use PHPUnit\Framework\TestCase;

final class BulkJobPreviewPresentationTest extends TestCase {

	public function test_dry_run_displays_would_change_not_changed(): void {
		$labels  = BulkJobAdminCopy::counter_labels( true );
		$changed = $this->label_for_key( $labels, 'changed' );
		$skipped = $this->label_for_key( $labels, 'skipped' );
		$failed  = $this->label_for_key( $labels, 'failed' );

		self::assertSame( 'Would change', $changed );
		self::assertSame( 'No change / would be skipped', $skipped );
		self::assertSame( 'Would fail', $failed );
		self::assertSame( 'Would change', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::Changed, true ) );
		self::assertStringNotContainsString( 'Changed', $changed );
	}

	public function test_applied_job_displays_actual_changed(): void {
		$labels = BulkJobAdminCopy::counter_labels( false );

		self::assertSame( 'Changed', $this->label_for_key( $labels, 'changed' ) );
		self::assertSame( 'Unchanged / skipped', $this->label_for_key( $labels, 'skipped' ) );
		self::assertSame( 'Failed', $this->label_for_key( $labels, 'failed' ) );
		self::assertSame( 'Changed', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::Changed, false ) );
	}

	public function test_machine_ready_renders_ready_to_apply(): void {
		self::assertSame( 'ready', BulkJobStatus::Ready->value );
		self::assertSame( 'Ready to apply', BulkJobAdminCopy::status_label( BulkJobStatus::Ready ) );
	}

	public function test_catalog_update_is_humanised(): void {
		self::assertSame( 'catalog_update', BulkOperationType::CatalogUpdate->value );
		self::assertSame( 'Catalog update', BulkJobAdminCopy::operation_label( BulkOperationType::CatalogUpdate ) );
	}

	public function test_product_target_shows_product_name_not_raw_id_as_primary(): void {
		$display = $this->resolver()->display( $this->product_item() );

		self::assertSame( 'T QA Beta Test Product', $display['primary'] );
		self::assertStringContainsString( 'SKU: QA-BETA', $display['secondary'] );
		self::assertStringContainsString( 'Product #49164', $display['secondary'] );
		self::assertSame( '49164', $display['raw_id'] );
		self::assertNotSame( '49164', $display['primary'] );
	}

	public function test_variation_target_shows_parent_and_variation_identity(): void {
		$display = $this->resolver()->display( $this->variation_item() );

		self::assertSame( 'T QA Beta Test Product — Champagne', $display['primary'] );
		self::assertStringContainsString( 'Variation SKU: QA-BETA-CHAMP', $display['secondary'] );
		self::assertStringContainsString( 'Variation #49165', $display['secondary'] );
		self::assertSame( '49165', $display['raw_id'] );
		self::assertNotSame( '49165', $display['primary'] );
	}

	public function test_raw_id_remains_secondary_when_catalog_is_unknown(): void {
		$display = ( new BulkJobTargetLabelResolver() )->display( $this->product_item() );

		self::assertSame( 'QA-BETA', $display['primary'] );
		self::assertStringContainsString( 'Product #49164', $display['secondary'] );
		self::assertSame( '49164', $display['raw_id'] );
	}

	public function test_result_describes_fulfilment_before_to_proposed_after(): void {
		$item     = $this->product_item()->with(
			[
				'status'          => BulkJobItemStatus::Changed,
				'before_snapshot' => [
					'scalars'      => [
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [
							'mode'  => 'override',
							'value' => 'in_warehouse',
						],
					],
					'collections'  => [],
				],
			]
		);
		$manifest = new CatalogActionManifest(
			[
				new CatalogFieldAction(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					CatalogFieldAction::SET_OVERRIDE,
					'international_fulfilment'
				),
			]
		);
		$blocks   = ( new BulkJobItemResultPresenter() )->blocks( $item, $manifest );
		$line     = BulkJobItemResultPresenter::compact_line( $blocks );

		self::assertSame( 'Fulfilment Availability', $blocks[0]['field'] );
		self::assertSame( 'In Warehouse', $blocks[0]['current'] );
		self::assertSame( 'International', $blocks[0]['proposed'] );
		self::assertSame( 'Fulfilment Availability: In Warehouse → International', $line );
		self::assertStringNotContainsString( 'in_warehouse', $line );
		self::assertStringNotContainsString( 'international_fulfilment', $line );
	}

	public function test_add_remove_replace_and_inherit_are_humanised(): void {
		$presenter = new BulkJobItemResultPresenter(
			[
				12              => 'Air Shipping',
				'air_shipping'  => 'Air Shipping',
				13              => 'Sea Shipping',
				'sea_shipping'  => 'Sea Shipping',
				14              => 'Standard Delivery',
			]
		);
		$item      = $this->product_item();

		$add = $presenter->blocks(
			$item,
			new CatalogActionManifest(
				[
					new CatalogFieldAction( ConfigurationFieldKey::DELIVERY_OFFER_IDS, CatalogFieldAction::COLLECTION_ADD, null, [ 12 ] ),
				]
			)
		);
		$remove = $presenter->blocks(
			$item,
			new CatalogActionManifest(
				[
					new CatalogFieldAction( ConfigurationFieldKey::DELIVERY_OFFER_IDS, CatalogFieldAction::COLLECTION_REMOVE, null, [ 13 ] ),
				]
			)
		);
		$replace = $presenter->blocks(
			$item,
			new CatalogActionManifest(
				[
					new CatalogFieldAction( ConfigurationFieldKey::DELIVERY_OFFER_IDS, CatalogFieldAction::COLLECTION_REPLACE, null, [ 14 ] ),
				]
			)
		);
		$inherit = $presenter->blocks(
			$item,
			new CatalogActionManifest(
				[
					new CatalogFieldAction( ConfigurationFieldKey::DELIVERY_OFFER_IDS, CatalogFieldAction::COLLECTION_INHERIT ),
				]
			)
		);

		self::assertSame( 'Add Air Shipping', $add[0]['proposed'] );
		self::assertSame( 'Remove Sea Shipping', $remove[0]['proposed'] );
		self::assertSame( 'Replace with Standard Delivery', $replace[0]['proposed'] );
		self::assertSame( 'Restore inherited Delivery Options', $inherit[0]['proposed'] );
		self::assertStringNotContainsString( 'COLLECTION_', $inherit[0]['proposed'] );
		self::assertStringNotContainsString( 'copy', strtolower( $inherit[0]['proposed'] ) );
	}

	public function test_reset_to_site_wide_explains_inheritance_not_copied_values(): void {
		$item     = $this->product_item()->with(
			[
				'status'          => BulkJobItemStatus::Changed,
				'result'          => [ 'dry_run' => true, 'would_delete_scope' => true ],
				'before_snapshot' => [
					'scalars'     => [
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [
							'mode'  => 'override',
							'value' => 'in_warehouse',
						],
					],
					'collections' => [],
				],
			]
		);
		$blocks   = ( new BulkJobItemResultPresenter() )->blocks( $item, new CatalogActionManifest( [], true ) );
		$proposed = $blocks[0]['proposed'];

		self::assertSame( 'Restore Site-wide inheritance', $proposed );
		self::assertNotSame( 'In Warehouse', $proposed );
		self::assertStringNotContainsString( 'copy', strtolower( $proposed ) );
		$reset_notice = strtolower( BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::ResetVariationsToParent ) );
		self::assertStringContainsString( 'inheritance', $reset_notice );
		self::assertStringContainsString( 'not copying', $reset_notice );
	}

	public function test_completed_preview_hides_cancel_remaining_while_engine_still_allows_cancel(): void {
		self::assertFalse( BulkJobAdminCopy::shows_cancel_remaining( BulkJobStatus::Ready ) );
		self::assertTrue( BulkJobStatus::Ready->allows_cancel() );
		self::assertTrue( BulkJobStatus::Ready->allows_apply() );
		self::assertStringContainsString( 'preserved', strtolower( BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides ) ) );
		self::assertStringContainsString( 'this preview does not write variation rows', strtolower( BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides ) ) );
		self::assertStringNotContainsString( 'now use the updated', strtolower( BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides ) ) );
	}

	public function test_running_job_still_permits_cancel(): void {
		self::assertTrue( BulkJobAdminCopy::shows_cancel_remaining( BulkJobStatus::Running ) );
		self::assertTrue( BulkJobAdminCopy::shows_cancel_remaining( BulkJobStatus::Previewing ) );
		self::assertTrue( BulkJobAdminCopy::shows_cancel_remaining( BulkJobStatus::Queued ) );
		self::assertTrue( BulkJobStatus::Running->allows_cancel() );
	}

	public function test_target_name_loading_collects_only_the_current_page(): void {
		$items = [];
		for ( $id = 1; $id <= 250; $id++ ) {
			$items[] = BulkJobItem::pending( 1, 'product', $id );
		}
		$ids = BulkJobTargetLabelResolver::collect_ids( $items );

		self::assertCount( BulkJobTargetLabelResolver::MAX_PAGE_IDS, $ids );
		self::assertSame( 200, BulkJobTargetLabelResolver::MAX_PAGE_IDS );
		self::assertSame( 1, $ids[0] );
		self::assertSame( 200, $ids[199] );
		self::assertNotContains( 201, $ids );
		self::assertLessThan( count( $items ), count( $ids ) );
	}

	public function test_variation_impact_counts_distinguish_inheritance_from_overrides(): void {
		$note = BulkJobAdminCopy::variation_inherit_count_note( 3, 1 );

		self::assertStringContainsString( '3 variations inherit this product', $note );
		self::assertStringContainsString( 'through inheritance', $note );
		self::assertStringContainsString( '1 variation has its own override and will remain unchanged', $note );
	}

	public function test_preview_and_apply_notices_are_explicit(): void {
		self::assertSame(
			'Preview only — no product settings have been changed yet.',
			BulkJobAdminCopy::preview_only_notice()
		);
		self::assertSame(
			'Applying starts a background job. Changes are processed in small batches.',
			BulkJobAdminCopy::apply_help()
		);
	}

	public function test_validation_scan_copy_is_read_only_and_hides_apply_language(): void {
		$job = BulkJob::create(
			BulkOperationType::ValidationScan,
			1,
			[ 'scope' => 'selected_ids', 'selected_ids' => [ 1 ] ],
			[]
		)->with(
			[
				'id'              => 18,
				'status'          => BulkJobStatus::Completed,
				'dry_run'         => true,
				'total_count'     => 1,
				'processed_count' => 1,
				'skipped_count'   => 1,
			]
		);

		self::assertSame( 'Scan result', BulkJobAdminCopy::job_result_heading( $job ) );
		self::assertSame( 'Finding', BulkJobAdminCopy::compare_after_heading( $job ) );
		self::assertFalse( $job->status->allows_apply() );
		self::assertFalse( BulkJobAdminCopy::shows_rollback( $job ) );
		self::assertSame( 'Valid / Healthy', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::Unchanged, true, false, 'valid' ) );
		self::assertSame( 'Invalid / Needs Attention', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::Failed, true, false, 'invalid' ) );
	}

	/**
	 * @param list<array{key: string, label: string}> $labels
	 */
	private function label_for_key( array $labels, string $key ): string {
		foreach ( $labels as $row ) {
			if ( $key === $row['key'] ) {
				return $row['label'];
			}
		}

		self::fail( 'Missing counter label for ' . $key );
	}

	private function resolver(): BulkJobTargetLabelResolver {
		return new BulkJobTargetLabelResolver(
			[
				49164 => [
					'title'        => 'T QA Beta Test Product',
					'sku'          => 'QA-BETA',
					'parent_id'    => 0,
					'parent_title' => '',
					'is_variation' => false,
				],
				49165 => [
					'title'        => 'Champagne',
					'sku'          => 'QA-BETA-CHAMP',
					'parent_id'    => 49164,
					'parent_title' => 'T QA Beta Test Product',
					'is_variation' => true,
				],
			],
			[
				49164 => [ 'inherit' => 3, 'override' => 1 ],
			]
		);
	}

	private function product_item(): BulkJobItem {
		return BulkJobItem::pending( 1, 'product', 49164, 'QA-BETA' );
	}

	private function variation_item(): BulkJobItem {
		return BulkJobItem::pending( 1, 'variation', 49165, 'QA-BETA-CHAMP', 49164 );
	}
}
