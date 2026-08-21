<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetQueryInterface;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigImportConflictMode;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;

/**
 * Processes one bounded batch of a durable bulk job. Never walks the full catalog
 * in a single invocation.
 */
final class BulkJobWorker {
	use BulkJobWorkerDispatch;

	public const CLAIM_TTL = 300;

	public function __construct(
		private readonly BulkJobRepositoryInterface $jobs,
		private readonly CatalogTargetQueryInterface $targets,
		private readonly CatalogScopeMutator $mutator,
		private readonly BackgroundQueueInterface $queue,
		private readonly int $time_budget_seconds = 8,
		private readonly ?RateCardBulkMutator $rate_mutator = null,
		private readonly ?ConfigurationImporter $importer = null,
		private readonly ?CatalogCsvMapper $csv = null
	) {
	}

	public function tick( int $job_id ): void {
		$started = microtime( true );
		$token   = 'tick-' . BulkJob::new_uuid();
		$job     = $this->jobs->claim_job( $job_id, $token, self::CLAIM_TTL );

		if ( ! $job instanceof BulkJob ) {
			return;
		}

		try {
			if ( $job->cancel_requested || BulkJobStatus::CancelRequested === $job->status ) {
				$this->finalize_cancellation( $job );
				return;
			}

			if ( $job->status->is_terminal() ) {
				$this->queue->cancel_job_ticks( $job_id );
				return;
			}

			$job = $this->advance_status_to_running( $job );

			if ( BulkJobStatus::RollingBack === $job->status || BulkJobStatus::RollbackQueued === $job->status ) {
				$this->process_rollback_batch( $job, $token, $started );
				return;
			}

			if ( ! $job->enumeration_complete ) {
				$this->enumerate_batch( $job, $started );
				$job = $this->jobs->find_job( $job_id );
				if ( ! $job instanceof BulkJob ) {
					return;
				}
			}

			if ( $job->enumeration_complete ) {
				$this->process_item_batch( $job, $token, $started );
			}
		} finally {
			$fresh = $this->jobs->find_job( $job_id );
			if ( $fresh instanceof BulkJob && $fresh->claim_token === $token ) {
				$this->jobs->release_job_claim( $job_id, $token );
			}
		}
	}

	private function advance_status_to_running( BulkJob $job ): BulkJob {
		if ( in_array( $job->status, [ BulkJobStatus::Draft, BulkJobStatus::Previewing, BulkJobStatus::Queued, BulkJobStatus::Ready ], true ) && ! $job->dry_run && BulkJobStatus::Ready !== $job->status ) {
			// previewing stays previewing until dry-run items finish.
		}

		$next = $job->status;
		if ( BulkJobStatus::Draft === $job->status ) {
			$next = $job->dry_run ? BulkJobStatus::Previewing : BulkJobStatus::Running;
		} elseif ( BulkJobStatus::Queued === $job->status ) {
			$next = BulkJobStatus::Running;
		} elseif ( BulkJobStatus::RollbackQueued === $job->status ) {
			$next = BulkJobStatus::RollingBack;
		} elseif ( BulkJobStatus::Ready === $job->status && ! $job->dry_run ) {
			$next = BulkJobStatus::Running;
		} elseif ( BulkJobStatus::Previewing !== $job->status && $job->dry_run && ! $job->status->is_terminal() ) {
			$next = BulkJobStatus::Previewing;
		}

		if ( $next !== $job->status ) {
			$job = $job->with_status( $next );
			$job = $this->jobs->save_job( $job );
		}

		return $job;
	}

	private function enumerate_batch( BulkJob $job, float $started ): void {
		if ( BulkOperationType::CatalogCsvImport === $job->operation_type ) {
			$this->enumerate_csv( $job, $started );
			return;
		}
		if ( BulkOperationType::ConfigImport === $job->operation_type ) {
			$this->enumerate_config( $job, $started );
			return;
		}
		if ( BulkOperationType::RateCardUpdate === $job->operation_type ) {
			$this->enumerate_selected_ids( $job, $started, 'rate_card' );
			return;
		}

		$definition = CatalogTargetDefinition::from_array( $job->target_definition );
		$after_id   = (int) $job->checkpoint_cursor;
		$limit      = $job->batch_size;
		$page       = $this->targets->page_after( $definition, $after_id, $limit );

		$items = [];
		$last  = $after_id;
		foreach ( $page as $target ) {
			$items[] = BulkJobItem::pending(
				(int) $job->id,
				$target->type,
				$target->id,
				$target->external_key,
				$target->parent_id
			);
			$last = $target->id;
		}

		if ( [] !== $items ) {
			$this->jobs->insert_items( $items );
		}

		$enumerated = $job->enumerated_count + count( $items );
		$complete   = count( $page ) < $limit;
		$total      = $complete ? $enumerated : max( $job->total_count, $enumerated );

		if ( $complete && 0 === $job->total_count ) {
			$total = $enumerated;
		} elseif ( ! $complete && 0 === $job->total_count ) {
			$total = $this->safe_count( $definition, $enumerated );
		}

		$job = $job->with_progress(
			$total,
			$enumerated,
			$job->processed_count,
			$job->changed_count,
			$job->skipped_count,
			$job->failed_count,
			$job->warning_count,
			$complete,
			(string) $last,
			$job->summary
		);
		$this->jobs->save_job( $job );
		$this->requeue_if_needed( $job, $started );
	}

