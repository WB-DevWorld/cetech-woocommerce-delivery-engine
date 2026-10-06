<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationChangeRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationCoordinatorTest.php';

final class OperationRepositoryTest extends TestCase {

	private string $path;
	private CoordinatorFixtureFactory $factory;
	private CoordinatorFixtureProfile $profile;

	protected function setUp(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'operation-repository-' );
		$this->factory = new CoordinatorFixtureFactory( $this->path );
		$this->profile = new CoordinatorFixtureProfile();
		$this->factory->inspection()->exec( 'CREATE TABLE unit_delivery_engine_operation_records (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, namespace_hash TEXT NOT NULL, intent_hash TEXT NOT NULL, namespace_format INTEGER NOT NULL, intent_format INTEGER NOT NULL, record_format INTEGER NOT NULL, operation TEXT NOT NULL, operation_version INTEGER NOT NULL, target_hash TEXT NOT NULL, state TEXT NOT NULL, publication_state TEXT NOT NULL, completion_json TEXT NULL, audit_id INTEGER NULL, row_version INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, completed_at TEXT NULL, UNIQUE(site_id,namespace_hash))' );
		$this->factory->inspection()->exec( 'CREATE TABLE unit_delivery_engine_operation_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, operation_id INTEGER NOT NULL, event_format INTEGER NOT NULL, event_json TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(site_id,operation_id))' );
		$this->factory->inspection()->exec( 'CREATE TABLE unit_counter (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, value INTEGER NOT NULL)' );
		$this->factory->inspection()->exec( 'INSERT INTO unit_counter VALUES(1,1,5)' );
	}

	protected function tearDown(): void {
		foreach ( $this->factory->sessions as $session ) { $session->retire(); }
		unlink( $this->path );
	}

	public function test_terminal_acceptance_and_no_change_refuse_rejection_overwrite_before_sql(): void {
		foreach ( [ 9, 5 ] as $value ) {
			$identity = $this->identity( (string) $value );
			$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $this->profile ] ), $this->factory, new CoordinatorFixtureReadiness() );
			$command = [ 'value' => $value, 'expected_revision' => 1 ];
			if ( 5 === $value ) { $this->factory->inspection()->exec( 'UPDATE unit_counter SET value=5,revision=1 WHERE id=1' ); }
			$accepted = $coordinator->attempt( $identity, $command, RequestContext::create() );
			self::assertContains( $accepted->outcome->state, [ 'accepted', 'not_applicable' ] );
			$session = $this->factory->open();
			$session->begin();
			$repository = new WpdbOperationRecordRepository( $session );
			$row = $repository->lock( $identity );
			$queries = $this->factory->queries;
			try {
				$repository->write_completion( $row, $identity, $this->profile->validate_command( $identity, $command )->intent(), OperationCompletion::rejected( new ContractError( 'temporarily_unavailable', RequestContext::create(), 'retry_original_request' ) ), null );
				self::fail( 'Terminal completion was replaced.' );
			} catch ( OperationStorageException $exception ) {
				self::assertSame( 'The operation store could not complete its database statement.', $exception->getMessage() );
				self::assertSame( $queries, $this->factory->queries );
			}
			self::assertSame( $accepted->completion->to_json(), $repository->lock( $identity )['completion_json'] );
			$session->rollback();
			$session->retire();
		}
	}

	public function test_failed_read_is_not_reported_as_missing_and_wrong_site_is_not_queried(): void {
		$session = $this->factory->open();
		$session->begin();
		$repository = new WpdbOperationRecordRepository( $session );
		$this->factory->fail_record_read = true;
		try {
			$repository->lock( $this->identity() );
			self::fail( 'Failed read was presented as absence.' );
		} catch ( OperationStorageException $exception ) { self::assertNull( $exception->getPrevious() ); }
		$queries = $this->factory->queries;
		try {
			$repository->lock( new OperationIdentity( 2, 'wordpress', 'user:7', 'fixture.counter', 1, 'counter:1', 'token' ) );
			self::fail( 'Wrong-site lookup was queried.' );
		} catch ( OperationStorageException ) { self::assertSame( $queries, $this->factory->queries ); }
		self::assertNull( $repository->lock( $this->identity() ) );
	}

	public function test_second_material_event_is_refused_and_original_event_is_immutable(): void {
		$session = $this->factory->open();
		$session->begin();
		$repository = new WpdbOperationChangeRepository( $session );
		$identity = $this->identity();
		$event = OperationMaterialEvent::from_mutation( $this->profile, $identity, RequestContext::create(), [ 'principal_id' => 7 ], [ 'row_id' => 1 ], 'counter_changed', 1, 2, [ 'value' ] );
		$audit = $repository->append( 1, 1, $event );
		$before = $repository->find( $audit, 1, 1 );
		try {
			$repository->append( 1, 1, $event );
			self::fail( 'Second material event was appended.' );
		} catch ( OperationStorageException $exception ) {
			self::assertNull( $exception->getPrevious() );
			self::assertSame( 'The operation store could not complete its database statement.', $exception->getMessage() );
		}
		self::assertSame( $before, $repository->find( $audit, 1, 1 ) );
		self::assertNull( $repository->find( $audit, 1, 2 ) );
	}

	public function test_unowned_or_retired_session_cannot_dispatch_a_reservation(): void {
		$session = $this->factory->open();
		$repository = new WpdbOperationRecordRepository( $session );
		$identity = $this->identity();
		$intent = CanonicalIntent::from_command( $identity, [ 'row_id' => 1 ], [ 'revision' => 1 ], [ 'value' => 9 ] );
		foreach ( [ false, true ] as $retired ) {
			if ( $retired ) { $session->retire(); }
			try {
				$repository->insert_pending( $identity, $intent );
				self::fail( 'Unowned session dispatched a reservation.' );
			} catch ( OperationStorageException ) { self::assertSame( 0, $this->factory->queries ); }
		}
	}

	private function identity( string $token = 'token' ): OperationIdentity {
		return new OperationIdentity( 1, 'wordpress', 'user:7', 'fixture.counter', 1, 'counter:1', $token );
	}
}
