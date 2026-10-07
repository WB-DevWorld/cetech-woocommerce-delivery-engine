<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofFactory;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$a = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $a ) ) { throw new RuntimeException(); }
	$factory = new DataLifecycleProofFactory( $a['prefix'], (int) ( $a['site'] ?? 1 ), (int) $a['clock'] );
	if ( isset( $a['phase'] ) || isset( $a['coordinator_dispatch_ready'] ) ) {
		$factory->configure = static function ( DataLifecycleProofTransport $transport ) use ( $a ): void {
			$pause = static function ( string $sql, bool $after ) use ( $a ): void {
				$delete = 1 === preg_match( '/\A\s*DELETE\s+FROM\s+`?[a-z0-9_]*options`?/i', $sql );
				$checkpoint = 1 === preg_match( '/\A\s*(?:UPDATE|INSERT\s+INTO)\s+`?[a-z0-9_]*options`?/i', $sql ) && str_contains( $sql, 'cetech_de_gc_state_geo_v1' );
				$commit = 1 === preg_match( '/\A\s*COMMIT\b/i', $sql );
				$phase = $delete ? 'delete' : ( $checkpoint ? 'checkpoint' : ( $commit ? 'commit' : '' ) );
				if ( isset( $a['phase'] ) && ( $after ? 'after_' : 'before_' ) . $phase === $a['phase'] ) { OperationProofBarrier::pause( $a['ready'], $a['release'] ); }
			};
			$coordinator = static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && str_contains( $sql, 'FOR UPDATE' ) && str_contains( $sql, 'cetech_de_gc_state_geo_v1' );
			$transport->before = static function ( string $sql ) use ( $a, $pause, $coordinator ): void { if ( isset( $a['coordinator_dispatch_ready'] ) && $coordinator( $sql ) ) { OperationProofBarrier::signal( $a['coordinator_dispatch_ready'] ); } $pause( $sql, false ); };
			$transport->after = static function ( string $sql, $unused, $result ) use ( $a, $pause, $coordinator ): void { if ( isset( $a['coordinator_response_ready'] ) && $coordinator( $sql ) ) { OperationProofBarrier::signal( $a['coordinator_response_ready'] ); } if ( $result->acknowledged ) { $pause( $sql, true ); } };
		};
	}
	$service = new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), $factory, static fn( int $site ): bool => 1 === $site, trusted_equivalent_route: true );
	if ( isset( $a['start_ready'] ) ) { OperationProofBarrier::pause( $a['start_ready'], $a['start_release'] ); }
	$c = isset( $a['continuation'] ) ? DataLifecycleContinuation::from_private_array( $a['continuation'] ) : null;
	$result = match ( $a['action'] ) { 'reconcile' => $service->reconcile( 1, $c ), 'batch' => $service->batch( 1, $c ), 'read' => $service->read( 1 ), default => throw new RuntimeException() };
	echo json_encode( [ 'status' => $result->status, 'progress' => $result->progress?->to_array(), 'continuation' => $result->continuation?->to_private_array(), 'publication_pending' => $result->publication_pending, 'error_code' => $result->error?->code, 'sent_commits' => array_sum( array_map( static fn( $t ): int => $t->sent_commits, $factory->transports ) ), 'deletes' => array_sum( array_map( static fn( $t ): int => $t->deletes, $factory->transports ) ) ], JSON_THROW_ON_ERROR );
	$factory->close_all();
} catch ( Throwable ) { echo '{"proof_error":"worker_failed"}'; exit( 1 ); }
