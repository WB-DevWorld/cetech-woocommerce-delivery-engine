<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\WooCommerceCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
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
		CatalogScopeMutator::$before_scope_lock = null;
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
		CatalogScopeMutator::$before_scope_lock = null;
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
		self::assertInstanceOf( ScopedConfiguration::class, $kept );
		self::assertSame( (int) $seeded->scope->id, (int) $kept->scope->id );
		self::assertSame( (int) $seeded->scope->config_version, (int) $kept->scope->config_version );
		self::assertSame( 33, $kept->scalars[ ConfigurationFieldKey::SUPPLIER_ID ]->value );
		self::assertNotSame( 'changed', $result['outcome'] );
	}

	public function test_a_committed_edit_after_the_membership_snapshot_is_not_replaced(): void {
		$repository = new WpdbScopedConfigurationRepository();
		$repository->saveScopedConfiguration( $this->inherited_delivery_scope() );
		$product    = $repository->saveScopedConfiguration( $this->product_scope( 8, 33 ) );
		$approved   = $product->fingerprint();
		$jobs       = new WpdbBulkJobRepository();
		$definition = [
			'scope'                     => BulkTargetScope::SelectedIds->value,
			'selected_ids'              => [ 8 ],
			'target_type'               => CatalogTargetDefinition::TARGET_PRODUCT,
			'variation_policy'          => BulkVariationPolicy::PreserveOverrides->value,
			'selected_ids_materialized' => false,
		];
		$job = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 1, $definition, ( new CatalogActionManifest( [], true ) )->to_array(), false ) );
		$job = $jobs->save_job( $job->with_status( BulkJobStatus::Queued )->with_progress( 1, 1, 0, 0, 0, 0, 0, true, '8', [] ) );
		$jobs->insert_items( [
			BulkJobItem::pending( (int) $job->id, CatalogTargetDefinition::TARGET_PRODUCT, 8, 'SKU-8' )->with( [
				'precondition_fingerprint' => $approved,
			] ),
		] );
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->pdo()->exec( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$snapshot_variable = $this->pdo->query( "SHOW VARIABLES LIKE 'innodb_snapshot_isolation'" )->fetch( \PDO::FETCH_ASSOC );
		if ( is_array( $snapshot_variable ) ) {
			$wpdb->pdo()->exec( 'SET SESSION innodb_snapshot_isolation = OFF' );
		}
		$isolation = (string) $wpdb->pdo()->query( 'SELECT @@session.transaction_isolation' )->fetchColumn();
		$snapshot  = is_array( $snapshot_variable ) ? (string) $wpdb->pdo()->query( 'SELECT @@session.innodb_snapshot_isolation' )->fetchColumn() : 'unavailable';
		$row_id    = (int) $product->scope->id;
		CatalogScopeMutator::$before_scope_lock = function () use ( $row_id ): void {
			$other = $this->open( 'cetech_cor004_fence' );
			$other->beginTransaction();
			$other->exec( 'UPDATE cor005_delivery_engine_configuration_scopes SET config_version = config_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ' . $row_id );
			$other->exec( "UPDATE cor005_delivery_engine_configuration_fields SET value_text = '99', updated_at = UTC_TIMESTAMP() WHERE scope_row_id = " . $row_id . " AND field_key = 'supplier_id'" );
			$other->commit();
		};
		$worker = new BulkJobWorker(
			$jobs,
			new WooCommerceCatalogTargetQuery(),
			new CatalogScopeMutator( $repository, new EffectiveConfigurationValidator() ),
			new class implements BackgroundQueueInterface {
				public function is_available(): bool {
					return true;
				}

				public function enqueue_job_tick( int $job_id, int $delay_seconds = 0 ): bool {
					return true;
				}

				public function cancel_job_ticks( int $job_id ): void {
				}

				public function unavailable_reason(): string {
					return '';
				}
			}
		);

		$worker->tick( (int) $job->id );

		$finished = $jobs->find_job( (int) $job->id );
		$item     = $jobs->list_items( (int) $job->id, 10 )[0];
		$kept     = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, 8, '' );
		self::assertStringContainsString( 'REPEATABLE', strtoupper( $isolation ), 'session=' . $isolation . ' innodb_snapshot_isolation=' . $snapshot );
		self::assertNotSame( '', $snapshot );
		self::assertInstanceOf( ScopedConfiguration::class, $kept );
		self::assertSame( $row_id, (int) $kept->scope->id );
		self::assertSame( (int) $product->scope->config_version + 1, (int) $kept->scope->config_version );
		self::assertSame( 99, $kept->scalars[ ConfigurationFieldKey::SUPPLIER_ID ]->value );
		self::assertSame( BulkJobItemStatus::Skipped, $item->status );
		self::assertSame( 'stale_target', $item->error_code );
		self::assertTrue( (bool) ( $item->result['stale'] ?? false ) );
		self::assertSame( $approved, $item->precondition_fingerprint );
		self::assertSame( '', $item->after_fingerprint );
		self::assertSame( 1, $finished?->total_count );
		self::assertSame( 0, $finished?->changed_count );
	}

	private function inherited_delivery_scope(): ScopedConfiguration {
		return new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, ConfigurationScope::DEFAULT_SLICE_KEY, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
				ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
				ConfigurationFieldKey::ESTIMATED_DELIVERY      => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '2 days' ),
			],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
			]
		);
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
		foreach ( [ 'bulk_job_items', 'bulk_jobs', 'bulk_recipes', 'configuration_collections', 'configuration_fields', 'configuration_scopes' ] as $suffix ) {
			$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . $suffix . '`' );
		}
		$this->pdo->exec( 'DROP TABLE IF EXISTS `cor005_posts`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `cor005_options`' );
		$this->pdo->exec( 'CREATE TABLE `cor005_posts` (ID bigint unsigned NOT NULL PRIMARY KEY, post_type varchar(20) NOT NULL, post_status varchar(20) NOT NULL) ENGINE=InnoDB' );
		$this->pdo->exec( "INSERT INTO `cor005_posts` (ID, post_type, post_status) VALUES (8, 'product', 'publish')" );
		$this->pdo->exec( 'CREATE TABLE `cor005_options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'off\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB' );
		foreach ( BulkJobSchema::create_table_statements( '', 'cor005_delivery_engine_' ) as $statement ) {
			$this->pdo->exec( $statement );
		}
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_scopes` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_type varchar(32) NOT NULL, scope_id bigint unsigned NOT NULL DEFAULT 0, slice_key varchar(64) NOT NULL DEFAULT '', parent_product_id bigint unsigned DEFAULT NULL, status varchar(32) NOT NULL DEFAULT 'active', config_version bigint unsigned NOT NULL DEFAULT 1, source varchar(32) NOT NULL DEFAULT 'native', legacy_rule_id bigint unsigned DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_identity (scope_type, scope_id, slice_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_fields` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, value_type varchar(32) NOT NULL, value_text longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_field (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_collections` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, members_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_collection (scope_row_id, field_key)) ENGINE=InnoDB" );
	}
}
