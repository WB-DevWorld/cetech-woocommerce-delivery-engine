<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23 geo.14 — verified no-op CAS for schema-6 worker leases.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013.
 */
final class Geo14CasNoOpTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
		parent::tearDown();
	}

	public function test_identical_expected_and_replacement_is_successful_noop_cas(): void {
		$upgrade = $this->schema6_upgrade_service();
		$wpdb    = $this->install_verifying_cas_wpdb();
		$state   = $this->running_state( 'worker-A', 'pass-noop' );
		$this->persist( $wpdb, $state );

		self::assertTrue( $this->invoke( $upgrade, 'compare_and_swap_state', [ $state, $state ] ) );
		self::assertSame( 0, $wpdb->last_changed );
		self::assertSame( serialize( $state ), $wpdb->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] );
	}

	public function test_same_second_valid_renewal_succeeds_without_sleep(): void {
		$upgrade = $this->schema6_upgrade_service();
		$wpdb    = $this->install_verifying_cas_wpdb();
		self::assertTrue( $upgrade->try_claim_owner( 'worker-A' ) );
		$state = $upgrade->current_state();
		$this->persist( $wpdb, $state );
		$pass = (string) ( $state['pass_id'] ?? '' );

		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );
		self::assertSame( 'worker-A', (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertSame( serialize( $upgrade->current_state() ), $wpdb->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] );
	}

	public function test_cas_mismatch_zero_rows_does_not_write_replacement(): void {
		$upgrade  = $this->schema6_upgrade_service();
		$wpdb     = $this->install_verifying_cas_wpdb();
		$stored   = $this->running_state( 'owner-stored', 'pass-mismatch' );
		$expected = $this->running_state( 'owner-expected', 'pass-mismatch' );
		$written  = $this->running_state( 'owner-thief', 'pass-mismatch' );
		$written['last_zone_id'] = 99;
		$this->persist( $wpdb, $stored );

		self::assertFalse( $this->invoke( $upgrade, 'compare_and_swap_state', [ $expected, $written ] ) );
		self::assertSame( 0, $wpdb->last_changed );
		self::assertSame( serialize( $stored ), $wpdb->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] );
		self::assertNotSame( serialize( $written ), $wpdb->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] );
	}

	public function test_stale_worker_still_fails_after_successor_takeover(): void {
		$upgrade = $this->schema6_upgrade_service();
		$wpdb    = $this->install_verifying_cas_wpdb();
		$pass    = 'pass-fence';
		$state_a = $this->running_state( 'worker-A', $pass );
		$state_a['worker_expires_at'] = time() - 1;
		$state_a['lease_expires_at']  = time() + 180;
		$this->persist( $wpdb, $state_a );

		$claimed = $this->invoke( $upgrade, 'acquire_worker', [] );
		self::assertIsArray( $claimed );
		$worker_b = (string) ( $claimed['owner'] ?? '' );
		self::assertNotSame( '', $worker_b );
		self::assertNotSame( 'worker-A', $worker_b );

		self::assertFalse( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );
		self::assertFalse(
			$this->invoke(
				$upgrade,
				'owner_store',
				[
					'worker-A',
					$pass,
					array_merge(
						$upgrade->current_state(),
						[
							'last_zone_id' => 9,
							'status'       => Schema6CoverageUpgradeService::STATUS_COMPLETED,
							'worker'       => 'worker-A',
						]
					),
				]
			)
		);
		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ $worker_b, $pass ] ) );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function persist( Geo14VerifyingCasWpdb $wpdb, array $state ): void {
		$serialized = serialize( $state );
		$wpdb->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] = $serialized;
		$GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] = $state;
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

	private function install_verifying_cas_wpdb(): Geo14VerifyingCasWpdb {
		$wpdb            = new Geo14VerifyingCasWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}

	/**
	 * @param list<mixed> $args
	 */
	private function invoke( object $object, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	private function schema6_upgrade_service(): Schema6CoverageUpgradeService {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return [];
				}
			},
		];
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );

		return new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
	}
}

/**
 * Real-CAS stand-in: returns 0 for identical replacements without writing.
 */
final class Geo14VerifyingCasWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	/** @var array<string, string> */
	public array $option_values = [];

	public int $last_changed = -1;

	public int $update_calls = 0;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where ) {
		unset( $table );
		++$this->update_calls;
		$name        = (string) ( $where['option_name'] ?? '' );
		$expected    = (string) ( $where['option_value'] ?? '' );
		$replacement = (string) ( $data['option_value'] ?? '' );
		$current     = $this->option_values[ $name ] ?? null;
		if ( $current !== $expected ) {
			$this->last_changed = 0;

			return 0;
		}
		if ( $current === $replacement ) {
			$this->last_changed = 0;

			return 0;
		}
		$this->option_values[ $name ] = $replacement;
		$this->last_changed           = 1;

		return 1;
	}

	public function get_var( string $sql ) {
		unset( $sql );

		return $this->option_values[ Schema6CoverageUpgradeService::OPTION_KEY ] ?? null;
	}

	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdfF]/',
			static function () use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';

				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_charset_collate(): string {
		return '';
	}
}
