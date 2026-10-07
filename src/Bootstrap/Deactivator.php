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
		DataLifecycleScheduler::suspend_current_site();
		delete_transient( 'cetech_de_activation_notice' );
		// WordPress can load non-autoloaded options into its alloptions fallback.
		// Retire that advisory view after deleting the fixed notice pair.
		wp_cache_delete( '_transient_cetech_de_activation_notice', 'options' );
		wp_cache_delete( '_transient_timeout_cetech_de_activation_notice', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		flush_rewrite_rules();
	}
}
