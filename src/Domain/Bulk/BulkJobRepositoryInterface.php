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

	public function count_jobs( ?BulkJobStatus $status = null ): int;

	/**
	 * Offset pagination for wp-admin. Per-page is clamped to 1–100.
	 *
	 * @return list<BulkJob>
	 */
	public function list_jobs_page( int $page, int $per_page, ?BulkJobStatus $status = null ): array;

	/**
	 * @param list<BulkJobItem> $items
	 * @return list<BulkJobItem>
	 */
	public function insert_items( array $items ): array;

	/**
	 * @return list<BulkJobItem>
	 */
	public function claim_items( int $job_id, int $limit, string $claim_token, int $claim_ttl_seconds = 300 ): array;

	/**
	 * Run a source write only while this token still owns the claimed item.
	 * The lock covers the callback and is released before the method returns.
	 */
	public function call_while_item_claimed( int $item_id, string $token, callable $callback ): mixed;

	public function save_item( BulkJobItem $item, ?bool &$applied = null ): BulkJobItem;

	/**
	 * @return list<BulkJobItem>
	 */
	public function list_items( int $job_id, int $limit = 50, int $after_id = 0, ?BulkJobItemStatus $status = null ): array;

	/**
	 * Offset pagination for wp-admin job items. Per-page is clamped to 1–100.
	 *
	 * @return list<BulkJobItem>
	 */
	public function list_items_page( int $job_id, int $page, int $per_page, ?BulkJobItemStatus $status = null ): array;

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

	public function release_job_claim( int $job_id, string $claim_token ): bool;

	public function save_recipe( BulkRecipe $recipe ): BulkRecipe;

	public function find_recipe( int $id ): ?BulkRecipe;

	/**
	 * @return list<BulkRecipe>
	 */
	public function list_recipes( int $limit = 50 ): array;

	/**
	 * Run one preparation checkpoint. A thrown exception restores the previous job and items.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function completeOwnedUnit( callable $work ): mixed;
}
