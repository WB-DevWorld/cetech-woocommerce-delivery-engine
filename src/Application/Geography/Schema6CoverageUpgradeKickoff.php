<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;

/**
 * Defers Woo-dependent schema-6 coverage conversion until WooCommerce and
 * Action Scheduler are ready. Table migrations stay on plugins_loaded.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013.
 */
final class Schema6CoverageUpgradeKickoff {

	public const HOOK = 'init';

	public const PRIORITY = 20;

	public function __construct(
		private Schema6CoverageUpgradeService $upgrade
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( self::HOOK, [ $this, 'run' ], self::PRIORITY );
	}

	public function run(): void {
		if ( ! $this->ready() ) {
			return;
		}

		$this->upgrade->maybe_run();
	}

	public function ready(): bool {
		if ( ! ConfigurationTables::exists( CoverageSchema::GROUPS_SUFFIX ) ) {
			return false;
		}

		if ( ! $this->woocommerce_ready() ) {
			return false;
		}

		return ActionSchedulerReadiness::is_initialized();
	}

	private function woocommerce_ready(): bool {
		if ( $this->upgrade->woo_catalog_available() ) {
			return true;
		}

		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_init' ) > 0 ) {
			return $this->upgrade->woo_catalog_available();
		}

		return false;
	}
}
