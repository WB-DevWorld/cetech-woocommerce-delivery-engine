<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogScanScopedConfigurationRepository;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotStore;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\CatalogReadinessSignal;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * M2.5 physical MariaDB proof: batched scope presence, readiness signals and
 * the database-direct generation read. Uses its own m25att_ table prefix.
 *
 * @group attention-snapshot-real-db
 */
final class NeedsAttentionSnapshotSqlTest extends TestCase {

	private const PREFIX = 'm25att_';

	private RealMysqliWpdb $wpdb;

	private \PDO $pdo;

	protected function setUp(): void {
		parent::setUp();
		AbstractWpdbRepository::reset_transaction_state();
		FulfilmentProfileRegistry::reset_for_tests();
		$base = RealMysqliWpdb::try_connect();
		if ( ! $base instanceof RealMysqliWpdb ) {
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		$this->pdo  = $base->pdo();
		$this->wpdb = new RealMysqliWpdb( $this->pdo, self::PREFIX );
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_actions'] = [];
		$this->install_schema();
	}

	protected function tearDown(): void {
		AbstractWpdbRepository::reset_transaction_state();
		$GLOBALS['cetech_de_test_actions'] = [];
		$GLOBALS['cetech_de_test_options'] = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_presence_query_reads_one_batch_of_ids_in_a_single_statement(): void {
		$repository = new WpdbScopedConfigurationRepository();
		$repository->saveScopedConfiguration( $this->product_scope( 7, '' ) );
		$repository->saveScopedConfiguration( $this->product_scope( 7, 'in_store' ) );
		$repository->saveScopedConfiguration( $this->product_scope( 240, '' ) );
		$repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 9, '', 7, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '1 day' ) ]
			)
		);

		$found = $repository->scopeIdsWithRows( ConfigurationScopeType::Product, range( 1, 250 ) );
		sort( $found );

		self::assertSame( [ 7, 240 ], $found );
		self::assertSame( [], $repository->scopeIdsWithRows( ConfigurationScopeType::Product, [] ) );
		self::assertSame( [ 9 ], $repository->scopeIdsWithRows( ConfigurationScopeType::Variation, [ 9, 10 ] ) );
	}

	public function test_saves_and_deletes_signal_readiness_changes_but_unchanged_saves_do_not(): void {
		$signals = 0;
		add_action(
			CatalogReadinessSignal::ACTION,
			static function () use ( &$signals ): void {
				++$signals;
			}
		);
		$repository = new WpdbScopedConfigurationRepository();

		$repository->saveScopedConfiguration( $this->product_scope( 5, '' ) );
		self::assertSame( 1, $signals, 'insert' );
		$repository->saveScopedConfiguration( $this->product_scope( 5, '' ) );
		self::assertSame( 1, $signals, 'identical content' );
		$repository->saveScopedConfiguration( $this->product_scope( 5, '', '9 days' ) );
		self::assertSame( 2, $signals, 'update' );
		self::assertTrue( $repository->deleteScope( ConfigurationScopeType::Product, 5 ) );
		self::assertSame( 3, $signals, 'delete' );
	}

	public function test_stored_generation_reads_the_database_not_this_requests_cache(): void {
		$options = $this->wpdb->options;
		$this->pdo->exec( "INSERT INTO `{$options}` (option_name, option_value, autoload) VALUES ('" . NeedsAttentionSnapshotStore::OPTION_GENERATION . "', '42', 'off')" );
		$GLOBALS['cetech_de_test_options'][ NeedsAttentionSnapshotStore::OPTION_GENERATION ] = 7;

		$store = new NeedsAttentionSnapshotStore();

		self::assertSame( 7, $store->generation(), 'Cached read.' );
		self::assertSame( 42, $store->stored_generation(), 'Database read sees the other process.' );
	}

	public function test_batched_scan_over_real_tables_counts_only_products_with_their_own_settings(): void {
		$repository = new WpdbScopedConfigurationRepository();
		$repository->ensureGlobalScope();
		foreach ( [ 3, 251, 599 ] as $product_id ) {
			$repository->saveScopedConfiguration( $this->product_scope( $product_id, '' ) );
		}
		$products = [];
		for ( $id = 1; $id <= 600; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$catalog  = new InMemoryCatalogIndex( $products );
		$settings = new SiteWideDefaultsSettings();
		$scopes   = new CatalogScanScopedConfigurationRepository( $repository );
		$resolver = new EffectiveConfigurationResolver( $scopes, new EffectiveConfigurationValidator(), new HardFulfilmentConstraintService(), $settings );
		$flags    = new FeatureFlags();
		$query    = new NeedsAttentionQuery(
			$catalog,
			new OperationalReadinessAssessor( $resolver ),
			new OperationalStateService( $flags, $settings, new SetupWizardProgress( $settings ), new ClassicCheckoutRuntimeActivation( $flags, $settings ), new SiteWideDefaultSummary( $repository ) ),
			new CatalogInheritanceClassifier( $scopes, $catalog, $resolver, $settings ),
			$scopes
		);

		self::assertSame( 3, $query->count() );
	}

	private function product_scope( int $product_id, string $slice, string $eta = '2 days' ): ScopedConfiguration {
		return new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, $slice, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, $eta ) ]
		);
	}

	private function install_schema(): void {
		$prefix = self::PREFIX . 'delivery_engine_';
		foreach ( [ 'configuration_collections', 'configuration_fields', 'configuration_scopes' ] as $suffix ) {
			$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . $suffix . '`' );
		}
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . self::PREFIX . 'options`' );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_scopes` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_type varchar(32) NOT NULL, scope_id bigint unsigned NOT NULL DEFAULT 0, slice_key varchar(64) NOT NULL DEFAULT '', parent_product_id bigint unsigned DEFAULT NULL, status varchar(32) NOT NULL DEFAULT 'active', config_version bigint unsigned NOT NULL DEFAULT 1, source varchar(32) NOT NULL DEFAULT 'native', legacy_rule_id bigint unsigned DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_identity (scope_type, scope_id, slice_key), KEY scope_lookup (scope_type, scope_id)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_fields` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, value_type varchar(32) NOT NULL, value_text longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_field (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_collections` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, members_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_collection (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( 'CREATE TABLE `' . self::PREFIX . "options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB" );
	}
}
