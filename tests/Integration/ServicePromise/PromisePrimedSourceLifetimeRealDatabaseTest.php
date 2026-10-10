<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\ServicePromise;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseHandoffSources;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase as DB, DataLifecycleProofFactory, DataLifecycleProofTransport};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;
use PHPUnit\Framework\Attributes\{DataProvider, Group};
use PHPUnit\Framework\TestCase;

/** The synchronous prime must never hide a later unit, same-owner write or dead SQL owner. */
#[Group( 'service-promise-persistence-real-db' )]
final class PromisePrimedSourceLifetimeRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private DataLifecycleProofFactory $factory;
	private PromiseAssignmentService $assignments;

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->database, $this->prefix );
		$this->factory = new DataLifecycleProofFactory( $this->prefix, clock: intdiv( RuleTime::parse( '2026-10-09 09:00:00.000000' )->epoch_microseconds(), 1000000 ) );
		$versions = new PromiseVersionLifecycleService( F::binding(), $this->factory, F::authority() );
		$this->assignments = new PromiseAssignmentService( F::binding(), $this->factory, F::authority() );
		$policy = F::policy();
		foreach ( [ 'create', 'seal', 'publish' ] as $action ) {
			$head = DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_promise_objects` WHERE kind='policy' ORDER BY id DESC LIMIT 1" );
			$row = 'create' === $action ? null : DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_promise_versions` WHERE kind='policy' ORDER BY id DESC LIMIT 1" );
			$payload = F::version_payload( $policy, $head, $row );
			self::assertSame( 'accepted', $versions->attempt( F::version_identity( 'promise.version.' . $action, $payload, 'prime-lifetime-' . $action ), $payload, RequestContext::create() )->outcome->state );
		}
		$payload = F::assignment_payload( $policy );
		self::assertSame( 'accepted', $this->assignments->attempt( F::assignment_identity( $payload, 'prime-lifetime-global' ), $payload, RequestContext::create() )->outcome->state );
	}
	protected function tearDown(): void {
		if ( isset( $this->factory ) ) { $this->factory->close_all(); }
		if ( isset( $this->database, $this->prefix ) ) { F::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function services(): array {
		$registry = new PromiseNativeServiceRegistry( [ [ 'native_service_id' => 30, 'service_kind' => 'built_in', 'service_code' => 'standard', 'origin_endpoint' => 'dispatch-origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'customer-door', 'destination_kind' => 'doorstep' ] ] );
		$services = []; foreach ( $registry->create_demands( Q::context() ) as $demand ) { $services[$demand->component_key()] = $demand->service_endpoint(); } return $services;
	}
	public static function unit_endings(): array { return [ 'rollback' => [ false ], 'commit' => [ true ] ]; }
	#[DataProvider( 'unit_endings' )]
	public function test_reusing_source_and_native_owner_after_a_unit_end_reloads_acknowledged_absence_changes( bool $commit ): void {
		$session = $this->factory->open(); $transport = $this->factory->transports[array_key_last( $this->factory->transports )]; self::assertTrue( $session->begin() );
		$sources = new WpdbPromiseHandoffSources( $session, F::binding() ); $at = RuleTime::parse( '2026-10-09 10:00:00.000000' );
		$before = $sources->prime( Q::context(), $this->services(), $at ); $component = array_key_first( $before );
		if ( $commit ) { self::assertSame( OperationCommitResult::Acknowledged, $session->commit() ); } else { self::assertTrue( $session->rollback() ); }
		$payload = F::assignment_payload( null, mode: 'inherit' ); $payload['key']['scope_kind'] = 'product'; $payload['key']['scope_id'] = 10;
		self::assertSame( 'accepted', $this->assignments->attempt( F::assignment_identity( $payload, 'prime-lifetime-inherit' ), $payload, RequestContext::create() )->outcome->state );
		self::assertTrue( $session->begin() ); $start = count( $transport->sql );
		$after = $sources->prime( Q::context(), $this->services(), $at );
		self::assertNotSame( $before[$component]['assignment_receipt_digest'], $after[$component]['assignment_receipt_digest'] );
		self::assertSame( $before[$component]['effective']->policy()->to_private_json(), $after[$component]['effective']->policy()->to_private_json() );
		$locks = array_filter( array_slice( $transport->sql, $start ), static fn( string $sql ): bool => str_contains( $sql, 'delivery_engine_promise_assignments`' ) && str_ends_with( $sql, 'FOR UPDATE' ) );
		self::assertCount( 2, $locks ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
	}
	public function test_a_new_prime_on_the_same_held_owner_rechecks_modified_source_receipts(): void {
		$session = $this->factory->open(); self::assertTrue( $session->begin() ); $sources = new WpdbPromiseHandoffSources( $session, F::binding() );
		$before = $sources->prime( Q::context(), $this->services(), RuleTime::parse( '2026-10-09 10:00:00.000000' ) );
		self::assertSame( 1, $session->query( "UPDATE `{$this->prefix}delivery_engine_promise_assignments` SET generation=generation+1 WHERE scope_kind='global'" ) );
		try {
			$sources->prime( Q::context(), $this->services(), RuleTime::parse( '2026-10-09 10:00:00.000000' ) );
			self::fail( 'A later prime reused the preceding source receipt despite this owner\'s source write.' );
		} catch ( OperationStorageException ) {
			self::assertSame( 'assigned', $before[array_key_first( $before )]['effective']->state() );
		} finally { self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); }
	}
	public function test_lost_owner_after_source_reads_refuses_at_the_uncached_final_probe_without_replacement(): void {
		$session = $this->factory->open(); self::assertTrue( $session->begin() );
		$transport = $this->factory->transports[array_key_last( $this->factory->transports )];
		$owner = (int) $session->get_row( 'SELECT CONNECTION_ID() AS owner' )['owner']; $connections = count( $this->factory->sessions ); $probes = 0;
		$transport->before = function( string $sql, DataLifecycleProofTransport $transport ) use ( $owner, &$probes ): void {
			if ( 'SELECT 1 AS promise_source_owner' === $sql ) { ++$probes; DB::execute( $this->database, 'KILL CONNECTION ' . $owner ); }
		};
		try {
			( new WpdbPromiseHandoffSources( $session, F::binding() ) )->prime( Q::context(), $this->services(), RuleTime::parse( '2026-10-09 10:00:00.000000' ) );
			self::fail( 'Cached source results concealed the lost native owner.' );
		} catch ( OperationStorageException ) {
			self::assertSame( 1, $probes ); self::assertTrue( $session->is_retired() ); self::assertCount( $connections, $this->factory->sessions );
		}
	}
}
