<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Bootstrap;

/** Fixed own-source closure: no Composer, Woo or caller-supplied loader. */
final class DataLifecycleBootstrap {
	public const FILES = [
		'Bootstrap/DataLifecycleManifest.php',
		'Domain/Contracts/RequestContext.php', 'Domain/Contracts/ContractError.php',
		'Domain/Operation/OperationJson.php',
		'Domain/Operation/OperationCommitResult.php', 'Domain/Operation/OperationSession.php', 'Domain/Operation/OperationConnectionFactory.php',
		'Infrastructure/WordPress/OperationConnectionResult.php', 'Infrastructure/WordPress/OperationConnectionTransport.php',
		'Infrastructure/WordPress/OperationConnectionMysqliTransport.php', 'Infrastructure/WordPress/OperationConnection.php', 'Infrastructure/WordPress/OperationConnectionFactory.php',
		'Domain/DataLifecycle/DataLifecyclePolicy.php', 'Domain/DataLifecycle/DataLifecycleClass.php', 'Domain/DataLifecycle/DataLifecycleRegistry.php',
		'Domain/DataLifecycle/DataLifecycleProgress.php', 'Domain/DataLifecycle/DataLifecycleContinuation.php', 'Domain/DataLifecycle/DataLifecycleResult.php',
		'Domain/DataLifecycle/ManagedGeographyCacheIdentity.php', 'Domain/DataLifecycle/ManagedGeographyCacheEnvelope.php',
		'Infrastructure/Persistence/DataLifecycleOptionsStore.php', 'Application/DataLifecycle/DataLifecycleCleanupService.php', 'Bootstrap/DataLifecycleUninstallExecutor.php',
	];
	public static function load(): bool {
		$root = dirname( __DIR__ );
		foreach ( self::FILES as $file ) { if ( ! is_readable( $root . '/' . $file ) ) { return false; } }
		try { foreach ( self::FILES as $file ) { require_once $root . '/' . $file; } return true; }
		catch ( \Throwable ) { return false; }
	}
}