	private function safe_count( CatalogTargetDefinition $definition, int $fallback ): int {
		try {
			return max( $fallback, $this->targets->count( $definition ) );
		} catch ( \Throwable ) {
			return $fallback;
		}
	}

	private function process_item_batch( BulkJob $job, string $token, float $started ): void {
		$items = $this->jobs->claim_items( (int) $job->id, $job->batch_size, $token, self::CLAIM_TTL );
		if ( [] === $items ) {
			$this->finalize_if_idle( $job );
			return;
		}

		$manifest   = CatalogActionManifest::from_array( $job->action_manifest );
		$definition = CatalogTargetDefinition::from_array( $job->target_definition );
		$changed    = 0;
		$skipped    = 0;
		$failed     = 0;
		$warnings   = 0;
		$examples   = is_array( $job->summary['examples'] ?? null ) ? $job->summary['examples'] : [];

		foreach ( $items as $item ) {
			if ( $this->over_budget( $started ) ) {
				$this->jobs->save_item(
					$item->with(
						[
							'status'      => BulkJobItemStatus::Pending,
							'claim_token' => null,
							'claimed_at'  => null,
						]
					)
				);
				break;
			}

			$parent = $item->parent_target_id;
			if ( CatalogTargetDefinition::TARGET_VARIATION === $item->target_type && null === $parent ) {
				$parent = $this->targets->parent_product_id( $item->target_id );
			}

			if (
				CatalogTargetDefinition::TARGET_PRODUCT === $item->target_type
				&& BulkVariationPolicy::ParentOnly === $definition->variation_policy
			) {
				// Parent-only is the default product mutation; variations are separate targets.
			}

			$result = $this->process_claimed_item( $job, $item, $manifest, $definition );

			$status = match ( $result['outcome'] ) {
				'changed'   => BulkJobItemStatus::Changed,
				'unchanged' => BulkJobItemStatus::Unchanged,
				'skipped'   => BulkJobItemStatus::Skipped,
				default     => BulkJobItemStatus::Failed,
			};

			if ( 'failed' === $result['outcome'] && ! empty( $result['warning'] ) ) {
				$status = BulkJobItemStatus::Skipped;
				++$warnings;
				++$skipped;
			} elseif ( BulkJobItemStatus::Changed === $status ) {
				++$changed;
			} elseif ( BulkJobItemStatus::Unchanged === $status || BulkJobItemStatus::Skipped === $status ) {
				++$skipped;
			} else {
				++$failed;
			}

			$saved_item = $item->with(
				[
					'status'                   => $status,
					'precondition_fingerprint' => (string) $result['precondition_fingerprint'],
					'after_fingerprint'        => (string) $result['after_fingerprint'],
					'before_snapshot'          => is_array( $result['before_snapshot'] ) ? $result['before_snapshot'] : [],
					'result'                   => array_merge(
						is_array( $item->result ) ? $item->result : [],
						is_array( $result['result'] ) ? $result['result'] : []
					),
					'error_code'               => $result['error_code'],
					'error_summary'            => $result['error_summary'],
					'completed_at'             => gmdate( 'Y-m-d H:i:s' ),
					'claim_token'              => null,
					'claimed_at'               => null,
				]
			);
			$this->jobs->save_item( $saved_item );

			if ( count( $examples ) < 8 ) {
				$examples[] = [
					'target_type' => $item->target_type,
					'target_id'   => $item->target_id,
					'external_key'=> $item->external_key,
					'outcome'     => $result['outcome'],
					'error_code'  => $result['error_code'],
				];
			}
		}

		$fresh = $this->jobs->find_job( (int) $job->id );
		if ( ! $fresh instanceof BulkJob ) {
			return;
		}

		$processed = $fresh->processed_count + $changed + $skipped + $failed;
		$summary   = $fresh->summary;
		$summary['examples'] = $examples;

		$fresh = $fresh->with_progress(
			$fresh->total_count,
			$fresh->enumerated_count,
			$processed,
			$fresh->changed_count + $changed,
			$fresh->skipped_count + $skipped,
			$fresh->failed_count + $failed,
			$fresh->warning_count + $warnings,
			true,
			$fresh->checkpoint_cursor,
			$summary
		);
		$this->jobs->save_job( $fresh );
		$this->finalize_if_idle( $fresh ) || $this->requeue_if_needed( $fresh, $started );
	}

