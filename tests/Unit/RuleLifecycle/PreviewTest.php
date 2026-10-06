<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

require_once __DIR__ . '/ReadServiceTest.php';

use CetechDeliveryEngine\Application\RuleLifecycle\RuleImpactPreviewService;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleClock;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleImpactPreview;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;

final class PreviewTest extends RuleApplicationTestCase {
	private function preview_clock(): RuleClock { return new class implements RuleClock { public function now(): RuleTime { return RuleTime::parse( '2026-10-06 12:00:00.000000' ); } }; }
	private function proposed( array $rows, RuleProofFamily $profile ): RuleCandidate {
		$snapshot = RuleSnapshot::from_rows( $profile, $rows['family'], $rows['logicals'], $rows['versions'] );
		$row = $rows['versions'][1]; $row['state'] = 'published'; $row['published_at'] = '2026-10-06 13:00:00.000000'; $row['updated_at'] = $row['published_at'];
		return new RuleCandidate( $snapshot->logicals[0], RuleVersion::hypothetical( $row, $snapshot->logicals[0], $profile ) );
	}
	public function test_baseline_overlay_use_same_evaluator_and_captured_time_without_writes(): void {
		$profile = new RuleProofFamily(); $rows = $this->rows( $profile, true ); [ $reader, $stats ] = $this->fixture( $rows, $profile );
		$proposed = $this->proposed( $rows, $profile ); $at = RuleTime::parse( '2026-10-06 13:00:00.000000' ); $subject = RuleProofEnvelope::scope();
		$result = ( new RuleImpactPreviewService( $reader, null, $this->preview_clock() ) )->preview( $this->actor(), $profile->family(), $subject, [ $subject ], $proposed, $at, RequestContext::create() );
		self::assertInstanceOf( RuleImpactPreview::class, $result ); self::assertSame( 100, $result->comparisons[0]['baseline']->selected->version_id ); self::assertSame( 101, $result->comparisons[0]['proposed']->selected->version_id );
		self::assertTrue( $result->hypothetical_due ); self::assertTrue( $result->comparisons[0]['proposed']->selected->hypothetical ); self::assertSame( 8, $result->comparisons[0]['proposed']->family_revision );
		$baseline = ( new RuleLifecycleEvaluator() )->evaluate( $profile, $result->snapshot->candidates(), $subject, $at, 8 );
		$overlay = ( new RuleLifecycleEvaluator() )->evaluate( $profile, [ $proposed ], $subject, $at, 8 );
		self::assertSame( $baseline->selected->facts(), $result->comparisons[0]['baseline']->selected->facts() ); self::assertSame( $overlay->selected->facts(), $result->comparisons[0]['proposed']->selected->facts() );
		self::assertCount( 1, $stats->sql ); self::assertSame( 1, $stats->rollbacks ); self::assertSame( $rows, $stats->rows );
	}
	public function test_future_proposal_does_not_retire_predecessor_before_hypothetical_cutover(): void {
		$profile = new RuleProofFamily(); $rows = $this->rows( $profile, true ); [ $reader ] = $this->fixture( $rows, $profile );
		$result = ( new RuleImpactPreviewService( $reader, null, $this->preview_clock() ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), [ RuleProofEnvelope::scope() ], $this->proposed( $rows, $profile ), RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( RuleImpactPreview::class, $result ); self::assertSame( 100, $result->comparisons[0]['proposed']->selected->version_id ); self::assertFalse( $result->hypothetical_due );
	}
	public function test_infinite_subject_input_stops_on_101st_and_never_opens_database(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile ); $observed = 0;
		$subjects = ( static function () use ( &$observed ): \Generator { while ( true ) { ++$observed; yield RuleProofEnvelope::scope(); } } )();
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), $subjects, null, RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $result ); self::assertSame( 'invalid_input', $result->code ); self::assertSame( 101, $observed ); self::assertSame( 0, $stats->opens );
	}
	public function test_1001st_candidate_reports_incomplete_without_partial_winner(): void {
		$profile = new RuleProofFamily(); $original = $this->rows( $profile ); $rows = $original; $rows['logicals'] = $rows['versions'] = [];
		for ( $i = 1; $i <= 1001; ++$i ) {
			$logical = $original['logicals'][0]; $version = $original['versions'][0]; $logical['id'] = $i; $logical['logical_uuid'] = RuleProofEnvelope::uuid( $i ); $logical['current_published_version_id'] = 2000 + $i;
			$version['id'] = 2000 + $i; $version['version_uuid'] = RuleProofEnvelope::uuid( 2000 + $i ); $version['logical_rule_id'] = $i; $rows['logicals'][] = $logical; $rows['versions'][] = $version;
		}
		[ $reader, $stats ] = $this->fixture( $rows, $profile );
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), [ RuleProofEnvelope::scope() ], null, RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( RuleImpactPreview::class, $result ); self::assertFalse( $result->snapshot->complete ); self::assertCount( 1000, $result->snapshot->versions );
		self::assertNull( $result->comparisons[0]['baseline']->selected ); self::assertNull( $result->comparisons[0]['proposed']->selected ); self::assertSame( 'rule_incomplete', $result->comparisons[0]['proposed']->reason ); self::assertCount( 1, $stats->sql );
	}
	public function test_unknown_nested_subject_is_refused_without_echoing_private_input(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), [ RuleProofEnvelope::scope() + [ 'renamed_private' => [ 'password' => 'PRIVATE_SENTINEL' ] ] ], null, RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $result ); self::assertSame( 0, $stats->opens ); self::assertStringNotContainsString( 'PRIVATE_SENTINEL', json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}
	public function test_denied_subject_stops_preview_before_capture(): void {
		$profile = new RuleProofFamily( static fn ( OperationIdentity $identity, array $scope ): bool => 20 !== $scope['id'] ); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope( 'product', 10 ), [ RuleProofEnvelope::scope( 'product', 20 ) ], null, RuleTime::parse( '2026-10-06 12:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $result ); self::assertSame( 'not_authorized', $result->code ); self::assertSame( 0, $stats->opens );
	}
	public function test_stale_opened_overlay_refuses_preview_selection(): void {
		$profile = new RuleProofFamily(); $rows = $this->rows( $profile, true ); $proposed = $this->proposed( $rows, $profile ); ++$rows['logicals'][0]['revision'];
		[ $reader ] = $this->fixture( $rows, $profile );
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), [ RuleProofEnvelope::scope() ], $proposed, RuleTime::parse( '2026-10-06 13:00:00.000000' ), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $result ); self::assertSame( 'invalid_input', $result->code );
	}
	public function test_safe_projection_omits_identity_payload_reason_and_direct_json_refuses(): void {
		$profile = new RuleProofFamily(); [ $reader ] = $this->fixture( $this->rows( $profile ), $profile ); $request = RequestContext::create();
		$result = ( new RuleImpactPreviewService( $reader ) )->preview( $this->actor(), $profile->family(), RuleProofEnvelope::scope(), [ RuleProofEnvelope::scope() ], null, RuleTime::parse( '2026-10-06 12:00:00.000000' ), $request );
		self::assertInstanceOf( RuleImpactPreview::class, $result ); $safe = json_encode( $result->safe( $request ), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE_FIXTURE_REASON', $safe ); self::assertStringNotContainsString( RuleProofEnvelope::uuid( 100 ), $safe ); self::assertStringNotContainsString( 'availability', $safe );
		$this->expectException( \InvalidArgumentException::class ); json_encode( $result, JSON_THROW_ON_ERROR );
	}
}
