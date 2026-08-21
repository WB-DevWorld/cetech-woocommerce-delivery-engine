<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * Test/queue double: records ticks and never processes the full job in one enqueue.
 */
final class InMemoryBoundedQueue implements BackgroundQueueInterface {

	/** @var list<int> */
	private array $queued = [];

	/** @var array<int, int> */
	public array $enqueue_counts = [];

	public bool $available = true;

	public function is_available(): bool {
		return $this->available;
	}

	public function enqueue_job_tick( int $job_id, int $delay_seconds = 0 ): bool {
		if ( ! $this->available ) {
			return false;
		}
		$this->queued[] = $job_id;
		$this->enqueue_counts[ $job_id ] = ( $this->enqueue_counts[ $job_id ] ?? 0 ) + 1;

		return true;
	}

	public function cancel_job_ticks( int $job_id ): void {
		$this->queued = array_values(
			array_filter( $this->queued, static fn ( int $queued ): bool => $queued !== $job_id )
		);
	}

	public function unavailable_reason(): string {
		return $this->available ? '' : 'background_queue_unavailable';
	}

	public function next_job_id(): ?int {
		if ( [] === $this->queued ) {
			return null;
		}

		return array_shift( $this->queued );
	}

	public function queued_count(): int {
		return count( $this->queued );
	}
}
