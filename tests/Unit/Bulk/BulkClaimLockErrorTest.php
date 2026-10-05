<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
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

	private function item_token(): ?string {
		$row = $this->wpdb->get_row( 'SELECT * FROM `wp_delivery_engine_bulk_job_items` WHERE id = 1', ARRAY_A );

		return is_array( $row ) ? (string) ( $row['claim_token'] ?? '' ) : null;
	}
}
