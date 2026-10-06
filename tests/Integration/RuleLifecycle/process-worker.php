<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleReadService;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofObserver;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFactory;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofTransport;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$arguments = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $arguments ) ) { throw new RuntimeException(); }
	$factory = new RuleProofFactory( $arguments['prefix'], (int) ( $arguments['site'] ?? 1 ),
		isset( $arguments['commit_ready'] ) ? static function ( RuleProofTransport $transport, int $open ) use ( $arguments ): void {
			if ( 1 === $open ) { $transport->pause_commit = 2; $transport->commit_ready = $arguments['commit_ready']; $transport->commit_release = $arguments['commit_release']; }
		} : null,
		RuleTime::parse( $arguments['clock'] ?? '2026-10-06 10:00:00.000000' )
	);
	$family = new RuleProofFamily();
	$payload = $arguments['payload'];
	$identity = RuleProofEnvelope::identity( $payload, $arguments['operation'], $arguments['token'], (int) ( $arguments['site'] ?? 1 ), (string) ( $arguments['principal'] ?? 'staff:9' ), (string) ( $arguments['authority'] ?? 'wordpress' ) );
	$observer = isset( $arguments['phase'] ) ? new OperationProofObserver( $arguments['phase'], $arguments['phase_ready'], $arguments['phase_release'] ) : null;
	$service = new RuleLifecycleService( new RuleFamilyRegistry( [ $family ] ), $factory, observer: $observer );
	if ( isset( $arguments['start_ready'], $arguments['start_release'] ) ) { OperationProofBarrier::pause( $arguments['start_ready'], $arguments['start_release'] ); }
	$request = RequestContext::create();
	if ( 'reconstruct' === ( $arguments['action'] ?? '' ) ) {
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory );
		$original = $reader->activation_envelope( $identity, $family->family(), $payload['version_uuid'], $payload['scope'], $request );
		if ( ! is_array( $original ) ) { throw new RuntimeException(); }
		$command = RuleLifecycleCommand::from_payload( $original['identity'], $original['payload'], new RuleFamilyRegistry( [ $family ] ) );
		echo json_encode( [ 'state' => 'reconstructed', 'namespace_digest' => $original['identity']->namespace_digest(), 'intent_digest' => $command->intent()->fingerprint(), 'preconditions' => $original['payload']['preconditions'] ], JSON_THROW_ON_ERROR );
		$factory->close_all(); exit( 0 );
	}
	$result = 'reconcile' === ( $arguments['action'] ?? 'attempt' ) ? $service->reconcile( $identity, $payload, $request ) : $service->attempt( $identity, $payload, $request );
	echo json_encode( [ 'state' => $result->outcome->state, 'accepted' => $result->outcome->mutation_accepted, 'replayed' => $result->replayed,
		'error_code' => $result->outcome->error?->code, 'result' => $result->completion?->result, 'request_id' => $request->request_id,
		'deadlocks' => array_sum( array_map( static fn( $transport ): int => $transport->deadlocks, $factory->transports ) ),
		'lock_wait_timeouts' => array_sum( array_map( static fn( $transport ): int => $transport->lock_wait_timeouts, $factory->transports ) ),
	], JSON_THROW_ON_ERROR );
	$factory->close_all();
} catch ( Throwable $error ) {
	echo json_encode( [ 'proof_error' => 'worker_failed', 'error_class' => get_class( $error ) ], JSON_THROW_ON_ERROR );
	exit( 1 );
}
