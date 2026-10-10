<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Integration\ServicePromise;

use CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseNativeCaptureService;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseVersionLifecycleService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseHandoffSources;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\{DataLifecycleProofDatabase as DB, DataLifecycleProofFactory};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Support\ServicePromise\PromisePersistenceFixtures as F;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Physical native transport traces; contexts are explicitly synthetic detached facts, not Woo carts. */
#[Group( 'service-promise-persistence-real-db' )]
final class PromiseQualificationBoundsRealDatabaseTest extends TestCase {
	private \mysqli $database; private string $prefix; private DataLifecycleProofFactory $factory;
	protected function setUp(): void { $this->database = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->database, $this->prefix ); $this->factory = new DataLifecycleProofFactory( $this->prefix, clock: intdiv( RuleTime::parse( '2026-10-09 09:00:00.000000' )->epoch_microseconds(), 1000000 ) ); }
	protected function tearDown(): void { if ( isset( $this->factory ) ) { $this->factory->close_all(); } if ( isset( $this->database, $this->prefix ) ) { F::cleanup( $this->database, $this->prefix ); $this->database->close(); } }
	private function row( string $suffix, string $where ): ?array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` WHERE {$where} ORDER BY id DESC LIMIT 1" ); }
	private function publish( BusinessCalendarVersion|ServicePromisePolicy $body ): void {
		$versions = new PromiseVersionLifecycleService( F::binding(), $this->factory, F::authority() ); $facts = $body->private_facts(); $kind = $body instanceof ServicePromisePolicy ? 'policy' : 'calendar'; $id = $this->database->real_escape_string( $facts[$kind . '_id'] );
		foreach ( [ 'create', 'seal', 'publish' ] as $action ) { $payload = F::version_payload( $body, $this->row( 'promise_objects', "kind='{$kind}' AND logical_id='{$id}'" ), 'create' === $action ? null : $this->row( 'promise_versions', "kind='{$kind}' AND logical_id='{$id}' AND domain_version={$facts['version']}" ) ); self::assertSame( 'accepted', $versions->attempt( F::version_identity( 'promise.version.' . $action, $payload, bin2hex( random_bytes( 8 ) ) ), $payload, RequestContext::create() )->outcome->state ); }
	}
	private function install_sources(): ServicePromisePolicy {
		$facts = F::calendar()->private_facts(); $facts['tzdata_version'] = timezone_version_get(); $calendar = BusinessCalendarVersion::from_array( $facts ); $this->publish( $calendar ); $policy = F::policy( [ $calendar ] ); $this->publish( $policy );
		$payload = F::assignment_payload( $policy ); $assignments = new PromiseAssignmentService( F::binding(), $this->factory, F::authority() ); self::assertSame( 'accepted', $assignments->attempt( F::assignment_identity( $payload, 'p06-initial-assignment' ), $payload, RequestContext::create() )->outcome->state ); return $policy;
	}
	private static function context( int $groups ): QuoteContext {
		$base = Q::context()->private_facts(); $line = $base['lines'][0]; $group = $base['groups'][0]; $base['lines'] = []; $base['groups'] = [];
		for ( $i = 0; $i < $groups; ++$i ) { $part = $line; $part['line_key'] = 'line_' . $i; $part['product_id'] = 10 + $i; $part['component_key'] = Q::digest( 'p06-group-' . $i ); $component = $group; $component['component_key'] = $part['component_key']; $component['line_keys'] = [ $part['line_key'] ]; $base['lines'][] = $part; $base['groups'][] = $component; }
		return QuoteContext::from_array( $base );
	}
	private static function registry(): PromiseNativeServiceRegistry { return new PromiseNativeServiceRegistry( [ [ 'native_service_id' => 30, 'service_kind' => 'built_in', 'service_code' => 'standard', 'origin_endpoint' => 'dispatch-origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'customer-door', 'destination_kind' => 'doorstep' ] ] ); }
	/** Count actual native SELECT FOR UPDATE calls, including returned absences, with distinct SQL witnesses. */
	private static function trace( array $queries ): array {
		$counts = [ 'assignments' => 0, 'policy_objects' => 0, 'policy_versions' => 0, 'calendar_objects' => 0, 'calendar_versions' => 0 ]; $distinct = [];
		$source_selects = [];
		foreach ( $queries as $sql ) { if ( str_starts_with( $sql, 'SELECT ' ) && preg_match( '/\bFROM `[^`]*delivery_engine_(?:promise_(?:objects|versions|assignments)|operation_(?:records|changes))`/', $sql ) ) { self::assertArrayNotHasKey( $sql, $source_selects, 'The exact source or acknowledged-receipt SELECT was sent twice in one prime.' ); $source_selects[$sql] = true; } }
		foreach ( $queries as $sql ) { if ( ! str_ends_with( $sql, 'FOR UPDATE' ) ) { continue; } $kind = null;
			if ( str_contains( $sql, 'delivery_engine_promise_assignments`' ) ) { $kind = 'assignments'; }
			elseif ( str_contains( $sql, 'delivery_engine_promise_objects`' ) ) { $kind = str_contains( $sql, "kind='policy'" ) ? 'policy_objects' : 'calendar_objects'; }
			elseif ( str_contains( $sql, 'delivery_engine_promise_versions`' ) ) { $kind = str_contains( $sql, "kind='policy'" ) ? 'policy_versions' : 'calendar_versions'; }
			if ( null !== $kind ) { ++$counts[$kind]; $distinct[$kind][$sql] = true; }
		}
		foreach ( $counts as $kind => $count ) { self::assertSame( $count, count( $distinct[$kind] ?? [] ), 'The held source identity was fetched more than once: ' . $kind ); }
		return $counts;
	}
	public function test_stack_local_prime_bulk_loads_unique_shared_policy_calendar_and_assignment_census_once(): void {
		$this->install_sources(); $registry = self::registry();
		$version_select_counts = [];
		foreach ( [ 1, 2, 4 ] as $groups ) { $base = self::context( $groups ); $demands = $registry->create_demands( $base ); $services = []; foreach ( $demands as $demand ) { $services[$demand->component_key()] = $demand->service_endpoint(); }
			$session = $this->factory->open(); self::assertTrue( $session->begin() ); $transport = $this->factory->transports[array_key_last( $this->factory->transports )]; $start = count( $transport->sql );
			try { $captured = ( new WpdbPromiseHandoffSources( $session, F::binding() ) )->prime( $base, $services, RuleTime::parse( '2026-10-09 10:00:00.000000' ) ); self::assertIsArray( $captured ); self::assertCount( $groups, $captured ); $queries = array_slice( $transport->sql, $start ); $trace = self::trace( $queries ); self::assertSame( [ 'assignments' => $groups + 1, 'policy_objects' => 1, 'policy_versions' => 1, 'calendar_objects' => 1, 'calendar_versions' => 1 ], $trace ); $version_select_counts[] = count( array_filter( $queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && str_contains( $sql, 'delivery_engine_promise_versions`' ) ) ); }
			finally { self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() ); }
			fwrite( STDERR, json_encode( [ 'case' => 'p06_unique_shared_source_prime', 'groups' => $groups, 'unique_queries' => $trace, 'physical_transport' => true, 'synthetic_context' => true ], JSON_THROW_ON_ERROR ) . "\n" );
		}
		self::assertSame( [ $version_select_counts[0], $version_select_counts[0], $version_select_counts[0] ], $version_select_counts, 'Shared immutable version SQL scaled with group count.' );
	}
	public function test_actual_capture_queries_are_deduplicated_and_authority_callbacks_run_only_outside_owned_sql(): void {
		$this->install_sources(); $factory = $this->factory;
		$authority = new class( $factory ) implements PromisePersistenceAuthorizer {
			public int $calls = 0; public int $owned_calls = 0;
			public function __construct( private DataLifecycleProofFactory $factory ) {}
			public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool { ++$this->calls; foreach ( $this->factory->sessions as $session ) { if ( ! $session->is_retired() && $session->in_transaction() ) { ++$this->owned_calls; } } return 'promise.assignment.read' === $identity->operation && Q::owner()->digest() === $identity->principal; }
			public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; }
		};
		$service = new PromiseNativeCaptureService( F::binding(), $factory, $authority ); $base = self::context( 2 ); $before = count( $factory->sessions ); $capture = $service->capture( $base, Q::owner(), RuleTime::parse( '2026-10-09 10:00:00.000000' ), self::registry()->create_demands( $base ) );
		self::assertCount( 2, $capture->promise_groups() ); self::assertCount( $before + 1, $factory->sessions ); self::assertSame( 0, $authority->owned_calls ); self::assertGreaterThan( 0, $authority->calls ); self::assertTrue( $factory->sessions[array_key_last( $factory->sessions )]->is_retired() );
		$trace = self::trace( $factory->transports[array_key_last( $factory->transports )]->sql ); self::assertSame( [ 'assignments' => 3, 'policy_objects' => 1, 'policy_versions' => 1, 'calendar_objects' => 1, 'calendar_versions' => 1 ], $trace );
		fwrite( STDERR, json_encode( [ 'case' => 'p06_capture_unique_sources_authority_order', 'unique_queries' => $trace, 'source_connections' => 1, 'authority_callbacks' => $authority->calls, 'callbacks_during_owned_sql' => $authority->owned_calls, 'all_retired_before_calculation' => true, 'native_wordpress_network_claim' => false ], JSON_THROW_ON_ERROR ) . "\n" );
	}
	public function test_a_second_transaction_never_reuses_a_cached_eligible_policy_after_physical_retirement(): void {
		$policy = $this->install_sources(); $base = self::context( 2 ); $services = []; foreach ( self::registry()->create_demands( $base ) as $demand ) { $services[$demand->component_key()] = $demand->service_endpoint(); }
		$session = $this->factory->open(); $repository = new WpdbPromiseHandoffSources( $session, F::binding() ); self::assertTrue( $session->begin() ); self::assertCount( 2, $repository->prime( $base, $services, RuleTime::parse( '2026-10-09 10:00:00.000000' ) ) ); self::assertTrue( $session->rollback() );
		$versions = new PromiseVersionLifecycleService( F::binding(), $this->factory, F::authority() ); $payload = F::version_payload( $policy, $this->row( 'promise_objects', "kind='policy'" ), $this->row( 'promise_versions', "kind='policy'" ) ); self::assertSame( 'accepted', $versions->attempt( F::version_identity( 'promise.version.retire', $payload, 'p06-between-primes' ), $payload, RequestContext::create() )->outcome->state );
		self::assertTrue( $session->begin() ); $refused = false; try { $repository->prime( $base, $services, RuleTime::parse( '2026-10-09 10:00:00.000000' ) ); } catch ( \CetechDeliveryEngine\Application\Operation\OperationStorageException ) { $refused = true; } finally { $session->rollback(); $session->retire(); } self::assertTrue( $refused, 'Fresh transaction used a stale effective source.' );
	}
}
