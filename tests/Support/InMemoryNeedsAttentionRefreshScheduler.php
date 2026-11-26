<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionRefreshSchedulerInterface;

final class InMemoryNeedsAttentionRefreshScheduler implements NeedsAttentionRefreshSchedulerInterface {

	/** @var list<int> */
	public array $scheduled = [];

	public int $clears = 0;

	public function is_scheduled(): bool {
		return [] !== $this->scheduled;
	}

	public function schedule( int $timestamp ): void {
		if ( $this->is_scheduled() ) {
			return;
		}

		$this->scheduled[] = $timestamp;
	}

	/**
	 * Simulate WP-Cron taking the event off the queue before running it.
	 */
	public function take(): ?int {
		return array_shift( $this->scheduled );
	}

	public function clear(): void {
		$this->scheduled = [];
		++$this->clears;
	}
}
