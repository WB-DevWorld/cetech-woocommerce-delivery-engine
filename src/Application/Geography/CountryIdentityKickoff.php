<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;

/**
 * Runs country-root identity repair after geography tables exist.
 * Does not start a pack import, coverage review, or schema 7.
 */
final class CountryIdentityKickoff {

	public const HOOK = 'init';

	public const PRIORITY = 25;

	public function __construct(
		private CountryIdentityReconciler $reconciler
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( self::HOOK, [ $this, 'run' ], self::PRIORITY );
	}

	public function run(): void {
		if ( ! ConfigurationTables::exists( GeographySchema::LOCATIONS_SUFFIX ) ) {
			return;
		}

		$this->reconciler->maybe_repair();
	}
}
