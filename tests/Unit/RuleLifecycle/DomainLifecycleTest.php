<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleDecision;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleImpactPreview;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleInterval;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleMatch;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleRecordCodec;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSchema;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleState;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainLifecycleTest extends TestCase {
	private const CREATED = '2026-10-06 09:00:00.000000';
	private const START = '2026-10-06 10:00:00.000000';
	private const UPDATED = '2026-10-06 12:00:00.000000';

	public function test_utc_microsecond_round_trip_preserves_boundaries_and_explicit_offset(): void {
		$time = RuleTime::parse( '2024-02-29 23:59:59.999999' );
		self::assertSame( $time->sql(), RuleTime::from_epoch_microseconds( $time->epoch_microseconds() )->sql() );
		self::assertSame( self::START, RuleTime::from_offset( '2026-10-06T12:00:00.000000+02:00' )->sql() );
		self::assertSame( self::START, RuleTime::from_offset( '2026-10-06T10:00:00.000000Z' )->sql() );
		self::assertSame( -1, RuleTime::parse( '1969-12-31 23:59:59.999999' )->epoch_microseconds() );
		self::assertSame( '1969-12-31 23:59:59.999999', RuleTime::from_epoch_microseconds( -1 )->sql() );
	}

	#[DataProvider('invalid_instants')]
	public function test_invalid_or_ambiguous_instants_are_not_normalized( string $input ): void {
		$this->refuses( static fn() => RuleTime::parse( $input ) );
	}
	public static function invalid_instants(): array {
		return array_map( static fn( string $value ): array => [ $value ], [ '2025-02-29 10:00:00.000000', '2026-13-01 10:00:00.000000', '2026-10-06 24:00:00.000000', '2026-10-06 10:00:60.000000', '2026-10-06 10:00:00', '2026-10-06T10:00:00.000000Z', '0000-00-00 00:00:00.000000', '2026-10-06 10:00:00.000000 PRIVATE-SQL' ] );
	}

	public function test_interval_start_inclusive_end_exclusive_and_adjacency_is_not_overlap(): void {
		$start = RuleTime::parse( self::START );
		$end = RuleTime::parse( '2026-10-06 10:00:00.000001' );
		$interval = new RuleInterval( $start, $end );
		self::assertTrue( $interval->contains( $start ) );
		self::assertFalse( $interval->contains( RuleTime::from_epoch_microseconds( $start->epoch_microseconds() - 1 ) ) );
		self::assertFalse( $interval->contains( $end ) );
		self::assertFalse( $interval->overlaps( new RuleInterval( $end ) ) );
		self::assertTrue( $interval->overlaps( new RuleInterval( $start ) ) );
		self::assertTrue( ( new RuleInterval( $end ) )->contains( RuleTime::parse( self::UPDATED ) ) );
		$this->refuses( static fn() => new RuleInterval( $start, $start ) );
		$this->refuses( static fn() => new RuleInterval( $end, $start ) );
	}

	public function test_schema_canonical_objects_detach_references_and_preserve_ordered_lists(): void {
		$spec = [ 'z' => [ 'list' => [ 'item' => 'integer', 'max' => 3 ] ], 'a' => [ 'object' => [ 'enabled' => 'bool' ] ], 'empty' => [ 'object' => [] ], 'maybe' => [ 'nullable' => 'integer' ] ];
		$schema = new RuleSchema( $spec );
		$enabled = false;
		$value = [ 'z' => [ 2, 1 ], 'a' => [ 'enabled' => &$enabled ], 'empty' => [], 'maybe' => null ];
		$detached = $schema->validate( $value );
		$encoded = $schema->encode( $value );
		$enabled = true;
		self::assertFalse( $detached['a']['enabled'] );
		self::assertSame( '{"a":{"enabled":false},"empty":{},"maybe":null,"z":[2,1]}', $encoded );
		self::assertSame( $detached, $schema->decode( $encoded ) );
		self::assertNotSame( $encoded, $schema->encode( array_replace( $detached, [ 'z' => [ 1, 2 ] ] ) ) );
		self::assertSame( $schema->fingerprint(), ( new RuleSchema( array_reverse( $spec, true ) ) )->fingerprint() );
	}

	public function test_duplicate_json_keys_escaped_duplicates_and_unknown_nested_fields_refuse(): void {
		$schema = new RuleSchema( [ 'target' => [ 'object' => [ 'id' => 'positive_int' ] ] ] );
		foreach ( [ '{"target":{"id":1,"id":2}}', '{"target":{"id":1,"\\u0069d":2}}', '{"target":{"id":1,"renamed_private":{"secret":"PRIVATE-SQL"}}}', '{"target":{"id":"1"}}', '{"target":[]}', '{"target":{"id":1},"extra":true}' ] as $json ) {
			$this->refuses( static fn() => $schema->decode( $json ) );
		}
	}

	public function test_bounded_private_text_is_not_an_unbounded_or_public_serializer(): void {
		$schema = new RuleSchema( [ 'summary' => [ 'string' => 8 ] ] );
		self::assertSame( [ 'summary' => 'é' ], $schema->decode( $schema->encode( [ 'summary' => 'é' ] ) ) );
		$this->refuses( static fn() => $schema->encode( [ 'summary' => str_repeat( 'é', 5 ) ] ) );
		$this->refuses( static fn() => $schema->encode( [ 'summary' => "bad\ntext" ] ) );
		$this->refuses( static fn() => $schema->encode( [ 'summary' => "\xff" ] ) );
		$this->refuses( static fn() => $schema->encode( [ 'summary' => '12345678' ], 10 ) );
		$this->refuses( static fn() => new RuleSchema( [ 'blob' => 'string' ] ) );
		$this->refuses( static fn() => new RuleSchema( array_fill_keys( array_map( static fn( int $i ): string => 'field' . $i, range( 1, 33 ) ), 'bool' ) ) );
	}

	public function test_registry_defaults_empty_and_requires_explicit_fixed_policy(): void {
		self::assertSame( [], ( new RuleFamilyRegistry() )->profiles() );
		$this->refuses( static fn() => ( new RuleFamilyRegistry() )->get( 'fixture_availability_v1' ) );
		$profile = new DomainMutableFamily();
		$registry = new RuleFamilyRegistry( [ $profile ] );
		self::assertSame( $profile, $registry->get( $profile->family() ) );
		$this->refuses( static fn() => new RuleFamilyRegistry( [ $profile, $profile ] ) );
		$profile->order = [ [ 'field' => 'priority', 'direction' => 'desc' ] ];
		$this->refuses( static fn() => $registry->get( $profile->family() ) );
		$profile->order = [ [ 'field' => 'priority', 'direction' => 'asc' ], [ 'field' => 'logical_uuid', 'direction' => 'asc' ] ];
		$this->refuses( static fn() => new RuleFamilyRegistry( [ $profile ] ) );
		$profile->collision = 'tie_break';
		self::assertCount( 1, ( new RuleFamilyRegistry( [ $profile ] ) )->profiles() );
	}

	#[DataProvider('invalid_sql_integers')]
	public function test_sql_integer_hydration_never_coerces_invalid_values( mixed $value ): void {
		$this->refuses( static fn() => RuleRecordCodec::integer( $value ) );
	}
	public static function invalid_sql_integers(): array {
		return [ [ '01' ], [ '1e1' ], [ ' 1' ], [ '+1' ], [ '0' ], [ false ], [ 1.0 ], [ '9223372036854775808' ], [ '-1' ], [ null ] ];
	}

	public function test_current_capture_can_be_empty_after_retirement_without_losing_history(): void {
		$profile = new RuleProofFamily();
		$logical = self::logical_row( $profile, [ 'last_version_sequence' => 101, 'current_published_version_id' => null ] );
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [] );
		self::assertTrue( $snapshot->complete );
		self::assertFalse( $snapshot->history_complete );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, self::subject(), RuleTime::parse( self::START ) );
		self::assertSame( 'rule_unavailable', $decision->reason );
		self::assertNull( $decision->selected );
		$this->refuses( static fn() => RuleSnapshot::from_history_rows( $profile, self::guard_row( $profile ), [ $logical ], [] ) );
	}

	public function test_current_successor_requires_one_direct_predecessor_but_not_all_ancestors(): void {
		$profile = new RuleProofFamily();
		$logical = self::logical_row( $profile, [ 'last_version_sequence' => 3, 'current_published_version_id' => 3 ] );
		$version = self::version_row( $profile, [ 'id' => 3, 'version_uuid' => self::uuid( 3 ), 'version_sequence' => 3, 'supersedes_version_id' => 2 ] );
		$prior = self::version_row( $profile, [ 'id' => 2, 'version_uuid' => self::uuid( 2 ), 'version_sequence' => 2, 'supersedes_version_id' => 1, 'state' => 'retired', 'published_at' => '2026-10-06 09:30:00.000000', 'effective_from' => '2026-10-06 09:30:00.000000', 'sealed_at' => '2026-10-06 09:30:00.000000', 'retired_at' => self::START ] );
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [ $version, $prior ] );
		self::assertCount( 1, $snapshot->candidates() );
		self::assertSame( 3, ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, self::subject(), RuleTime::parse( self::START ) )->selected?->version_id );
		$this->refuses( static fn() => RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [ $version ] ) );
		$this->refuses( static fn() => RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [ $version, array_replace( $prior, [ 'retired_at' => '2026-10-06 10:00:00.000001' ] ) ] ) );
		$this->refuses( static fn() => RuleSnapshot::from_history_rows( $profile, self::guard_row( $profile ), [ $logical ], [ $version ] ) );
	}

	public function test_full_history_requires_exact_atomic_cutover_and_same_logical_predecessor(): void {
		$profile = new RuleProofFamily();
		$logical = self::logical_row( $profile, [ 'last_version_sequence' => 2, 'current_published_version_id' => 2 ] );
		$prior = self::version_row( $profile, [ 'state' => 'retired', 'published_at' => '2026-10-06 09:30:00.000000', 'effective_from' => '2026-10-06 09:30:00.000000', 'sealed_at' => '2026-10-06 09:30:00.000000', 'retired_at' => self::START ] );
		$next = self::version_row( $profile, [ 'id' => 2, 'version_uuid' => self::uuid( 2 ), 'version_sequence' => 2, 'supersedes_version_id' => 1 ] );
		$snapshot = RuleSnapshot::from_history_rows( $profile, self::guard_row( $profile ), [ $logical ], [ $prior, $next ] );
		self::assertTrue( $snapshot->history_complete );
		$this->refuses( static fn() => RuleSnapshot::from_history_rows( $profile, self::guard_row( $profile ), [ $logical ], [ array_replace( $prior, [ 'retired_at' => '2026-10-06 10:00:00.000001' ] ), $next ] ) );
	}

	public function test_pointer_reverse_membership_and_complete_vs_partial_capture_are_distinct(): void {
		$profile = new RuleProofFamily();
		$logical = self::logical_row( $profile );
		$this->refuses( static fn() => RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [] ) );
		$partial = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [], false );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $partial, self::subject(), RuleTime::parse( self::START ) );
		self::assertFalse( $decision->complete );
		self::assertNull( $decision->selected );
		$this->refuses( static fn() => RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ array_replace( $logical, [ 'current_published_version_id' => null ] ) ], [ self::version_row( $profile ) ] ) );
		$this->refuses( static fn() => RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical ], [ self::version_row( $profile ), self::version_row( $profile ) ] ) );
	}

	public function test_record_codec_rejects_unknown_fields_wrong_site_digest_and_payload_shape(): void {
		$profile = new RuleProofFamily();
		$guard = RuleFamilyGuard::from_row( self::guard_row( $profile ), $profile );
		$logical = LogicalRule::from_row( self::logical_row( $profile ), $guard, $profile );
		$valid = self::version_row( $profile );
		foreach ( [ $valid + [ 'renamed_private' => 'PRIVATE-SQL' ], array_replace( $valid, [ 'site_id' => 2 ] ), array_replace( $valid, [ 'content_hash' => str_repeat( 'a', 64 ) ] ), array_replace( $valid, [ 'payload_json' => '{"availability":"allow","private":"PRIVATE-SQL"}' ] ), array_replace( $valid, [ 'payload_json' => '{ "availability":"allow" }' ] ), array_replace( $valid, [ 'change_reason' => "PRIVATE-SQL\n" ] ), array_replace( $valid, [ 'priority' => '2147483648' ] ) ] as $row ) {
			$this->refuses( static fn() => RuleVersion::from_row( $row, $logical, $profile ) );
		}
	}

	public function test_scheduling_original_revision_envelope_is_mandatory_and_retained(): void {
		$profile = new RuleProofFamily();
		$guard = RuleFamilyGuard::from_row( self::guard_row( $profile ), $profile );
		$logical = LogicalRule::from_row( self::logical_row( $profile, [ 'current_published_version_id' => null, 'scheduled_version_id' => 1 ] ), $guard, $profile );
		$row = self::scheduled_row( $profile );
		$scheduled = RuleVersion::from_row( $row, $logical, $profile );
		self::assertSame( 2, $scheduled->scheduled_revision );
		self::assertSame( 2, $scheduled->scheduled_logical_revision );
		self::assertFalse( $scheduled->eligible_at( RuleTime::parse( self::UPDATED ) ) );
		foreach ( [ 'scheduled_revision', 'scheduled_logical_revision' ] as $field ) {
			$this->refuses( static fn() => RuleVersion::from_row( array_replace( $row, [ $field => null ] ), $logical, $profile ) );
		}
		$this->refuses( static fn() => RuleVersion::from_row( array_replace( $row, [ 'scheduled_predecessor_row_revision' => 1 ] ), $logical, $profile ) );
		$this->refuses( static fn() => RuleVersion::from_row( array_replace( $row, [ 'scheduled_revision' => 3 ] ), $logical, $profile ) );
		$published = RuleVersion::from_row( array_replace( $row, [ 'state' => 'published', 'published_at' => '2026-10-06 10:07:00.000000', 'row_revision' => 3 ] ), $logical, $profile );
		self::assertSame( $scheduled->content_hash, $published->content_hash );
		self::assertSame( $scheduled->scheduled_revision, $published->scheduled_revision );
		self::assertFalse( $published->eligible_at( RuleTime::parse( self::START ) ) );
		self::assertTrue( $published->eligible_at( RuleTime::parse( '2026-10-06 10:07:00.000000' ) ) );
	}

	public function test_sealed_version_detaches_payload_priority_reason_and_original_schedule_facts(): void {
		$profile = new RuleProofFamily();
		$guard = RuleFamilyGuard::from_row( self::guard_row( $profile ), $profile );
		$logical = LogicalRule::from_row( self::logical_row( $profile, [ 'current_published_version_id' => null, 'scheduled_version_id' => 1 ] ), $guard, $profile );
		$row = self::scheduled_row( $profile );
		$priority = $row['priority']; $reason = $row['change_reason']; $revision = $row['scheduled_revision'];
		$row['priority'] =& $priority; $row['change_reason'] =& $reason; $row['scheduled_revision'] =& $revision;
		$version = RuleVersion::from_row( $row, $logical, $profile );
		$priority = -100; $reason = 'Changed reason'; $revision = 9;
		self::assertSame( 100, $version->priority ); self::assertSame( 'Fixture rule', $version->change_reason ); self::assertSame( 2, $version->scheduled_revision );
		try { $version->payload['availability'] = 'deny'; self::fail( 'Sealed payload must be immutable.' ); } catch ( \Error ) { self::assertSame( [ 'availability' => 'allow' ], $version->payload ); }
		try { $version->priority = -100; self::fail( 'Sealed priority must be immutable.' ); } catch ( \Error ) { self::assertSame( 100, $version->priority ); }
		foreach ( [ $guard, $logical, $version, new RuleCandidate( $logical, $version ) ] as $private ) { $this->refuses( static fn() => json_encode( $private, JSON_THROW_ON_ERROR ) ); }
	}

	public function test_expired_activation_and_lifecycle_clock_contradictions_fail_closed(): void {
		$profile = new RuleProofFamily();
		$guard = RuleFamilyGuard::from_row( self::guard_row( $profile ), $profile );
		$logical = LogicalRule::from_row( self::logical_row( $profile ), $guard, $profile );
		$late = self::scheduled_row( $profile, [ 'state' => 'published', 'published_at' => '2026-10-06 10:07:00.000000', 'effective_until' => '2026-10-06 10:05:00.000000', 'row_revision' => 3 ] );
		$this->refuses( static fn() => RuleVersion::from_row( $late, $logical, $profile ) );
		$this->refuses( static fn() => RuleVersion::from_row( array_replace( self::version_row( $profile ), [ 'published_at' => '2026-10-06 09:59:59.999999' ] ), $logical, $profile ) );
		$this->refuses( static fn() => RuleVersion::from_row( array_replace( self::version_row( $profile ), [ 'retired_at' => self::START ] ), $logical, $profile ) );
	}

	public function test_selection_excludes_scheduled_draft_retired_and_expired_heads(): void {
		$profile = new RuleProofFamily();
		$evaluator = new RuleLifecycleEvaluator();
		foreach ( [ 'draft', 'scheduled', 'retired', 'expired' ] as $state ) {
			$version = match ( $state ) {
				'draft' => self::draft_row( $profile ),
				'scheduled' => self::scheduled_row( $profile ),
				'retired' => self::version_row( $profile, [ 'state' => 'retired', 'retired_at' => '2026-10-06 11:00:00.000000' ] ),
				default => self::version_row( $profile, [ 'effective_until' => '2026-10-06 11:00:00.000000' ] ),
			};
			$candidate = self::candidate( $profile, $version );
			$decision = $evaluator->evaluate( $profile, [ $candidate ], self::subject(), RuleTime::parse( self::UPDATED ), 1 );
			self::assertNull( $decision->selected ); self::assertTrue( $decision->complete );
		}
	}

	public function test_explicit_specificity_priority_and_shuffled_input_have_identical_selection(): void {
		$profile = new RuleProofFamily();
		$global = self::candidate( $profile, self::version_row( $profile, [ 'id' => 1, 'version_uuid' => self::uuid( 1 ), 'priority' => -100 ] ), [ 'scope_json' => $profile->scope_schema()->encode( [ 'type' => 'global', 'id' => 0, 'parent_id' => 0 ] ) ] );
		$product = self::candidate( $profile, self::version_row( $profile, [ 'id' => 2, 'logical_rule_id' => 2, 'version_uuid' => self::uuid( 2 ), 'priority' => 100 ] ), [ 'id' => 2, 'logical_uuid' => self::uuid( 102 ), 'current_published_version_id' => 2 ] );
		$evaluator = new RuleLifecycleEvaluator();
		foreach ( [ [ $global, $product ], [ $product, $global ] ] as $order ) {
			self::assertSame( 2, $evaluator->evaluate( $profile, $order, self::subject(), RuleTime::parse( self::START ), 3 )->selected?->version_id );
		}
		$priority_first = new DomainMutableFamily();
		$priority_first->order = [ [ 'field' => 'priority', 'direction' => 'asc' ], [ 'field' => 'specificity', 'direction' => 'desc' ] ];
		// Same declared payload/scopes, separately hydrated under this explicit profile.
		$global2 = self::candidate( $priority_first, $global->version->row(), $global->logical->row() );
		$product2 = self::candidate( $priority_first, $product->version->row(), $product->logical->row() );
		self::assertSame( 1, $evaluator->evaluate( $priority_first, [ $product2, $global2 ], self::subject(), RuleTime::parse( self::START ), 3 )->selected?->version_id );
	}

	public function test_equal_rank_refuses_selection_and_unknown_overlap_refuses_publication(): void {
		$profile = new RuleProofFamily();
		$left = self::candidate( $profile, self::version_row( $profile ) );
		$right = self::candidate( $profile, self::version_row( $profile, [ 'id' => 2, 'logical_rule_id' => 2, 'version_uuid' => self::uuid( 2 ) ] ), [ 'id' => 2, 'logical_uuid' => self::uuid( 102 ), 'current_published_version_id' => 2 ] );
		$evaluator = new RuleLifecycleEvaluator();
		$decision = $evaluator->evaluate( $profile, [ $right, $left ], self::subject(), RuleTime::parse( self::START ), 2 );
		self::assertSame( 'rule_conflict', $decision->reason ); self::assertNull( $decision->selected );
		$conflict = $evaluator->conflicts( $profile, $right, [ $left ] );
		self::assertTrue( $conflict->complete ); self::assertTrue( $conflict->conflict ); self::assertFalse( $conflict->permits() );
		$profile->unknown_overlap = true;
		self::assertFalse( $evaluator->conflicts( $profile, $right, [ $left ] )->complete );
	}

	public function test_only_explicit_declared_stable_tie_break_can_resolve_equal_rank(): void {
		$profile = new DomainMutableFamily();
		$profile->collision = 'tie_break';
		$profile->order[] = [ 'field' => 'logical_uuid', 'direction' => 'asc' ];
		$left = self::candidate( $profile, self::version_row( $profile ) );
		$right = self::candidate( $profile, self::version_row( $profile, [ 'id' => 2, 'logical_rule_id' => 2, 'version_uuid' => self::uuid( 2 ) ] ), [ 'id' => 2, 'logical_uuid' => self::uuid( 102 ), 'current_published_version_id' => 2 ] );
		$evaluator = new RuleLifecycleEvaluator();
		foreach ( [ [ $left, $right ], [ $right, $left ] ] as $candidates ) {
			$decision = $evaluator->evaluate( $profile, $candidates, self::subject(), RuleTime::parse( self::START ), 1 );
			self::assertTrue( $decision->complete ); self::assertSame( 1, $decision->selected?->version_id );
		}
		self::assertTrue( $evaluator->conflicts( $profile, $right, [ $left ] )->permits() );
	}

	public function test_adjacent_windows_and_distinct_subjects_do_not_conflict(): void {
		$profile = new RuleProofFamily();
		$left = self::candidate( $profile, self::version_row( $profile, [ 'effective_until' => '2026-10-06 11:00:00.000000' ] ) );
		$adjacent = self::candidate( $profile, self::version_row( $profile, [ 'id' => 2, 'logical_rule_id' => 2, 'version_uuid' => self::uuid( 2 ), 'effective_from' => '2026-10-06 11:00:00.000000', 'published_at' => '2026-10-06 11:00:00.000000', 'sealed_at' => '2026-10-06 11:00:00.000000' ] ), [ 'id' => 2, 'logical_uuid' => self::uuid( 102 ), 'current_published_version_id' => 2 ] );
		self::assertTrue( ( new RuleLifecycleEvaluator() )->conflicts( $profile, $adjacent, [ $left ] )->permits() );
		$distinct = self::candidate( $profile, self::version_row( $profile, [ 'id' => 3, 'logical_rule_id' => 3, 'version_uuid' => self::uuid( 3 ) ] ), [ 'id' => 3, 'logical_uuid' => self::uuid( 103 ), 'current_published_version_id' => 3, 'scope_json' => $profile->scope_schema()->encode( [ 'type' => 'product', 'id' => 44, 'parent_id' => 0 ] ) ] );
		self::assertTrue( ( new RuleLifecycleEvaluator() )->conflicts( $profile, $distinct, [ $left ] )->permits() );
	}

	public function test_only_declared_same_logical_predecessor_can_be_excluded_from_conflicts(): void {
		$profile = new RuleProofFamily();
		$left = self::candidate( $profile, self::version_row( $profile ) );
		$next = self::candidate( $profile, self::version_row( $profile, [ 'id' => 2, 'version_uuid' => self::uuid( 2 ), 'version_sequence' => 2, 'supersedes_version_id' => 1 ] ), [ 'last_version_sequence' => 2, 'current_published_version_id' => 2 ] );
		$evaluator = new RuleLifecycleEvaluator();
		self::assertTrue( $evaluator->conflicts( $profile, $next, [ $left ] )->permits() );
		self::assertFalse( $evaluator->conflicts( $profile, $next, [] )->complete );
		self::assertFalse( $evaluator->conflicts( $profile, $next, [ $left ], 99 )->complete );
	}

	public function test_unbounded_candidate_generator_stops_at_1001_and_never_selects_partial_winner(): void {
		$profile = new RuleProofFamily();
		$count = 0;
		$generator = ( static function () use ( $profile, &$count ): \Generator {
			for ( $id = 1; ; ++$id ) {
				++$count;
				yield self::candidate( $profile, self::version_row( $profile, [ 'id' => $id, 'logical_rule_id' => $id, 'version_uuid' => self::uuid( $id ) ] ), [ 'id' => $id, 'logical_uuid' => self::uuid( $id + 2000 ), 'current_published_version_id' => $id ] );
			}
		} )();
		$decision = ( new RuleLifecycleEvaluator() )->evaluate( $profile, $generator, self::subject(), RuleTime::parse( self::START ), 1 );
		self::assertSame( 1001, $count ); self::assertFalse( $decision->complete ); self::assertNull( $decision->selected );
	}

	public function test_direct_retired_evidence_has_a_separate_bound_from_1000_current_candidates(): void {
		$profile = new RuleProofFamily();
		$logicals = $versions = [];
		for ( $id = 1; $id <= 1000; ++$id ) {
			$logicals[] = self::logical_row( $profile, [ 'id' => $id, 'logical_uuid' => self::uuid( $id + 4000 ), 'scope_json' => $profile->scope_schema()->encode( [ 'type' => 'product', 'id' => $id, 'parent_id' => 0 ] ), 'last_version_sequence' => 2, 'current_published_version_id' => $id + 2000 ] );
			$versions[] = self::version_row( $profile, [ 'id' => $id, 'logical_rule_id' => $id, 'version_uuid' => self::uuid( $id ), 'state' => 'retired', 'effective_from' => '2026-10-06 09:30:00.000000', 'published_at' => '2026-10-06 09:30:00.000000', 'sealed_at' => '2026-10-06 09:30:00.000000', 'retired_at' => self::START ] );
			$versions[] = self::version_row( $profile, [ 'id' => $id + 2000, 'logical_rule_id' => $id, 'version_uuid' => self::uuid( $id + 2000 ), 'version_sequence' => 2, 'supersedes_version_id' => $id ] );
		}
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), $logicals, $versions );
		self::assertCount( 2000, $snapshot->versions ); self::assertCount( 1000, $snapshot->candidates() );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, [ 'type' => 'product', 'id' => 1, 'parent_id' => 0 ], RuleTime::parse( self::START ) );
		self::assertTrue( $decision->complete ); self::assertSame( 2001, $decision->selected?->version_id );
	}

	public function test_safe_projection_omits_private_ids_scope_reason_and_payload_and_rechecks_admin_grant(): void {
		$profile = new RuleProofFamily();
		$candidate = self::candidate( $profile, self::version_row( $profile, [ 'change_reason' => 'PRIVATE-SQL customer address' ] ) );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate( $profile, [ $candidate ], self::subject(), RuleTime::parse( self::START ), 3 );
		$context = RequestContext::create( 'PRIVATE-SQL' );
		$safe = json_encode( $decision->safe( $context ), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE-SQL', $safe ); self::assertStringNotContainsString( 'version_id', $safe ); self::assertStringNotContainsString( 'availability', $safe );
		self::assertSame( 1, $decision->admin( $profile, self::identity() )['selected']['version_id'] );
		$this->refuses( static fn() => json_encode( $decision, JSON_THROW_ON_ERROR ) );
		$this->refuses( static fn() => json_encode( $decision->selected, JSON_THROW_ON_ERROR ) );
		$profile->allowed = false;
		$this->refuses( static fn() => $decision->admin( $profile, self::identity() ) );
	}

	public function test_revocation_during_private_projection_and_cross_site_disclosure_refuse(): void {
		$profile = new RuleProofFamily();
		$decision = ( new RuleLifecycleEvaluator() )->evaluate( $profile, [ self::candidate( $profile, self::version_row( $profile ) ) ], self::subject(), RuleTime::parse( self::START ), 1 );
		$profile->revoke_at_call = 3;
		$this->refuses( static fn() => $decision->admin( $profile, self::identity() ) );
		$profile->revoke_at_call = null;
		$this->refuses( static fn() => $decision->admin( $profile, new OperationIdentity( 2, 'wordpress', 'user:9', 'rule.preview', 1, 'fixture', 'token' ) ) );
	}

	public function test_constructor_omitted_selected_evidence_cannot_bypass_selected_scope_authority(): void {
		$profile = new RuleProofFamily( static fn( OperationIdentity $identity, array $scope ): bool => 'variation' === $scope['type'] );
		$candidate = self::candidate( $profile, self::version_row( $profile ) );
		$subject = [ 'type' => 'variation', 'id' => 330, 'parent_id' => 33 ];
		$decision = new RuleDecision( $profile, $candidate, $subject, RuleTime::parse( self::START ), 1, true, 'rule_selected' );
		$this->refuses( static fn() => $decision->admin( $profile, self::identity() ) );
		self::assertStringNotContainsString( 'logical_id', json_encode( $decision->safe( RequestContext::create() ), JSON_THROW_ON_ERROR ) );
	}

	public function test_selected_scope_grant_is_rechecked_after_projection_subject_stays_authorized(): void {
		$product_checks = 0;
		$profile = new RuleProofFamily( static function ( OperationIdentity $identity, array $scope ) use ( &$product_checks ): bool {
			return 'variation' === $scope['type'] || ++$product_checks < 2;
		} );
		$candidate = self::candidate( $profile, self::version_row( $profile ) );
		$decision = new RuleDecision( $profile, $candidate, [ 'type' => 'variation', 'id' => 330, 'parent_id' => 33 ], RuleTime::parse( self::START ), 1, true, 'rule_selected' );
		$this->refuses( static fn() => $decision->admin( $profile, self::identity() ) );
		self::assertSame( 2, $product_checks );
	}

	public function test_future_hypothetical_overlay_preserves_opened_metadata_and_cannot_be_a_stored_snapshot(): void {
		$profile = new RuleProofFamily();
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ self::logical_row( $profile ) ], [ self::version_row( $profile ) ] );
		$logical = $snapshot->logicals[0];
		$future = self::version_row( $profile, [ 'effective_from' => '2026-10-07 10:00:00.000000', 'published_at' => '2026-10-07 10:00:00.000000', 'sealed_at' => '2026-10-07 10:00:00.000000' ] );
		$this->refuses( static fn() => RuleVersion::from_row( $future, $logical, $profile ) );
		$version = RuleVersion::hypothetical( $future, $logical, $profile );
		self::assertTrue( $version->hypothetical ); self::assertSame( self::UPDATED, $logical->updated_at->sql() );
		$this->refuses( static fn() => new RuleSnapshot( $profile, $snapshot->guard, [ $logical ], [ $version ] ) );
	}

	public function test_preview_same_instant_same_evaluator_has_no_source_changes_and_rejects_101_subjects(): void {
		$profile = new RuleProofFamily();
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ self::logical_row( $profile ) ], [ self::version_row( $profile ) ] );
		$before = $snapshot->candidate_digest();
		$at = RuleTime::parse( self::START );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, self::subject(), $at );
		$row = [ 'subject' => self::subject(), 'baseline' => $decision, 'proposed' => $decision ];
		$preview = new RuleImpactPreview( $snapshot, $at, [ $row ], false );
		self::assertSame( $before, $snapshot->candidate_digest() );
		self::assertSame( $decision->safe( RequestContext::create() )['reason'], $preview->safe( RequestContext::create() )['comparisons'][0]['proposed']['reason'] );
		self::assertSame( $before, $preview->admin( $profile, self::identity() )['candidate_digest'] );
		$this->refuses( static fn() => new RuleImpactPreview( $snapshot, $at, array_fill( 0, 101, $row ), false ) );
		$this->refuses( static fn() => json_encode( $preview, JSON_THROW_ON_ERROR ) );
		$this->refuses( static fn() => new RuleImpactPreview( $snapshot, RuleTime::parse( self::UPDATED ), [ $row ], false ) );
	}

	public function test_preview_discloses_authorized_proposal_identity_even_when_it_is_not_selected(): void {
		$profile = new RuleProofFamily();
		$logical_row = self::logical_row( $profile, [ 'current_published_version_id' => null, 'draft_version_id' => 1 ] );
		$snapshot = RuleSnapshot::from_rows( $profile, self::guard_row( $profile ), [ $logical_row ], [ self::draft_row( $profile ) ] );
		$proposed = $snapshot->candidates()[0]; $at = RuleTime::parse( self::START );
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, self::subject(), $at );
		$preview = new RuleImpactPreview( $snapshot, $at, [ [ 'subject' => self::subject(), 'baseline' => $decision, 'proposed' => $decision ] ], false, $proposed );
		$admin = $preview->admin( $profile, self::identity() );
		self::assertNull( $admin['comparisons'][0]['proposed']['selected'] );
		self::assertSame( 1, $admin['proposed']['reference']['version_id'] );
		self::assertSame( 10, $admin['proposed']['opened_logical_revision'] );
		self::assertSame( 2, $admin['proposed']['opened_version_revision'] );
		self::assertSame( 'draft', $admin['proposed']['opened_version_state'] );
		self::assertArrayHasKey( 'authored_from', $admin['proposed'] ); self::assertArrayHasKey( 'authored_until', $admin['proposed'] );
		self::assertArrayNotHasKey( 'proposed', $preview->safe( RequestContext::create() ) );
		$profile->allowed = false; $this->refuses( static fn() => $preview->admin( $profile, self::identity() ) );
	}

	private function refuses( callable $action ): void {
		try { $action(); self::fail( 'Expected a safe refusal.' ); } catch ( \InvalidArgumentException $error ) { self::assertStringNotContainsString( 'PRIVATE-SQL', $error->getMessage() ); }
	}
	private static function uuid( int $id ): string { return '00000000-0000-4000-8000-' . str_pad( (string) $id, 12, '0', STR_PAD_LEFT ); }
	private static function subject(): array { return [ 'type' => 'product', 'id' => 33, 'parent_id' => 0 ]; }
	private static function identity(): OperationIdentity { return new OperationIdentity( 1, 'wordpress', 'user:9', 'rule.preview', 1, 'fixture', 'token' ); }
	private static function guard_row( RuleFamilyProfile $profile ): array { return [ 'id' => 1, 'site_id' => 1, 'family_code' => $profile->family(), 'family_format' => 1, 'policy_hash' => $profile->policy_hash(), 'revision' => 10, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ]; }
	private static function logical_row( RuleFamilyProfile $profile, array $changes = [] ): array {
		$row = array_replace( [ 'id' => 1, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => self::uuid( 101 ), 'scope_format' => 1, 'scope_json' => $profile->scope_schema()->encode( self::subject() ), 'scope_hash' => '', 'revision' => 10, 'last_version_sequence' => 1, 'current_published_version_id' => 1, 'draft_version_id' => null, 'scheduled_version_id' => null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ], $changes );
		$row['scope_hash'] = RuleContent::scope_hash( $profile, $profile->scope_schema()->decode( $row['scope_json'] ) );
		return $row;
	}
	private static function version_row( RuleFamilyProfile $profile, array $changes = [] ): array {
		$row = array_replace( [ 'id' => 1, 'site_id' => 1, 'logical_rule_id' => 1, 'version_uuid' => self::uuid( 1 ), 'version_sequence' => 1, 'row_revision' => 2, 'state' => 'published', 'payload_format' => 1, 'payload_json' => $profile->payload_schema()->encode( [ 'availability' => 'allow' ] ), 'content_hash' => '', 'priority' => 100, 'start_mode' => 'immediate', 'effective_from' => self::START, 'effective_until' => null, 'author_user_id' => 9, 'change_reason' => 'Fixture rule', 'supersedes_version_id' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null, 'sealed_at' => self::START, 'scheduled_at' => null, 'published_at' => self::START, 'retired_at' => null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ], $changes );
		$row['content_hash'] = RuleContent::hash( $profile, $profile->payload_schema()->decode( $row['payload_json'] ), RuleStartMode::from( $row['start_mode'] ), null === $row['effective_from'] ? null : RuleTime::parse( $row['effective_from'] ), null === $row['effective_until'] ? null : RuleTime::parse( $row['effective_until'] ), $row['priority'], $row['supersedes_version_id'] );
		return $row;
	}
	private static function scheduled_row( RuleFamilyProfile $profile, array $changes = [] ): array { return self::version_row( $profile, array_replace( [ 'state' => 'scheduled', 'start_mode' => 'at', 'sealed_at' => '2026-10-06 09:30:00.000000', 'scheduled_at' => '2026-10-06 09:30:00.000000', 'published_at' => null, 'scheduled_revision' => 2, 'scheduled_logical_revision' => 2 ], $changes ) ); }
	private static function draft_row( RuleFamilyProfile $profile ): array { return self::version_row( $profile, [ 'state' => 'draft', 'effective_from' => null, 'sealed_at' => null, 'published_at' => null ] ); }
	private static function candidate( RuleFamilyProfile $profile, array $version, array $logical_changes = [] ): RuleCandidate {
		$guard = RuleFamilyGuard::from_row( self::guard_row( $profile ), $profile );
		$logical = LogicalRule::from_row( self::logical_row( $profile, $logical_changes ), $guard, $profile );
		return new RuleCandidate( $logical, RuleVersion::from_row( $version, $logical, $profile ) );
	}
}

