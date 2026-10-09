<?php
declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase, DataLifecycleProofFactory};
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;

$facts = json_decode( $argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR ); DataLifecycleProofDatabase::validate_prefix( $facts['prefix'] );
$factory = new DataLifecycleProofFactory( $facts['prefix'] );
if ( isset( $facts['reserved'] ) ) { $factory->configure = static function( $transport ) use ( $facts ): void { $transport->before = static function( string $sql ) use ( $facts ): void { if ( str_contains( $sql, 'FOR UPDATE' ) && ( str_contains( $sql, 'delivery_engine_promise_objects`' ) || str_contains( $sql, 'delivery_engine_promise_assignments`' ) ) ) { OperationProofBarrier::signal( $facts['reserved'] ); } }; }; }
$observer = new class( $facts ) implements OperationPhaseObserver {
	public function __construct( private array $facts ) {}
	public function observe( string $phase, OperationIdentity $identity ): void {
		if ( 'reservation_committed' === $phase && ( $this->facts['reserve_only'] ?? false ) ) { echo '{"state":"reserved"}', "\n"; exit( 0 ); }
		if ( 'reservation_committed' === $phase && isset( $this->facts['reserved'] ) ) { OperationProofBarrier::signal( $this->facts['reserved'] ); }
		if ( 'before_effect_commit' === $phase && isset( $this->facts['ready'], $this->facts['release'] ) ) { OperationProofBarrier::pause( $this->facts['ready'], $this->facts['release'] ); }
	}
};
try {
	$assignment = 'promise.assignment.set' === $facts['operation'];
	$service = $assignment ? new PromiseAssignmentService( F::binding(), $factory, F::authority(), observer: $observer ) : new PromiseVersionLifecycleService( F::binding(), $factory, F::authority(), observer: $observer );
	$identity = $assignment ? F::assignment_identity( $facts['payload'], $facts['token'] ) : F::version_identity( $facts['operation'], $facts['payload'], $facts['token'] );
	$result = $service->attempt( $identity, $facts['payload'], RequestContext::create() );
	echo json_encode( [ 'state' => $result->outcome->state, 'code' => $result->outcome->error?->code, 'replayed' => $result->replayed ], JSON_THROW_ON_ERROR ), "\n";
} finally { $factory->close_all(); }
