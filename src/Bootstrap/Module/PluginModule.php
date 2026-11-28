<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * One M3–M8 lane's wiring point (docs/m3-m8/LANE-PLAN.md §3, "Module seam").
 *
 * Plugin::boot() calls every module in PluginModules::all() order, so a lane never edits
 * Plugin.php. A module wires only its own lane's services, and its runtime stays behind its
 * own feature switch.
 */
interface PluginModule {

	/** Stable machine id, for example `promise_dates`. */
	public function id(): string;

	/**
	 * Bind services. Called on every request after the core services are registered and before
	 * `cetech_de_services_registered`, whether or not the module's switch is on.
	 */
	public function register( ServiceContainer $container ): void;

	/**
	 * Add hooks. Called once WooCommerce is active, after the core runtime hooks are registered.
	 * A module whose switch is off must add no customer-visible behaviour here.
	 */
	public function boot( ServiceContainer $container ): void;
}
