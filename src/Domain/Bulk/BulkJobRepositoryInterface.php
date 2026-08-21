<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Bulk;

use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;

interface BulkJobRepositoryInterface {

	public function save_job( BulkJob $job ): BulkJob;

	public function find_job( int $id ): ?BulkJob;

	public function find_job_by_code( string $job_code ): ?BulkJob;

	public function find_job_by_uuid( string $job_uuid ): ?BulkJob;

	/**
	 * @return list<BulkJob>
	 */
	public function list_jobs( int $limit = 50, int $after_id = 0, ?BulkJobStatus $status = null ): array;

	/**
	 * @param list<BulkJobItem> $items
	 * @return list<BulkJobItem>
	 */
	public function insert_items( array $items ): array;

	/**
	 * @return list<BulkJobItem>
	 */
	public function claim_items( int $job_id, int $limit, string $claim_token, int $claim_ttl_seconds = 300 ): array;

	public function save_item( BulkJobItem $item ): BulkJobItem;

	/**
	 * @return list<BulkJobItem>
	 */
	public function list_items( int $job_id, int $limit = 50, int $after_id = 0, ?BulkJobItemStatus $status = null ): array;

	public function count_items( int $job_id, ?BulkJobItemStatus $status = null ): int;

	public function find_item( int $job_id, string $target_type, int $target_id, string $external_key = '' ): ?BulkJobItem;

	/**
	 * @param list<BulkJobItemStatus> $from_statuses
	 */
	public function reset_item_statuses( int $job_id, array $from_statuses, BulkJobItemStatus $to ): int;

	/**
	 * Atomic compare-and-set for worker claiming a job.
	 */
	public function claim_job( int $job_id, string $claim_token, int $claim_ttl_seconds = 300 ): ?BulkJob;

	public function release_job_claim( int $job_id, string $claim_token ): void;

	public function save_recipe( BulkRecipe $recipe ): BulkRecipe;

	public function find_recipe( int $id ): ?BulkRecipe;

	/**
	 * @return list<BulkRecipe>
	 */
	public function list_recipes( int $limit = 50 ): array;
}
