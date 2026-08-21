<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkRecipe;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;

/**
 * In-memory bulk job store for unit tests. Mirrors claim/idempotent semantics of the WPDB store.
 */
final class InMemoryBulkJobRepository implements BulkJobRepositoryInterface {

	/** @var array<int, BulkJob> */
	private array $jobs = [];

	/** @var array<int, BulkJobItem> */
	private array $items = [];

	/** @var array<int, BulkRecipe> */
	private array $recipes = [];

	private int $next_job_id = 1;

	private int $next_item_id = 1;

	private int $next_recipe_id = 1;

	public function save_job( BulkJob $job ): BulkJob {
		if ( null === $job->id ) {
			$id      = $this->next_job_id++;
			$saved   = $job->with_id( $id, BulkJob::display_code_for_id( $id ) );
			$this->jobs[ $id ] = $saved;

			return $saved;
		}

		$this->jobs[ $job->id ] = $job;

		return $job;
	}

	public function find_job( int $id ): ?BulkJob {
		return $this->jobs[ $id ] ?? null;
	}

	public function find_job_by_code( string $job_code ): ?BulkJob {
		foreach ( $this->jobs as $job ) {
			if ( $job->job_code === $job_code ) {
				return $job;
			}
		}

		return null;
	}

	public function find_job_by_uuid( string $job_uuid ): ?BulkJob {
		foreach ( $this->jobs as $job ) {
			if ( $job->job_uuid === $job_uuid ) {
				return $job;
			}
		}

		return null;
	}

	public function list_jobs( int $limit = 50, int $after_id = 0, ?BulkJobStatus $status = null ): array {
		$matches = [];
		foreach ( $this->jobs as $job ) {
			if ( (int) $job->id <= $after_id ) {
				continue;
			}
			if ( null !== $status && $job->status !== $status ) {
				continue;
			}
			$matches[] = $job;
		}

		usort(
			$matches,
			static fn ( BulkJob $a, BulkJob $b ): int => ( (int) $b->id ) <=> ( (int) $a->id )
		);

		return array_slice( $matches, 0, max( 1, $limit ) );
	}

