<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB proofs for issue #23 geo.14 verified no-op CAS.
 * Excluded from default CI. Fail closed if the database is unreachable.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013.
 *
 * @group geo14-real-db
 */
final class Geo14RealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		$this->wpdb                   = $wpdb;
		$GLOBALS['wpdb']              = $wpdb;
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$GLOBALS['cetech_de_test_options'] = [];
		$this->reset_schema();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
		parent::tearDown();
	}

	/**
	 * @group geo14-real-db
	 */
	public function test_real_noop_cas_succeeds_without_sleep(): void {
		$this->install_schema6_and_options();
		$upgrade = $this->schema6_upgrade_service();
		$state   = $this->running_state( 'worker-A', 'pass-noop' );
		$this->persist_option( $state );

		self::assertTrue( $this->invoke( $upgrade, 'compare_and_swap_state', [ $state, $state ] ) );
		self::assertSame( serialize( $state ), $this->stored_option_value() );
	}

	/**
	 * @group geo14-real-db
	 */
	public function test_same_second_valid_renewal_succeeds_without_sleep(): void {
		$this->install_schema6_and_options();
		$upgrade = $this->schema6_upgrade_service();
		self::assertTrue( $upgrade->try_claim_owner( 'worker-A' ) );
		$state = $upgrade->current_state();
		$this->persist_option( $state );
		$pass = (string) ( $state['pass_id'] ?? '' );

		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );
		self::assertSame( 'worker-A', (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertSame( serialize( $upgrade->current_state() ), $this->stored_option_value() );
	}

	/**
	 * @group geo14-real-db
	 */
	public function test_cas_mismatch_zero_rows_does_not_write_replacement(): void {
		$this->install_schema6_and_options();
		$upgrade  = $this->schema6_upgrade_service();
		$stored   = $this->running_state( 'owner-stored', 'pass-mismatch' );
		$expected = $this->running_state( 'owner-expected', 'pass-mismatch' );
		$written  = $this->running_state( 'owner-thief', 'pass-mismatch' );
		$written['last_zone_id'] = 99;
		$this->persist_option( $stored );

		self::assertFalse( $this->invoke( $upgrade, 'compare_and_swap_state', [ $expected, $written ] ) );
		self::assertSame( serialize( $stored ), $this->stored_option_value() );
		self::assertNotSame( serialize( $written ), $this->stored_option_value() );
	}

	/**
	 * @group geo14-real-db
	 */
	public function test_stale_worker_still_fails_after_successor_takeover(): void {
		$this->install_schema6_and_options();
		$upgrade = $this->schema6_upgrade_service();
		$pass    = 'pass-fence';
		$state_a = $this->running_state( 'worker-A', $pass );
		$state_a['worker_expires_at'] = time() - 1;
		$state_a['lease_expires_at']  = time() + 180;
		$this->persist_option( $state_a );

		$claimed = $this->invoke( $upgrade, 'acquire_worker', [] );
		self::assertIsArray( $claimed );
		$worker_b = (string) ( $claimed['owner'] ?? '' );
		self::assertNotSame( '', $worker_b );
		self::assertNotSame( 'worker-A', $worker_b );
		self::assertFalse( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );
		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ $worker_b, $pass ] ) );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
	}

	/**
	 * @group geo14-real-db
	 */
	public function test_maybe_run_same_second_acquire_and_renew_is_not_stale_owner(): void {
		$this->install_schema6_and_options();
		$upgrade = $this->schema6_upgrade_service();
		$pending = [ 'status' => Schema6CoverageUpgradeService::STATUS_PENDING ];
		$this->persist_option( $pending );

		$result = $upgrade->maybe_run();
		$reason = (string) ( $result['migration']['reason'] ?? '' );
		self::assertNotSame( 'stale_owner', $reason );
		self::assertNotSame( 'coverage_tables_missing', $reason );
		self::assertNotSame( 'woocommerce_unavailable', $reason );
		self::assertContains(
			(string) ( $upgrade->current_state()['status'] ?? '' ),
			[
				Schema6CoverageUpgradeService::STATUS_RUNNING,
				Schema6CoverageUpgradeService::STATUS_COMPLETED,
			]
		);
	}

	private function schema6_upgrade_service(): Schema6CoverageUpgradeService {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );

		return new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
	}

	/**
	 * @param list<mixed> $args
	 */
	private function invoke( object $object, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function persist_option( array $state ): void {
		$key = Schema6CoverageUpgradeService::OPTION_KEY;
		$this->wpdb->query( 'DELETE FROM `' . $this->wpdb->options . '` WHERE option_name = \'' . addslashes( $key ) . '\'' );
		$this->wpdb->insert(
			$this->wpdb->options,
			[
				'option_name'  => $key,
				'option_value' => serialize( $state ),
				'autoload'     => 'no',
			]
		);
		$GLOBALS['cetech_de_test_options'][ $key ] = $state;
	}

	private function stored_option_value(): string {
		return (string) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT option_value FROM `' . $this->wpdb->options . '` WHERE option_name = %s',
				Schema6CoverageUpgradeService::OPTION_KEY
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function running_state( string $owner, string $pass ): array {
		$now = time();

		return [
			'status'            => Schema6CoverageUpgradeService::STATUS_RUNNING,
			'owner'             => $owner,
			'lease_owner'       => $owner,
			'pass_id'           => $pass,
			'pass_kind'         => Schema6CoverageUpgradeService::PASS_INITIAL,
			'last_zone_id'      => 0,
			'lease_expires_at'  => $now + Schema6CoverageUpgradeService::LEASE_TTL,
			'worker'            => $owner,
			'worker_expires_at' => $now + Schema6CoverageUpgradeService::WORKER_TTL,
		];
	}

	private function install_schema6_and_options(): void {
		$charset = $this->wpdb->get_charset_collate();
		foreach ( GeographySchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
		foreach ( CoverageSchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
		$this->wpdb->query(
			'CREATE TABLE IF NOT EXISTS `' . $this->wpdb->options . '` (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL,
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		);
	}

	private function reset_schema(): void {
		$suffixes = array_merge(
			GeographySchema::SUFFIXES,
			CoverageSchema::SUFFIXES
		);
		foreach ( $suffixes as $suffix ) {
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . TableNames::for( $suffix ) . '`' );
		}
		$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $this->wpdb->options . '`' );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return [];
				}
			},
		];
	}
}
