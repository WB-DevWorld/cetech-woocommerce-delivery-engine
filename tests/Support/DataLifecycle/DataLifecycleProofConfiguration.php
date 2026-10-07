<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DataLifecycle;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbAuditLogRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;

/** Same COR-007 service with a native disposable PDO-backed wpdb and original envelope. */
final class DataLifecycleProofConfiguration {
	public static function open( string $prefix ): RealMysqliWpdb {
		DataLifecycleProofDatabase::validate_prefix( $prefix );
		$host = (string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ); $port = (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ); $name = (string) getenv( 'CETECH_DE_REAL_DB_NAME' );
		$pdo = new \PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", (string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ), (string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ), [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$wpdb = new RealMysqliWpdb( $pdo, $prefix ); $GLOBALS['wpdb'] = $wpdb; return $wpdb;
	}
	public static function service(): ScopedConfigurationAdminService {
		$r = new WpdbScopedConfigurationRepository(); return new ScopedConfigurationAdminService( $r, new EffectiveConfigurationResolver( $r, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() ), new ScopedConfigurationSubmissionParser(), new ProductVariationScopeGuard(), new EntityLabelResolver(), new LegacyCategoryConfigurationInspector(), new ConfigurationAuditLogger( new WpdbAuditLogRepository(), new Logger() ) );
	}
	public static function command( string $priority, string $token, int $revision = 0 ): ScopedConfigurationWriteCommand {
		return new ScopedConfigurationWriteCommand( ConfigurationScopeType::Product, 101, '', null, [ ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ], ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ], ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => '10' ], ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => '20' ], ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => '30' ], ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => $priority ], ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '1' ] ] ], true, $revision, $token );
	}
}
