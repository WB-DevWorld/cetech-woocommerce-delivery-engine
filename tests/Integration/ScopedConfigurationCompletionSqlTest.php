<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

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
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbAuditLogRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Physical MariaDB proof for COR-007. Excluded from the default suite.
 *
 * @group cor007-real-db
 */
final class ScopedConfigurationCompletionSqlTest extends TestCase {

	private \PDO $pdo;

	private \PDO $reader;

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		AbstractWpdbRepository::reset_transaction_state();
		$wpdb = $this->connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::markTestSkipped( 'Disposable COR-007 MariaDB is not reachable.' );
		}
		$this->wpdb   = $wpdb;
		$this->pdo    = $wpdb->pdo();
		$this->reader = $this->independent_pdo();
		$database     = (string) $this->pdo->query( 'SELECT DATABASE()' )->fetchColumn();
		if ( ! str_starts_with( $database, 'cetech_cor004_' ) ) {
			self::fail( 'Refusing to mutate a database outside the COR-004 disposable prefix.' );
		}
		$GLOBALS['wpdb'] = $wpdb;
		$this->install_fixture();
	}

	protected function tearDown(): void {
		AbstractWpdbRepository::reset_transaction_state();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_tables_are_innodb_and_a_paused_save_is_invisible_to_an_independent_reader(): void {
		$engines = $this->reader->query( "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'cor007_delivery_engine_%'" )->fetchAll( \PDO::FETCH_KEY_PAIR );
		foreach ( [ 'cor007_delivery_engine_configuration_scopes', 'cor007_delivery_engine_configuration_fields', 'cor007_delivery_engine_configuration_collections', 'cor007_delivery_engine_audit_log' ] as $table ) {
			self::assertSame( 'InnoDB', $engines[ $table ] ?? null, $table );
		}
		$service = $this->service();
		$first   = $service->save( $this->command( '5', 'baseline' ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );
		$seen = null;
		AbstractWpdbRepository::set_qualification_probe(
			function ( string $phase ) use ( &$seen ): void {
				if ( 'before_owned_commit' !== $phase ) {
					return;
				}
				$seen = $this->priority( $this->reader );
			}
		);
		$second = $service->save( $this->command( '9', 'change' ) );
		self::assertTrue( $second->success, implode( ' ', $second->errors ) );
		self::assertSame( '5', $seen );
		self::assertSame( '9', $this->priority( $this->reader ) );
	}

	public function test_rejected_field_insert_and_rejected_audit_keep_the_previous_complete_state(): void {
		$service = $this->service();
		$first   = $service->save( $this->command( '5', 'baseline' ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );
		$audits = $this->audit_count( $this->reader );

		$field = $service->save( $this->command( '77', 'field-fail' ) );
		self::assertFalse( $field->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $field->errors ) );
		self::assertSame( '5', $this->priority( $this->reader ) );
		self::assertSame( $audits, $this->audit_count( $this->reader ) );

		$audit = $service->save( $this->command( '8', 'reject-audit' ) );
		self::assertFalse( $audit->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $audit->errors ) );
		self::assertSame( '5', $this->priority( $this->reader ) );
		self::assertSame( $audits, $this->audit_count( $this->reader ) );
		self::assertSame( 0, (int) $this->reader->query( "SELECT COUNT(*) FROM cor007_delivery_engine_audit_log WHERE new_value LIKE '%reject-audit%'" )->fetchColumn() );
	}

	public function test_unsent_commit_rolls_back_and_lost_acknowledgement_stays_unconfirmed_until_reconciled(): void {
		$service = $this->service();
		$first   = $service->save( $this->command( '5', 'baseline' ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );
		$this->wpdb->reject_next_commit = true;
		$refused = $service->save( $this->command( '6', 'unsent' ) );
		self::assertFalse( $refused->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $refused->errors ) );
		self::assertStringContainsString( 'The commit was not sent.', implode( ' ', $refused->errors ) );
		self::assertSame( '5', $this->priority( $this->reader ) );

		$this->wpdb->lose_next_commit_ack = true;
		$lost = $service->save( $this->command( '9', 'lost-ack' ) );
		self::assertFalse( $lost->success );
		self::assertStringContainsString( 'Save outcome could not be confirmed.', implode( ' ', $lost->errors ) );
		self::assertStringNotContainsString( 'Settings were not saved.', implode( ' ', $lost->errors ) );
		self::assertSame( '9', $this->priority( $this->reader ) );
		$audits = $this->audit_count( $this->reader );

		$retry = $this->fresh_service()->save( $this->command( '9', 'lost-ack' ) );
		self::assertTrue( $retry->success, implode( ' ', $retry->errors ) );
		self::assertTrue( $retry->replayed );
		self::assertSame( $audits, $this->audit_count( $this->reader ) );
		self::assertSame( '9', $this->priority( $this->reader ) );
	}

	public function test_rejected_parent_revision_and_child_delete_keep_the_previous_state(): void {
		$service = $this->service();
		self::assertTrue( $service->save( $this->command( '5', 'baseline' ) )->success );
		$this->pdo->exec( 'SET @cor007_reject_parent := 1' );
		$parent = $service->save( $this->command( '9', 'parent-fail' ) );
		self::assertFalse( $parent->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $parent->errors ) );
		self::assertSame( '5', $this->priority( $this->reader ) );
		$this->pdo->exec( 'SET @cor007_reject_parent := 0' );
		$this->pdo->exec( 'SET @cor007_reject_child_delete := 1' );
		$child = $service->save( $this->command( '8', 'child-delete-fail' ) );
		self::assertFalse( $child->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $child->errors ) );
		self::assertSame( '5', $this->priority( $this->reader ) );
		self::assertSame( 1, $this->audit_count( $this->reader ) );
	}

	public function test_failed_reset_preserves_the_selected_slice_and_its_sibling(): void {
		$service = $this->service();
		self::assertTrue( $service->save( $this->command( '5', 'global' ) )->success );
		self::assertTrue( $service->save( $this->product_command( '', '4', 'default-slice' ) )->success );
		$custom = $service->save( $this->product_command( 'in_store', '6', 'custom-slice' ) );
		self::assertTrue( $custom->success, implode( ' ', $custom->errors ) );
		$row = $this->reader->query( "SELECT id, config_version FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = 'in_store'" )->fetch( \PDO::FETCH_ASSOC );

		try {
			$service->reset( ConfigurationScopeType::Product, 101, 'in_store', null, (int) $row['config_version'], 'reject-audit', (int) $row['id'] );
			self::fail( 'A rejected reset audit must not report completion.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Settings were not saved.', $exception->getMessage() );
		}

		self::assertSame( '6', $this->slice_priority( $this->reader, 'in_store' ) );
		self::assertSame( '4', $this->slice_priority( $this->reader, '' ) );
		self::assertSame( '5', $this->priority( $this->reader ) );
	}

	public function test_a_recreated_scope_does_not_honor_the_old_row_identity(): void {
		$service = $this->service();
		$saved   = $service->save( $this->product_command( '', '4', 'original' ) );
		self::assertTrue( $saved->success, implode( ' ', $saved->errors ) );
		$original = $this->reader->query( "SELECT id FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = ''" )->fetchColumn();
		$this->reader->exec( "DELETE FROM cor007_delivery_engine_configuration_fields WHERE scope_row_id = " . (int) $original );
		$this->reader->exec( "DELETE FROM cor007_delivery_engine_configuration_collections WHERE scope_row_id = " . (int) $original );
		$this->reader->exec( 'DELETE FROM cor007_delivery_engine_configuration_scopes WHERE id = ' . (int) $original );
		$this->reader->exec( "INSERT INTO cor007_delivery_engine_configuration_scopes (scope_type, scope_id, slice_key, status, config_version, source, created_at, updated_at) VALUES ('product', 101, '', 'active', 1, 'native', UTC_TIMESTAMP(), UTC_TIMESTAMP())" );
		$new_id = (int) $this->reader->lastInsertId();
		$this->reader->exec( "INSERT INTO cor007_delivery_engine_configuration_fields (scope_row_id, field_key, mode, value_type, value_text, created_at, updated_at) VALUES ({$new_id}, 'priority', 'override', 'int', '2', UTC_TIMESTAMP(), UTC_TIMESTAMP())" );

		$stale = $service->save( $this->product_command( '', '3', 'stale-editor', 1, (int) $original ) );

		self::assertFalse( $stale->success );
		self::assertStringContainsString( 'out of date', implode( ' ', $stale->errors ) );
		self::assertSame( '2', $this->slice_priority( $this->reader, '' ) );
		self::assertSame( $new_id, (int) $this->reader->query( "SELECT id FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = ''" )->fetchColumn() );
	}

	public function test_explicit_zero_disable_empty_replace_slice_variation_and_reset_replay(): void {
		$service = $this->service();
		$fields  = $this->fields( '0' );
		$fields[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ] = [ 'mode' => 'disable' ];
		$fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]   = [ 'mode' => 'replace', 'members' => [] ];
		$saved = $service->save( $this->product_command( 'in_store', '0', 'slice-values', null, null, $fields ) );
		self::assertTrue( $saved->success, implode( ' ', $saved->errors ) );
		$stored = $this->reader->query( "SELECT f.field_key, f.mode, f.value_text, f2.members_json FROM cor007_delivery_engine_configuration_fields f LEFT JOIN cor007_delivery_engine_configuration_collections f2 ON f2.scope_row_id = f.scope_row_id AND f2.field_key = 'delivery_offer_ids' INNER JOIN cor007_delivery_engine_configuration_scopes s ON s.id = f.scope_row_id WHERE s.scope_type = 'product' AND s.scope_id = 101 AND s.slice_key = 'in_store' AND f.field_key IN ('priority','logistics_profile_id')" )->fetchAll( \PDO::FETCH_ASSOC );
		$by_key = [];
		foreach ( $stored as $row ) {
			$by_key[ $row['field_key'] ] = $row;
		}
		self::assertSame( '0', $by_key['priority']['value_text'] ?? null );
		self::assertSame( 'disable', $by_key['logistics_profile_id']['mode'] ?? null );
		self::assertSame( '[]', $by_key['priority']['members_json'] ?? null );
		$audits = $this->audit_count( $this->reader );
		$same   = $service->save( $this->product_command( 'in_store', '0', 'slice-same', $saved->version_after, null, $fields ) );
		self::assertTrue( $same->success, implode( ' ', $same->errors ) );
		self::assertFalse( $same->version_changed );
		self::assertFalse( $same->replayed );
		self::assertSame( $audits, $this->audit_count( $this->reader ) );

		$row = $this->reader->query( "SELECT id, config_version FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = 'in_store'" )->fetch( \PDO::FETCH_ASSOC );
		self::assertTrue( $service->reset( ConfigurationScopeType::Product, 101, 'in_store', null, (int) $row['config_version'], 'reset-slice', (int) $row['id'] ) );
		self::assertNull( $this->slice_priority( $this->reader, 'in_store' ) );
		$after_reset = $this->audit_count( $this->reader );
		self::assertTrue( $service->reset( ConfigurationScopeType::Product, 101, 'in_store', null, (int) $row['config_version'], 'reset-slice', (int) $row['id'] ) );
		self::assertTrue( $service->reset_was_replayed() );
		self::assertSame( $after_reset, $this->audit_count( $this->reader ) );
		try {
			$service->reset( ConfigurationScopeType::Product, 101, '', null, null, 'reset-slice', null );
			self::fail( 'A reset token must not replay a different slice.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'does not match the saved operation', $exception->getMessage() );
		}

		$recreated = $service->save( $this->product_command( 'in_store', '3', 'recreate' ) );
		self::assertTrue( $recreated->success, implode( ' ', $recreated->errors ) );
		$new_id = (int) $this->reader->query( "SELECT id FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = 'in_store'" )->fetchColumn();
		self::assertNotSame( (int) $row['id'], $new_id );
		$stale = $service->save( $this->product_command( 'in_store', '4', 'stale-after-reset', 1, (int) $row['id'] ) );
		self::assertFalse( $stale->success );
		self::assertStringContainsString( 'out of date', implode( ' ', $stale->errors ) );
		self::assertSame( '3', $this->slice_priority( $this->reader, 'in_store' ) );

		$guarded = $this->service( static fn ( int $variation_id, int $parent_id ): bool => false );
		$denied  = $guarded->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Variation,
				501,
				'',
				101,
				$this->fields( '1' ),
				true,
				null,
				'wrong-parent'
			)
		);
		self::assertFalse( $denied->success );
		self::assertStringContainsString( 'does not belong to the selected parent product', implode( ' ', $denied->errors ) );
		self::assertSame( 0, (int) $this->reader->query( "SELECT COUNT(*) FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'variation'" )->fetchColumn() );
	}

	public function test_two_processes_with_one_expected_revision_do_not_mix_instructions(): void {
		$service = $this->service();
		$seed    = $service->save( $this->product_command( '', '5', 'seed', null ) );
		self::assertTrue( $seed->success, implode( ' ', $seed->errors ) );
		$expected = $seed->version_after;
		$left     = $this->spawn_save( '8', 'left-process', $expected );
		$right    = $this->spawn_save( '9', 'right-process', $expected );
		$results  = [ $this->finish_process( $left ), $this->finish_process( $right ) ];
		$wins     = array_values( array_filter( $results, static fn ( array $result ): bool => true === $result['success'] ) );
		$conflicts = array_values( array_filter( $results, static fn ( array $result ): bool => false === $result['success'] ) );
		self::assertCount( 1, $wins, json_encode( $results ) );
		self::assertCount( 1, $conflicts, json_encode( $results ) );
		self::assertStringContainsString( 'out of date', implode( ' ', $conflicts[0]['errors'] ) );
		$priority = $this->slice_priority( $this->reader, '' );
		self::assertContains( $priority, [ '8', '9' ] );
		self::assertSame( 1, (int) $this->reader->query( "SELECT COUNT(*) FROM cor007_delivery_engine_configuration_fields WHERE field_key = 'priority' AND scope_row_id IN (SELECT id FROM cor007_delivery_engine_configuration_scopes WHERE scope_type = 'product' AND scope_id = 101 AND slice_key = '')" )->fetchColumn() );
		self::assertSame( 2, (int) $this->reader->query( "SELECT COUNT(*) FROM cor007_delivery_engine_audit_log WHERE action = 'scoped_configuration_updated' AND new_value LIKE '%\"scope_id\":101%'" )->fetchColumn() );
	}

	private function service( ?callable $variation_checker = null ): ScopedConfigurationAdminService {
		$repository = new WpdbScopedConfigurationRepository();

		return new ScopedConfigurationAdminService(
			$repository,
			new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() ),
			new ScopedConfigurationSubmissionParser(),
			new ProductVariationScopeGuard(),
			new EntityLabelResolver(),
			new LegacyCategoryConfigurationInspector(),
			new ConfigurationAuditLogger( new WpdbAuditLogRepository(), new Logger() ),
			$variation_checker
		);
	}

	private function fresh_service(): ScopedConfigurationAdminService {
		$this->wpdb = $this->connect();
		if ( ! $this->wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'Could not open a replacement connection.' );
		}
		$this->pdo           = $this->wpdb->pdo();
		$GLOBALS['wpdb']     = $this->wpdb;

		return $this->service();
	}

	private function command( string $priority, string $token ): ScopedConfigurationWriteCommand {
		return new ScopedConfigurationWriteCommand(
			ConfigurationScopeType::Global,
			0,
			'',
			null,
			$this->fields( $priority ),
			false,
			null,
			$token
		);
	}

	/**
	 * @param array<string, array<string, mixed>>|null $fields
	 */
	private function product_command( string $slice, string $priority, string $token, ?int $expected_revision = null, ?int $row_id = null, ?array $fields = null ): ScopedConfigurationWriteCommand {
		return new ScopedConfigurationWriteCommand(
			ConfigurationScopeType::Product,
			101,
			$slice,
			null,
			$fields ?? $this->fields( $priority ),
			true,
			$expected_revision,
			$token,
			$row_id
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( string $priority ): array {
		return [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ],
			ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ],
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => '10' ],
			ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => '20' ],
			ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => '30' ],
			ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => $priority ],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '1' ] ],
		];
	}

	private function priority( \PDO $pdo ): ?string {
		return $this->slice_priority( $pdo, '', 'global', 0 );
	}

	private function slice_priority( \PDO $pdo, string $slice, string $type = 'product', int $scope_id = 101 ): ?string {
		$statement = $pdo->prepare( 'SELECT f.value_text FROM cor007_delivery_engine_configuration_fields f INNER JOIN cor007_delivery_engine_configuration_scopes s ON s.id = f.scope_row_id WHERE s.scope_type = ? AND s.scope_id = ? AND s.slice_key = ? AND f.field_key = ?' );
		$statement->execute( [ $type, $scope_id, $slice, 'priority' ] );
		$value = $statement->fetchColumn();

		return false === $value ? null : (string) $value;
	}

	private function audit_count( \PDO $pdo ): int {
		return (int) $pdo->query( 'SELECT COUNT(*) FROM cor007_delivery_engine_audit_log' )->fetchColumn();
	}

	/**
	 * @return array{process: resource, output: string}
	 */
	private function spawn_save( string $priority, string $token, int $expected ): array {
		$output  = tempnam( sys_get_temp_dir(), 'cor007' );
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/Integration/cor007-save-worker.php' );
		$process = proc_open(
			$command,
			[ 1 => [ 'file', (string) $output, 'w' ], 2 => [ 'file', (string) $output, 'a' ] ],
			$pipes,
			dirname( __DIR__, 2 ),
			[
				'CETECH_DE_COR007_PRIORITY' => $priority,
				'CETECH_DE_COR007_TOKEN'    => $token,
				'CETECH_DE_COR007_EXPECTED' => (string) $expected,
				'CETECH_DE_COR007_DB_HOST'  => (string) ( getenv( 'CETECH_DE_COR007_DB_HOST' ) ?: '127.0.0.1' ),
				'CETECH_DE_COR007_DB_PORT'  => (string) ( getenv( 'CETECH_DE_COR007_DB_PORT' ) ?: 33079 ),
				'CETECH_DE_COR007_DB_USER'  => (string) ( getenv( 'CETECH_DE_COR007_DB_USER' ) ?: 'root' ),
				'CETECH_DE_COR007_DB_PASSWORD' => (string) ( getenv( 'CETECH_DE_COR007_DB_PASSWORD' ) ?: 'cetech-cor004' ),
				'CETECH_DE_COR007_DB_NAME'  => (string) ( getenv( 'CETECH_DE_COR007_DB_NAME' ) ?: 'cetech_cor004_cor007' ),
			]
		);
		if ( ! is_resource( $process ) ) {
			self::fail( 'Could not start a second save process.' );
		}

		return [ 'process' => $process, 'output' => (string) $output ];
	}

	/**
	 * @param array{process: resource, output: string} $spawned
	 * @return array<string, mixed>
	 */
	private function finish_process( array $spawned ): array {
		$exit = proc_close( $spawned['process'] );
		$raw  = (string) file_get_contents( $spawned['output'] );
		@unlink( $spawned['output'] );
		$decoded = json_decode( trim( $raw ), true );
		if ( ! is_array( $decoded ) ) {
			self::fail( 'Save process exit ' . $exit . ' did not return JSON: ' . $raw );
		}

		return $decoded;
	}

	private function connect(): ?RealMysqliWpdb {
		if ( ! class_exists( \PDO::class ) || ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			return null;
		}
		try {
			return new RealMysqliWpdb( $this->independent_pdo(), 'cor007_' );
		} catch ( \PDOException ) {
			return null;
		}
	}

	private function independent_pdo(): \PDO {
		$host = (string) ( getenv( 'CETECH_DE_COR007_DB_HOST' ) ?: '127.0.0.1' );
		$port = (int) ( getenv( 'CETECH_DE_COR007_DB_PORT' ) ?: 33079 );
		$user = (string) ( getenv( 'CETECH_DE_COR007_DB_USER' ) ?: 'root' );
		$pass = (string) ( getenv( 'CETECH_DE_COR007_DB_PASSWORD' ) ?: 'cetech-cor004' );
		$name = (string) ( getenv( 'CETECH_DE_COR007_DB_NAME' ) ?: 'cetech_cor004_cor007' );
		$server = new \PDO( "mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$server->exec( 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', $name ) . '`' );

		return new \PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC ] );
	}

	private function install_fixture(): void {
		$prefix = 'cor007_delivery_engine_';
		foreach ( [ 'configuration_collections', 'configuration_fields', 'configuration_scopes', 'audit_log' ] as $suffix ) {
			$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . $suffix . '`' );
		}
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_scopes` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_type varchar(32) NOT NULL, scope_id bigint unsigned NOT NULL DEFAULT 0, slice_key varchar(64) NOT NULL DEFAULT '', parent_product_id bigint unsigned DEFAULT NULL, status varchar(32) NOT NULL DEFAULT 'active', config_version bigint unsigned NOT NULL DEFAULT 1, source varchar(32) NOT NULL DEFAULT 'native', legacy_rule_id bigint unsigned DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_identity (scope_type, scope_id, slice_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_fields` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, value_type varchar(32) NOT NULL, value_text longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_field (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}configuration_collections` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, members_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY scope_collection (scope_row_id, field_key)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}audit_log` (id bigint unsigned NOT NULL AUTO_INCREMENT, actor_user_id bigint unsigned DEFAULT NULL, action varchar(64) NOT NULL, entity_type varchar(64) NOT NULL, entity_id bigint unsigned DEFAULT NULL, previous_value longtext, new_value longtext, site_context varchar(255) DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );
		$this->pdo->exec( "CREATE TRIGGER cor007_reject_field BEFORE INSERT ON `{$prefix}configuration_fields` FOR EACH ROW BEGIN IF NEW.value_text = '77' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected field insert'; END IF; END" );
		$this->pdo->exec( "CREATE TRIGGER cor007_reject_audit BEFORE INSERT ON `{$prefix}audit_log` FOR EACH ROW BEGIN IF NEW.new_value LIKE '%reject-audit%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected audit append'; END IF; END" );
		$this->pdo->exec( "CREATE TRIGGER cor007_reject_parent BEFORE UPDATE ON `{$prefix}configuration_scopes` FOR EACH ROW BEGIN IF @cor007_reject_parent = 1 AND NEW.config_version <> OLD.config_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected parent revision'; END IF; END" );
		$this->pdo->exec( "CREATE TRIGGER cor007_reject_child_delete BEFORE DELETE ON `{$prefix}configuration_fields` FOR EACH ROW BEGIN IF @cor007_reject_child_delete = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected child delete'; END IF; END" );
	}
}
