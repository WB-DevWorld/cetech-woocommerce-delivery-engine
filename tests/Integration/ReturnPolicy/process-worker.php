<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofDatabase, OperationProofProcess};
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope as Envelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\{RuleProofFactory, RuleProofTransport};

require dirname( __DIR__, 2 ) . '/bootstrap.php';

$factory = null;
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_array( $args['payload'] ?? null ) || ! is_string( $args['prefix'] ?? null ) ) { throw new RuntimeException(); }
	OperationProofDatabase::validate_prefix( $args['prefix'] );
	$role = $args['role'] ?? 'recovery';
	if ( ! in_array( $role, [ 'recovery', 'left', 'right' ], true ) || ! in_array( $args['action'] ?? 'attempt', [ 'attempt', 'reconcile' ], true ) ) { throw new RuntimeException(); }
	$directory = $args['directory'] ?? null;
	if ( 'recovery' !== $role && ( ! is_string( $directory ) || ! is_dir( $directory ) || ! str_starts_with( basename( $directory ), 'cetech-operation-proof-' ) ) ) { throw new RuntimeException(); }
	$signal = static function( string $file, array $facts ): void {
		$bytes = json_encode( $facts, JSON_THROW_ON_ERROR );
		if ( strlen( $bytes ) !== file_put_contents( $file . '.tmp', $bytes, LOCK_EX )
			|| ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) {
			throw new RuntimeException( 'Owned Return proof signal failed.' );
		}
	};
	$factory = new RuleProofFactory( $args['prefix'], configure: static function( RuleProofTransport $transport ) use ( $role, $directory, $signal ): void {
		if ( 'right' === $role ) {
			$transport->before_guard_lock = static function( object $native ) use ( $directory, $signal ): void {
				$signal( $directory . '/right-dispatched', [ 'connection_id' => $native->connection_id() ] );
			};
		}
	}, clock: RuleTime::parse( Envelope::NOW ) );
	$observer = 'recovery' === $role ? null : new class( $factory, $role, $directory, $signal ) implements OperationPhaseObserver {
		private bool $observed = false;
		public function __construct( private RuleProofFactory $factory, private string $role, private string $directory, private \Closure $signal ) {}
		public function observe( string $phase, OperationIdentity $identity ): void {
			if ( $this->observed || ( 'right' === $this->role ? 'reservation_committed' : 'before_effect_commit' ) !== $phase ) { return; }
			$this->observed = true; $owner = $this->factory->transports[0]; $session = $this->factory->sessions[0]; $state = $owner->transaction_state();
			$held = 'left' === $this->role;
			if ( $session->is_retired() || ! is_array( $state ) || $state['in_transaction'] !== $held || true !== $state['autocommit'] || $session->in_transaction() !== $held ) {
				throw new RuntimeException( 'Owned Return proof phase refused.' );
			}
			( $this->signal )( $this->directory . ( $held ? '/left-held' : '/right-reserved' ),
				[ 'connection_id' => $owner->connection_id(), 'in_transaction' => $state['in_transaction'], 'autocommit' => $state['autocommit'] ] );
			OperationProofProcess::wait_for( $this->directory . ( $held ? '/release-left' : '/release-right' ), 30.0 );
		}
	};
	$service = new ReturnPolicyCatalogService( Envelope::family(), $factory,
		static fn( OperationIdentity $identity ): bool => 1 === $identity->site_id && 'staff:9' === $identity->principal,
		observer: $observer );
	$identity = Envelope::identity( $args['payload'], $args['operation'], $args['token'] );
	$result = 'reconcile' === ( $args['action'] ?? 'attempt' )
		? $service->reconcile( $identity, $args['payload'], RequestContext::create() )
		: $service->attempt( $identity, $args['payload'], RequestContext::create() );
	$all_retired = [] !== $factory->sessions;
	foreach ( $factory->sessions as $session ) { $all_retired = $all_retired && $session->is_retired(); }
	$packet = [ 'process_id' => getmypid(), 'state' => $result->outcome->state,
		'accepted' => $result->outcome->mutation_accepted, 'replayed' => $result->replayed,
		'error_code' => $result->outcome->error?->code, 'completion_json' => $result->completion?->to_json(),
		'all_retired' => $all_retired ];
	$factory->close_all();
	echo json_encode( $packet, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) {
	if ( $factory instanceof RuleProofFactory ) { $factory->close_all(); }
	echo json_encode( [ 'proof_error' => 'return_policy_worker_failed', 'error_class' => get_class( $error ) ], JSON_THROW_ON_ERROR ), PHP_EOL;
	exit( 1 );
}
