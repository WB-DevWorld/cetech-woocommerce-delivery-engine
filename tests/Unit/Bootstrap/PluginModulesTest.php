<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bootstrap;

use CetechDeliveryEngine\Application\PromiseDates\FrozenOrderPromiseReader;
use CetechDeliveryEngine\Application\PromiseDates\NullFrozenOrderPromiseReader;
use CetechDeliveryEngine\Application\PromiseDates\NullPromiseDateProvider;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDateProvider;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDateRangeFormatter;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Bootstrap\Module\PluginModule;
use CetechDeliveryEngine\Bootstrap\Module\PluginModules;
use CetechDeliveryEngine\Bootstrap\ServiceContainer;
use PHPUnit\Framework\TestCase;

/**
 * The M3–M8 module seam (docs/m3-m8/LANE-PLAN.md §3): a fixed lane order, unique ids, every
 * module behind a switch that ships off, and two Plugin.php calls so lanes never edit it.
 */
final class PluginModulesTest extends TestCase {

	public function test_lane_modules_are_fixed_unique_and_behind_switches_that_ship_off(): void {
		$modules  = PluginModules::all();
		$defaults = ( new FeatureFlags() )->defaults();

		self::assertSame( [ 'promise_rules', 'promise_dates', 'emergency_pause', 'delivery_embeds', 'rest_api' ], array_map( static fn ( PluginModule $m ): string => $m->id(), $modules ) );
		foreach ( $modules as $module ) {
			$switch = constant( $module::class . '::SWITCH' );
			self::assertArrayHasKey( $switch, $defaults, $module->id() );
			self::assertFalse( $defaults[ $switch ], $module->id() . ' must ship switched off' );
		}
	}

	public function test_batch_switches_exist_and_ship_off(): void {
		$defaults = ( new FeatureFlags() )->defaults();
		self::assertCount( 27, $defaults );
		foreach ( [ 'enable_promised_dates', 'enable_emergency_pause_control', 'enable_delivery_embeds', 'enable_rest_api' ] as $switch ) {
			self::assertFalse( $defaults[ $switch ], $switch );
		}
		self::assertSame( [ 'enable_classic_checkout_adapter' => true ], array_filter( $defaults ) );
	}

	public function test_default_wiring_keeps_the_m2_duration_until_rules_are_wired(): void {
		$container = new ServiceContainer();
		PluginModules::register_all( $container, PluginModules::all() );

		self::assertInstanceOf( NullPromiseDateProvider::class, $container->get( PromiseDateProvider::class ) );
		self::assertInstanceOf( PromiseDateRangeFormatter::class, $container->get( PromiseDateRangeFormatter::class ) );
		self::assertSame( $container->get( PromiseDateProvider::class ), $container->get( PromiseDateProvider::class ) );
		self::assertInstanceOf( NullFrozenOrderPromiseReader::class, $container->get( FrozenOrderPromiseReader::class ) );
		self::assertSame( [], $container->get( FrozenOrderPromiseReader::class )->for_order( 42 ) );
	}

	public function test_frozen_promise_meta_key_is_owned_and_registered(): void {
		self::assertSame( '_cetech_de_promise_snapshot', FrozenOrderPromiseReader::META_KEY );
		self::assertContains( FrozenOrderPromiseReader::META_KEY, DataLifecycleManifest::WOO_META_KEYS );
	}

	public function test_duplicate_or_malformed_module_ids_are_refused(): void {
		$module = new class() implements PluginModule {
			public function id(): string { return 'rest_api'; }
			public function register( ServiceContainer $container ): void {}
			public function boot( ServiceContainer $container ): void {}
		};
		$bad = new class() implements PluginModule {
			public function id(): string { return 'Bad Id'; }
			public function register( ServiceContainer $container ): void {}
			public function boot( ServiceContainer $container ): void {}
		};

		foreach ( [ [ ...PluginModules::all(), $module ], [ $bad ] ] as $modules ) {
			try {
				PluginModules::boot_all( new ServiceContainer(), $modules );
				self::fail( 'A duplicate or malformed module id was accepted.' );
			} catch ( \LogicException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_a_failing_module_is_skipped_and_logged_while_the_others_still_run(): void {
		$log      = tempnam( sys_get_temp_dir(), 'cetech-de-module-log' );
		$previous = ini_set( 'error_log', (string) $log );
		try {
			$ran = new \ArrayObject();
			$healthy = new class( $ran ) implements PluginModule {
				public function __construct( private \ArrayObject $ran ) {}
				public function id(): string { return 'healthy'; }
				public function register( ServiceContainer $container ): void { $this->ran[] = 'healthy:register'; }
				public function boot( ServiceContainer $container ): void { $this->ran[] = 'healthy:boot'; }
			};
			$broken_register = new class( $ran ) implements PluginModule {
				public function __construct( private \ArrayObject $ran ) {}
				public function id(): string { return 'broken_register'; }
				public function register( ServiceContainer $container ): void { throw new \RuntimeException( 'register fault' ); }
				public function boot( ServiceContainer $container ): void { $this->ran[] = 'broken_register:boot'; }
			};
			$broken_boot = new class( $ran ) implements PluginModule {
				public function __construct( private \ArrayObject $ran ) {}
				public function id(): string { return 'broken_boot'; }
				public function register( ServiceContainer $container ): void { $this->ran[] = 'broken_boot:register'; }
				public function boot( ServiceContainer $container ): void { throw new \LogicException( 'boot fault' ); }
			};
			$modules   = [ $broken_register, $healthy, $broken_boot ];
			$container = new ServiceContainer();

			PluginModules::register_all( $container, $modules );
			PluginModules::boot_all( $container, $modules );

			self::assertSame( [ 'healthy:register', 'broken_boot:register', 'healthy:boot' ], $ran->getArrayCopy() );
			self::assertSame(
				[ 'broken_register' => [ 'phase' => 'register', 'error' => \RuntimeException::class ], 'broken_boot' => [ 'phase' => 'boot', 'error' => \LogicException::class ] ],
				PluginModules::failures( $container )->all()
			);
			self::assertSame( [], PluginModules::failures( new ServiceContainer() )->all(), 'Failures are kept per container.' );
			$logged = (string) file_get_contents( (string) $log );
			self::assertStringContainsString( 'module "broken_register" failed during register', $logged );
			self::assertStringContainsString( 'module "broken_boot" failed during boot', $logged );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
			@unlink( (string) $log );
		}
	}

	public function test_plugin_bootstrap_calls_the_modules_exactly_twice(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Bootstrap/Plugin.php' );
		self::assertSame( 1, substr_count( $source, 'Module\PluginModules::register_all( $this->container, $this->modules() );' ) );
		self::assertSame( 1, substr_count( $source, 'Module\PluginModules::boot_all( $this->container, $this->modules() );' ) );
		// Registration ends register_services(); hooks come after the core runtime in boot().
		self::assertLessThan( strpos( $source, 'private function register_repository_bindings' ), strpos( $source, 'PluginModules::register_all' ) );
		self::assertGreaterThan( strpos( $source, 'OrderDeliverySnapshotAdminDisplay::class )->register()' ), strpos( $source, 'PluginModules::boot_all' ) );
	}
}
