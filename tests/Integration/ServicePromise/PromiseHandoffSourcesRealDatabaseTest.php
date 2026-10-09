<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\ServicePromise;

use CetechDeliveryEngine\Application\ServicePromise\Handoff\{PromiseCapacityCurrentFence, PromiseHandoffSourceFence, PromiseNativeCaptureService};
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseCapacityObservation, PromiseInput, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromiseCapacityObserver;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase as DB, DataLifecycleProofFactory};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures as L;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofTransport as Transport;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnection, OperationConnectionMysqliTransport};
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual P02 producer acknowledgements and one-owner final fences on a fresh tracked SQL prefix. */
#[Group( 'service-promise-persistence-real-db' )]
final class PromiseHandoffSourcesRealDatabaseTest extends TestCase {
	private \mysqli $database; private string $prefix; private DataLifecycleProofFactory $factory; private PromiseVersionLifecycleService $versions; private PromiseAssignmentService $assignments;
	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->database, $this->prefix ); $this->factory = new DataLifecycleProofFactory( $this->prefix, clock: intdiv( RuleTime::parse( '2026-10-09 09:00:00.000000' )->epoch_microseconds(), 1000000 ) ); $authority = F::authority();
		$this->versions = new PromiseVersionLifecycleService( F::binding(), $this->factory, $authority ); $this->assignments = new PromiseAssignmentService( F::binding(), $this->factory, $authority );
	}
	protected function tearDown(): void { if ( isset( $this->factory ) ) { $this->factory->close_all(); } if ( isset( $this->database, $this->prefix ) ) { F::cleanup( $this->database, $this->prefix ); $this->database->close(); } }
	private function row( string $suffix, string $where = '1=1' ): ?array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` WHERE {$where} ORDER BY id DESC LIMIT 1" ); }
	private function transition( string $action, BusinessCalendarVersion|ServicePromisePolicy $body, ?string $calendar_until = null ): void {
		$facts = $body->private_facts(); $kind = $body instanceof ServicePromisePolicy ? 'policy' : 'calendar'; $id = $this->database->real_escape_string( $facts[$kind . '_id'] ); $payload = F::version_payload( $body, $this->row( 'promise_objects', "kind='{$kind}' AND logical_id='{$id}'" ), 'create' === $action ? null : $this->row( 'promise_versions', "kind='{$kind}' AND logical_id='{$id}' AND domain_version={$facts['version']}" ) );
		if ( null !== $calendar_until ) { $payload['declared_until'] = $calendar_until; }
		self::assertSame( 'accepted', $this->versions->attempt( F::version_identity( 'promise.version.' . $action, $payload, bin2hex( random_bytes( 8 ) ) ), $payload, RequestContext::create() )->outcome->state );
	}
	private function install_policy( ?ServicePromisePolicy $policy = null ): ServicePromisePolicy { $policy ??= F::policy(); foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $this->transition( $action, $policy ); } $payload = F::assignment_payload( $policy ); $service = $policy->service()->private_facts(); $payload['key']['service_kind'] = $service['kind']; $payload['key']['service_code'] = $service['code']; self::assertSame( 'accepted', $this->assignments->attempt( F::assignment_identity( $payload, 'initial-native-assignment' ), $payload, RequestContext::create() )->outcome->state ); return $policy; }
	private function registry( string $code = 'standard' ): PromiseNativeServiceRegistry { return new PromiseNativeServiceRegistry( [ [ 'native_service_id' => 30, 'service_kind' => 'built_in', 'service_code' => $code, 'origin_endpoint' => 'dispatch-origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'customer-door', 'destination_kind' => 'doorstep' ] ] ); }
	private function service( ?PromiseCapacityObserver $capacity = null, ?PromiseCapacityCurrentFence $capacity_fence = null ): PromiseNativeCaptureService {
		$authority = new class implements PromisePersistenceAuthorizer {
			public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool { return 'service_promise.native_capture.v1' === $identity->authority && Q::owner()->digest() === $identity->principal && 1 === $identity->site_id && 1 === $binding->site_id() && 'promise.assignment.read' === $identity->operation && 0 === $author_user_id; }
			public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; }
		}; return new PromiseNativeCaptureService( F::binding(), $this->factory, $authority, $capacity, $capacity_fence );
	}
	private function capture( ?QuoteContext $base = null ): object { $base ??= Q::context(); return $this->service()->capture( $base, Q::owner(), RuleTime::parse( '2026-10-09 10:00:00.000000' ), $this->registry()->create_demands( $base ) ); }
	private function physical_factory( ?string $isolation = null, ?\Closure $configure = null ): object {
		return new class( $this->prefix, $isolation, $configure ) implements OperationConnectionFactory {
			public array $sessions = []; public array $transports = [];
			public function __construct( private string $prefix, private ?string $isolation, private ?\Closure $configure ) {}
			public function open(): OperationSession { $native = OperationConnectionMysqliTransport::connect( (string) getenv( 'CETECH_DE_REAL_DB_HOST' ), (string) getenv( 'CETECH_DE_REAL_DB_USER' ), (string) getenv( 'CETECH_DE_REAL_DB_PASSWORD' ), (string) getenv( 'CETECH_DE_REAL_DB_NAME' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) ); if ( null !== $this->isolation && ! $native->execute( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $this->isolation )->acknowledged ) { throw new \RuntimeException( 'Fixture isolation failed.' ); } $transport = new Transport( $native ); $this->transports[] = $transport; if ( null !== $this->configure ) { ( $this->configure )( $transport ); } $session = new OperationConnection( 1, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' ); $this->sessions[] = $session; return $session; }
		};
	}
	private function verify( QuoteContext $context, string $at = '2026-10-09 10:01:00.000000' ): bool {
		$fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( $at ) ); $before = count( $this->factory->sessions ); $session = $this->factory->open(); self::assertTrue( $session->begin() );
		try { self::assertCount( 5, $fence->tables( $session ) ); $ok = $fence->verify_context( $session, Q::owner(), $context ); self::assertCount( $before + 1, $this->factory->sessions ); return $ok; } finally { if ( $session->in_transaction() ) { $session->rollback(); } $session->retire(); }
	}
	public function test_capture_reads_actual_acknowledged_policy_and_preserves_native_money_and_original_clock(): void {
		$policy = $this->install_policy(); $before = count( $this->factory->sessions ); $base = Q::context(); $native_bytes = $base->to_private_json(); $capture = $this->capture( $base ); self::assertCount( $before + 1, $this->factory->sessions ); self::assertTrue( $this->factory->sessions[array_key_last( $this->factory->sessions )]->is_retired() );
		$context = $capture->context(); $packet = PromiseHistoricalPacket::from_array( $capture->promise_groups()[0]['packet'] ); self::assertSame( $native_bytes, $context->base_context()->to_private_json() ); self::assertSame( $policy->reference()->private_facts(), $capture->context_captures()[0]['policy_reference'] ); self::assertSame( '2026-10-09 10:00:00.000000', $packet->input_facts()['evaluated_at'] ); self::assertSame( '2026-10-09 10:05:00.000000', $packet->input_facts()['anchor']['quote_expires_at'] ); self::assertSame( 'none', $packet->input_facts()['capacity']['mode'] );
		self::assertSame( L::terms()->private_facts()['groups'], $capture->terms( L::terms() )->private_facts()['groups'] ); self::assertTrue( $this->verify( $context ) );
	}
	public function test_new_acknowledged_product_inherit_row_invalidates_the_original_absence_even_when_policy_stays_global(): void {
		$this->install_policy(); $context = $this->capture()->context(); self::assertTrue( $this->verify( $context ) ); $payload = F::assignment_payload( null, mode: 'inherit' ); $payload['key']['scope_kind'] = 'product'; $payload['key']['scope_id'] = 10;
		self::assertSame( 'accepted', $this->assignments->attempt( F::assignment_identity( $payload, 'new-product-inherit' ), $payload, RequestContext::create() )->outcome->state ); self::assertFalse( $this->verify( $context ) );
	}
	public function test_policy_retirement_refuses_current_admission_but_original_history_needs_no_source_dereference(): void {
		$policy = $this->install_policy(); $capture = $this->capture(); $context = $capture->context(); $packet = PromiseHistoricalPacket::from_array( $capture->promise_groups()[0]['packet'] ); $before = $packet->to_private_json(); $public = $packet->public_facts(); $this->transition( 'retire', $policy ); self::assertFalse( $this->verify( $context ) );
		DB::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_promise_versions` WHERE kind='policy'" ); $old_zone = date_default_timezone_get(); try { date_default_timezone_set( 'Pacific/Auckland' ); $history = PromiseHistoricalPacket::from_json( $before ); self::assertSame( $before, $history->to_private_json() ); self::assertSame( $public, $history->public_facts() ); } finally { date_default_timezone_set( $old_zone ); }
	}
	public function test_missing_required_capacity_adapter_never_converts_required_observation_to_none(): void {
		$facts = F::policy()->private_facts(); $facts['capacity_mode'] = 'required'; $facts['capacity_source'] = [ 'format_version' => 1, 'site_id' => F::binding()->site_key(), 'source_id' => 'capacity-source', 'version' => 1, 'digest' => hash( 'sha256', 'captured-capacity-source' ) ]; $this->install_policy( ServicePromisePolicy::from_array( $facts ) );
		$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Required native promise capacity is unavailable.' ); $this->capture();
	}
	/** Injected future-port contract only; no mounted native capacity authority or schema is claimed. */
	public function test_required_capacity_future_port_preserves_unknown_demand_and_exclusive_validity_on_the_original_owner(): void {
		$facts = F::policy()->private_facts(); $facts['anchor'] = 'checkout_capture'; $facts['capacity_mode'] = 'required'; $facts['capacity_source'] = [ 'format_version' => 1, 'site_id' => F::binding()->site_key(), 'source_id' => 'fixture-future-capacity', 'version' => 1, 'digest' => hash( 'sha256', 'explicit-injected-capacity-port' ) ]; $this->install_policy( ServicePromisePolicy::from_array( $facts ) );
		$observer = new class( $this->factory ) implements PromiseCapacityObserver {
			public int $calls = 0; public ?PromiseCapacityObservation $observation = null;
			public function __construct( private DataLifecycleProofFactory $factory ) {}
			public function observe( PromiseInput $input ): PromiseCapacityObservation { TestCase::assertTrue( $this->factory->sessions[array_key_last( $this->factory->sessions )]->is_retired() ); TestCase::assertSame( 'required', $input->capacity()->mode() ); TestCase::assertSame( 'unknown', $input->capacity()->state() ); ++$this->calls; $facts = $input->capacity()->private_facts(); $facts['state'] = 'available'; $facts['valid_until'] = '2026-10-09 10:01:30.000000'; return $this->observation = PromiseCapacityObservation::from_array( $facts ); }
		};
		$capacity_fence = new class( $observer ) implements PromiseCapacityCurrentFence {
			public int $calls = 0; public ?OperationSession $expected_owner = null;
			public function __construct( private PromiseCapacityObserver $observer ) {}
			public function tables( OperationSession $session, PromiseSiteBinding $binding ): array { return []; }
			public function verify( OperationSession $session, PromiseSiteBinding $binding, PromiseCapacityObservation $original ): bool { ++$this->calls; return $session === $this->expected_owner && $session->in_transaction() && ! $session->is_retired() && null !== $session->get_row( 'SELECT CONNECTION_ID() AS p04_capacity_owner' ) && F::binding()->site_key() === $binding->site_key() && hash_equals( $this->observer->observation->digest(), $original->digest() ); }
		};
		$service = $this->service( $observer, $capacity_fence ); $base = Q::context(); $capture = $service->capture( $base, Q::owner(), RuleTime::parse( '2026-10-09 10:00:00.000000' ), $this->registry()->create_demands( $base ) ); $context = $capture->context(); $source = $service->current_fence( $context, RuleTime::parse( '2026-10-09 10:01:29.999999' ) ); $before = count( $this->factory->sessions ); $session = $this->factory->open(); $capacity_fence->expected_owner = $session; self::assertTrue( $session->begin() );
		try { $validity = $source->validity_context( $session, Q::owner(), $context ); self::assertNotNull( $validity ); self::assertFalse( $source->at( RuleTime::parse( '2026-10-09 10:01:30.000000' ) )->verify_context( $session, Q::owner(), $context ) ); self::assertCount( $before + 1, $this->factory->sessions ); self::assertSame( 1, $observer->calls ); self::assertSame( 1, $capacity_fence->calls ); self::assertSame( 'required', PromiseHistoricalPacket::from_array( $capture->promise_groups()[0]['packet'] )->input_facts()['capacity']['mode'] ); }
		finally { $session->rollback(); $session->retire(); }
		self::assertFalse( $validity->valid_at( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::parse( '2026-10-09 10:01:30.000000' ) ) );
	}
	public function test_required_unknown_observation_cannot_be_reclassified_as_none_by_an_injected_observer(): void {
		$facts = F::policy()->private_facts(); $facts['capacity_mode'] = 'required'; $facts['capacity_source'] = [ 'format_version' => 1, 'site_id' => F::binding()->site_key(), 'source_id' => 'fixture-future-capacity', 'version' => 1, 'digest' => hash( 'sha256', 'explicit-injected-capacity-port' ) ]; $this->install_policy( ServicePromisePolicy::from_array( $facts ) );
		$observer = new class implements PromiseCapacityObserver { public function observe( PromiseInput $input ): PromiseCapacityObservation { TestCase::assertSame( 'required', $input->capacity()->mode() ); TestCase::assertSame( 'unknown', $input->capacity()->state() ); return PromiseCapacityObservation::from_array( [ 'format_version' => 1, 'mode' => 'none' ] ); } };
		$fence = new class implements PromiseCapacityCurrentFence { public function tables( OperationSession $session, PromiseSiteBinding $binding ): array { return []; } public function verify( OperationSession $session, PromiseSiteBinding $binding, PromiseCapacityObservation $original ): bool { return false; } };
		$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Required native promise capacity is unavailable.' ); $base = Q::context(); $this->service( $observer, $fence )->capture( $base, Q::owner(), RuleTime::parse( '2026-10-09 10:00:00.000000' ), $this->registry()->create_demands( $base ) );
	}
	public function test_quote_and_promise_deadline_equality_refuse_without_retiming_source(): void {
		$this->install_policy(); $context = $this->capture()->context(); self::assertTrue( $this->verify( $context, '2026-10-09 10:04:59.999999' ) ); self::assertFalse( $this->verify( $context, '2026-10-09 10:05:00.000000' ) ); self::assertFalse( $this->verify( $context, '2026-10-09 10:05:00.000001' ) );
	}
	public function test_final_clock_rechecks_calendar_lifecycle_expiry_without_recapturing_runtime_or_retiming_quote(): void {
		$facts = F::calendar()->private_facts(); $facts['tzdata_version'] = timezone_version_get(); $calendar = BusinessCalendarVersion::from_array( $facts ); foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $this->transition( $action, $calendar, '2026-10-09 10:02:00.000000' ); } $this->install_policy( F::policy( [ $calendar ] ) ); $capture = $this->capture(); $context = $capture->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $session = $this->factory->open(); self::assertTrue( $session->begin() );
		try {
			self::assertTrue( $fence->verify_context( $session, Q::owner(), $context ) ); $validity = $fence->at( RuleTime::parse( '2026-10-09 10:01:59.999999' ) )->validity_context( $session, Q::owner(), $context ); self::assertNotNull( $validity ); self::assertFalse( $fence->at( RuleTime::parse( '2026-10-09 10:02:00.000000' ) )->verify_context( $session, Q::owner(), $context ) ); self::assertSame( '2026-10-09 10:05:00.000000', PromiseHistoricalPacket::from_array( $capture->promise_groups()[0]['packet'] )->input_facts()['anchor']['quote_expires_at'] ); self::assertSame( $context->to_private_json(), $capture->context()->to_private_json() );
		} finally { $session->rollback(); $session->retire(); }
		$transport = $this->factory->transports[array_key_last( $this->factory->transports )]; $queries = count( $transport->sql ); self::assertTrue( $validity->valid_at( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::parse( '2026-10-09 10:01:59.999999' ) ) ); self::assertFalse( $validity->valid_at( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::parse( '2026-10-09 10:02:00.000000' ) ) ); self::assertTrue( $capture->terms( L::terms() )->feasibility_at( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::parse( '2026-10-09 10:02:00.000000' ) ) ); self::assertCount( $queries, $transport->sql );
	}
	public function test_lost_existing_sql_owner_refuses_without_replacement_connection(): void {
		$this->install_policy(); $context = $this->capture()->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $session = $this->factory->open(); self::assertTrue( $session->begin() ); $count = count( $this->factory->sessions ); $owner = (int) $session->get_row( 'SELECT CONNECTION_ID() AS owner' )['owner']; DB::execute( $this->database, 'KILL CONNECTION ' . $owner ); self::assertFalse( $fence->verify_context( $session, Q::owner(), $context ) ); self::assertTrue( $session->is_retired() ); self::assertCount( $count, $this->factory->sessions );
	}
	public function test_literal_same_day_last_minute_caps_promise_midnight_without_retiming_price_ttl(): void {
		$facts = F::policy()->private_facts(); $facts['service']['code'] = 'same_day'; $facts['service']['customer_label'] = 'Same day delivery'; $facts['day_constraint'] = 'same_day'; $facts['graph']['components'][0]['duration']['min'] = 0; $facts['graph']['components'][0]['duration']['max'] = 0; $this->install_policy( ServicePromisePolicy::from_array( $facts ) ); $base = Q::context();
		$capture = $this->service()->capture( $base, Q::owner(), RuleTime::parse( '2026-10-09 23:59:00.000000' ), $this->registry( 'same_day' )->create_demands( $base ) ); $input = PromiseHistoricalPacket::from_array( $capture->promise_groups()[0]['packet'] )->input_facts(); self::assertSame( '2026-10-10 00:00:00.000000', $input['anchor']['accept_until'] ); self::assertSame( '2026-10-10 00:04:00.000000', $input['anchor']['quote_expires_at'] ); self::assertTrue( $this->verify( $capture->context(), '2026-10-09 23:59:59.999999' ) ); self::assertFalse( $this->verify( $capture->context(), '2026-10-10 00:00:00.000000' ) );
	}
	public function test_whole_cart_locks_every_assignment_and_absence_before_any_policy_or_calendar(): void {
		$calendar_facts = F::calendar()->private_facts(); $calendar_facts['tzdata_version'] = timezone_version_get(); $calendar = BusinessCalendarVersion::from_array( $calendar_facts ); foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $this->transition( $action, $calendar ); } $this->install_policy( F::policy( [ $calendar ] ) ); $facts = Q::context()->private_facts(); $second = $facts['lines'][0]; $second['line_key'] = 'line_two'; $second['product_id'] = 12; $second['component_key'] = Q::digest( 'second-native-component' ); $facts['lines'][] = $second; $group = $facts['groups'][0]; $group['component_key'] = $second['component_key']; $group['line_keys'] = [ 'line_two' ]; $facts['groups'][] = $group; $base = QuoteContext::from_array( $facts ); $capture = $this->capture( $base ); self::assertCount( 2, $capture->promise_groups() );
		$transport = $this->factory->transports[array_key_last( $this->factory->transports )]; $seen = []; $first_policy = null;
		foreach ( $transport->sql as $index => $sql ) {
			if ( ! str_ends_with( $sql, 'FOR UPDATE' ) ) { continue; }
			if ( str_contains( $sql, 'delivery_engine_promise_assignments`' ) ) { $seen[$sql] ??= $index; }
			if ( null === $first_policy && str_contains( $sql, 'delivery_engine_promise_objects`' ) && str_contains( $sql, "kind='policy'" ) ) { $first_policy = $index; }
		}
		self::assertNotNull( $first_policy ); self::assertCount( 3, $seen ); foreach ( $seen as $first ) { self::assertLessThan( $first_policy, $first ); } self::assertTrue( $this->verify( $capture->context() ) );
	}
	public function test_changed_original_owner_refuses_before_any_source_sql(): void {
		$this->install_policy(); $context = $this->capture()->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $session = $this->factory->open(); self::assertTrue( $session->begin() ); $transport = $this->factory->transports[array_key_last( $this->factory->transports )]; $before = count( $transport->sql ); $owner = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner::from_array( array_replace( Q::owner()->facts(), [ 'session_hash' => Q::digest( 'foreign-session' ) ] ) ); self::assertFalse( $fence->verify_context( $session, $owner, $context ) ); self::assertCount( $before, $transport->sql ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
	}
	public function test_read_committed_cannot_supply_an_authoritative_absent_assignment_fence(): void {
		$this->install_policy(); $context = $this->capture()->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $factory = $this->physical_factory( 'READ COMMITTED' ); $session = $factory->open(); self::assertTrue( $session->begin() ); self::assertFalse( $fence->verify_context( $session, Q::owner(), $context ) ); self::assertCount( 1, $factory->sessions ); foreach ( $factory->transports[0]->sql as $sql ) { self::assertFalse( str_contains( $sql, 'delivery_engine_promise_assignments`' ) ); } self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
	}
	public function test_actual_stalled_source_read_retires_one_owner_without_replacement_or_effect(): void {
		$this->install_policy(); $context = $this->capture()->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $queries = 0; $errno = 0; $factory = $this->physical_factory( configure: static function( Transport $transport ) use ( &$queries, &$errno ): void { $transport->before = static function( string $sql, Transport $transport ) use ( &$queries, &$errno ): void { if ( 0 === $queries && str_contains( $sql, 'delivery_engine_promise_assignments`' ) && str_ends_with( $sql, 'FOR UPDATE' ) ) { ++$queries; $response = $transport->native_execute( 'SELECT SLEEP(8) AS p04_source_stall' ); $errno = $response->errno; } }; } ); $session = $factory->open(); self::assertTrue( $session->begin() ); $before = $this->row( 'promise_assignments' ); $start = hrtime( true ); self::assertFalse( $fence->verify_context( $session, Q::owner(), $context ) ); $elapsed = ( hrtime( true ) - $start ) / 1000000000; self::assertSame( 1, $queries ); self::assertContains( $errno, [ 2006, 2013 ] ); self::assertGreaterThanOrEqual( 4, $elapsed ); self::assertLessThan( 7.5, $elapsed ); self::assertCount( 1, $factory->sessions ); self::assertTrue( $session->is_retired() ); self::assertSame( $before, $this->row( 'promise_assignments' ) );
		fwrite( STDERR, json_encode( [ 'case' => 'p04_stalled_native_source_read', 'elapsed_ms' => (int) round( $elapsed * 1000 ), 'native_errno' => $errno, 'stalled_queries' => $queries, 'owner_connections' => count( $factory->sessions ), 'implicit_replacements' => 0, 'source_effects' => 0, 'all_owners_retired' => true ], JSON_THROW_ON_ERROR ) . "\n" );
	}
	public static function overlaps(): array { return [ 'assignment' => [ true ], 'policy publication lifecycle' => [ false ] ]; }
	#[DataProvider( 'overlaps' )]
	public function test_concurrent_p02_source_mutation_waits_for_the_existing_handoff_owner_and_then_invalidates_it( bool $assignment ): void {
		$policy = $this->install_policy(); $context = $this->capture()->context(); $fence = PromiseHandoffSourceFence::for_context( F::binding(), $context, RuleTime::parse( '2026-10-09 10:01:00.000000' ) ); $session = $this->factory->open(); self::assertTrue( $session->begin() ); self::assertTrue( $fence->verify_context( $session, Q::owner(), $context ) ); $owner = (int) $session->get_row( 'SELECT CONNECTION_ID() AS owner' )['owner'];
		if ( $assignment ) { $operation = 'promise.assignment.set'; $payload = F::assignment_payload( null, mode: 'inherit' ); $payload['key']['scope_kind'] = 'product'; $payload['key']['scope_id'] = 10; }
		else { $operation = 'promise.version.retire'; $payload = F::version_payload( $policy, $this->row( 'promise_objects', "kind='policy'" ), $this->row( 'promise_versions', "kind='policy'" ) ); }
		$directory = OperationProofBarrier::directory(); $worker = null;
		try {
			$worker = new OperationProofProcess( [ dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/source-overlap-worker.php', json_encode( [ 'prefix' => $this->prefix, 'operation' => $operation, 'payload' => $payload, 'material_started' => $directory . '/material-started' ], JSON_THROW_ON_ERROR ) ] ); OperationProofProcess::wait_for( $directory . '/material-started' ); $blocked = false; $end = hrtime( true ) + 1000000000;
			do { $blocked = (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS waits JOIN information_schema.INNODB_TRX blocker ON blocker.trx_id=waits.blocking_trx_id WHERE blocker.trx_mysql_thread_id={$owner}" ) > 0; if ( $blocked ) { break; } usleep( 10000 ); } while ( hrtime( true ) < $end );
			self::assertTrue( $blocked, 'Actual source mutation did not wait on the original fence owner.' ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); $result = $worker->finish(); self::assertSame( 'accepted', $result['state'] ); self::assertTrue( $result['all_retired'] ); self::assertFalse( $this->verify( $context ) );
		} finally { if ( $session->in_transaction() ) { $session->rollback(); } $session->retire(); unset( $worker ); OperationProofBarrier::cleanup( $directory ); }
	}
}