	public function count_jobs( ?BulkJobStatus $status = null ): int {
		$count = 0;
		foreach ( $this->jobs as $job ) {
			if ( null !== $status && $job->status !== $status ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	public function list_jobs_page( int $page, int $per_page, ?BulkJobStatus $status = null ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$matches  = [];
		foreach ( $this->jobs as $job ) {
			if ( null !== $status && $job->status !== $status ) {
				continue;
			}
			$matches[] = $job;
		}
		usort(
			$matches,
			static fn ( BulkJob $a, BulkJob $b ): int => ( (int) $b->id ) <=> ( (int) $a->id )
		);
		$offset = ( $page - 1 ) * $per_page;

		return array_slice( $matches, $offset, $per_page );
	}

	public function insert_items( array $items ): array {
		$saved = [];
		foreach ( $items as $item ) {
			if ( ! $item instanceof BulkJobItem ) {
				continue;
			}
			$existing = $this->find_item( $item->job_id, $item->target_type, $item->target_id, $item->external_key );
			if ( null !== $existing ) {
				$saved[] = $existing;
				continue;
			}
			$id    = $this->next_item_id++;
			$row   = $item->with( [ 'id' => $id ] );
			$this->items[ $id ] = $row;
			$saved[]            = $row;
		}

		return $saved;
	}

	public function claim_items( int $job_id, int $limit, string $claim_token, int $claim_ttl_seconds = 300 ): array {
		$now     = time();
		$claimed = [];
		foreach ( $this->items as $id => $item ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			if ( $item->job_id !== $job_id ) {
				continue;
			}
			$expired = BulkJobItemStatus::Claimed === $item->status
				&& null !== $item->claimed_at
				&& strtotime( $item->claimed_at ) < ( $now - $claim_ttl_seconds );
			if ( BulkJobItemStatus::Pending !== $item->status && ! $expired ) {
				continue;
			}
			$row = $item->with(
				[
					'status'        => BulkJobItemStatus::Claimed,
					'claim_token'   => $claim_token,
					'claimed_at'    => gmdate( 'Y-m-d H:i:s' ),
					'attempt_count' => $item->attempt_count + 1,
				]
			);
			$this->items[ $id ] = $row;
			$claimed[]          = $row;
		}

		return $claimed;
	}

	public function save_item( BulkJobItem $item ): BulkJobItem {
		if ( null === $item->id ) {
			return $this->insert_items( [ $item ] )[0];
		}
		$this->items[ $item->id ] = $item;

		return $item;
	}

	public function list_items( int $job_id, int $limit = 50, int $after_id = 0, ?BulkJobItemStatus $status = null ): array {
		$matches = [];
		foreach ( $this->items as $item ) {
			if ( $item->job_id !== $job_id || (int) $item->id <= $after_id ) {
				continue;
			}
			if ( null !== $status && $item->status !== $status ) {
				continue;
			}
			$matches[] = $item;
		}

		usort(
			$matches,
			static fn ( BulkJobItem $a, BulkJobItem $b ): int => ( (int) $a->id ) <=> ( (int) $b->id )
		);

		return array_slice( $matches, 0, max( 1, $limit ) );
	}

	public function list_items_page( int $job_id, int $page, int $per_page, ?BulkJobItemStatus $status = null ): array {
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$matches  = [];
		foreach ( $this->items as $item ) {
			if ( $item->job_id !== $job_id ) {
				continue;
			}
			if ( null !== $status && $item->status !== $status ) {
				continue;
			}
			$matches[] = $item;
		}
		usort(
			$matches,
			static fn ( BulkJobItem $a, BulkJobItem $b ): int => ( (int) $a->id ) <=> ( (int) $b->id )
		);
		$offset = ( $page - 1 ) * $per_page;

		return array_slice( $matches, $offset, $per_page );
	}

	public function count_items( int $job_id, ?BulkJobItemStatus $status = null ): int {
		$count = 0;
		foreach ( $this->items as $item ) {
			if ( $item->job_id !== $job_id ) {
				continue;
			}
			if ( null !== $status && $item->status !== $status ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	public function find_item( int $job_id, string $target_type, int $target_id, string $external_key = '' ): ?BulkJobItem {
		foreach ( $this->items as $item ) {
			if (
				$item->job_id === $job_id
				&& $item->target_type === $target_type
				&& $item->target_id === $target_id
				&& $item->external_key === $external_key
			) {
				return $item;
			}
		}

		return null;
	}

	public function reset_item_statuses( int $job_id, array $from_statuses, BulkJobItemStatus $to ): int {
		$updated = 0;
		foreach ( $this->items as $id => $item ) {
			if ( $item->job_id !== $job_id ) {
				continue;
			}
			if ( ! in_array( $item->status, $from_statuses, true ) ) {
				continue;
			}
			$this->items[ $id ] = $item->with(
				[
					'status'      => $to,
					'claim_token' => null,
					'claimed_at'  => null,
				]
			);
			++$updated;
		}

		return $updated;
	}

	public function claim_job( int $job_id, string $claim_token, int $claim_ttl_seconds = 300 ): ?BulkJob {
		$job = $this->jobs[ $job_id ] ?? null;
		if ( ! $job instanceof BulkJob ) {
			return null;
		}

		$now     = time();
		$expired = null !== $job->claimed_at && strtotime( $job->claimed_at ) < ( $now - $claim_ttl_seconds );
		if ( null !== $job->claim_token && $job->claim_token !== $claim_token && ! $expired ) {
			return null;
		}

		$claimed = $job->with_claim( $claim_token, gmdate( 'Y-m-d H:i:s' ) );
		$this->jobs[ $job_id ] = $claimed;

		return $claimed;
	}

	public function release_job_claim( int $job_id, string $claim_token ): void {
		$job = $this->jobs[ $job_id ] ?? null;
		if ( ! $job instanceof BulkJob || $job->claim_token !== $claim_token ) {
			return;
		}
		$this->jobs[ $job_id ] = $job->with_claim( null, null );
	}

	public function save_recipe( BulkRecipe $recipe ): BulkRecipe {
		if ( null === $recipe->id ) {
			$id     = $this->next_recipe_id++;
			$saved  = $recipe->with_id( $id );
			$this->recipes[ $id ] = $saved;

			return $saved;
		}
		$this->recipes[ $recipe->id ] = $recipe;

		return $recipe;
	}

	public function find_recipe( int $id ): ?BulkRecipe {
		return $this->recipes[ $id ] ?? null;
	}

	public function list_recipes( int $limit = 50 ): array {
		return array_slice( array_values( $this->recipes ), 0, max( 1, $limit ) );
	}

	/**
	 * Test helper: how many item rows exist across all jobs.
	 */
	public function item_storage_count(): int {
		return count( $this->items );
	}
}