	private function process_rollback_batch( BulkJob $job, string $token, float $started ): void {
		$items = $this->jobs->claim_items( (int) $job->id, $job->batch_size, $token, self::CLAIM_TTL );
		if ( [] === $items ) {
			$pending = $this->jobs->count_items( (int) $job->id, BulkJobItemStatus::Pending )
				+ $this->jobs->count_items( (int) $job->id, BulkJobItemStatus::Claimed );
			if ( $pending > 0 ) {
				$this->requeue_if_needed( $job, $started );
				return;
			}
			$this->finalize_rollback( $job );
			return;
		}

		$rolled = 0;
		$skipped = 0;
		$failed = 0;
		foreach ( $items as $item ) {
			if ( $this->over_budget( $started ) ) {
				$this->jobs->save_item( $item->with( [ 'status' => BulkJobItemStatus::Pending, 'claim_token' => null, 'claimed_at' => null ] ) );
				break;
			}
			$parent = $item->parent_target_id;
			$result = $this->mutator->rollback(
				$item->target_type,
				$item->target_id,
				$parent,
				$item->before_snapshot,
				$item->after_fingerprint
			);
			$status = match ( $result['outcome'] ) {
				'rolled_back'      => BulkJobItemStatus::RolledBack,
				'rollback_skipped' => BulkJobItemStatus::RollbackSkipped,
				default            => BulkJobItemStatus::RollbackFailed,
			};
			if ( BulkJobItemStatus::RolledBack === $status ) {
				++$rolled;
			} elseif ( BulkJobItemStatus::RollbackSkipped === $status ) {
				++$skipped;
			} else {
				++$failed;
			}
			$this->jobs->save_item(
				$item->with(
					[
						'status'        => $status,
						'error_code'    => $result['error_code'],
						'error_summary' => $result['error_summary'],
						'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
						'claim_token'   => null,
						'claimed_at'    => null,
					]
				)
			);
		}

		$fresh = $this->jobs->find_job( (int) $job->id );
		if ( $fresh instanceof BulkJob ) {
			$summary = $fresh->summary;
			$summary['rollback_restored'] = (int) ( $summary['rollback_restored'] ?? 0 ) + $rolled;
			$summary['rollback_skipped']  = (int) ( $summary['rollback_skipped'] ?? 0 ) + $skipped;
			$summary['rollback_failed']   = (int) ( $summary['rollback_failed'] ?? 0 ) + $failed;
			$fresh = $fresh->with( [ 'summary' => $summary ] );
			$this->jobs->save_job( $fresh );
			$this->requeue_if_needed( $fresh, $started );
		}
	}

	private function finalize_if_idle( BulkJob $job ): bool {
		$pending = $this->jobs->count_items( (int) $job->id, BulkJobItemStatus::Pending );
		$claimed = $this->jobs->count_items( (int) $job->id, BulkJobItemStatus::Claimed );
		if ( $pending > 0 || $claimed > 0 || ! $job->enumeration_complete ) {
			return false;
		}

		$failed = $job->failed_count;
		if ( $job->dry_run ) {
			$job = $job->with_status( BulkJobStatus::Ready )->with( [ 'dry_run' => true ] );
		} else {
			$job = $job->with_status( $failed > 0 ? BulkJobStatus::CompletedWithErrors : BulkJobStatus::Completed );
		}
		if ( BulkOperationType::ConfigImport === $job->operation_type && $this->importer instanceof ConfigurationImporter ) {
			$summary                       = $job->summary;
			$summary['post_import_health'] = $this->importer->health_report();
			$job                           = $job->with( [ 'summary' => $summary ] );
		}

		$this->jobs->save_job( $job );
		$this->queue->cancel_job_ticks( (int) $job->id );

		return true;
	}

	private function finalize_cancellation( BulkJob $job ): void {
		do {
			$pending = $this->jobs->list_items( (int) $job->id, 200, 0, BulkJobItemStatus::Pending );
			foreach ( $pending as $item ) {
				$this->jobs->save_item( $item->with( [ 'status' => BulkJobItemStatus::Cancelled ] ) );
			}
		} while ( [] !== $pending );

		$job = $job->with_status( BulkJobStatus::Cancelled );
		$this->jobs->save_job( $job );
		$this->queue->cancel_job_ticks( (int) $job->id );
	}

	private function finalize_rollback( BulkJob $job ): void {
		$summary = $job->summary;
		$skipped = (int) ( $summary['rollback_skipped'] ?? 0 );
		$failed  = (int) ( $summary['rollback_failed'] ?? 0 );
		$status  = ( $skipped > 0 || $failed > 0 ) ? BulkJobStatus::PartiallyRolledBack : BulkJobStatus::RolledBack;
		$this->jobs->save_job( $job->with_status( $status ) );
		$this->queue->cancel_job_ticks( (int) $job->id );
	}

	private function requeue_if_needed( BulkJob $job, float $started ): void {
		if ( $job->status->is_terminal() ) {
			return;
		}
		$this->queue->enqueue_job_tick( (int) $job->id, $this->over_budget( $started ) ? 1 : 0 );
	}

	private function over_budget( float $started ): bool {
		return ( microtime( true ) - $started ) >= $this->time_budget_seconds;
	}
}
