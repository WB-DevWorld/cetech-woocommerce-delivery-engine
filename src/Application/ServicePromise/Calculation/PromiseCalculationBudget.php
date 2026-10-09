<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;

/** One non-renewing work account, shared by every group in the complete cart. */
final class PromiseCalculationBudget {
	private int $used = 0;
	private bool $exhausted = false;

	public function consume( int $steps = 1 ): void {
		if ( $steps < 1 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		if ( $this->exhausted || $steps > PromiseLimits::CART_STEPS - $this->used ) { $this->exhausted = true; throw new PromiseCalculationException( 'budget_exceeded' ); }
		$this->used += $steps;
	}

	public function used_steps(): int { return $this->used; }
	/** A refused charge poisons the complete cart; an exact successful limit remains valid. */
	public function exhausted(): bool { return $this->exhausted; }
}
