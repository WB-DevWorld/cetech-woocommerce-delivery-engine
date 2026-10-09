<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\ServicePromise;

use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseStoredObject, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, PromiseStorageReadiness, PromiseStorageSchema, RuleLifecycleSchema};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

/** Pure typed bodies plus tracked-prefix SQL fixtures; never a live-site connector. */
final class PromisePersistenceFixtures {
	public const FROM = '2026-01-01 00:00:00.000000';
	public static function binding(): PromiseSiteBinding { return PromiseSiteBinding::bind( 1, 'private-fixture-site' ); }
	public static function uuid( int $number ): string { return sprintf( '5e71ce00-0000-4000-8000-%012d', $number ); }
	public static function calendar( string $id = 'working-hours', int $version = 1, string $site = 'private-fixture-site' ): BusinessCalendarVersion {
		$week = []; foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ] as $day ) { $week[$day] = [ [ 'open' => '09:00', 'close' => '17:00' ] ]; }
		return BusinessCalendarVersion::from_array( [ 'format_version' => 1, 'site_id' => $site, 'calendar_id' => $id, 'version' => $version, 'timezone' => 'UTC', 'tzdata_version' => 'fixture-pinned', 'weekly_openings' => $week, 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ] );
	}
	public static function policy( array $calendars = [], string $id = 'standard-delivery', int $version = 1, string $from = self::FROM, ?string $until = null, string $site = 'private-fixture-site' ): ServicePromisePolicy {
		$refs = array_map( static fn( BusinessCalendarVersion $calendar ): array => $calendar->reference()->private_facts(), $calendars ); $calendar = $refs[0] ?? null;
		return ServicePromisePolicy::from_array( [ 'format_version' => 1, 'site_id' => $site, 'policy_id' => $id, 'version' => $version, 'effective_from' => $from, 'effective_until' => $until, 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard delivery' ], 'anchor' => 'order_accepted', 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'UTC', 'graph' => [ 'format_version' => 1, 'components' => [ [ 'format_version' => 1, 'component_id' => 'delivery', 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 1, 'max' => 2, 'unit' => null === $calendar ? 'elapsed_minutes' : 'business_days', 'calendar' => $calendar ], 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => [], 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'source' => [ 'format_version' => 1, 'site_id' => $site, 'source_id' => 'declared-merchant-source', 'version' => 1, 'digest' => hash( 'sha256', 'declared-fixture-source' ) ] ] ], 'terminal_component_ids' => [ 'delivery' ] ], 'calendars' => $refs, 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'refuse_if_infeasible', 'capacity_mode' => 'none', 'capacity_source' => null ] );
	}
	public static function version_payload( BusinessCalendarVersion|ServicePromisePolicy $body, ?array $object = null, ?array $stored = null ): array {
		$facts = $body->private_facts(); $policy = $body instanceof ServicePromisePolicy;
		$logical = $facts[$policy ? 'policy_id' : 'calendar_id']; $uuid = '5e71ce00-0000-4000-8000-' . substr( hash( 'sha256', $facts['site_id'] . ':' . ( $policy ? 'policy' : 'calendar' ) . ':' . $logical . ':' . $facts['version'] ), 0, 12 );
		return [ 'kind' => $policy ? 'policy' : 'calendar', 'logical_id' => $logical, 'domain_version' => $facts['version'], 'version_uuid' => $stored['version_uuid'] ?? $uuid, 'body_digest' => $body->digest(), 'scope' => $policy ? PromiseVersionCommand::policy_scope( $body ) : [ 'kind' => 'global', 'target_id' => 0 ], 'body_json' => null === $stored ? $body->to_private_json() : null, 'declared_from' => $policy ? $facts['effective_from'] : self::FROM, 'declared_until' => $policy ? $facts['effective_until'] : null, 'author_user_id' => 7, 'reason' => 'Private immutable persistence fixture', 'scheduled_author_user_id' => null, 'preconditions' => [ 'object_revision' => (int) ( $object['revision'] ?? 0 ), 'version_revision' => (int) ( $stored['row_revision'] ?? 0 ), 'published_version_id' => (int) ( $object['published_version_id'] ?? 0 ) ] ];
	}
	public static function version_identity( string $operation, array $payload, string $token, ?PromiseSiteBinding $binding = null ): OperationIdentity {
		$binding ??= self::binding(); return new OperationIdentity( $binding->site_id(), 'promise.persistence.fixture.v1', 'user:7', $operation, 1, PromiseVersionCommand::target_key( $binding, $payload['kind'], $payload['logical_id'] ), $token );
	}
	public static function assignment_key( ?PromiseSiteBinding $binding = null ): array { $binding ??= self::binding(); return [ 'site_id' => $binding->site_id(), 'site_key' => $binding->site_key(), 'scope_kind' => 'global', 'scope_id' => 0, 'service_kind' => 'built_in', 'service_code' => 'standard', 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep' ]; }
	public static function assignment_payload( ?ServicePromisePolicy $policy, ?array $assignment = null, string $mode = 'assigned' ): array { return [ 'key' => array_diff_key( self::assignment_key(), array_flip( [ 'site_id', 'site_key' ] ) ), 'mode' => $mode, 'policy_reference' => $policy?->reference()->private_facts(), 'expected_revision' => (int) ( $assignment['revision'] ?? 0 ), 'expected_generation' => (int) ( $assignment['generation'] ?? 0 ), 'author_user_id' => 7, 'reason' => 'Private exact assignment fixture' ]; }
	public static function assignment_identity( array $payload, string $token ): OperationIdentity { $binding = self::binding(); return new OperationIdentity( 1, 'promise.persistence.fixture.v1', 'user:7', PromiseAssignmentCommand::OPERATION, 1, PromiseAssignmentCommand::target_key( $binding, $payload['key'] ), $token ); }
	public static function authority(): PromisePersistenceAuthorizer { return new class implements PromisePersistenceAuthorizer {
		public bool $allowed = true;
		public bool $original_author_allowed = true;
		public int $calls = 0;
		public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool { ++$this->calls; return $this->allowed && in_array( $author_user_id, [ 0, 7 ], true ) && 1 === $identity->site_id && 1 === $binding->site_id() && 'user:7' === $identity->principal; }
		public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return $this->allowed && $this->original_author_allowed && 7 === $author_user_id && 1 === $binding->site_id(); }
	}; }
	/** Real owned schemas; migration proof itself is exercised separately in native WordPress. */
	public static function install( \mysqli $database, string $prefix ): void {
		DB::install( $database, $prefix );
		foreach ( PromiseStorageSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) { DB::execute( $database, $sql ); }
		$status = $database->real_escape_string( serialize( [ 'status' => 'success', 'to_version' => '10', 'migration_id' => PromiseStorageReadiness::MIGRATION_ID ] ) );
		$version_name = $database->real_escape_string( SchemaVersion::OPTION_NAME ); $status_name = $database->real_escape_string( MigrationStatus::OPTION_NAME );
		DB::execute( $database, "UPDATE `{$prefix}options` SET option_value='10' WHERE option_name='{$version_name}'" );
		DB::execute( $database, "UPDATE `{$prefix}options` SET option_value='{$status}' WHERE option_name='{$status_name}'" );
	}
	public static function cleanup( \mysqli $database, string $prefix ): void { DB::cleanup( $database, $prefix ); }
}
