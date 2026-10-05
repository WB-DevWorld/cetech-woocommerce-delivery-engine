<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Real migration classes with isolated option-boundary doubles, not native WP proof.
 */
final class MigrationRunnerFailureTest extends TestCase {

	public function test_ordered_success_repeat_and_matching_noop_writes(): void {
		$result = $this->proof( 'success' );
		self::assertSame( '3', $result['first']['version'] );
		self::assertSame( [ 1, 1, 1 ], $result['first']['runs'] );
		self::assertSame( [ 'up:one', 'verify:one', 'up:two', 'verify:two', 'up:three', 'verify:three' ], $result['first']['trace'] );
		self::assertSame( $result['first']['trace'], $result['repeat']['trace'] );
		self::assertSame( '3', $result['noop_version'] );
		self::assertSame( 'fixed', $result['noop_status']['recorded_at'] );
		self::assertSame( 'success', $result['noop_status']['status'] );
	}

	public function test_middle_up_failure_stops_later_stage_and_repairs_in_order(): void {
		$result = $this->proof( 'up' );
		self::assertSame( [ 'up:one', 'verify:one', 'up:two' ], $result['first']['trace'] );
		$this->assert_unfinished_middle( $result );
	}

	public function test_middle_verification_failure_stops_later_stage_and_repairs_in_order(): void {
		$result = $this->proof( 'verify' );
		self::assertSame( [ 'up:one', 'verify:one', 'up:two', 'verify:two' ], $result['first']['trace'] );
		$this->assert_unfinished_middle( $result );
	}

	public function test_required_version_write_failure_stops_before_later_stage(): void {
		$result = $this->proof( 'version' );
		self::assertStringContainsString( 'Schema version could not be persisted', $result['first']['status']['error'] );
		$this->assert_unfinished_middle( $result );
	}

	public function test_failed_success_status_preserves_verified_version_and_reconciles_without_reapplying(): void {
		$result = $this->proof( 'status' );
		self::assertSame( '2', $result['first']['version'] );
		self::assertSame( 'failed', $result['first']['status']['status'] );
		self::assertSame( 'two', $result['first']['status']['migration_id'] );
		self::assertStringContainsString( 'Migration status could not be persisted', $result['first']['status']['error'] );
		$this->assert_status_recovery( $result );
	}

	public function test_failed_failure_status_is_independently_logged_and_stale_success_is_reconciled(): void {
		$result = $this->proof( 'status-all' );
		self::assertSame( '2', $result['first']['version'] );
		self::assertSame( 'one', $result['first']['status']['migration_id'] );
		$messages = array_column( $result['first']['logs'], 'message' );
		self::assertContains( 'Migration failed.', $messages );
		self::assertContains( 'Migration failure status could not be persisted.', $messages );
		$this->assert_status_recovery( $result );
	}

	public function test_duplicate_versions_and_identities_are_rejected_before_any_work(): void {
		foreach ( [ 'duplicate-version', 'duplicate-id' ] as $scenario ) {
			$result = $this->proof( $scenario );
			self::assertSame( '0', $result['first']['version'] );
			self::assertSame( [ 0, 0, 0 ], $result['first']['runs'] );
			self::assertSame( [], $result['first']['trace'] );
			self::assertSame( 'failed', $result['first']['status']['status'] );
			self::assertSame( [ 0, 0, 0 ], $result['repeat']['runs'] );
		}
	}

	public function test_present_invalid_or_throwing_discovery_file_rejects_whole_batch(): void {
		foreach ( [ 'discovery-invalid', 'discovery-throw' ] as $scenario ) {
			$result = $this->proof( $scenario );
			self::assertSame( '0', $result['first']['version'] );
			self::assertSame( [ 0, 0, 0 ], $result['first']['runs'] );
			self::assertSame( 'failed', $result['first']['status']['status'] );
			self::assertSame( '02.php', $result['first']['status']['file'] );
		}
		$valid = $this->proof( 'discovery-success' );
		self::assertSame( '3', $valid['first']['version'] );
		self::assertSame( [ 1, 1, 1 ], $valid['first']['runs'] );
	}

	/** @param array<string, mixed> $result */
	private function assert_unfinished_middle( array $result ): void {
		self::assertSame( '1', $result['first']['version'] );
		self::assertSame( 'failed', $result['first']['status']['status'] );
		self::assertSame( 'two', $result['first']['status']['migration_id'] );
		self::assertSame( [ 1, 1, 0 ], $result['first']['runs'] );
		self::assertSame( 'keep-me', $result['first']['data']['sentinel'] );
		self::assertSame( '3', $result['retry']['version'] );
		self::assertSame( [ 1, 2, 1 ], $result['retry']['runs'] );
		self::assertSame( 'keep-me', $result['retry']['data']['sentinel'] );
		self::assertSame( 'success', $result['retry']['status']['status'] );
		self::assertSame( 'three', $result['retry']['status']['migration_id'] );
		self::assertSame( $result['retry']['trace'], $result['repeat']['trace'] );
	}

	/** @param array<string, mixed> $result */
	private function assert_status_recovery( array $result ): void {
		self::assertSame( [ 1, 1, 0 ], $result['first']['runs'] );
		self::assertSame( '3', $result['retry']['version'] );
		self::assertSame( [ 1, 1, 1 ], $result['retry']['runs'] );
		self::assertSame( [ 1, 2, 1 ], $result['retry']['verifications'] );
		self::assertSame( 'success', $result['retry']['status']['status'] );
		self::assertSame( 'three', $result['retry']['status']['migration_id'] );
		self::assertSame( 'keep-me', $result['retry']['data']['sentinel'] );
		self::assertSame( $result['retry']['trace'], $result['repeat']['trace'] );
	}

	/** @return array<string, mixed> */
	private function proof( string $scenario ): array {
		$fixture = __DIR__ . '/fixtures/migration-runner-failure-proof.php';
		$output = [];
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1', $output, $code );
		self::assertSame( 0, $code, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );
		return $result;
	}
}
