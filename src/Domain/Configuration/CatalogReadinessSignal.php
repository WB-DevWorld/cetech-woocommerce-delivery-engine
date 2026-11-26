<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

/**
 * Announces a write that can change catalog delivery readiness (scoped
 * configuration, delivery options, pickup locations).
 *
 * Fired by the persistence layer after a successful write. The Needs Attention
 * snapshot listens and marks itself stale. A write inside a transaction that is
 * later rolled back only causes one extra background refresh.
 */
final class CatalogReadinessSignal {

	public const ACTION = 'cetech_de_catalog_readiness_changed';

	private function __construct() {
	}

	public static function changed( string $source ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( self::ACTION, $source );
		}
	}
}
