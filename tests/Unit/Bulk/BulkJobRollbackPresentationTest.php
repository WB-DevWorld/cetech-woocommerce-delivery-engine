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
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAdminCopy;
use CetechDeliveryEngine\Presentation\Admin\BulkJobItemResultPresenter;
use PHPUnit\Framework\TestCase;

final class BulkJobRollbackPresentationTest extends TestCase {

	public function test_rollback_counters_use_restored_skipped_failed_not_changed(): void {
		$job    = $this->rollback_job( BulkJobStatus::RolledBack, 1, 0, 0 );
		$labels = BulkJobAdminCopy::counter_labels_for_job( $job );
		$values = BulkJobAdminCopy::counter_values( $job );
		$keys   = array_column( $labels, 'key' );

		self::assertSame( 'Restored', $this->label_for_key( $labels, 'restored' ) );
		self::assertSame( 'Skipped / conflict', $this->label_for_key( $labels, 'skipped' ) );
		self::assertSame( 'Failed', $this->label_for_key( $labels, 'failed' ) );
		self::assertNotContains( 'changed', $keys );
		self::assertSame( 1, $values['restored'] );
		self::assertSame( 1, $values['total'] );
		foreach ( $labels as $row ) {
			self::assertNotSame( 'Changed', $row['label'] );
		}
	}

	public function test_queued_rollback_uses_will_restore_not_applied(): void {
		$job = $this->rollback_job( BulkJobStatus::RollbackQueued, 0, 0, 0 );

		self::assertSame( 'Will restore', BulkJobAdminCopy::compare_after_heading( $job ) );
		self::assertSame( 'Rollback result', BulkJobAdminCopy::job_result_heading( $job ) );
		self::assertSame( 'Waiting', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::Pending, false, true ) );
		self::assertNotSame( 'Applied', BulkJobAdminCopy::compare_after_heading( $job ) );
		self::assertStringNotContainsString( 'Applied', BulkJobAdminCopy::compare_after_heading( $job ) );
	}

	public function test_completed_rollback_uses_restored_heading(): void {
		$job = $this->rollback_job( BulkJobStatus::RolledBack, 1, 0, 0 );

		self::assertSame( 'Restored', BulkJobAdminCopy::compare_after_heading( $job ) );
		self::assertSame( 'Restored', BulkJobAdminCopy::item_status_label( BulkJobItemStatus::RolledBack, false, true ) );
		self::assertSame( 'Rolled back · 1 / 1', sprintf( '%s · %d / %d', BulkJobAdminCopy::status_label( $job->status ), $job->processed_count, $job->total_count ) );
	}

	public function test_queued_rollback_current_is_applied_value_and_will_restore_is_previous_inheritance(): void {
		$item     = BulkJobItem::pending( 2, 'product', 49164, 'QA-BETA' )->with(
			[
				'status'          => BulkJobItemStatus::Pending,
				'before_snapshot' => [
					'scalars'     => [],
					'collections' => [],
				],
			]
		);
		$manifest = new CatalogActionManifest(
			[
				new CatalogFieldAction(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					CatalogFieldAction::SET_OVERRIDE,
					FulfilmentAvailability::InternationalFulfilment->value
				),
			]
		);
		$blocks = ( new BulkJobItemResultPresenter() )->blocks( $item, $manifest );

		self::assertSame( 'International', $blocks[0]['proposed'] );
		self::assertSame( 'Uses Site-wide Defaults', $blocks[0]['current'] );
	}

	public function test_preview_variation_copy_is_not_used_on_applied_or_rollback_jobs(): void {
		$preview = BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides, 'preview' );
		$applied = BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides, 'applied' );
		$rollback = BulkJobAdminCopy::variation_policy_notice( BulkVariationPolicy::PreserveOverrides, 'rollback' );

		self::assertStringContainsString( 'This preview does not write variation rows', $preview );
		self::assertStringContainsString( 'would use the proposed Product settings', $preview );
		self::assertStringNotContainsString( 'This preview does not write', $applied );
		self::assertStringNotContainsString( 'This preview does not write', $rollback );
		self::assertStringContainsString( 'now use the updated Product settings through inheritance', $applied );
		self::assertStringContainsString( 'No Variation override rows were created', $applied );
		self::assertStringContainsString( 'restored to its previous inheritance state', $rollback );
		self::assertStringContainsString( 'Inheriting Variations again resolve', $rollback );
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

	private function rollback_job( BulkJobStatus $status, int $restored, int $skipped, int $failed ): BulkJob {
		$processed = $restored + $skipped + $failed;

		return BulkJob::create( BulkOperationType::Rollback, 1, [], [], false, 25, 1 )
			->with_status( $status )
			->with_progress(
				max( 1, $processed ),
				max( 1, $processed ),
				$processed,
				0,
				$skipped,
				$failed,
				0,
				true,
				'0',
				[
					'rollback_restored' => $restored,
					'rollback_skipped'  => $skipped,
					'rollback_failed'   => $failed,
				]
			);
	}
}
