<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkJobRunnerState;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
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
			BulkJobStatus::Running => __( 'Processing', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::CancelRequested => __( 'Paused / needs attention', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Cancelled => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Completed => __( 'Completed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::CompletedWithErrors => __( 'Completed with errors', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::Failed => __( 'Failed', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RollbackQueued => __( 'Queued', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RollingBack => __( 'Processing', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::RolledBack => __( 'Rolled back', 'cetech-woocommerce-delivery-engine' ),
			BulkJobStatus::PartiallyRolledBack => __( 'Partially rolled back', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function public_status_label( BulkJob $job ): string {
		$state = BulkJobRunnerState::from_job( $job );

		return match ( $state->phase ) {
			BulkJobRunnerState::PHASE_QUEUED => __( 'Queued', 'cetech-woocommerce-delivery-engine' ),
			BulkJobRunnerState::PHASE_STARTING => __( 'Starting background work', 'cetech-woocommerce-delivery-engine' ),
			BulkJobRunnerState::PHASE_PROCESSING => __( 'Processing', 'cetech-woocommerce-delivery-engine' ),
			BulkJobRunnerState::PHASE_WAITING => __( 'Waiting for the site\'s background runner', 'cetech-woocommerce-delivery-engine' ),
			BulkJobRunnerState::PHASE_PAUSED => __( 'Paused / needs attention', 'cetech-woocommerce-delivery-engine' ),
			BulkJobRunnerState::PHASE_COMPLETED => self::status_label( $job->status ),
			BulkJobRunnerState::PHASE_FAILED => self::status_label( $job->status ),
			BulkJobRunnerState::PHASE_READY => self::status_label( $job->status ),
			BulkJobRunnerState::PHASE_CANCELLED => self::status_label( $job->status ),
			default => self::status_label( $job->status ),
		};
	}

	public static function waiting_notice(): string {
		return __( 'This job is waiting for WordPress background processing. You can safely leave this page.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function resume_label(): string {
		return __( 'Process next batch', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function progress_payload( BulkJob $job ): array {
		$state = BulkJobRunnerState::from_job( $job );

		return [
			'code'                => $job->job_code,
			'status'              => $job->status->value,
			'status_label'        => self::public_status_label( $job ),
			'total'               => $job->total_count,
			'processed'           => $job->processed_count,
			'changed'             => $job->changed_count,
			'skipped'             => $job->skipped_count,
			'failed'              => $job->failed_count,
			'show_cancel'         => self::shows_cancel_remaining( $job->status ),
			'allows_apply'        => $job->status->allows_apply(),
			'dry_run'             => $job->dry_run,
			'terminal'            => $job->status->is_terminal() || $job->status->allows_apply(),
			'waiting_for_runner'  => $state->waiting_for_runner,
			'can_resume'          => $state->can_resume,
			'waiting_notice'      => $state->waiting_for_runner ? self::waiting_notice() : '',
			'resume_label'        => self::resume_label(),
			'stale'               => $state->stale,
		];
	}

	public static function item_status_label( BulkJobItemStatus $status, bool $dry_run, bool $rollback = false ): string {
		if ( $rollback ) {
			return match ( $status ) {
				BulkJobItemStatus::RolledBack => __( 'Restored', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::RollbackSkipped => __( 'Skipped / conflict', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::RollbackFailed => __( 'Failed', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Pending => __( 'Waiting', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Claimed => __( 'In progress', 'cetech-woocommerce-delivery-engine' ),
				BulkJobItemStatus::Cancelled => __( 'Cancelled', 'cetech-woocommerce-delivery-engine' ),
				default => self::item_status_label( $status, false ),
			};
		}

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

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public static function counter_labels_for_job( BulkJob $job ): array {
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			return [
				[ 'key' => 'total', 'label' => __( 'Total', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'restored', 'label' => __( 'Restored', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'skipped', 'label' => __( 'Skipped / conflict', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'failed', 'label' => __( 'Failed', 'cetech-woocommerce-delivery-engine' ) ],
				[ 'key' => 'warnings', 'label' => __( 'Warnings', 'cetech-woocommerce-delivery-engine' ) ],
			];
		}

		return self::counter_labels( $job->dry_run );
	}

	/**
	 * Generic job-row counters plus rollback-specific summary fields.
	 *
	 * Rollback: processed_count = restored + skipped + failed. changed_count stays 0.
	 *
	 * @return array<string, int>
	 */
	public static function counter_values( BulkJob $job ): array {
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			$restored = (int) ( $job->summary['rollback_restored'] ?? 0 );
			$skipped  = (int) ( $job->summary['rollback_skipped'] ?? $job->skipped_count );
			$failed   = (int) ( $job->summary['rollback_failed'] ?? $job->failed_count );

			return [
				'total'     => $job->total_count,
				'restored'  => $restored,
				'skipped'   => $skipped,
				'failed'    => $failed,
				'warnings'  => $job->warning_count,
			];
		}

		return [
			'total'    => $job->total_count,
			'changed'  => $job->changed_count,
			'skipped'  => $job->skipped_count,
			'failed'   => $job->failed_count,
			'warnings' => $job->warning_count,
		];
	}

	public static function job_result_heading( BulkJob $job ): string {
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			return __( 'Rollback result', 'cetech-woocommerce-delivery-engine' );
		}

		return $job->dry_run
			? __( 'Preview result', 'cetech-woocommerce-delivery-engine' )
			: __( 'Applied result', 'cetech-woocommerce-delivery-engine' );
	}

	public static function compare_after_heading( BulkJob $job ): string {
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			return $job->status->is_terminal()
				? __( 'Restored', 'cetech-woocommerce-delivery-engine' )
				: __( 'Will restore', 'cetech-woocommerce-delivery-engine' );
		}

		return $job->dry_run
			? __( 'Proposed', 'cetech-woocommerce-delivery-engine' )
			: __( 'Applied', 'cetech-woocommerce-delivery-engine' );
	}

	public static function copy_phase( BulkJob $job ): string {
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			return 'rollback';
		}

		return $job->dry_run ? 'preview' : 'applied';
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

	public static function rollback_notice( bool $completed ): string {
		return $completed
			? __( 'These counters are the rollback result. Restored items returned to their previous Delivery Engine settings.', 'cetech-woocommerce-delivery-engine' )
			: __( 'Rollback is queued. Settings have not been restored yet.', 'cetech-woocommerce-delivery-engine' );
	}

	public static function variation_policy_notice( BulkVariationPolicy $policy, string $phase = 'preview' ): string {
		if ( 'rollback' === $phase ) {
			return __( 'Existing variation overrides were preserved. The Product configuration was restored to its previous inheritance state. Inheriting Variations again resolve through the restored Product/Site-wide configuration.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( 'applied' === $phase ) {
			return match ( $policy ) {
				BulkVariationPolicy::PreserveOverrides => __( 'Existing variation overrides were preserved. Variations that inherit this Product now use the updated Product settings through inheritance. No Variation override rows were created unless a Variation was directly targeted.', 'cetech-woocommerce-delivery-engine' ),
				BulkVariationPolicy::ParentOnly => __( 'Only parent product settings were targeted. Variation-specific settings were not written by this job.', 'cetech-woocommerce-delivery-engine' ),
				BulkVariationPolicy::ResetVariationsToParent => __( 'After the parent change, variations were restored to inherit from the product. That is inheritance, not copying today’s product values into each variation.', 'cetech-woocommerce-delivery-engine' ),
				BulkVariationPolicy::ParentAndInheriting => __( 'The parent product and variations that inherit it were affected. Variations with their own override stayed unchanged unless they were direct targets.', 'cetech-woocommerce-delivery-engine' ),
				BulkVariationPolicy::SelectedVariations => __( 'Only the selected variations were targeted.', 'cetech-woocommerce-delivery-engine' ),
			};
		}

		return match ( $policy ) {
			BulkVariationPolicy::PreserveOverrides => __( 'Existing variation overrides will be preserved. Variations that inherit this product would use the proposed Product settings. This preview does not write variation rows.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ParentOnly => __( 'Only parent product settings are targeted. Variation-specific settings are not written by this job.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ResetVariationsToParent => __( 'After the parent change, variations would be restored to inherit from the product. That is inheritance, not copying today’s product values into each variation.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::ParentAndInheriting => __( 'The parent product and variations that inherit it would be affected. Variations with their own override stay unchanged unless they are direct targets.', 'cetech-woocommerce-delivery-engine' ),
			BulkVariationPolicy::SelectedVariations => __( 'Only the selected variations are targeted.', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public static function variation_inherit_count_note( int $inherit, int $override, string $phase = 'preview' ): string {
		$parts = [];
		if ( $inherit > 0 ) {
			$parts[] = match ( $phase ) {
				'applied' => sprintf(
					/* translators: %d: variation count */
					_n(
						'%d variation inherits this product and now uses the updated product settings through inheritance.',
						'%d variations inherit this product and now use the updated product settings through inheritance.',
						$inherit,
						'cetech-woocommerce-delivery-engine'
					),
					$inherit
				),
				'rollback' => sprintf(
					/* translators: %d: variation count */
					_n(
						'%d inheriting variation again resolves through the restored Product or Site-wide configuration.',
						'%d inheriting variations again resolve through the restored Product or Site-wide configuration.',
						$inherit,
						'cetech-woocommerce-delivery-engine'
					),
					$inherit
				),
				default => sprintf(
					/* translators: %d: variation count */
					_n(
						'%d variation inherits this product and would therefore be affected through inheritance.',
						'%d variations inherit this product and would therefore be affected through inheritance.',
						$inherit,
						'cetech-woocommerce-delivery-engine'
					),
					$inherit
				),
			};
		}
		if ( $override > 0 ) {
			$parts[] = match ( $phase ) {
				'applied', 'rollback' => sprintf(
					/* translators: %d: variation count */
					_n(
						'%d variation has its own override and remained unchanged.',
						'%d variations have their own override and remained unchanged.',
						$override,
						'cetech-woocommerce-delivery-engine'
					),
					$override
				),
				default => sprintf(
					/* translators: %d: variation count */
					_n(
						'%d variation has its own override and will remain unchanged.',
						'%d variations have their own override and will remain unchanged.',
						$override,
						'cetech-woocommerce-delivery-engine'
					),
					$override
				),
			};
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
