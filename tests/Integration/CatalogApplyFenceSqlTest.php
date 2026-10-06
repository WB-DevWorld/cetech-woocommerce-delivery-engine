<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Two-connection proof that Apply holds the target scope through its write.
 *
 * @group cor005-real-db
 */
final class CatalogApplyFenceSqlTest extends TestCase {

	private \PDO $pdo;

	protected function setUp(): void {
		parent::setUp();
		AbstractWpdbRepository::reset_transaction_state();
		CatalogScopeMutator::$during_locked_resolution = null;
		if ( ! class_exists( \PDO::class ) || ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			self::markTestSkipped( 'Disposable COR-005 MariaDB is not reachable.' );
		}
		try {
			$this->pdo = $this->open( 'cetech_cor004_fence' );
		} catch ( \PDOException ) {
			self::markTestSkipped( 'Disposable COR-005 MariaDB is not reachable.' );
		}
		$database = (string) $this->pdo->query( 'SELECT DATABASE()' )->fetchColumn();
		if ( ! str_starts_with( $database, 'cetech_cor004_' ) ) {
			self::fail( 'Refusing to mutate a database outside the COR-004 disposable prefix.' );
		}
		$this->install();
		$GLOBALS['wpdb'] = new RealMysqliWpdb( $this->pdo, 'cor005_' );
	}

	protected function tearDown(): void {
		CatalogScopeMutator::$during_locked_resolution = null;
		AbstractWpdbRepository::reset_transaction_state();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_a_second_connection_cannot_commit_while_apply_holds_the_scope_lock(): void {
		$repository = new WpdbScopedConfigurationRepository();
		$seeded     = $repository->saveScopedConfiguration( $this->product_scope( 8, 33 ) );
		$mutator    = new CatalogScopeMutator( $repository, new EffectiveConfigurationValidator() );
		$blocked    = false;
		CatalogScopeMutator::$during_locked_resolution = function () use ( &$blocked ): void {
			$other = $this->open( 'cetech_cor004_fence' );
			$other->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
			$other->beginTransaction();
			try {
				$other->query( "SELECT id FROM cor005_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 8 AND slice_key = '' LIMIT 1 FOR UPDATE" )->fetch();
				$other->rollBack();
			} catch ( \PDOException $exception ) {
				$blocked = str_contains( $exception->getMessage(), 'Lock wait timeout' );
				if ( $other->inTransaction() ) {
					$other->rollBack();
				}
			}
		};

		$result = $mutator->process(
			CatalogTargetDefinition::TARGET_PRODUCT,
			8,
			null,
			new CatalogActionManifest( [], true ),
			false,
			$seeded->fingerprint()
		);

		self::assertTrue( $blocked, 'The second connection acquired the scope while Apply was resolving it.' );
		$kept = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, 8, '' );
		if ( $kept instanceof ScopedConfiguration ) {
			self::assertSame( 33, $kept->scalars[ ConfigurationFieldKey::SUPPLIER_ID ]->value );
		}
		self::assertArrayHasKey( 'outcome', $result );
	}

	private function product_scope( int $product_id, int $supplier_id ): ScopedConfiguration {
		return new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, $supplier_id ) ]
		);
	}

	private function open( string $name ): \PDO {
		$host = (string) ( getenv( 'CETECH_DE_COR007_DB_HOST' ) ?: '127.0.0.1' );
		$port = (int) ( getenv( 'CETECH_DE_COR007_DB_PORT' ) ?: 33079 );
		$user = (string) ( getenv( 'CETECH_DE_COR007_DB_USER' ) ?: 'root' );
		$pass = (string) ( getenv( 'CETECH_DE_COR007_DB_PASSWORD' ) ?: 'cetech-cor004' );
		$safe = str_replace( '`', '', $name );
		$server = new \PDO( "mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$server->exec( 'CREATE DATABASE IF NOT EXISTS `' . $safe . '`' );

		return new \PDO( "mysql:host={$host};port={$port};dbname={$safe};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
	}

	private function install(): void {
		$prefix = 'cor005_delivery_engine_';
		foreach ( [ 'configuration_collections', 'configuration_fields', 'configuration_scopes' ] as $suffix ) {
			$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . $suffix . '`' );
		}
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_scopes` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_type varchar(32) NOT NULL, scope_id bigint unsigned NOT NULL DEFAULT 0, slice_key varchar(64) NOT NULL DEFAULT '', parent_product_id bigint unsigned DEFAULT NULL, status varchar(32) NOT NULL DEFAULT 'active', config_version bigint unsigned NOT NULL DEFAULT 1, source varchar(32) NOT NULL DEFAULT 'native', legacy_rule_id bigint unsigned DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_identity (scope_type, scope_id, slice_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_fields` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, value_type varchar(32) NOT NULL, value_text longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_field (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_collections` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, members_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_collection (scope_row_id, field_key)) ENGINE=InnoDB" );
	}
}
