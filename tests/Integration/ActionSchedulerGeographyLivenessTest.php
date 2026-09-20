<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;
use CetechDeliveryEngine\Tests\Support\ActionSchedulerUniqueStore;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Source-faithful Action Scheduler 3.9.2 uniqueness-contract reproduction
 * plus unattended geography tick lifecycle.
 *
 * Unique matching is the Action Scheduler 3.9.2 DBStore rule:
 * hook + group against pending and in-progress; arguments are ignored.
 * SQLite reproduces the unique INSERT ... WHERE NOT EXISTS query where
 * PDO sqlite is available. UniqueStore then runs worker A → successor B → C
 * until Ready. This is not a full production Action Scheduler package
 * end-to-end test.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 */
final class ActionSchedulerGeographyLivenessTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_action_scheduler_unique_predicate_matches_hook_group_pending_and_in_progress(): void {
		$rows = [
			[
				'action_id' => 1889,
				'hook'      => GeographyPackService::HOOK,
				'status'    => 'in-progress',
				'group_id'  => 1,
				'args'      => '{"pack_id":1}',
			],
		];
		self::assertSame( 1889, $this->unique_existing_id( $rows, GeographyPackService::HOOK, 1 ) );
		self::assertSame( 1889, $this->unique_existing_id( $rows, GeographyPackService::HOOK, 1, '{"pack_id":2}' ), 'unique matching ignores arguments' );
		self::assertSame( 0, $this->unique_existing_id( $rows, GeographyPackService::DOWNLOAD_HOOK, 1 ) );
		$rows[0]['status'] = 'complete';
		self::assertSame( 0, $this->unique_existing_id( $rows, GeographyPackService::HOOK, 1 ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sqlite_unique_insert_treats_in_progress_as_existing_unique_action(): void {
		if ( ! class_exists( \PDO::class ) || ! in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) ) {
			self::markTestSkipped( 'PDO sqlite is not available on this PHP build; UniqueStore + product helper tests still reproduce Action Scheduler unique semantics.' );
		}
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$pdo->exec(
			'CREATE TABLE actions (
				action_id INTEGER PRIMARY KEY AUTOINCREMENT,
				hook TEXT NOT NULL,
				status TEXT NOT NULL,
				group_id INTEGER NOT NULL,
				args TEXT
			)'
		);
		$pdo->exec(
			"INSERT INTO actions (hook, status, group_id, args)
			 VALUES ('cetech_de_geography_pack_tick', 'in-progress', 1, '{\"pack_id\":1}')"
		);
		$running_id = (int) $pdo->lastInsertId();
		self::assertGreaterThan( 0, $running_id );

		// Action Scheduler 3.9.2 build_where_clause_for_insert unique branch.
		$pdo->exec(
			"INSERT INTO actions (hook, status, group_id, args)
			 SELECT 'cetech_de_geography_pack_tick', 'pending', 1, '{\"pack_id\":1}'
			 WHERE NOT EXISTS (
				SELECT action_id FROM actions
				WHERE status IN ('pending', 'in-progress')
				AND hook = 'cetech_de_geography_pack_tick'
				AND group_id = 1
				LIMIT 1
			 )"
		);
		self::assertSame(
			1,
			(int) $pdo->query( 'SELECT COUNT(*) FROM actions' )->fetchColumn(),
			'unique=true must not create a successor while the current action is in-progress.'
		);

		$pdo->exec(
			"INSERT INTO actions (hook, status, group_id, args)
			 VALUES ('cetech_de_geography_pack_tick', 'pending', 1, '{\"pack_id\":1}')"
		);
		self::assertSame( 2, (int) $pdo->query( 'SELECT COUNT(*) FROM actions' )->fetchColumn() );

		$pdo->exec( 'UPDATE actions SET status = \'complete\' WHERE action_id = ' . $running_id );
		self::assertSame(
			1,
			(int) $pdo->query( "SELECT COUNT(*) FROM actions WHERE status = 'pending'" )->fetchColumn()
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_product_helper_successor_survives_current_action_completion(): void {
		require_once dirname( __DIR__ ) . '/Support/action-scheduler-test-functions.php';
		$store = ActionSchedulerUniqueStore::install();
		$hook  = GeographyPackService::HOOK;
		$group = GeographyPackService::GROUP . '-33';
		$args  = [
			'pack_id'          => 33,
			'source_path'      => '/tmp/GH.txt',
			'generation_token' => 'geonames:GH:1:token',
		];
		$running = $store->enqueue_async( $hook, $args, $group, false );
		$store->mark_running( $running );
		self::assertSame( $running, as_enqueue_async_action( $hook, $args, $group, true ) );

		$ok = ActionSchedulerReadiness::enqueue_unique_async( $hook, $args, $group );
		self::assertTrue( $ok );
		$successor = $store->last_id();
		self::assertNotSame( $running, $successor );
		$store->complete( $running );
		self::assertSame( 1, $store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( $successor, $store->claim_next_pending( $hook, $group ) );
		ActionSchedulerUniqueStore::uninstall();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unattended_worker_chain_reaches_ready_across_many_batches(): void {
		require_once dirname( __DIR__ ) . '/Support/action-scheduler-test-functions.php';
		$store   = ActionSchedulerUniqueStore::install();
		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter(
				$geo->locations,
				$geo->locations,
				$geo->locations,
				$packs,
				new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ),
				new GeoNamesGazetteerParser()
			),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$lines = [ $this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ) ];
		for ( $i = 1; $i <= 24; ++$i ) {
			$code    = str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$lines[] = $this->row( (string) ( 1000 + $i ), 'Region ' . $code, 'Region ' . $code, 'A', 'ADM1', $code );
			$lines[] = $this->row( (string) ( 2000 + $i ), 'Town ' . $code, 'Town ' . $code, 'P', 'PPL', $code );
		}
		$file = tempnam( sys_get_temp_dir(), 'geoliveas' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		$importer = new GeoNamesPackImporter(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ),
			new GeoNamesGazetteerParser()
		);
		$pack = $importer->begin_dataset(
			$packs->save(
				[
					'country_code'     => 'GH',
					'provider'         => GeographyProvider::GeoNames->value,
					'dataset_name'     => 'gazetteer',
					'source_reference' => $file,
					'checksum'         => hash_file( 'sha256', $file ) ?: 'sum',
					'status'           => GeographyPackStatus::Pending->value,
				]
			),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo-live.as'
		);
		$service->retry( $pack->id, $file );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		$ticks = 0;
		$status = GeographyPackStatus::Importing->value;
		for ( $i = 0; $i < 120; ++$i ) {
			$id = $store->claim_next_pending( GeographyPackService::HOOK, $group );
			self::assertGreaterThan( 0, $id, 'Importing pack lost its continuation after tick ' . $ticks . '.' );
			$fresh  = $service->find( $pack->id );
			$token  = $fresh instanceof \CetechDeliveryEngine\Domain\Geography\GeographyPack ? $fresh->target_token() : '';
			$result = $service->tick( $pack->id, $file, 4, $token );
			$store->complete( $id );
			$service->on_scheduler_after_execute( $id, $store->fetch_action( $id ) );
			++$ticks;
			$status = (string) ( $result['status'] ?? '' );
			if ( GeographyPackStatus::Ready->value === $status || GeographyPackStatus::Failed->value === $status ) {
				break;
			}
		}
		self::assertSame( GeographyPackStatus::Ready->value, $status );
		self::assertGreaterThan( 8, $ticks, 'GH-scale fixture must cross many Action Scheduler batches.' );
		self::assertSame( 0, $store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$ready = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Ready, $ready?->status );
		self::assertSame( $file, $ready?->source_reference );
		unlink( $file );
		ActionSchedulerUniqueStore::uninstall();
	}

	private function row( string $id, string $name, string $ascii, string $class, string $code, string $admin1, string $admin2 = '', string $country = 'GH' ): string {
		$parts     = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = $country;
		$parts[10] = $admin1;
		$parts[11] = $admin2;

		return implode( "\t", $parts );
	}

	/**
	 * Action Scheduler 3.9.2 unique INSERT predicate: pending or in-progress,
	 * same hook, same group_id. Arguments are not compared.
	 *
	 * @param list<array{action_id: int, hook: string, status: string, group_id: int, args: string}> $rows
	 */
	private function unique_existing_id( array $rows, string $hook, int $group_id, string $args = '' ): int {
		unset( $args );
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['status'], [ 'pending', 'in-progress' ], true ) ) {
				continue;
			}
			if ( $row['hook'] === $hook && $row['group_id'] === $group_id ) {
				return (int) $row['action_id'];
			}
		}

		return 0;
	}
}
