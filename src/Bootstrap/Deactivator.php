<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

/**
 * Plugin deactivation handler.
 *
 * Deactivation is non-destructive: it must not drop configuration or shipment
 * tables and must not delete shipment records.
 */
final class Deactivator {

	public static function deactivate(): void {
		delete_transient( 'cetech_de_activation_notice' );

		// Stop the Needs Attention background refresh. Its options are left alone:
		// the lock expires by itself and activation starts a new generation, which
		// makes the last snapshot and any refresh cursor stale.
		( new \CetechDeliveryEngine\Infrastructure\WordPress\WpCronNeedsAttentionRefreshScheduler() )->clear();

		flush_rewrite_rules();
	}
}
