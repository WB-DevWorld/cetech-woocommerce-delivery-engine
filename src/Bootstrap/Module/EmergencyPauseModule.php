<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * Owned by lane L3 (emergency pause); see docs/m3-m8/LANE-PLAN.md.
 * The pause control stays behind the `enable_emergency_pause_control` switch (default off).
 */
final class EmergencyPauseModule implements PluginModule {

	public const SWITCH = 'enable_emergency_pause_control';

	public function id(): string {
		return 'emergency_pause';
	}

	public function register( ServiceContainer $container ): void {
	}

	public function boot( ServiceContainer $container ): void {
	}
}
