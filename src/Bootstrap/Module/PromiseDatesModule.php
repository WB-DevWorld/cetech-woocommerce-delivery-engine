<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Application\PromiseDates\PromiseDateRangeFormatter;
use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * Owned by lane L1 (promise dates on customer surfaces); see docs/m3-m8/LANE-PLAN.md.
 * Customer-visible runtime stays behind the `enable_promised_dates` switch (default off).
 */
final class PromiseDatesModule implements PluginModule {

	public const SWITCH = 'enable_promised_dates';

	public function id(): string {
		return 'promise_dates';
	}

	public function register( ServiceContainer $container ): void {
		$container->singleton( PromiseDateRangeFormatter::class, static fn (): PromiseDateRangeFormatter => new PromiseDateRangeFormatter() );
	}

	public function boot( ServiceContainer $container ): void {
	}
}
