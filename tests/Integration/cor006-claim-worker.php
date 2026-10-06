<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Tests\Support\RateCardActiveListingTrait;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;

use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Support\Cor006LogisticsStore;
use CetechDeliveryEngine\Tests\Support\Cor006OriginStore;
use CetechDeliveryEngine\Tests\Support\Cor006PickupStore;
use CetechDeliveryEngine\Tests\Support\Cor006SupplierStore;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require dirname( __DIR__, 2 ) . '/tests/Support/Cor006WorkerProofStores.php';
require dirname( __DIR__, 2 ) . '/tests/Support/Cor006ImportStubs.php';

$mode   = (string) ( getenv( 'CETECH_DE_COR006_MODE' ) ?: 'claim' );
$result = (string) getenv( 'CETECH_DE_COR006_RESULT' );
$job_id = (int) getenv( 'CETECH_DE_COR006_JOB_ID' );
$token  = (string) getenv( 'CETECH_DE_COR006_TOKEN' );
$host   = (string) ( getenv( 'CETECH_DE_COR006_DB_HOST' ) ?: '127.0.0.1' );
$port   = (int) ( getenv( 'CETECH_DE_COR006_DB_PORT' ) ?: 33079 );
$user   = (string) ( getenv( 'CETECH_DE_COR006_DB_USER' ) ?: 'root' );
$pass   = (string) ( getenv( 'CETECH_DE_COR006_DB_PASSWORD' ) ?: 'cetech-cor004' );
$name   = (string) ( getenv( 'CETECH_DE_COR006_DB_NAME' ) ?: 'cetech_cor006_worker' );

