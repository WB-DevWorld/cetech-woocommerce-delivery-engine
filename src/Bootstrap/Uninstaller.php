<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Bootstrap;
/** Saved business records survive default and explicit uninstall. */
final class Uninstaller {
 public const DELETE_DATA_OPTION = 'cetech_de_delete_data_on_uninstall';
 public static function uninstall(): void {
  if (!DataLifecycleManifest::supports_uninstall_intent(get_option(self::DELETE_DATA_OPTION, 0))) { return; }
  if (DataLifecycleBootstrap::load()) { DataLifecycleUninstallExecutor::run(); }
 }
}
