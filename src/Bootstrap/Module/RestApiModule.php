<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * Owned by lane L7 (REST API, then CLI); see docs/m3-m8/LANE-PLAN.md.
 * REST routes stay behind the `enable_rest_api` switch (default off).
 */
final class RestApiModule implements PluginModule {

	public const SWITCH = 'enable_rest_api';

	public function id(): string {
		return 'rest_api';
	}

	public function register( ServiceContainer $container ): void {
	}

	public function boot( ServiceContainer $container ): void {
	}
}
