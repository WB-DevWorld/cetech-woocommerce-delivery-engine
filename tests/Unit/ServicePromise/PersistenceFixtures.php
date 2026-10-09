<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSourceReceipt;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredAssignment;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredObject;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;

/** Pure fixtures: these bytes do not claim physical C03 acknowledgements. */
final class PersistenceFixtures {
	public const CREATED = '2026-10-09 08:00:00.000000';
	public const SEALED = '2026-10-09 08:01:00.000000';
	public const PUBLISHED = '2026-10-09 08:02:00.000000';
	public const RETIRED = '2026-10-09 08:03:00.000000';
	public const UUID = '550e8400-e29b-41d4-a716-446655440000';
	public static function body( string $kind = 'policy' ): ServicePromisePolicy|BusinessCalendarVersion {
		if ( 'calendar' === $kind ) { return BusinessCalendarVersion::from_array( [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'warehouse', 'version' => 1, 'timezone' => 'Africa/Accra', 'tzdata_version' => '2026a', 'weekly_openings' => array_fill_keys( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ], [] ), 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ] ); }
		return ServicePromisePolicy::from_array( [ 'format_version' => 1, 'site_id' => 'site-1', 'policy_id' => 'local-standard', 'version' => 1, 'effective_from' => self::CREATED, 'effective_until' => '2026-10-10 08:00:00.000000', 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ], 'anchor' => 'order_accepted', 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'Africa/Accra', 'graph' => [ 'format_version' => 1, 'components' => [ [ 'format_version' => 1, 'component_id' => 'delivery', 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 0, 'max' => 0, 'unit' => 'elapsed_minutes', 'calendar' => null ], 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => [], 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'source' => [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => 'configured', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ] ] ], 'terminal_component_ids' => [ 'delivery' ] ], 'calendars' => [], 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'refuse_if_infeasible', 'capacity_mode' => 'none', 'capacity_source' => null ] );
	}
	public static function object( string $kind = 'policy', string $state = 'draft' ): array {
		return [ 'id' => 1, 'site_id' => 1, 'site_key' => 'site-1', 'kind' => $kind, 'logical_id' => 'policy' === $kind ? 'local-standard' : 'warehouse', 'revision' => match ( $state ) { 'draft' => 2, 'sealed' => 3, 'published' => 4, 'retired' => 5 }, 'last_sequence' => 1, 'draft_version_id' => in_array( $state, [ 'draft', 'sealed' ], true ) ? 1 : null, 'scheduled_version_id' => null, 'published_version_id' => 'published' === $state ? 1 : null, 'latest_version_id' => 1, 'latest_source_receipt_digest' => self::receipt( $kind, $state )->digest(), 'created_at' => self::CREATED, 'updated_at' => match ( $state ) { 'draft' => self::CREATED, 'sealed' => self::SEALED, 'published' => self::PUBLISHED, 'retired' => self::RETIRED } ];
	}
	public static function receipt( string $kind = 'policy', string $state = 'draft' ): PromiseSourceReceipt {
		$body = self::body( $kind ); $logical = 'policy' === $kind ? 'local-standard' : 'warehouse'; $revision = match ( $state ) { 'draft' => 1, 'sealed' => 2, 'published' => 3, 'retired' => 4 };
		return PromiseSourceReceipt::from_array( self::common() + [ 'kind' => 'version', 'role' => 'primary', 'operation' => match ( $state ) { 'draft' => 'promise.version.create', 'sealed' => 'promise.version.seal', 'published' => 'promise.version.publish', 'retired' => 'promise.version.retire' }, 'accepted_at' => match ( $state ) { 'draft' => self::CREATED, 'sealed' => self::SEALED, 'published' => self::PUBLISHED, 'retired' => self::RETIRED }, 'before_revision' => $revision, 'after_revision' => $revision + 1, 'object_id' => 1, 'version_uuid' => self::UUID, 'domain_version' => 1, 'content_digest' => $body->digest(), 'logical_digest' => PromiseStoredObject::identity_digest( 1, 'site-1', $kind, $logical ), 'version_before_revision' => $revision - 1, 'version_after_revision' => $revision, 'state' => $state, 'declared_from' => self::CREATED, 'declared_until' => 'policy' === $kind ? '2026-10-10 08:00:00.000000' : null, 'calendar_publications' => [] ] );
	}
	public static function common(): array {
		return [ 'format_version' => 1, 'site_id' => 1, 'site_key' => 'site-1', 'namespace_hash' => str_repeat( 'b', 64 ), 'intent_hash' => str_repeat( 'c', 64 ), 'target_digest' => str_repeat( 'd', 64 ), 'author_user_id' => 7, 'authority_hash' => str_repeat( 'e', 64 ), 'principal_hash' => str_repeat( 'f', 64 ), 'reason' => 'Explicit policy change' ];
	}
	public static function version( string $kind = 'policy', string $state = 'draft' ): array {
		$body = self::body( $kind ); $create = self::receipt( $kind ); $source = self::receipt( $kind, $state ); $publication = in_array( $state, [ 'published', 'retired' ], true ) ? self::receipt( $kind, 'published' ) : null;
		return [ 'id' => 1, 'site_id' => 1, 'site_key' => 'site-1', 'object_id' => 1, 'kind' => $kind, 'logical_id' => 'policy' === $kind ? 'local-standard' : 'warehouse', 'version_uuid' => self::UUID, 'format_version' => 1, 'domain_version' => 1, 'row_revision' => match ( $state ) { 'draft' => 1, 'sealed' => 2, 'published' => 3, 'retired' => 4 }, 'state' => $state, 'body_json' => $body->to_private_json(), 'body_digest' => $body->digest(), 'declared_from' => self::CREATED, 'declared_until' => 'policy' === $kind ? '2026-10-10 08:00:00.000000' : null, 'created_at' => self::CREATED, 'sealed_at' => 'draft' === $state ? null : self::SEALED, 'scheduled_at' => null, 'published_at' => $publication?->accepted_at()->sql(), 'retired_at' => 'retired' === $state ? self::RETIRED : null, 'author_user_id' => 7, 'reason' => 'Explicit policy change', 'predecessor_version_id' => null, 'schedule_expected_object_revision' => null, 'schedule_expected_published_version_id' => null, 'create_receipt_json' => $create->to_private_json(), 'create_receipt_digest' => $create->digest(), 'publication_receipt_json' => $publication?->to_private_json(), 'publication_receipt_digest' => $publication?->digest(), 'source_receipt_json' => $source->to_private_json(), 'source_receipt_digest' => $source->digest() ];
	}
	public static function assignment_key(): array { return [ 'site_id' => 1, 'site_key' => 'site-1', 'scope_kind' => 'global', 'scope_id' => 0, 'service_kind' => 'built_in', 'service_code' => 'standard', 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep' ]; }
	public static function assignment( string $state = 'assigned', bool $baseline = false ): array {
		$publication = self::receipt( 'policy', 'published' ); $reference = self::body()->reference();
		$receipt = $baseline ? null : PromiseSourceReceipt::from_array( self::common() + [ 'kind' => 'assignment', 'operation' => 'promise.assignment.set', 'accepted_at' => self::RETIRED, 'before_revision' => 1, 'after_revision' => 2, 'assignment_key_hash' => PromiseStoredAssignment::key_digest( self::assignment_key() ), 'mode' => $state, 'generation_before' => 0, 'generation_after' => 1, 'policy_publication' => 'assigned' === $state ? [ 'reference' => $reference->private_facts(), 'publication_digest' => $publication->digest(), 'published_at' => self::PUBLISHED ] : null ] );
		return [ 'id' => 1 ] + self::assignment_key() + [ 'revision' => $baseline ? 1 : 2, 'generation' => $baseline ? 0 : 1, 'state' => $baseline ? 'inherit' : $state, 'policy_object_id' => ! $baseline && 'assigned' === $state ? 1 : null, 'policy_version_id' => ! $baseline && 'assigned' === $state ? 1 : null, 'policy_reference_json' => ! $baseline && 'assigned' === $state ? $reference->to_private_json() : null, 'created_at' => self::RETIRED, 'updated_at' => self::RETIRED, 'source_receipt_json' => $receipt?->to_private_json(), 'source_receipt_digest' => $receipt?->digest() ];
	}
}
