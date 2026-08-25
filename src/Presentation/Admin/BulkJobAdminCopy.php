<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;

/**
 * Human administrator labels for Bulk Tools jobs. Machine codes stay unchanged.
 */
final class BulkJobAdminCopy {

	public static function operation_label( BulkOperationType $type ): string {
		return match ( $type ) {
			BulkOperationType::CatalogUpdate => __( 'Catalog update', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::CatalogCsvImport => __( 'Catalog CSV import', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::CatalogCsvExport => __( 'Catalog CSV export', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::ConfigExport => __( 'Configuration export', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::ConfigImport => __( 'Configuration import', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::RateCardUpdate => __( 'Delivery Charge update', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::EntityUpdate => __( 'Setup update', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::ValidationScan => __( 'Validation scan', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::Cleanup => __( 'Cleanup', 'cetech-woocommerce-delivery-engine' ),
			BulkOperationType::Rollback => __( 'Rollback', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function status_label( BulkJobStatus $status ): string {
		return match ( $status ) {
			BulkJobStatus::Draft => __( 'Draft', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Previewing => __( 'Preparing preview', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Ready => __( 'Ready to apply', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Queued => __( 'Queued', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Running => __( 'Running', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::CancelRequested => __( 'Cancel requested', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Cancelled => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Completed => __( 'Completed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::CompletedWithErrors => __( 'Completed with errors', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Failed => __( 'Failed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RollbackQueued => __( 'Rollback queued', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RollingBack => __( 'Rolling back', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RolledBack => __( 'Rolled back', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::PartiallyRolledBack => __( 'Partially rolled back', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function item_status_label( BulkJobItemStatus $status, bool $dry_run ): string {
		if ( $dry_run ) {
			return match ( $status ) {
				BulkJobItemStatus::Changed => __( 'Would change', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Unchanged, BulkJobItemStatus::Skipped => __( 'No change / would be skipped', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Failed => __( 'Would fail', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Pending => __( 'Waiting', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Claimed => __( 'In progress', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Cancelled => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::RolledBack => __( 'Rolled back', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::RollbackSkipped => __( 'Rollback skipped', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::RollbackFailed => __( 'Rollback failed', 'cetech-woocommerce-delivery-engine' ),
			};
		}

		return match ( $status ) {
			BulkJobItemStatus::Changed => __( 'Changed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::Unchanged, BulkJobItemStatus::Skipped => __( 'Unchanged / skipped', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::Failed => __( 'Failed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::Pending => __( 'Waiting', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::Claimed => __( 'In progress', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::Cancelled => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::RolledBack => __( 'Rolled back', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::RollbackSkipped => __( 'Rollback skipped', 'cetech-woocommerce-delivery-engine' ),
			BulkJobItemStatus::RollbackFailed => __( 'Rollback failed', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public static function counter_labels( bool $dry_run ): array {
		if ( $dry_run ) {
			return [
				[ 'key' => 'total', 'label' => __( 'Total', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'changed', 'label' => __( 'Would change', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'skipped', 'label' => __( 'No change / would be skipped', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'failed', 'label' => __( 'Would fail', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'warnings', 'label' => __( 'Warnings', 'cetech-woocommerce-delivery-engine' ) ],
			];
		}

		return [
			[ 'key' => 'total', 'label' => __( 'Total', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'key' => 'changed', 'label' => __( 'Changed', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'key' => 'skipped', 'label' => __( 'Unchanged / skipped', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'key' => 'failed', 'label' => __( 'Failed', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'key' => 'warnings', 'label' => __( 'Warnings', 'cetech-woocommerce-delivery-engine' ) ],
		];
	}

	public static function shows_cancel_remaining( BulkJobStatus $status ): bool {
		return $status->is_active_worker_state();
	}

	public static function target_type_label( string $target_type ): string {
		return match ( $target_type ) {
			'variation' => __( 'Variation', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Product', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function empty_jobs_title(): string {
		return __( 'No bulk jobs yet.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function empty_jobs_text(): string {
		return __( 'Preview a Catalog change to create the first job. Apply runs afterwards as a separate background job.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function empty_job_items_text(): string {
		return __( 'No job items on this page. Try another page, or wait for the job to finish listing products.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function empty_csv_text(): string {
		return __( 'Paste a CSV with a header row to preview. Nothing is imported until you apply a preview job.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function empty_package_text(): string {
		return __( 'Paste a configuration package to preview. Importing does not activate checkout.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function preview_only_notice(): string {
		return __( 'Preview only — no product settings have been changed yet.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function apply_help(): string {
		return __( 'Applying starts a background job. Changes are processed in small batches.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function applied_notice(): string {
		return __( 'These counters are the actual applied result.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function variation_policy_notice( BulkVariationPolicy $policy ): string {
		return match ( $policy ) {
			BulkVariationPolicy::PreserveOverrides => __( 'Existing variation overrides will be preserved. Variations that inherit this product would use the proposed product settings through inheritance. This preview does not write variation rows unless a variation is a direct target.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ParentOnly => __( 'Only parent product settings are targeted. Variation-specific settings are not written by this job.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ResetVariationsToParent => __( 'After the parent change, variations would be restored to inherit from the product. That is inheritance, not copying today’s product values into each variation.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ParentAndInheriting => __( 'The parent product and variations that inherit it would be affected. Variations with their own override stay unchanged unless they are direct targets.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::SelectedVariations => __( 'Only the selected variations are targeted.', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function variation_inherit_count_note( int $inherit, int $override ): string {
		$parts = [];
		if ( $inherit > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: variation count */
				_n(
					'%d variation inherits this product and would therefore be affected through inheritance.',
					'%d variations inherit this product and would therefore be affected through inheritance.',
					$inherit,
					'cetech-woocommerce-delivery-engine'
				),
				$inherit
			);
		}
		if ( $override > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: variation count */
				_n(
					'%d variation has its own override and will remain unchanged.',
					'%d variations have their own override and will remain unchanged.',
					$override,
					'cetech-woocommerce-delivery-engine'
				),
				$override
			);
		}

		return implode( ' ', $parts );
	}

	public static function field_label( string $field_key ): string {
		return match ( $field_key ) {
			'fulfilment_availability' => __( 'Fulfilment Availability', 'cetech-woocommerce-delivery-engine' ),
			'delivery_offer_ids' => __( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			'logistics_profile_id' => __( 'Logistics Profile', 'cetech-woocommerce-delivery-engine' ),
			'estimated_delivery' => __( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ),
			default => $field_key,
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function status_labels_map(): array {
		$map = [];
		foreach ( BulkJobStatus::cases() as $status ) {
			$map[ $status->value ] = self::status_label( $status );
		}

		return $map;
	}
}
