<?php
/**
 * Uninstall handler for CETECH WooCommerce Delivery Engine.
 * Default preserves data and permissions. Exact saved intent removes only
 * temporary managed geography responses, the activation notice and role caps.
 * All saved business records and domain tables remain.
 * This file must not fatal when WooCommerce is absent.
 * @package CetechDeliveryEngine
 */
declare(strict_types=1);
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
$intent = get_option( 'cetech_de_delete_data_on_uninstall', 0 );
if ( 1 !== $intent && '1' !== $intent ) { return; }
$helper = __DIR__ . '/src/Bootstrap/DataLifecycleBootstrap.php';
if ( ! is_readable( $helper ) ) { return; }
try {
 require_once $helper;
 if ( ! CetechDeliveryEngine\Bootstrap\DataLifecycleBootstrap::load() ) { return; }
 CetechDeliveryEngine\Bootstrap\DataLifecycleUninstallExecutor::run();
} catch ( Throwable ) {
 // An unavailable helper or owner preserves data and the saved request.
}
