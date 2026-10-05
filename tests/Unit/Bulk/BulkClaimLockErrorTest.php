<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

require_once __DIR__ . '/BulkJobBulk9RepairTest.php';
require_once dirname( __DIR__, 2 ) . '/Support/Cor006ImportStubs.php';

use CetechDeliveryEngine\Application\Bulk\Portability\ConfigImportConflictMode;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Support\Cor006LogisticsStore;
use CetechDeliveryEngine\Tests\Support\Cor006OriginStore;
use CetechDeliveryEngine\Tests\Support\Cor006PickupStore;
use CetechDeliveryEngine\Tests\Support\Cor006SupplierStore;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class BulkClaimLockErrorTest extends TestCase {

	private FakeWpdb $wpdb;

	private WpdbBulkJobRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		AbstractWpdbRepository::reset_transaction_state();
		$this->wpdb = new FakeWpdb();
		$this->wpdb->create_table( 'wp_delivery_engine_bulk_job_items' );
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->repository = new WpdbBulkJobRepository();
		$this->wpdb->insert(
			'wp_delivery_engine_bulk_job_items',
			[
				'id'          => 1,
				'job_id'      => 1,
				'status'      => 'claimed',
				'claim_token' => 'owner',
			]
		);
	}

	protected function tearDown(): void {
		AbstractWpdbRepository::reset_transaction_state();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_a_rejected_transaction_start_does_not_run_the_source_callback(): void {
		$this->wpdb->fail_sql_containing = [ 'START TRANSACTION' ];
		$ran = false;

		try {
			$this->repository->call_while_item_claimed( 1, 'owner', static function () use ( &$ran ): bool {
				$ran = true;

				return true;
			} );
			self::fail( 'A rejected transaction start must be explicit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Bulk item write failed.', $exception->getMessage() );
			self::assertStringContainsString( 'START TRANSACTION', $exception->getMessage() );
		}

		self::assertFalse( $ran );
	}

	public function test_a_failed_locking_read_is_not_treated_as_a_missing_owner(): void {
		$this->wpdb->fail_sql_containing = [ 'FOR UPDATE' ];
		$ran = false;

		try {
			$this->repository->call_while_item_claimed( 1, 'owner', static function () use ( &$ran ): bool {
				$ran = true;

				return true;
			} );
			self::fail( 'A failed locking read must be explicit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'FOR UPDATE', $exception->getMessage() );
		}

		self::assertFalse( $ran );
		self::assertSame( 'owner', $this->item_token() );
	}

	public function test_a_missing_token_is_not_an_sql_failure(): void {
		$ran = false;
		$result = $this->repository->call_while_item_claimed( 1, 'other-owner', static function () use ( &$ran ): bool {
			$ran = true;

			return true;
		} );

		self::assertNull( $result );
		self::assertFalse( $ran );
		self::assertSame( 'owner', $this->item_token() );
	}

	public function test_a_failed_commit_rolls_back_the_source_write(): void {
		$this->wpdb->create_table( 'wp_delivery_engine_lock_probe' );
		$this->wpdb->fail_sql_containing = [ 'COMMIT' ];

		try {
			$this->repository->call_while_item_claimed(
				1,
				'owner',
				function (): bool {
					$this->wpdb->insert( 'wp_delivery_engine_lock_probe', [ 'id' => 1, 'written' => 1 ] );

					return true;
				}
			);
			self::fail( 'A rejected commit must be explicit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'COMMIT', $exception->getMessage() );
		}

		self::assertNull( $this->wpdb->get_var( 'SELECT id FROM `wp_delivery_engine_lock_probe` WHERE id = 1' ) );
	}

	public function test_a_joined_import_keeps_an_unresolved_rollback_from_returning_normally(): void {
		$this->seed_rules( 'NG' );
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_destination_rules';
		$this->wpdb->fail_sql_containing    = [ 'ROLLBACK' ];
		$importer = $this->rules_importer();
		$returned = false;

		try {
			$this->repository->call_while_item_claimed(
				1,
				'owner',
				static function () use ( $importer ): array {
					return $importer->apply_item(
						'delivery_area_rules',
						[
							'zone_code'  => 'accra',
							'rule_type'  => 'country',
							'rule_value' => 'GH',
							'match_mode' => 'exact',
							'priority'   => 10,
						],
						ConfigImportConflictMode::Replace,
						false,
						false
					);
				}
			);
			$returned = true;
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Simulated insert failure', $exception->getMessage() );
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
			self::assertStringContainsString( 'ROLLBACK', $exception->getMessage() );
		}

		self::assertFalse( $returned, 'An unresolved joined rollback must not return the importer failure normally.' );

		self::assertNotContains( 'COMMIT', $this->wpdb->sql_log );
		self::assertSame( [ 'NG' ], $this->snapshot_rule_values() );
		self::assertSame( '', $this->rule_value() );
		$starts = $this->start_transaction_count();
		$sql_before = count( $this->wpdb->sql_log );
		try {
			$this->repository->save_item(
				BulkJobItem::pending( 1, 'delivery_area_rules', 1, 'accra' )->with(
					[
						'id'          => 1,
						'status'      => BulkJobItemStatus::Failed,
						'claim_token' => 'owner',
					]
				)
			);
			self::fail( 'A later item write must not enter an unresolved transaction.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
		}
		try {
			$this->repository->save_job( BulkJob::create( BulkOperationType::ConfigImport, 1, [], [] ) );
			self::fail( 'A later job write must not enter an unresolved transaction.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
		}
		try {
			$this->repository->release_job_claim( 1, 'owner' );
			self::fail( 'A later claim release must not enter an unresolved transaction.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
		}
		try {
			( new WpdbDestinationRuleRepository() )->replaceForZone(
				1,
				[
					[
						'rule_type'  => 'country',
						'rule_value' => 'GH',
						'match_mode' => 'exact',
						'priority'   => 10,
					],
				]
			);
			self::fail( 'A later rules replacement must not join an unresolved transaction.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
		}
		self::assertSame( $starts, $this->start_transaction_count() );
		self::assertNotContains( 'COMMIT', $this->wpdb->sql_log );
		self::assertSame( $sql_before, count( $this->wpdb->sql_log ) );
		self::assertSame( '', $this->rule_value() );
		self::assertSame( [ 'NG' ], $this->snapshot_rule_values() );
	}

	public function test_a_joined_insert_failure_with_a_successful_rollback_restores_the_old_rule(): void {
		$this->seed_rules( 'NG' );
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_destination_rules';
		$importer = $this->rules_importer();
		$result = $this->repository->call_while_item_claimed(
			1,
			'owner',
			static function () use ( $importer ): array {
				return $importer->apply_item(
					'delivery_area_rules',
					[
						'zone_code'  => 'accra',
						'rule_type'  => 'country',
						'rule_value' => 'GH',
						'match_mode' => 'exact',
						'priority'   => 10,
					],
					ConfigImportConflictMode::Replace,
					false,
					false
				);
			}
		);

		self::assertIsArray( $result );
		self::assertSame( 'failed', $result['outcome'] ?? null );
		self::assertSame( [], $this->snapshot_rule_values() );
		self::assertSame( 'NG', $this->rule_value() );
	}

	public function test_a_standalone_rule_replacement_commits_its_own_transaction(): void {
		$this->seed_rules( 'NG' );
		$saved = ( new WpdbDestinationRuleRepository() )->replaceForZone(
			1,
			[
				[
					'rule_type'  => 'country',
					'rule_value' => 'GH',
					'match_mode' => 'exact',
					'priority'   => 10,
				],
			]
		);

		self::assertTrue( $saved );
		self::assertContains( 'START TRANSACTION', $this->wpdb->sql_log );
		self::assertContains( 'COMMIT', $this->wpdb->sql_log );
		self::assertSame( 'GH', $this->rule_value() );
	}

	public function test_a_failed_lock_read_keeps_its_diagnostic_when_rollback_also_fails(): void {
		$this->wpdb->fail_sql_containing = [ 'FOR UPDATE', 'ROLLBACK' ];

		try {
			$this->repository->call_while_item_claimed( 1, 'owner', static fn (): bool => true );
			self::fail( 'Cleanup failure must preserve the locking-read diagnostic.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'FOR UPDATE', $exception->getMessage() );
			self::assertStringContainsString( 'Rollback also failed.', $exception->getMessage() );
		}
	}

	private function rules_importer(): ConfigurationImporter {
		return new ConfigurationImporter(
			new ArrayDeliveryOfferStore(),
			new WpdbDestinationZoneRepository(),
			new WpdbDestinationRuleRepository(),
			new ArrayRateCardStore(),
			new Cor006LogisticsStore(),
			new Cor006PickupStore(),
			new Cor006SupplierStore(),
			new Cor006OriginStore()
		);
	}

	private function seed_rules( string $rule_value ): void {
		$this->wpdb->create_table( 'wp_delivery_engine_destination_zones' );
		$this->wpdb->create_table( 'wp_delivery_engine_destination_rules' );
		$this->wpdb->insert(
			'wp_delivery_engine_destination_zones',
			[
				'id'            => 1,
				'internal_code' => 'accra',
				'internal_name' => 'Accra',
			]
		);
		$this->wpdb->insert(
			'wp_delivery_engine_destination_rules',
			[
				'id'         => 1,
				'zone_id'    => 1,
				'rule_type'  => 'country',
				'rule_value' => $rule_value,
			]
		);
	}

	private function start_transaction_count(): int {
		return count(
			array_filter(
				$this->wpdb->sql_log,
				static fn ( string $sql ): bool => str_contains( $sql, 'START TRANSACTION' )
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function snapshot_rule_values(): array {
		$rows = $this->wpdb->transaction_snapshot( 'wp_delivery_engine_destination_rules' );
		$values = [];
		foreach ( $rows as $row ) {
			$values[] = (string) ( $row['rule_value'] ?? '' );
		}

		return $values;
	}

	private function rule_value(): string {
		$row = $this->wpdb->get_row( 'SELECT * FROM `wp_delivery_engine_destination_rules` WHERE zone_id = 1', ARRAY_A );

		return is_array( $row ) ? (string) ( $row['rule_value'] ?? '' ) : '';
	}

	private function item_token(): ?string {
		$row = $this->wpdb->get_row( 'SELECT * FROM `wp_delivery_engine_bulk_job_items` WHERE id = 1', ARRAY_A );

		return is_array( $row ) ? (string) ( $row['claim_token'] ?? '' ) : null;
	}
}
