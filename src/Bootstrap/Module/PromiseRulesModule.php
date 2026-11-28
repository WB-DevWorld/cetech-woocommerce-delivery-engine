<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Application\PromiseDates\NullPromiseDateProvider;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDateProvider;
use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * Owned by lane L2 (promise rules, engine and order freeze); see docs/m3-m8/LANE-PLAN.md.
 * Customer-visible runtime stays behind the `enable_promised_dates` switch (default off).
 */
final class PromiseRulesModule implements PluginModule {

	public const SWITCH = 'enable_promised_dates';

	public function id(): string {
		return 'promise_rules';
	}

	public function register( ServiceContainer $container ): void {
		// Until L2 wires the stored rules, no rule applies and every surface keeps the M2 duration.
		$container->singleton( PromiseDateProvider::class, static fn (): PromiseDateProvider => new NullPromiseDateProvider() );
	}

	public function boot( ServiceContainer $container ): void {
	}
}