/** Mutable declaration fixture proves registry freezing without mutating runtime business code. */
final class DomainMutableFamily implements RuleFamilyProfile {
	public array $order = [ [ 'field' => 'specificity', 'direction' => 'desc' ], [ 'field' => 'priority', 'direction' => 'asc' ] ];
	public string $collision = 'refuse';
	private RuleProofFamily $base;
	public function __construct() { $this->base = new RuleProofFamily(); }
	public function family(): string { return $this->base->family(); }
	public function version(): int { return 1; }
	public function policy_hash(): string { return $this->base->policy_hash(); }
	public function scope_schema(): RuleSchema { return $this->base->scope_schema(); }
	public function payload_schema(): RuleSchema { return $this->base->payload_schema(); }
	public function subject_schema(): RuleSchema { return $this->base->subject_schema(); }
	public function authorize( OperationIdentity $identity, array $scope ): bool { return true; }
	public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool { return true; }
	public function match( array $scope, array $subject ): RuleMatch { return $this->base->match( $scope, $subject ); }
	public function overlaps( array $left_scope, array $right_scope ): ?bool { return $this->base->overlaps( $left_scope, $right_scope ); }
	public function precedence(): array { return $this->order; }
	public function equal_rank_policy(): string { return $this->collision; }
	public function unavailable_reason(): string { return 'rule_unavailable'; }
}
