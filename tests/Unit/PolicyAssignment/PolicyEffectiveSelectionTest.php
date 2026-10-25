<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyEffectiveSelection as Selection;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyVersion;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleSnapshot, RuleTime};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentDomainFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyEffectiveSelectionTest extends TestCase {
	#[DataProvider( 'families' )]
	public function test_current_published_snapshot_yields_exact_matching_private_content( string $family, string $type ): void {
		$snapshot = F::snapshot( $family ); $at = RuleTime::parse( F::NOW ); $provenance = [ 'scope_type' => 'product', 'scope_id' => 33, 'offer_id' => 4 ];
		$selection = Selection::assigned( $family, $snapshot, F::uuid( 101 ), $at, $provenance, str_repeat( 'a', 64 ) );
		self::assertSame( $family, $selection->family() ); self::assertSame( 'assigned', $selection->disposition() );
		self::assertInstanceOf( $type, $selection->version() ); self::assertSame( F::uuid( 101 ), $selection->version()->reference()->logical_uuid );
		self::assertSame( "Supplied café.\nSecond line.", $selection->version()->content()->facts()['summary'] );
		self::assertSame( $provenance, $selection->provenance() ); self::assertSame( F::NOW, $selection->evaluated_at()->sql() );
		self::assertSame( 'policy_assigned', $selection->code() );
		self::assertNotSame( $selection->version()->content()->digest(), $selection->version()->reference()->content_hash );
	}
	public static function families(): array { return [ [ 'return_policy_v1', ReturnPolicyVersion::class ], [ 'refund_policy_v1', RefundPolicyVersion::class ] ]; }
	public function test_evaluation_time_is_separate_from_stable_material_fingerprint(): void {
		$snapshot = F::snapshot(); $a = Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( F::NOW ), [], str_repeat( 'a', 64 ) );
		$b = Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( '2026-10-24 12:00:01.000000' ), [], str_repeat( 'a', 64 ) );
		self::assertSame( $a->fingerprint(), $b->fingerprint() ); self::assertNotSame( $a->evaluated_at()->sql(), $b->evaluated_at()->sql() );
		$c = Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( F::NOW ), [ 'offer_id' => 7 ], str_repeat( 'a', 64 ) );
		$d = Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( F::NOW ), [], str_repeat( 'b', 64 ) );
		self::assertCount( 3, array_unique( [ $a->fingerprint(), $c->fingerprint(), $d->fingerprint() ] ) );
	}
	#[DataProvider( 'unavailable_sources' )]
	public function test_unavailable_assigned_source_never_becomes_fallback_terms( string $kind ): void {
		$snapshot = match ( $kind ) {
			'draft' => F::snapshot( state: 'draft' ),
			'scheduled' => F::snapshot( state: 'scheduled' ),
			'retired' => F::snapshot( state: 'retired' ),
			'expired' => F::snapshot( version_changes: [ 'effective_until' => F::NOW ] ),
			'before publication' => F::snapshot(),
			'incomplete' => F::snapshot( complete: false ),
			'foreign family' => F::snapshot( 'refund_policy_v1' ),
			'unknown logical' => F::snapshot(),
			'empty source' => new RuleSnapshot( F::profile( 'return_policy_v1' ), null, [], [] ),
		};
		$this->expectException( \InvalidArgumentException::class );
		Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 'unknown logical' === $kind ? 999 : 101 ), RuleTime::parse( 'before publication' === $kind ? F::CREATED : F::NOW ), [], str_repeat( 'a', 64 ) );
	}
	public static function unavailable_sources(): array { return array_map( static fn( $x ) => [ $x ], [ 'draft', 'scheduled', 'retired', 'expired', 'before publication', 'incomplete', 'foreign family', 'unknown logical', 'empty source' ] ); }
	public function test_changed_authored_bytes_and_source_generation_change_material_fingerprint(): void {
		$at = RuleTime::parse( F::NOW ); $plain = F::snapshot(); $text = F::snapshot( content_changes: [ 'full_text' => 'A new supplied body' ] );
		$rows = F::rows(); $rows['guard']['revision'] = 11;
		$guard = RuleSnapshot::from_rows( $rows['profile'], $rows['guard'], [ $rows['logical'] ], [ $rows['version'] ] );
		$hashes = array_map( static fn( $s ) => Selection::assigned( 'return_policy_v1', $s, F::uuid( 101 ), $at, [], str_repeat( 'a', 64 ) )->fingerprint(), [ $plain, $text, $guard ] );
		self::assertCount( 3, array_unique( $hashes ) );
	}
	public function test_nonassigned_disabled_and_unresolved_are_distinct_with_no_version_or_wording(): void {
		$at = RuleTime::parse( F::NOW ); $values = [
			Selection::not_assigned( 'return_policy_v1', $at, [], str_repeat( 'a', 64 ) ),
			Selection::disabled( 'return_policy_v1', $at, [], str_repeat( 'a', 64 ) ),
			Selection::unresolved( 'return_policy_v1', $at, [], str_repeat( 'a', 64 ) ),
		];
		self::assertSame( [ 'not_assigned', 'disabled', 'unresolved' ], array_map( static fn( $s ) => $s->disposition(), $values ) );
		self::assertCount( 3, array_unique( array_map( static fn( $s ) => $s->fingerprint(), $values ) ) );
		foreach ( $values as $value ) { self::assertNull( $value->version() ); }
		self::assertNotSame( $values[0]->fingerprint(), Selection::not_assigned( 'refund_policy_v1', $at, [], str_repeat( 'a', 64 ) )->fingerprint() );
	}
	public function test_unknown_error_codes_and_arbitrary_digest_inputs_are_refused(): void {
		$at = RuleTime::parse( F::NOW );
		foreach ( [
			static fn() => Selection::unresolved( 'return_policy_v1', $at, [], str_repeat( 'a', 64 ), 'raw_private_error' ),
			static fn() => Selection::disabled( 'return_policy_v1', $at, [], 'bad-fingerprint' ),
			static fn() => Selection::not_assigned( 'promise', $at, [], str_repeat( 'a', 64 ) ),
			static fn() => Selection::not_assigned( 'return_policy_v1', $at, [ 'object' => new \DateTimeImmutable() ], str_repeat( 'a', 64 ) ),
		] as $make ) {
			try { $make(); self::fail( 'Unsupported selection accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}
	public function test_private_selection_refuses_serialization_and_debug_disclosure(): void {
		$value = Selection::assigned( 'return_policy_v1', F::snapshot(), F::uuid( 101 ), RuleTime::parse( F::NOW ), [ 'private_source' => 'PRIVATE_SOURCE' ], str_repeat( 'a', 64 ) );
		foreach ( [ static fn() => json_encode( $value, JSON_THROW_ON_ERROR ), static fn() => serialize( $value ) ] as $persist ) {
			try { $persist(); self::fail( 'Selection persisted implicitly.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		}
		ob_start(); var_dump( $value ); $debug = ob_get_clean();
		self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( 'PRIVATE', $debug );
		$projection = $value->provenance(); $projection['private_source'] = 'changed';
		self::assertSame( 'PRIVATE_SOURCE', $value->provenance()['private_source'] );
	}
	public function test_future_family_generation_is_not_a_current_source_even_with_an_older_eligible_head(): void {
		$rows = F::rows(); $rows['guard']['updated_at'] = '2026-10-24 12:00:01.000000';
		$snapshot = RuleSnapshot::from_rows( $rows['profile'], $rows['guard'], [ $rows['logical'] ], [ $rows['version'] ] );
		self::assertTrue( $snapshot->versions[0]->eligible_at( RuleTime::parse( F::NOW ) ) );
		try { Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( F::NOW ), [], str_repeat( 'a', 64 ) ); self::fail( 'Future source accepted.' ); }
		catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		self::assertSame( 'assigned', Selection::assigned( 'return_policy_v1', $snapshot, F::uuid( 101 ), RuleTime::parse( '2026-10-24 12:00:01.000000' ), [], str_repeat( 'a', 64 ) )->disposition() );
	}
}
