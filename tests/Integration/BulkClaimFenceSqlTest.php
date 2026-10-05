<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Physical MariaDB claim fencing. Excluded from the default suite.
 *
 * @group cor006-real-db
 */
final class BulkClaimFenceSqlTest extends TestCase {

	private \PDO $pdo;

	private WpdbBulkJobRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = $this->connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::markTestSkipped( 'Disposable COR-006 MariaDB is not reachable.' );
		}
		$database = (string) $wpdb->pdo()->query( 'SELECT DATABASE()' )->fetchColumn();
		if ( ! str_starts_with( $database, 'cetech_cor006_' ) ) {
			self::fail( 'Refusing to mutate a database outside the COR-006 disposable prefix.' );
		}
		$this->pdo = $wpdb->pdo();
		$GLOBALS['wpdb'] = $wpdb;
		foreach ( BulkJobSchema::create_table_statements( $wpdb->get_charset_collate(), 'cor006_delivery_engine_' ) as $statement ) {
			$this->pdo->exec( 'DROP TABLE IF EXISTS ' . $this->table_from_statement( $statement ) );
		}
		foreach ( BulkJobSchema::create_table_statements( $wpdb->get_charset_collate(), 'cor006_delivery_engine_' ) as $statement ) {
			$this->pdo->exec( $statement );
		}
		$this->pdo->exec( 'DROP TABLE IF EXISTS cor006_barrier' );
		$this->pdo->exec( 'CREATE TABLE cor006_barrier (worker_token varchar(64) NOT NULL PRIMARY KEY, phase int NOT NULL)' );
		$this->repository = new WpdbBulkJobRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_two_processes_cannot_both_claim_one_job(): void {
		$job = $this->repository->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 4, [ 'scope' => 'selected_ids' ], [] ) );
		$first_process  = $this->start( 'claim', (int) $job->id, 'process-a', true );
		$second_process = $this->start( 'claim', (int) $job->id, 'process-b', true );
		$first          = $this->finish( $first_process );
		$second         = $this->finish( $second_process );
		$tokens = array_filter( [ $first['token'], $second['token'] ] );

		self::assertNotSame( $first['pid'], $second['pid'] );
		self::assertNotSame( $first['connection_id'], $second['connection_id'] );
		self::assertCount( 1, $tokens );
		self::assertSame( array_values( $tokens )[0], $this->repository->find_job( (int) $job->id )->claim_token );
	}

	public function test_stale_process_cannot_save_over_a_renewed_claim_or_item(): void {
		$job = $this->repository->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 4, [ 'scope' => 'selected_ids' ], [] ) );
		$this->repository->insert_items(
			[
				BulkJobItem::pending( (int) $job->id, 'product', 11, 'SKU' )->with(
					[
						'status'      => BulkJobItemStatus::Claimed,
						'claim_token' => 'expired-item',
						'claimed_at'  => gmdate( 'Y-m-d H:i:s', time() - 1000 ),
					]
				),
			]
		);
		$owner = $this->repository->claim_job( (int) $job->id, 'current-owner', 300 );
		$owner = $this->repository->save_job( $owner->with_progress( 1, 1, 2, 1, 0, 0, 0, false, '1', [] ) );
		$stale = $this->spawn( 'stale-save', (int) $job->id, 'expired-owner', false );

		self::assertSame( 'Stale bulk claim.', $stale['error'] );
		self::assertSame( 2, $this->repository->find_job( (int) $job->id )->processed_count );
		self::assertSame( 'current-owner', $this->repository->find_job( (int) $job->id )->claim_token );

		$item = $this->spawn( 'item-claim', (int) $job->id, 'item-owner', false );
		self::assertSame( 'item-owner', $item['token'] );
		self::assertSame( 1, $item['count'] );
		$again = $this->spawn( 'item-claim', (int) $job->id, 'item-loser', false );
		self::assertNull( $again['token'] );
		self::assertSame( 'item-owner', $this->repository->list_items( (int) $job->id, 10 )[0]->claim_token );
		unset( $owner );
	}

	public function test_duplicate_item_insert_and_counter_failure_stay_explicit(): void {
		$job = $this->repository->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 4, [ 'scope' => 'selected_ids' ], [] ) );
		$item = BulkJobItem::pending( (int) $job->id, 'product', 21, 'ONCE' );
		$first = $this->repository->save_item( $item );
		$second = $this->repository->save_item( $item );
		self::assertSame( $first->id, $second->id );
		self::assertSame( 1, $this->repository->count_items( (int) $job->id ) );

		$claimed = $this->repository->claim_job( (int) $job->id, 'counter-owner', 300 );
		$unchanged = $this->repository->save_job( $claimed );
		self::assertSame( 0, $unchanged->processed_count );
		$this->pdo->exec( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" );
		$this->pdo->exec( 'CREATE TRIGGER cor006_fail_counter BEFORE UPDATE ON cor006_delivery_engine_bulk_jobs FOR EACH ROW SET NEW.processed_count = IF(@cor006_fail_counter = 1 AND NEW.processed_count <> OLD.processed_count, -1, NEW.processed_count)' );
		$this->pdo->exec( 'SET @cor006_fail_counter = 1' );
		try {
			$this->repository->save_job( $claimed->with_progress( 1, 1, 4, 1, 0, 0, 0, true, '1', [] ) );
			self::fail( 'A rejected counter write must be explicit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Bulk job write failed.', $exception->getMessage() );
		}
		self::assertSame( 0, $this->repository->find_job( (int) $job->id )->processed_count );
		$this->pdo->exec( 'SET @cor006_fail_counter = 0' );
		$saved = $this->repository->save_job( $claimed->with_progress( 1, 1, 4, 1, 0, 0, 0, true, '1', [] ) );
		self::assertSame( 4, $saved->processed_count );
		self::assertSame( 4, $this->repository->find_job( (int) $job->id )->processed_count );
	}

	public function test_release_failure_remains_held_and_a_lost_token_does_not(): void {
		$job = $this->repository->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 4, [ 'scope' => 'selected_ids' ], [] ) );
		$this->repository->claim_job( (int) $job->id, 'releaser', 300 );
		self::assertFalse( $this->repository->release_job_claim( (int) $job->id, 'someone-else' ) );
		self::assertSame( 'releaser', $this->repository->find_job( (int) $job->id )->claim_token );

		$this->pdo->exec( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" );
		$this->pdo->exec( 'CREATE TRIGGER cor006_fail_release BEFORE UPDATE ON cor006_delivery_engine_bulk_jobs FOR EACH ROW SET NEW.processed_count = IF(@cor006_fail_release = 1 AND NEW.claim_token IS NULL AND OLD.claim_token IS NOT NULL, -1, NEW.processed_count)' );
		$this->pdo->exec( 'SET @cor006_fail_release = 1' );
		try {
			$this->repository->release_job_claim( (int) $job->id, 'releaser' );
			self::fail( 'A rejected release must be explicit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Bulk job write failed.', $exception->getMessage() );
		}
		self::assertSame( 'releaser', $this->repository->find_job( (int) $job->id )->claim_token );
		$this->pdo->exec( 'SET @cor006_fail_release = 0' );
		self::assertTrue( $this->repository->release_job_claim( (int) $job->id, 'releaser' ) );
		self::assertNull( $this->repository->find_job( (int) $job->id )->claim_token );
	}

	/**
	 * @return array{process: resource, result: string, output: string}
	 */
	private function start( string $mode, int $job_id, string $token, bool $barrier ): array {
		$result = tempnam( sys_get_temp_dir(), 'cor006' );
		$output = tempnam( sys_get_temp_dir(), 'cor006out' );
		$env    = [
			'CETECH_DE_COR006_MODE'    => $mode,
			'CETECH_DE_COR006_RESULT'  => (string) $result,
			'CETECH_DE_COR006_JOB_ID'  => (string) $job_id,
			'CETECH_DE_COR006_TOKEN'   => $token,
			'CETECH_DE_COR006_BARRIER' => $barrier ? '1' : '0',
			'CETECH_DE_COR006_DB_PORT' => (string) ( getenv( 'CETECH_DE_COR006_DB_PORT' ) ?: 33079 ),
			'SystemRoot'               => (string) getenv( 'SystemRoot' ),
		];
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/Integration/cor006-claim-worker.php' );
		$process = proc_open( $command, [ 1 => [ 'file', (string) $output, 'w' ], 2 => [ 'file', (string) $output, 'a' ] ], $pipes, dirname( __DIR__, 2 ), $env );
		self::assertIsResource( $process );

		return [
			'process' => $process,
			'result'  => (string) $result,
			'output'  => (string) $output,
		];
	}

	/**
	 * @param array{process: resource, result: string, output: string} $started
	 * @return array<string, mixed>
	 */
	private function finish( array $started ): array {
		$code = proc_close( $started['process'] );
		$raw  = (string) file_get_contents( $started['result'] );
		$log  = (string) file_get_contents( $started['output'] );
		@unlink( $started['result'] );
		@unlink( $started['output'] );
		self::assertSame( 0, $code, $log );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function spawn( string $mode, int $job_id, string $token, bool $barrier ): array {
		return $this->finish( $this->start( $mode, $job_id, $token, $barrier ) );
	}

	private function connect(): ?RealMysqliWpdb {
		if ( ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			return null;
		}
		$host = (string) ( getenv( 'CETECH_DE_COR006_DB_HOST' ) ?: '127.0.0.1' );
		$port = (int) ( getenv( 'CETECH_DE_COR006_DB_PORT' ) ?: 33079 );
		$user = (string) ( getenv( 'CETECH_DE_COR006_DB_USER' ) ?: 'root' );
		$pass = (string) ( getenv( 'CETECH_DE_COR006_DB_PASSWORD' ) ?: 'cetech-cor004' );
		$name = (string) ( getenv( 'CETECH_DE_COR006_DB_NAME' ) ?: 'cetech_cor006_worker' );
		try {
			$server = new \PDO( "mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
			$server->exec( 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', $name ) . '`' );
			$pdo = new \PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC ] );
		} catch ( \PDOException ) {
			return null;
		}

		return new RealMysqliWpdb( $pdo, 'cor006_' );
	}

	private function table_from_statement( string $statement ): string {
		if ( 1 !== preg_match( '/CREATE TABLE\s+(\S+)/', $statement, $matches ) ) {
			self::fail( 'Could not identify a bulk table.' );
		}

		return $matches[1];
	}
}
