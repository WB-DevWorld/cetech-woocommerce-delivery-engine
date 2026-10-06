<?php

declare(strict_types=1);

use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
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
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';

$host = (string) ( getenv( 'CETECH_DE_COR007_DB_HOST' ) ?: '127.0.0.1' );
$port = (int) ( getenv( 'CETECH_DE_COR007_DB_PORT' ) ?: 33079 );
$user = (string) ( getenv( 'CETECH_DE_COR007_DB_USER' ) ?: 'root' );
$pass = (string) ( getenv( 'CETECH_DE_COR007_DB_PASSWORD' ) ?: 'cetech-cor004' );
$name = (string) ( getenv( 'CETECH_DE_COR007_DB_NAME' ) ?: 'cetech_cor004_cor007' );
$priority = (string) getenv( 'CETECH_DE_COR007_PRIORITY' );
$token = (string) getenv( 'CETECH_DE_COR007_TOKEN' );
$expected = getenv( 'CETECH_DE_COR007_EXPECTED' );
$expected_revision = false === $expected || '' === $expected ? null : (int) $expected;

$pdo = new PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
$wpdb = new RealMysqliWpdb( $pdo, 'cor007_' );
$GLOBALS['wpdb'] = $wpdb;
$repository = new WpdbScopedConfigurationRepository();
$service = new ScopedConfigurationAdminService(
	$repository,
	new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() ),
	new ScopedConfigurationSubmissionParser(),
	new ProductVariationScopeGuard(),
	new EntityLabelResolver(),
	new LegacyCategoryConfigurationInspector(),
	new ConfigurationAuditLogger( new WpdbAuditLogRepository(), new Logger() )
);
$result = $service->save(
	new ScopedConfigurationWriteCommand(
		ConfigurationScopeType::Product,
		101,
		'',
		null,
		[
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ],
			ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ],
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => '10' ],
			ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => '20' ],
			ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => '30' ],
			ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => $priority ],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '1' ] ],
		],
		true,
		$expected_revision,
		$token
	)
);
fwrite( STDOUT, json_encode( [ 'success' => $result->success, 'errors' => $result->errors, 'replayed' => $result->replayed, 'version_after' => $result->version_after ] ) . PHP_EOL );
