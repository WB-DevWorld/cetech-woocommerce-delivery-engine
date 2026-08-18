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
		flush_rewrite_rules();
	}
}
