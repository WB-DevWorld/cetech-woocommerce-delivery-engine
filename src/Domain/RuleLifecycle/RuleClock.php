<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Trusted UTC clock; an untrusted caller timestamp never supplies acceptance. */
interface RuleClock {
	public function now(): RuleTime;
}
