<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkRecipe;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;

/**
 * Application facade for bulk jobs. Creating a job never processes the catalog
 * in the initiating request.
 */
final class BulkJobEngine {

	public function __construct(
		private readonly BulkJobRepositoryInterface $jobs,
		private readonly BackgroundQueueInterface $queue,
		private readonly BulkJobWorker $worker
	) {
	}

	/**
	 * @param array<string, mixed> $target_definition
	 * @param array<string, mixed> $action_manifest
	 */
	public function create_preview(
		BulkOperationType $operation_type,
		int $actor_user_id,
		array $target_definition,
		array $action_manifest,
		int $batch_size = BulkJob::DEFAULT_BATCH_SIZE
	): BulkJob {
		$definition = CatalogTargetDefinition::from_array( $target_definition );
		if ( $definition->requires_entire_catalog_confirmation() ) {
			throw new \InvalidArgumentException( 'Entire catalog operations require explicit confirmation.' );
		}

		if ( BulkTargetScope::EntireCatalog === $definition->scope && ! $definition->entire_catalog_confirmed ) {
			throw new \InvalidArgumentException( 'Entire catalog operations require explicit confirmation.' );
		}

		$manifest = CatalogActionManifest::from_array( $action_manifest );
		if ( BulkOperationType::CatalogUpdate === $operation_type && ! $manifest->has_work() ) {
			throw new \InvalidArgumentException( 'Choose at least one Delivery Engine action.' );
		}

		$job = BulkJob::create(
			$operation_type,
			$actor_user_id,
			$definition->to_array(),
			BulkOperationType::CatalogUpdate === $operation_type ? $manifest->to_array() : $action_manifest,
			true,
			$batch_size
		);
		$job = $job->with_status( BulkJobStatus::Previewing );
		$job = $this->jobs->save_job( $job );

		if ( ! $this->queue->is_available() ) {
			$job = $job->with_status( BulkJobStatus::Failed )->with_error(
				'background_queue_unavailable',
				'Background processing is not available. The catalog was not updated.'
			);

			return $this->jobs->save_job( $job );
		}

		$this->queue->enqueue_job_tick( (int) $job->id );

		return $job;
	}

	public function apply( int $job_id, int $actor_user_id ): BulkJob {
		$job = $this->require_job( $job_id );
		if ( ! $job->status->allows_apply() ) {
			throw new \InvalidArgumentException( 'This job is not ready to apply.' );
		}
		if ( ! $this->queue->is_available() ) {
			return $this->jobs->save_job(
				$job->with_status( BulkJobStatus::Failed )->with_error(
					'background_queue_unavailable',
					'Background processing is not available. The catalog was not updated.'
				)
			);
		}

		$this->jobs->reset_item_statuses(
			$job_id,
			[ BulkJobItemStatus::Changed, BulkJobItemStatus::Unchanged, BulkJobItemStatus::Skipped, BulkJobItemStatus::Failed ],
			BulkJobItemStatus::Pending
		);

		$job = $job->with(
			[
				'dry_run'          => false,
				'processed_count'  => 0,
				'changed_count'    => 0,
				'skipped_count'    => 0,
				'failed_count'     => 0,
				'warning_count'    => 0,
				'status'           => BulkJobStatus::Queued,
				'completed_at'     => null,
				'error_code'       => null,
				'error_summary'    => null,
			]
		);
		$job = $this->jobs->save_job( $job );
		$this->queue->enqueue_job_tick( $job_id );

		return $job;
	}

	public function cancel( int $job_id ): BulkJob {
		$job = $this->require_job( $job_id );
		if ( ! $job->status->allows_cancel() ) {
			return $job;
		}
		$job = $job->request_cancel();
		$job = $this->jobs->save_job( $job );
		$this->queue->enqueue_job_tick( $job_id );

		return $job;
	}

	public function rollback( int $job_id, int $actor_user_id ): BulkJob {
		$job = $this->require_job( $job_id );
		if ( ! $job->status->allows_rollback() ) {
			throw new \InvalidArgumentException( 'This job cannot be rolled back.' );
		}
		if ( ! $this->queue->is_available() ) {
			throw new \RuntimeException( 'Background processing is not available.' );
		}

		$child = BulkJob::create(
			BulkOperationType::Rollback,
			$actor_user_id,
			$job->target_definition,
			$job->action_manifest,
			false,
			$job->batch_size,
			$job->id
		);
		$child = $child->with_status( BulkJobStatus::RollbackQueued );
		$child = $this->jobs->save_job( $child );

		$after_id = 0;
		do {
			$changed = $this->jobs->list_items( $job_id, 100, $after_id, BulkJobItemStatus::Changed );
			$copies  = [];
			foreach ( $changed as $item ) {
				$copies[] = BulkJobItem::pending(
					(int) $child->id,
					$item->target_type,
					$item->target_id,
					$item->external_key,
					$item->parent_target_id
				)->with(
					[
						'before_snapshot'          => $item->before_snapshot,
						'after_fingerprint'        => $item->after_fingerprint,
						'precondition_fingerprint' => $item->after_fingerprint,
					]
				);
				$after_id = (int) $item->id;
			}
			if ( [] !== $copies ) {
				$this->jobs->insert_items( $copies );
			}
		} while ( count( $changed ) === 100 );

		$child = $child->with_progress(
			$this->jobs->count_items( (int) $child->id ),
			$this->jobs->count_items( (int) $child->id ),
			0,
			0,
			0,
			0,
			0,
			true,
			'0',
			[ 'source_job_code' => $job->job_code ]
		);
		$child = $this->jobs->save_job( $child );
		$this->queue->enqueue_job_tick( (int) $child->id );

		return $child;
	}

	public function run_next_tick( ?int $job_id = null ): void {
		if ( null !== $job_id ) {
			$this->worker->tick( $job_id );
		}
	}

	public function save_recipe( string $name, int $owner_user_id, array $target_definition, array $action_manifest ): BulkRecipe {
		return $this->jobs->save_recipe(
			BulkRecipe::create( $name, $owner_user_id, $target_definition, $action_manifest )
		);
	}

	public function find( int $job_id ): ?BulkJob {
		return $this->jobs->find_job( $job_id );
	}

	public function find_by_code( string $job_code ): ?BulkJob {
		return $this->jobs->find_job_by_code( $job_code );
	}

	private function require_job( int $job_id ): BulkJob {
		$job = $this->jobs->find_job( $job_id );
		if ( ! $job instanceof BulkJob ) {
			throw new \InvalidArgumentException( 'Unknown bulk job.' );
		}

		return $job;
	}
}
