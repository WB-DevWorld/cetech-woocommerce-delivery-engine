<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * Administrative background queue. Never used as a checkout/storefront runner.
 */
interface BackgroundQueueInterface {

	public function is_available(): bool;

	public function enqueue_job_tick( int $job_id, int $delay_seconds = 0 ): bool;

	public function cancel_job_ticks( int $job_id ): void;

	public function unavailable_reason(): string;
}
