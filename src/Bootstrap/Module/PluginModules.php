<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

use CetechDeliveryEngine\Bootstrap\ServiceContainer;

/**
 * The fixed, lead-owned list of lane modules. Order matters: providers register before the
 * modules that consume them (promise rules before promise dates).
 */
final class PluginModules {

	/**
	 * @return list<PluginModule>
	 */
	public static function all(): array {
		return [
			new PromiseRulesModule(),
			new PromiseDatesModule(),
			new EmergencyPauseModule(),
			new EmbedsModule(),
			new RestApiModule(),
		];
	}

	/**
	 * @param list<PluginModule> $modules
	 */
	public static function register_all( ServiceContainer $container, array $modules ): void {
		self::assert_unique( $modules );
		foreach ( $modules as $module ) {
			$module->register( $container );
		}
	}

	/**
	 * @param list<PluginModule> $modules
	 */
	public static function boot_all( ServiceContainer $container, array $modules ): void {
		self::assert_unique( $modules );
		foreach ( $modules as $module ) {
			$module->boot( $container );
		}
	}

	/**
	 * @param list<PluginModule> $modules
	 */
	private static function assert_unique( array $modules ): void {
		$ids = [];
		foreach ( $modules as $module ) {
			$id = $module->id();
			if ( 1 !== preg_match( '/\A[a-z][a-z0-9_]{1,47}\z/', $id ) || isset( $ids[ $id ] ) ) {
				throw new \LogicException( 'Plugin module ids must be unique machine names.' );
			}
			$ids[ $id ] = true;
		}
	}
}
