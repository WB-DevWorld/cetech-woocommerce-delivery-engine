<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofFactory;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$a = json_decode( $argv[1] ?? '', true, 12, JSON_THROW_ON_ERROR );
	if ( ! is_array( $a ) ) { throw new RuntimeException(); }
	$factory = new EmergencyControlProofFactory( $a['prefix'], (int) ( $a['site'] ?? 1 ) );
	$factory->configure = static function ( EmergencyControlProofTransport $transport ) use ( $a ): void {
		$control = static fn ( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && str_contains( $sql, 'FOR UPDATE' ) && str_contains( $sql, 'cetech_de_checkout_control_v1' );
		$transport->before = static function ( string $sql, EmergencyControlProofTransport $t ) use ( $a, $control ): void {
			if ( $control( $sql ) && isset( $a['lock_dispatch'] ) ) { OperationProofBarrier::signal( $a['lock_dispatch'] ); }
			if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'cetech_de_checkout_control_v1' ) && isset( $a['state_dispatch'] ) ) { OperationProofBarrier::signal( $a['state_dispatch'] ); }
		};
		$paused = false;
		$transport->after = static function ( string $sql, EmergencyControlProofTransport $t, $result ) use ( $a, $control, &$paused ): void {
			if ( ! $paused && $control( $sql ) && $result->acknowledged && isset( $a['lock_ready'], $a['lock_release'] ) ) {
				$paused = true; OperationProofBarrier::pause( $a['lock_ready'], $a['lock_release'] );
			}
		};
	};
	$service = new EmergencyControlService( $factory, static fn ( int $site, int $actor ): bool => 1 === $site && 9 === $actor, new EmergencyControlStore( static fn (): bool => false ), trusted_equivalent_route: true );
	if ( isset( $a['start_ready'], $a['start_release'] ) ) { OperationProofBarrier::pause( $a['start_ready'], $a['start_release'] ); }
	$began = microtime( true );
	if ( 'confirm' === $a['action'] ) {
		$r = $service->confirm_enabled( 1, (int) $a['revision'], static fn (): bool => true );
		$output = [ 'status' => $r->status, 'available' => $r->available, 'code' => $r->code, 'revision' => $r->state?->revision ];
	} elseif ( 'read' === $a['action'] ) {
		$r = $service->read( 1 ); $output = [ 'status' => $r->status, 'available' => $r->available, 'code' => $r->code, 'state' => $r->state?->state, 'revision' => $r->state?->revision ];
	} else {
		$id = EmergencyControlCommand::identity( 1, 9, $a['token'] ); $context = RequestContext::create();
		$r = 'reconcile' === $a['action'] ? $service->reconcile( $id, $a['payload'], $context ) : $service->transition( $id, $a['payload'], $context );
		$output = [ 'state' => $r->outcome->state, 'accepted' => $r->outcome->mutation_accepted, 'replayed' => $r->replayed, 'publication_pending' => $r->outcome->publication_pending, 'error_code' => $r->outcome->error?->code, 'result' => $r->completion?->result ];
	}
	$output += [ 'process_id' => getmypid(), 'elapsed_ms' => (int) round( ( microtime( true ) - $began ) * 1000 ), 'state_writes' => array_sum( array_column( $factory->transports, 'state_writes' ) ), 'audit_appends' => array_sum( array_column( $factory->transports, 'audit_appends' ) ), 'lock_timeouts' => array_sum( array_column( $factory->transports, 'lock_timeouts' ) ), 'deadlocks' => array_sum( array_column( $factory->transports, 'deadlocks' ) ) ];
	$factory->close_all(); echo json_encode( $output, JSON_THROW_ON_ERROR );
} catch ( Throwable $error ) { echo json_encode( [ 'proof_error' => 'worker_failed', 'error_class' => get_class( $error ) ], JSON_THROW_ON_ERROR ); exit( 1 ); }