$pdo = new PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
if ( ! str_starts_with( (string) $pdo->query( 'SELECT DATABASE()' )->fetchColumn(), 'cetech_cor006_' ) ) {
	fwrite( STDERR, "refusing database\n" );
	exit( 2 );
}
$wpdb = new RealMysqliWpdb( $pdo, 'cor006_' );
$GLOBALS['wpdb'] = $wpdb;
$repository    = new WpdbBulkJobRepository();
$connection_id = (int) $pdo->query( 'SELECT CONNECTION_ID()' )->fetchColumn();
if ( '1' === getenv( 'CETECH_DE_COR006_BARRIER' ) ) {
	$pdo->prepare( 'INSERT INTO cor006_barrier (worker_token, phase) VALUES (?, 1)' )->execute( [ $token ] );
	$deadline = microtime( true ) + 8;
	do {
		$arrived = (int) $pdo->query( 'SELECT COUNT(*) FROM cor006_barrier WHERE phase >= 1' )->fetchColumn();
		if ( $arrived >= 2 ) {
			break;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
}

$outcome = [ 'pid' => getmypid(), 'connection_id' => $connection_id, 'mode' => $mode, 'error' => null, 'token' => null ];
try {
	if ( 'claim' === $mode ) {
		$claimed = $repository->claim_job( $job_id, $token, 300 );
		$outcome['token'] = $claimed?->claim_token;
	} elseif ( 'stale-save' === $mode ) {
		$current = $repository->find_job( $job_id );
		$stale   = $current->with_claim( $token, gmdate( 'Y-m-d H:i:s', time() - 1000 ) )->with_progress( 3, 3, 9, 4, 0, 0, 0, true, '3', [] );
		$repository->save_job( $stale );
		$outcome['error'] = 'saved';
	} elseif ( 'item-claim' === $mode ) {
		$claimed = $repository->claim_items( $job_id, 1, $token, 30 );
		$outcome['token'] = $claimed[0]->claim_token ?? null;
		$outcome['count'] = count( $claimed );
	} elseif ( str_starts_with( $mode, 'worker-' ) ) {
		$outcome = array_merge( $outcome, cor006_run_worker_mode( $mode, $pdo, $repository, $job_id ) );
	}
} catch ( Throwable $exception ) {
	$outcome['error'] = $exception->getMessage();
}
file_put_contents( $result, json_encode( $outcome ) );

/**
 * @return array<string, mixed>
 */
function cor006_run_worker_mode( string $mode, PDO $pdo, WpdbBulkJobRepository $repository, int $job_id ): array {
	if ( str_starts_with( $mode, 'worker-import' ) ) {
		return cor006_run_import_mode( $mode, $pdo, $job_id );
	}
	$gate = cor006_gate_connection();
	$started = microtime( true );
	$worker  = cor006_worker( $pdo );
	$phase   = (string) getenv( 'CETECH_DE_COR006_PHASE' );
	if ( 'worker-owner' === $mode ) {
		$worker->set_qualification_boundary(
			static function ( string $current ) use ( $gate, $phase, $job_id ): void {
				if ( $current !== $phase ) {
					return;
				}
				$hash = (string) $gate->query( 'SELECT SHA2(claim_token, 256) FROM cor006_delivery_engine_bulk_jobs WHERE id = ' . (int) $job_id )->fetchColumn();
				$update = $gate->prepare( "UPDATE cor006_worker_gate SET state = 1, note = ? WHERE name = 'a_paused'" );
				$update->execute( [ $hash ] );
				if ( 'inside_source' === $phase ) {
					sleep( 3 );

					return;
				}
				$deadline = microtime( true ) + 15;
				do {
					$done = (int) $gate->query( "SELECT state FROM cor006_worker_gate WHERE name = 'b_finished'" )->fetchColumn();
					if ( 1 === $done ) {
						return;
					}
					usleep( 50000 );
				} while ( microtime( true ) < $deadline );
				throw new RuntimeException( 'Takeover barrier timed out.' );
			}
		);
		$worker->tick( $job_id );
	} elseif ( 'worker-successor' === $mode ) {
		cor006_wait_gate( $gate, 'a_paused' );
		$past = gmdate( 'Y-m-d H:i:s', time() - 1000 );
		$pdo->prepare( 'UPDATE cor006_delivery_engine_bulk_jobs SET claimed_at = ? WHERE id = ?' )->execute( [ $past, $job_id ] );
		$pdo->prepare( 'UPDATE cor006_delivery_engine_bulk_job_items SET claimed_at = ? WHERE job_id = ? AND status = ?' )->execute( [ $past, $job_id, 'claimed' ] );
		$worker->set_qualification_boundary(
			static function ( string $current ) use ( $gate, $job_id ): void {
				if ( 'before_aggregate' !== $current ) {
					return;
				}
				$hash = (string) $gate->query( 'SELECT SHA2(claim_token, 256) FROM cor006_delivery_engine_bulk_jobs WHERE id = ' . (int) $job_id )->fetchColumn();
				$update = $gate->prepare( "UPDATE cor006_worker_gate SET note = ? WHERE name = 'b_finished'" );
				$update->execute( [ $hash ] );
			}
		);
		$worker->tick( $job_id );
		$gate->exec( "UPDATE cor006_worker_gate SET state = 1 WHERE name = 'b_finished'" );
	} elseif ( 'worker-contender' === $mode ) {
		cor006_wait_gate( $gate, 'a_paused' );
		$pdo->exec( 'SET innodb_lock_wait_timeout = 1' );
		$item_id = (int) getenv( 'CETECH_DE_COR006_ITEM_ID' );
		$wrote   = false;
		try {
			$repository->call_while_item_claimed(
				$item_id,
				'contender-not-owner',
				static function () use ( &$wrote, $pdo ): null {
					$wrote = true;
					$pdo->exec( "UPDATE cor006_source_amount SET base_amount = '999.0000' WHERE id = 1" );

					return null;
				}
			);
		} catch ( Throwable $exception ) {
			return [
				'error'    => $exception->getMessage(),
				'elapsed'  => microtime( true ) - $started,
				'wrote'    => $wrote,
				'amount'   => (string) $pdo->query( 'SELECT base_amount FROM cor006_source_amount WHERE id = 1' )->fetchColumn(),
			];
		}

		return [
			'error'   => null,
			'elapsed' => microtime( true ) - $started,
			'wrote'   => $wrote,
			'amount'  => (string) $pdo->query( 'SELECT base_amount FROM cor006_source_amount WHERE id = 1' )->fetchColumn(),
		];
	}

	return [
		'error'   => null,
		'elapsed' => microtime( true ) - $started,
		'amount'  => (string) $pdo->query( 'SELECT base_amount FROM cor006_source_amount WHERE id = 1' )->fetchColumn(),
	];
}

function cor006_wait_gate( PDO $gate, string $name ): void {
	$deadline = microtime( true ) + 15;
	do {
		$state = (int) $gate->query( 'SELECT state FROM cor006_worker_gate WHERE name = ' . $gate->quote( $name ) )->fetchColumn();
		if ( 1 === $state ) {
			return;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Takeover barrier timed out.' );
}

function cor006_gate_connection(): PDO {
	$host = (string) ( getenv( 'CETECH_DE_COR006_DB_HOST' ) ?: '127.0.0.1' );
	$port = (int) ( getenv( 'CETECH_DE_COR006_DB_PORT' ) ?: 33079 );
	$user = (string) ( getenv( 'CETECH_DE_COR006_DB_USER' ) ?: 'root' );
	$pass = (string) ( getenv( 'CETECH_DE_COR006_DB_PASSWORD' ) ?: 'cetech-cor004' );
	$name = (string) ( getenv( 'CETECH_DE_COR006_DB_NAME' ) ?: 'cetech_cor006_worker' );

	return new PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
}

/**
 * @return array<string, mixed>
 */
function cor006_run_import_mode( string $mode, PDO $pdo, int $job_id ): array {
	$gate    = cor006_gate_connection();
	$started = microtime( true );
	if ( 'worker-import' === $mode ) {
		$worker = cor006_import_worker();
		AbstractWpdbRepository::set_qualification_probe(
			static function ( string $phase ) use ( $gate ): void {
				if ( 'after_transaction_open' !== $phase ) {
					return;
				}
				$gate->exec( "UPDATE cor006_worker_gate SET state = 1 WHERE name = 'a_paused'" );
				sleep( 3 );
			}
		);
		$worker->tick( $job_id );

		return [
			'error'   => null,
			'elapsed' => microtime( true ) - $started,
		];
	}

	cor006_wait_gate( $gate, 'a_paused' );
	$pdo->exec( 'SET innodb_lock_wait_timeout = 1' );
	$item_id = (int) getenv( 'CETECH_DE_COR006_ITEM_ID' );
	try {
		$pdo->prepare( 'UPDATE cor006_delivery_engine_bulk_job_items SET claimed_at = ? WHERE id = ?' )->execute( [ '2000-01-01 00:00:00', $item_id ] );
	} catch ( Throwable $exception ) {
		return [
			'error'   => $exception->getMessage(),
			'elapsed' => microtime( true ) - $started,
		];
	}

	return [
		'error'   => null,
		'elapsed' => microtime( true ) - $started,
	];
}

function cor006_import_worker(): BulkJobWorker {
	$offers = new Cor006OfferStore();
	$mutator = new CatalogScopeMutator(
		new InMemoryScopedConfigurationRepository(),
		new EffectiveConfigurationValidator(),
		new HardFulfilmentConstraintService( $offers ),
		new SiteWideDefaultsSettings()
	);

	return new BulkJobWorker(
		new WpdbBulkJobRepository(),
		new InMemoryCatalogTargetQuery(),
		$mutator,
		new InMemoryBoundedQueue(),
		30,
		null,
		new ConfigurationImporter(
			$offers,
			new WpdbDestinationZoneRepository(),
			new WpdbDestinationRuleRepository(),
			new Cor006ObservableRateStore( $GLOBALS['wpdb']->pdo() ),
			new Cor006LogisticsStore(),
			new Cor006PickupStore(),
			new Cor006SupplierStore(),
			new Cor006OriginStore()
		)
	);
}

function cor006_worker( PDO $pdo ): BulkJobWorker {
	$offers = new Cor006OfferStore();
	$mutator = new CatalogScopeMutator(
		new InMemoryScopedConfigurationRepository(),
		new EffectiveConfigurationValidator(),
		new HardFulfilmentConstraintService( $offers ),
		new SiteWideDefaultsSettings()
	);

	return new BulkJobWorker(
		new WpdbBulkJobRepository(),
		new InMemoryCatalogTargetQuery(),
		$mutator,
		new InMemoryBoundedQueue(),
		30,
		new RateCardBulkMutator( new Cor006ObservableRateStore( $pdo ), $offers )
	);
}

