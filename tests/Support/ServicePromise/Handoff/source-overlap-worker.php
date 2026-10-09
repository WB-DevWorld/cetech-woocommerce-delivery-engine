<?php
declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/bootstrap.php';

use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase, DataLifecycleProofFactory};
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;

$facts = json_decode( $argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR ); DataLifecycleProofDatabase::validate_prefix( $facts['prefix'] ); $factory = new DataLifecycleProofFactory( $facts['prefix'] );
$factory->configure = static function( $transport ) use ( $facts ): void { $transport->before = static function( string $sql ) use ( $facts ): void { if ( str_ends_with( $sql, 'FOR UPDATE' ) && ( str_contains( $sql, 'delivery_engine_promise_objects`' ) || str_contains( $sql, 'delivery_engine_promise_assignments`' ) ) ) { OperationProofBarrier::signal( $facts['material_started'] ); } }; };
try {
	$assignment = 'promise.assignment.set' === $facts['operation']; $service = $assignment ? new PromiseAssignmentService( F::binding(), $factory, F::authority() ) : new PromiseVersionLifecycleService( F::binding(), $factory, F::authority() ); $identity = $assignment ? F::assignment_identity( $facts['payload'], 'p04-concurrent-source' ) : F::version_identity( $facts['operation'], $facts['payload'], 'p04-concurrent-source' ); $result = $service->attempt( $identity, $facts['payload'], RequestContext::create() ); $retired = true; foreach ( $factory->sessions as $session ) { $retired = $retired && $session->is_retired(); }
	echo json_encode( [ 'state' => $result->outcome->state, 'code' => $result->outcome->error?->code, 'owners' => count( $factory->sessions ), 'all_retired' => $retired ], JSON_THROW_ON_ERROR ), "\n";
} finally { $factory->close_all(); }
