<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofFactory;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$a = json_decode( $argv[1] ?? '', true, 12, JSON_THROW_ON_ERROR );
	if ( ! is_array( $a ) ) { throw new RuntimeException(); }
	$factory = new EmergencyControlProofFactory( $a['prefix'], (int) ( $a['site'] ?? 1 ) );
	$factory->configure = static function ( EmergencyControlProofTransport $transport ) use ( $a ): void {
		$control = static fn ( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && str_contains( $sql, 'FOR UPDATE' ) && str_contains( $sql, 'cetech_de_checkout_control_v1' );
		$signal_connection = static function ( string $path, EmergencyControlProofTransport $t ): void {
			$bytes = json_encode( [ 'connection_id' => $t->connection_id(), 'process_id' => getmypid() ], JSON_THROW_ON_ERROR );
			if ( false === file_put_contents( $path . '.pending', $bytes, LOCK_EX ) || ! rename( $path . '.pending', $path ) ) { throw new RuntimeException( 'Proof connection signal failed.' ); }
		};
		$transport->before = static function ( string $sql, EmergencyControlProofTransport $t ) use ( $a, $control, $signal_connection ): void {
			if ( $control( $sql ) && isset( $a['lock_dispatch'] ) ) {
				$signal_connection( $a['lock_dispatch'], $t );
				if ( isset( $a['dispatch_release'] ) ) { OperationProofProcess::wait_for( $a['dispatch_release'], 12.0 ); }
			}
			if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'cetech_de_checkout_control_v1' ) && isset( $a['state_dispatch'] ) ) { $signal_connection( $a['state_dispatch'], $t ); }
		};
		$paused = false;
		$transport->after = static function ( string $sql, EmergencyControlProofTransport $t, $result ) use ( $a, $control, $signal_connection, &$paused ): void {
			if ( ! $paused && $control( $sql ) && $result->acknowledged && isset( $a['lock_ready'], $a['lock_release'] ) ) {
				$paused = true; $signal_connection( $a['lock_ready'], $t );
				OperationProofProcess::wait_for( $a['lock_release'], 12.0 );
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
