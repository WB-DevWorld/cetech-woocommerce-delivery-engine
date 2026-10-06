<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleClock;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Deterministic disposable server clock; no product runtime setting is changed. */
final class RuleProofClock implements RuleClock {
	public function __construct( public RuleTime $instant ) {}
	public function now(): RuleTime { return $this->instant; }
	public function set( string $utc ): void { $this->instant = RuleTime::parse( $utc ); }
}
