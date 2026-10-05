<?php

declare(strict_types=1);

use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';

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
	}
} catch ( Throwable $exception ) {
	$outcome['error'] = $exception->getMessage();
}
file_put_contents( $result, json_encode( $outcome ) );
