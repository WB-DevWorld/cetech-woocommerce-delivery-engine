<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\ServicePromise;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService, PromiseVersionReadService};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseCalendarReference, PromisePolicyReference, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Infrastructure\Persistence\{PromiseStorageReadiness, PromiseStorageSchema, StoredPromisePolicyVersionLoader, WpdbPromiseRepository};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofDatabase, OperationProofProcess};
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase as DB, DataLifecycleProofFactory};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Fresh native owners and physical SQL; explicit selection never silently skips. */
#[Group( 'service-promise-persistence-real-db' )]
final class ServicePromisePersistenceRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];
	protected function setUp(): void { $this->database = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->database, $this->prefix ); }
	protected function tearDown(): void { foreach ( $this->factories as $factory ) { $factory->close_all(); } foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); } if ( isset( $this->database, $this->prefix ) ) { F::cleanup( $this->database, $this->prefix ); $this->database->close(); } }
	private function stack( ?callable $configure = null, ?int $clock = null ): array { $factory = new DataLifecycleProofFactory( $this->prefix, clock: $clock ); $factory->configure = null === $configure ? null : \Closure::fromCallable( $configure ); $this->factories[] = $factory; $authority = F::authority(); return [ new PromiseVersionLifecycleService( F::binding(), $factory, $authority ), new PromiseAssignmentService( F::binding(), $factory, $authority ), $factory, $authority ]; }
	private function row( string $suffix, string $where = '1=1' ): ?array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` WHERE {$where} ORDER BY id DESC LIMIT 1" ); }
	private function row_count( string $suffix, string $where = '1=1' ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}` WHERE {$where}" ); }
	private function version_payload( BusinessCalendarVersion|ServicePromisePolicy $body, bool $create = false ): array { $facts = $body->private_facts(); $kind = $body instanceof ServicePromisePolicy ? 'policy' : 'calendar'; $logical = $facts[$kind . '_id']; $escaped = $this->database->real_escape_string( $logical ); return F::version_payload( $body, $this->row( 'promise_objects', "kind='{$kind}' AND logical_id='{$escaped}'" ), $create ? null : $this->row( 'promise_versions', "kind='{$kind}' AND logical_id='{$escaped}' AND domain_version={$facts['version']}" ) ); }
	private function attempt( object $service, string $operation, array $payload, ?string $token = null ): object { return $service->attempt( F::version_identity( $operation, $payload, $token ?? bin2hex( random_bytes( 8 ) ) ), $payload, RequestContext::create() ); }
	private function transition( object $service, string $action, BusinessCalendarVersion|ServicePromisePolicy $body ): object { return $this->attempt( $service, 'promise.version.' . $action, $this->version_payload( $body, 'create' === $action ) ); }
	private function publish( object $service, BusinessCalendarVersion|ServicePromisePolicy $body ): void { foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $result = $this->transition( $service, $action, $body ); self::assertSame( 'accepted', $result->outcome->state, $action . ':' . ( $result->outcome->error?->code ?? '' ) ); } }
	private function owned( DataLifecycleProofFactory $factory ): array { $session = $factory->open(); self::assertTrue( $session->begin() ); return [ $session, new WpdbPromiseRepository( $session, F::binding() ) ]; }
	private function assignment_attempt( object $service, array $payload, ?string $token = null ): object { return $service->attempt( F::assignment_identity( $payload, $token ?? bin2hex( random_bytes( 8 ) ) ), $payload, RequestContext::create() ); }
	private function refusal( callable $work ): void { try { $work(); self::fail( 'Invalid physical facts were disclosed.' ); } catch ( OperationStorageException ) { self::assertTrue( true ); } }

	public function test_real_metadata_exact_historical_policy_calendar_and_retirement(): void {
		[ $versions, , $factory ] = $this->stack(); $calendar = F::calendar(); $policy = F::policy( [ $calendar ] ); $this->publish( $versions, $calendar ); $this->publish( $versions, $policy );
		$policy_before = $this->row( 'promise_versions', "kind='policy'" ); $calendar_before = $this->row( 'promise_versions', "kind='calendar'" );
		self::assertSame( 'accepted', $this->transition( $versions, 'retire', $policy )->outcome->state ); self::assertSame( 'accepted', $this->transition( $versions, 'retire', $calendar )->outcome->state );
		[ $session, $repository ] = $this->owned( $factory ); self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new PromiseStorageReadiness( $session ) )->get_status() );
		self::assertSame( $policy->digest(), $repository->load_policy_versions( [ $policy->reference(), $policy->reference() ] )[0]->digest() ); self::assertSame( $calendar->digest(), $repository->load_calendar_versions( [ $calendar->reference() ] )[0]->digest() ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		foreach ( [ [ 'policy', $policy_before ], [ 'calendar', $calendar_before ] ] as [ $kind, $before ] ) { $after = $this->row( 'promise_versions', "kind='{$kind}'" ); foreach ( [ 'body_json', 'body_digest', 'create_receipt_json', 'create_receipt_digest', 'publication_receipt_json', 'publication_receipt_digest', 'published_at', 'author_user_id', 'reason' ] as $field ) { self::assertSame( $before[$field], $after[$field], $field ); } }
	}
	public function test_complete_bulk_deduplicates_and_refuses_missing_wrong_digest_or_foreign_site(): void {
		[ $versions, , $factory ] = $this->stack(); $calendar = F::calendar(); $this->publish( $versions, $calendar ); [ $session, $repository ] = $this->owned( $factory );
		self::assertCount( 1, $repository->load_calendar_versions( [ $calendar->reference(), $calendar->reference() ] ) );
		$missing = F::calendar( 'missing-calendar' )->reference(); $bad = $calendar->reference()->private_facts(); $bad['digest'] = hash( 'sha256', 'wrong-version-body' );
		foreach ( [ [ $calendar->reference(), $missing ], [ PromiseCalendarReference::from_array( $bad ) ], [ $calendar->reference(), PromiseCalendarReference::from_array( $bad ) ], [ F::calendar( site: 'foreign-site' )->reference() ] ] as $refs ) { $this->refusal( fn() => $repository->load_calendar_versions( $refs ) ); }
		self::assertTrue( $session->rollback() );
	}
	public function test_draft_and_forged_publication_backlink_never_disclose_body(): void {
		[ $versions, , $factory ] = $this->stack(); $calendar = F::calendar(); self::assertSame( 'accepted', $this->transition( $versions, 'create', $calendar )->outcome->state ); [ $session, $repository ] = $this->owned( $factory ); $this->refusal( fn() => $repository->load_calendar_versions( [ $calendar->reference() ] ) ); self::assertTrue( $session->rollback() );
		self::assertSame( 'accepted', $this->transition( $versions, 'seal', $calendar )->outcome->state ); self::assertSame( 'accepted', $this->transition( $versions, 'publish', $calendar )->outcome->state );
		$before = $this->row( 'promise_versions' ); $receipt = json_decode( $before['publication_receipt_json'], true, flags: JSON_THROW_ON_ERROR ); $namespace = $this->database->real_escape_string( $receipt['namespace_hash'] ); DB::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_operation_changes` WHERE operation_id=(SELECT id FROM `{$this->prefix}delivery_engine_operation_records` WHERE namespace_hash='{$namespace}')" );
		[ $fresh, $repository ] = $this->owned( $factory ); $this->refusal( fn() => $repository->load_calendar_versions( [ $calendar->reference() ] ) ); self::assertTrue( $fresh->rollback() ); self::assertSame( $before, $this->row( 'promise_versions' ) );
	}
	public function test_oversized_physical_body_and_receipt_refuse_before_disclosure(): void {
		[ $versions, , $factory ] = $this->stack(); $calendar = F::calendar(); $this->publish( $versions, $calendar ); $row = $this->row( 'promise_versions' );
		foreach ( [ 'body_json' => 32769, 'publication_receipt_json' => 16385 ] as $field => $length ) { $bytes = $this->database->real_escape_string( str_repeat( 'X', $length ) ); DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promise_versions` SET {$field}='{$bytes}' WHERE id={$row['id']}" ); [ $session, $repository ] = $this->owned( $factory ); $this->refusal( fn() => $repository->load_calendar_versions( [ $calendar->reference() ] ) ); self::assertTrue( $session->rollback() ); $original = $this->database->real_escape_string( $row[$field] ); DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promise_versions` SET {$field}='{$original}' WHERE id={$row['id']}" ); }
	}
	public function test_lost_ack_reconciles_original_receipt_without_current_body_lookup(): void {
		[ $versions, , $factory ] = $this->stack( static function( $transport, int $number ): void { if ( 1 === $number ) { $transport->before = static function( string $sql, $owner ): void { if ( 1 === $owner->commits && 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) { $owner->commit_fault = 'lost_ack'; } }; } } );
		$calendar = F::calendar(); $payload = F::version_payload( $calendar ); $identity = F::version_identity( 'promise.version.create', $payload, 'original-create' ); $attempt = $versions->attempt( $identity, $payload, RequestContext::create() ); self::assertSame( 'unconfirmed', $attempt->outcome->state ); self::assertSame( 1, $this->row_count( 'promise_versions' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promise_versions` SET body_json='invalid-current-body'" ); $replay = $versions->reconcile( $identity, $payload, RequestContext::create() ); self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed ); self::assertSame( 1, $this->row_count( 'promise_versions' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertTrue( $factory->sessions[0]->is_retired() );
	}
	public function test_audit_failure_rolls_back_object_version_and_private_assignment_baseline(): void {
		[ $versions, $assignments ] = $this->stack( static function( $transport ): void { $transport->before = static function( string $sql ): void { if ( str_starts_with( $sql, 'INSERT INTO `' ) && str_contains( $sql, 'delivery_engine_operation_changes`' ) ) { throw new OperationStorageException(); } }; } ); $calendar = F::calendar(); self::assertNotSame( 'accepted', $this->transition( $versions, 'create', $calendar )->outcome->state ); self::assertSame( 0, $this->row_count( 'promise_objects' ) ); self::assertSame( 0, $this->row_count( 'promise_versions' ) );
		$payload = F::assignment_payload( null, mode: 'disabled' ); self::assertNotSame( 'accepted', $this->assignment_attempt( $assignments, $payload )->outcome->state ); self::assertSame( 0, $this->row_count( 'promise_assignments' ) ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public function test_assignment_actual_guard_and_generation_tombstones_are_monotonic(): void {
		[ $versions, $assignments, $factory ] = $this->stack(); $policy = F::policy(); $this->publish( $versions, $policy ); $initial = F::assignment_payload( $policy ); self::assertSame( 'accepted', $this->assignment_attempt( $assignments, $initial )->outcome->state ); $row = $this->row( 'promise_assignments' ); self::assertSame( '2', $row['revision'] ); self::assertSame( '1', $row['generation'] );
		$normalized = array_replace( $row, [ 'revision' => (int) $row['revision'], 'generation' => (int) $row['generation'] ] ); self::assertSame( 'accepted', $this->assignment_attempt( $assignments, F::assignment_payload( null, $normalized, 'inherit' ) )->outcome->state ); $after = $this->row( 'promise_assignments' ); self::assertSame( '3', $after['revision'] ); self::assertSame( '2', $after['generation'] ); self::assertNull( $after['policy_reference_json'] ); self::assertSame( 'rejected', $this->assignment_attempt( $assignments, $initial )->outcome->state ); self::assertSame( $after, $this->row( 'promise_assignments' ) );
		[ $session, $repo ] = $this->owned( $factory ); $stored = $repo->find_assignment( F::assignment_key() ); self::assertSame( 3, $stored['revision'] ); $repo->accepted_receipt( \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredAssignment::from_row( $stored, F::binding() )->source_receipt(), $stored ); self::assertTrue( $session->rollback() );
	}
	public function test_lost_native_owner_retires_without_replacement_connection(): void {
		[ , , $factory ] = $this->stack(); [ $session, $repository ] = $this->owned( $factory ); $id = (int) $session->get_row( 'SELECT CONNECTION_ID() AS owner' )['owner']; DB::execute( $this->database, 'KILL CONNECTION ' . $id ); $this->refusal( fn() => $repository->find_object( 'calendar', 'working-hours' ) ); self::assertTrue( $session->is_retired() ); self::assertCount( 1, $factory->sessions ); self::assertFalse( $session->begin() );
	}
	private function read_identity(): \CetechDeliveryEngine\Domain\Contracts\OperationIdentity { return new \CetechDeliveryEngine\Domain\Contracts\OperationIdentity( 1, 'promise.persistence.fixture.v1', 'user:7', 'promise.versions.read', 1, 'promise-versions:' . F::binding()->site_key(), 'private-read' ); }
	private function capture( PromiseVersionReadService $reads, string $instant ): object { $payload = F::assignment_payload( null, mode: 'inherit' ); $identity = new \CetechDeliveryEngine\Domain\Contracts\OperationIdentity( 1, 'promise.persistence.fixture.v1', 'user:7', 'promise.assignment.read', 1, \CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseAssignmentCommand::target_key( F::binding(), $payload['key'] ), 'current-assignment-read' ); return $reads->capture_assignment( $identity, $payload['key'], \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $instant ) ); }
	public function test_atomic_successor_cutover_keeps_original_historical_calendar_and_policy(): void {
		[ $versions, $assignments, $factory, $authority ] = $this->stack(); $calendar1 = F::calendar(); $policy1 = F::policy( [ $calendar1 ] ); $this->publish( $versions, $calendar1 ); $this->publish( $versions, $policy1 ); self::assertSame( 'accepted', $this->assignment_attempt( $assignments, F::assignment_payload( $policy1 ) )->outcome->state ); $before = $this->row( 'promise_versions', "kind='policy'" );
		$calendar2 = F::calendar( version: 2 ); $this->publish( $versions, $calendar2 ); $policy2 = F::policy( [ $calendar2 ], version: 2 ); $this->publish( $versions, $policy2 );
		$old = $this->row( 'promise_versions', "kind='policy' AND domain_version=1" ); $head = $this->row( 'promise_objects', "kind='policy'" ); $current = $this->row( 'promise_versions', "kind='policy' AND domain_version=2" ); self::assertSame( 'retired', $old['state'] ); self::assertSame( 'published', $current['state'] ); self::assertSame( $current['id'], $head['published_version_id'] );
		foreach ( [ 'body_json', 'body_digest', 'create_receipt_json', 'publication_receipt_json', 'publication_receipt_digest', 'published_at' ] as $field ) { self::assertSame( $before[$field], $old[$field] ); }
		[ $session, $repo ] = $this->owned( $factory ); $loaded = $repo->load_policy_versions( [ $policy2->reference(), $policy1->reference() ] ); self::assertSame( [ $policy1->digest(), $policy2->digest() ], array_map( static fn( $body ): string => $body->digest(), $loaded ) ); ( new PromiseStorageReadiness( $session ) )->verify_stored_records(); self::assertTrue( $session->rollback() );
		$reads = new PromiseVersionReadService( F::binding(), $factory, $authority ); self::assertSame( 'unavailable', $this->capture( $reads, '2026-10-09 23:59:00.000000' )->state() );
	}
	public function test_companion_cutover_audit_failure_preserves_predecessor_successor_and_head_together(): void {
		[ $versions ] = $this->stack(); $first = F::policy(); $second = F::policy( version: 2 ); $this->publish( $versions, $first );
		foreach ( [ 'create', 'seal' ] as $action ) { self::assertSame( 'accepted', $this->transition( $versions, $action, $second )->outcome->state ); }
		$head = $this->row( 'promise_objects' ); $predecessor = $this->row( 'promise_versions', 'domain_version=1' ); $successor = $this->row( 'promise_versions', 'domain_version=2' ); $changes = $this->row_count( 'operation_changes' );
		[ $failing, , $factory ] = $this->stack( static function( $transport ): void { $transport->before = static function( string $sql ): void { if ( str_starts_with( $sql, 'INSERT INTO `' ) && str_contains( $sql, 'delivery_engine_operation_changes`' ) ) { throw new OperationStorageException(); } }; } );
		$payload = $this->version_payload( $second ); $refused = $this->attempt( $failing, 'promise.version.publish', $payload, 'atomic-companion-failure' ); self::assertNotSame( 'accepted', $refused->outcome->state );
		self::assertSame( $head, $this->row( 'promise_objects' ) ); self::assertSame( $predecessor, $this->row( 'promise_versions', 'domain_version=1' ) ); self::assertSame( $successor, $this->row( 'promise_versions', 'domain_version=2' ) ); self::assertSame( $changes, $this->row_count( 'operation_changes' ) );
		self::assertTrue( $factory->sessions[array_key_last( $factory->sessions )]->is_retired() );
		// A fresh original command can still complete the unchanged successor after the failed effect.
		self::assertSame( 'accepted', $this->attempt( $versions, 'promise.version.publish', $payload, 'atomic-companion-retry' )->outcome->state ); self::assertSame( 'retired', $this->row( 'promise_versions', 'domain_version=1' )['state'] ); self::assertSame( 'published', $this->row( 'promise_versions', 'domain_version=2' )['state'] ); self::assertSame( $changes + 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_schedule_activation_author_revocation_and_exact_exclusive_effective_bounds(): void {
		$start = '2026-10-10 10:00:00.000000'; $end = '2026-10-10 11:00:00.000000'; [ $versions ] = $this->stack( clock: 1791540000 ); $policy = F::policy( from: $start, until: $end );
		foreach ( [ 'create', 'seal', 'schedule' ] as $action ) { self::assertSame( 'accepted', $this->transition( $versions, $action, $policy )->outcome->state ); } $scheduled = $this->row( 'promise_versions' );
		$start_epoch = intdiv( \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( $start )->epoch_microseconds(), 1000000 ); [ $activations, $assignments, $factory, $authority ] = $this->stack( clock: $start_epoch ); $authority->original_author_allowed = false; $payload = $this->version_payload( $policy ); $payload['scheduled_author_user_id'] = 7; self::assertSame( 'rejected', $this->attempt( $activations, 'promise.version.activate', $payload )->outcome->state ); self::assertSame( $scheduled, $this->row( 'promise_versions' ) );
		$authority->original_author_allowed = true; self::assertSame( 'accepted', $this->attempt( $activations, 'promise.version.activate', $payload )->outcome->state ); $published = $this->row( 'promise_versions' ); self::assertSame( $scheduled['body_json'], $published['body_json'] ); self::assertSame( $start, $published['declared_from'] ); self::assertSame( $start, $published['published_at'] );
		self::assertSame( 'accepted', $this->assignment_attempt( $assignments, F::assignment_payload( $policy ) )->outcome->state ); $reads = new PromiseVersionReadService( F::binding(), $factory, $authority ); self::assertSame( 'unavailable', $this->capture( $reads, '2026-10-10 09:59:59.999999' )->state() ); self::assertSame( 'assigned', $this->capture( $reads, $start )->state() ); self::assertSame( 'assigned', $this->capture( $reads, '2026-10-10 10:59:59.999999' )->state() ); self::assertSame( 'unavailable', $this->capture( $reads, $end )->state() );
	}
	public function test_exact_loader_reauthorizes_before_lookup_and_after_owner_retirement(): void {
		[ $versions, , $factory, $authority ] = $this->stack(); $policy = F::policy(); $this->publish( $versions, $policy ); $reads = new PromiseVersionReadService( F::binding(), $factory, $authority ); $loader = new StoredPromisePolicyVersionLoader( $reads, $this->read_identity() ); self::assertSame( $policy->digest(), $loader->load_versions( [ $policy->reference() ] )[0]->digest() );
		$authority->allowed = false; $before = count( $factory->sessions ); try { $loader->load_versions( [ $policy->reference() ] ); self::fail( 'Revoked private read was disclosed.' ); } catch ( \CetechDeliveryEngine\Domain\Operation\OperationRefusal $error ) { self::assertSame( 'not_authorized', $error->error( RequestContext::create() )->code ); } self::assertCount( $before, $factory->sessions );
		$authority->allowed = true; $factory->configure = static function( $transport ) use ( $authority ): void { $transport->after = static function( string $sql ) use ( $authority ): void { if ( str_contains( $sql, 'FROM `' ) && str_contains( $sql, 'delivery_engine_promise_versions`' ) ) { $authority->allowed = false; } }; };
		try { $loader->load_versions( [ $policy->reference() ] ); self::fail( 'Revoked post-read grant was disclosed.' ); } catch ( \CetechDeliveryEngine\Domain\Operation\OperationRefusal ) { self::assertTrue( true ); } self::assertTrue( $factory->sessions[array_key_last( $factory->sessions )]->is_retired() );
	}
	private function race( string $operation, array $payload ): array {
		$directory = OperationProofBarrier::directory(); $this->directories[] = $directory; $worker = dirname( __DIR__, 2 ) . '/Support/ServicePromise/persistence-race-worker.php';
		// Reserve both original C03 commands first, so the race isolates the material row guard.
		foreach ( [ 'first-racer', 'second-racer' ] as $token ) { $reservation = new OperationProofProcess( [ $worker, json_encode( [ 'prefix' => $this->prefix, 'operation' => $operation, 'payload' => $payload, 'token' => $token, 'reserve_only' => true ], JSON_THROW_ON_ERROR ) ] ); self::assertSame( 'reserved', $reservation->finish()['state'] ); }
		$first = new OperationProofProcess( [ $worker, json_encode( [ 'prefix' => $this->prefix, 'operation' => $operation, 'payload' => $payload, 'token' => 'first-racer', 'ready' => $directory . '/first-ready', 'release' => $directory . '/release' ], JSON_THROW_ON_ERROR ) ] ); $second = null;
		try { OperationProofProcess::wait_for( $directory . '/first-ready' ); $second = new OperationProofProcess( [ $worker, json_encode( [ 'prefix' => $this->prefix, 'operation' => $operation, 'payload' => $payload, 'token' => 'second-racer', 'reserved' => $directory . '/second-reserved' ], JSON_THROW_ON_ERROR ) ] ); OperationProofProcess::wait_for( $directory . '/second-reserved' ); OperationProofBarrier::signal( $directory . '/release' ); return [ $first->finish(), $second->finish() ]; }
		catch ( \Throwable $error ) { OperationProofBarrier::signal( $directory . '/release' ); $facts = [ 'first' => $first->finish(), 'second' => null === $second ? null : $second->finish() ]; throw new \RuntimeException( 'Two-owner barrier failed: ' . json_encode( $facts, JSON_THROW_ON_ERROR ), previous: $error ); }
		finally { OperationProofBarrier::signal( $directory . '/release' ); }
	}
	public function test_two_physical_owners_publish_same_version_only_once(): void {
		[ $versions ] = $this->stack(); $policy = F::policy(); self::assertSame( 'accepted', $this->transition( $versions, 'create', $policy )->outcome->state ); self::assertSame( 'accepted', $this->transition( $versions, 'seal', $policy )->outcome->state );
		[ $winner, $loser ] = $this->race( 'promise.version.publish', $this->version_payload( $policy ) ); self::assertSame( 'accepted', $winner['state'] ); self::assertSame( 'rejected', $loser['state'] ); self::assertSame( 'stale_revision', $loser['code'] ); self::assertSame( 3, $this->row_count( 'operation_changes' ) ); self::assertSame( 1, $this->row_count( 'promise_versions' ) ); self::assertSame( '3', $this->row( 'promise_versions' )['row_revision'] ); self::assertSame( '4', $this->row( 'promise_objects' )['revision'] );
	}
	public function test_two_physical_owners_cannot_overwrite_same_assignment_generation(): void {
		[ , $assignments ] = $this->stack(); self::assertSame( 'accepted', $this->assignment_attempt( $assignments, F::assignment_payload( null, mode: 'inherit' ) )->outcome->state ); $row = $this->row( 'promise_assignments' ); $payload = F::assignment_payload( null, $row, 'disabled' );
		[ $winner, $loser ] = $this->race( 'promise.assignment.set', $payload ); self::assertSame( 'accepted', $winner['state'] ); self::assertSame( 'rejected', $loser['state'] ); self::assertSame( 'stale_revision', $loser['code'] ); $after = $this->row( 'promise_assignments' ); self::assertSame( '3', $after['revision'] ); self::assertSame( '2', $after['generation'] ); self::assertSame( 'disabled', $after['state'] ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}
	public function test_forged_head_guard_cannot_create_or_capture_but_original_history_remains_exact(): void {
		[ $versions, $assignments, $factory, $authority ] = $this->stack(); $policy = F::policy(); $this->publish( $versions, $policy ); self::assertSame( 'accepted', $this->assignment_attempt( $assignments, F::assignment_payload( $policy ) )->outcome->state ); $original = $this->row( 'promise_objects' );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promise_objects` SET revision=revision+10,last_sequence=last_sequence+1" ); $forged = $this->row( 'promise_objects' ); $next = F::policy( version: 3 ); $payload = F::version_payload( $next, $forged ); self::assertNotSame( 'accepted', $this->attempt( $versions, 'promise.version.create', $payload )->outcome->state ); self::assertSame( $forged, $this->row( 'promise_objects' ) ); self::assertSame( 1, $this->row_count( 'promise_versions' ) );
		$reads = new PromiseVersionReadService( F::binding(), $factory, $authority ); $this->refusal( fn() => $this->capture( $reads, '2026-10-09 23:59:00.000000' ) ); self::assertSame( $policy->digest(), $reads->load_policy_versions( $this->read_identity(), [ $policy->reference() ] )[0]->digest() );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promise_objects` SET revision={$original['revision']},last_sequence={$original['last_sequence']}" );
	}
}
