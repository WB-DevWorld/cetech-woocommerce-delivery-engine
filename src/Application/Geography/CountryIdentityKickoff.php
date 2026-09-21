<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;

/**
 * Runs the historical country-root identity repair after geography tables exist.
 * Gated by repair revision (checked again after lease acquire) and an
 * owner-fenced renewable lease. Does not start a pack import, coverage
 * review, or schema 7. Pack promotion still calls repair_country_code()
 * independently of this kickoff.
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
