<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\Operation;

/** Explicit profile opt-in: a recorded refusal is immutable and replays without new target work. */
interface OperationTerminalRejectionProfile {
	/** Pure policy for the already validated bound command; no authorization or external callbacks. */
	public function rejects_are_terminal(): bool;
}
