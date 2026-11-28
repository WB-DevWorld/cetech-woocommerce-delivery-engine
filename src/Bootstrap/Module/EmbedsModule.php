<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * Owned by lane L5 (embeds, then mobile layouts); see docs/m3-m8/LANE-PLAN.md.
 * Shortcodes and blocks stay behind the `enable_delivery_embeds` switch (default off).
 */
final class EmbedsModule implements PluginModule {

	public const SWITCH = 'enable_delivery_embeds';

	public function id(): string {
		return 'delivery_embeds';
	}

	public function register( ServiceContainer $container ): void {
	}

	public function boot( ServiceContainer $container ): void {
	}
}
