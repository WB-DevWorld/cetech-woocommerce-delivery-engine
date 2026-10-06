<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofFactory;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofObserver;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProfile;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$arguments = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $arguments ) ) { throw new RuntimeException(); }
	$profile = new OperationProofProfile( $arguments['prefix'], (bool) ( $arguments['publication'] ?? false ), (string) ( $arguments['operation'] ?? 'fixture.counter_update' ), (int) ( $arguments['version'] ?? 1 ) );
	$factory = new OperationProofFactory( $arguments['prefix'], (int) ( $arguments['site'] ?? 1 ) );
	$identity = new OperationIdentity( (int) ( $arguments['site'] ?? 1 ), (string) ( $arguments['authority'] ?? 'wordpress' ), (string) ( $arguments['principal'] ?? 'staff:9' ), $profile->operation(), $profile->version(), (string) ( $arguments['target'] ?? 'counter:1' ), $arguments['token'] );
	$command = [ 'row_id' => 1, 'expected_revision' => (int) ( $arguments['revision'] ?? 1 ), 'value' => (int) ( $arguments['value'] ?? 7 ) ];
	$observer = isset( $arguments['phase'] ) ? new OperationProofObserver( $arguments['phase'], $arguments['phase_ready'], $arguments['phase_release'] ) : null;
	$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $factory, observer: $observer );
	if ( isset( $arguments['start_ready'], $arguments['start_release'] ) ) {
		OperationProofBarrier::pause( $arguments['start_ready'], $arguments['start_release'] );
	}
	$request = RequestContext::create();
	$result = 'reconcile' === ( $arguments['action'] ?? 'attempt' )
		? $coordinator->reconcile( $identity, $command, $request ) : $coordinator->attempt( $identity, $command, $request );
	echo json_encode( [
		'state' => $result->outcome->state, 'accepted' => $result->outcome->mutation_accepted,
		'publication_pending' => $result->outcome->publication_pending, 'replayed' => $result->replayed,
		'error_code' => $result->outcome->error?->code, 'result' => $result->completion?->result,
		'request_id' => $request->request_id,
		'lock_wait_timeouts' => array_sum( array_map( static fn( $transport ): int => $transport->lock_wait_timeouts, $factory->transports ) ),
		'deadlocks' => array_sum( array_map( static fn( $transport ): int => $transport->deadlocks, $factory->transports ) ),
	], JSON_THROW_ON_ERROR );
	$factory->close_all();
} catch ( Throwable $error ) {
	echo json_encode( [ 'proof_error' => 'worker_failed', 'error_class' => get_class( $error ) ], JSON_THROW_ON_ERROR );
	exit( 1 );
}
