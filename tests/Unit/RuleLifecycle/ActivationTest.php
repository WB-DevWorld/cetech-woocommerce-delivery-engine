<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

require_once __DIR__ . '/ReadServiceTest.php';

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;

final class ActivationTest extends RuleApplicationTestCase {
	public function test_original_envelope_uses_sealed_revisions_and_stable_service_token(): void {
		$profile = new RuleProofFamily(); $rows = $this->rows( $profile, true ); [ $reader, $stats, , , $registry ] = $this->fixture( $rows, $profile );
		$uuid = RuleProofEnvelope::uuid( 101 ); $actor = $this->actor( 'rule.activate', 'rule-activation:' . $uuid, 'rule.lifecycle.activation.v1' );
		$original = $reader->activation_envelope( $actor, $profile->family(), $uuid, RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertIsArray( $original ); self::assertSame( [ 'family_revision' => 0, 'logical_id' => 10, 'logical_revision' => 4, 'version_id' => 101, 'version_revision' => 2, 'predecessor_id' => 100, 'predecessor_revision' => 2 ], $original['payload']['preconditions'] );
		// A committed cutover and later generation advance cannot refresh the original.
		$stats->rows['family']['revision'] = 20; $stats->rows['family']['updated_at'] = '2026-10-06 14:00:00.000000';
		$stats->rows['logicals'][0]['revision'] = 10; $stats->rows['logicals'][0]['updated_at'] = '2026-10-06 14:00:00.000000'; $stats->rows['logicals'][0]['current_published_version_id'] = 101; $stats->rows['logicals'][0]['scheduled_version_id'] = null;
		$stats->rows['versions'][0]['state'] = 'retired'; $stats->rows['versions'][0]['row_revision'] = 3; $stats->rows['versions'][0]['retired_at'] = '2026-10-06 13:00:00.000000'; $stats->rows['versions'][0]['updated_at'] = '2026-10-06 13:00:00.000000';
		$stats->rows['versions'][1]['state'] = 'published'; $stats->rows['versions'][1]['row_revision'] = 3; $stats->rows['versions'][1]['published_at'] = '2026-10-06 13:00:00.000000'; $stats->rows['versions'][1]['updated_at'] = '2026-10-06 13:00:00.000000';
		$recovered = $reader->activation_envelope( $actor, $profile->family(), $uuid, RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertIsArray( $recovered ); self::assertSame( $original['payload'], $recovered['payload'] ); self::assertSame( $original['identity']->namespace_digest(), $recovered['identity']->namespace_digest() );
		self::assertSame( RuleLifecycleCommand::from_payload( $original['identity'], $original['payload'], $registry )->intent()->fingerprint(), RuleLifecycleCommand::from_payload( $recovered['identity'], $recovered['payload'], $registry )->intent()->fingerprint() );
		self::assertNotSame( $actor->namespace_digest(), $original['identity']->namespace_digest() ); self::assertSame( 2, $stats->opens );
	}
	public function test_external_request_token_is_not_activation_authority(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile, true ), $profile );
		$error = $reader->activation_envelope( $this->actor(), $profile->family(), RuleProofEnvelope::uuid( 101 ), RuleProofEnvelope::scope(), RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code ); self::assertSame( 0, $stats->opens );
	}
	public function test_page_bound_fixed_ceiling_keyset_and_unavailable_candidates_are_truthful(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile, true ), $profile ); $stats->ceiling = 26;
		for ( $i = 1; $i <= 26; ++$i ) { $stats->due_rows[] = [ 'id' => $i, 'effective_from' => '2026-10-06 13:00:00.000000', 'version_uuid' => RuleProofEnvelope::uuid( 1000 + $i ) ]; }
		$actor = $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ); $at = RuleTime::parse( '2026-10-06 13:00:00.000000' );
		$first = $reader->scan_due( $actor, $profile->family(), RuleProofEnvelope::scope(), $at, RequestContext::create() );
		self::assertSame( 26, $first['observed_ceiling'] ); self::assertCount( 25, $first['items'] ); self::assertFalse( $first['complete'] ); self::assertSame( 25, $first['cursor_id'] );
		foreach ( $first['items'] as $item ) { self::assertSame( 'unavailable', $item['state'] ); self::assertNull( $item['original'] ); self::assertInstanceOf( ContractError::class, $item['error'] ); }
		$stats->due_rows[] = [ 'id' => 27, 'effective_from' => $at->sql(), 'version_uuid' => RuleProofEnvelope::uuid( 1027 ) ]; $stats->ceiling = 27;
		$second = $reader->scan_due( $actor, $profile->family(), RuleProofEnvelope::scope(), $at, RequestContext::create(), $first['observed_ceiling'], RuleTime::parse( $first['cursor_at'] ), $first['cursor_id'] );
		self::assertSame( 26, $second['observed_ceiling'] ); self::assertCount( 1, $second['items'] ); self::assertSame( 26, $second['items'][0]['candidate_id'] ); self::assertTrue( $second['complete'] );
		self::assertCount( 1, array_filter( $stats->sql, static fn ( string $sql ): bool => str_contains( $sql, 'MAX(v.id)' ) ) );
	}
	public function test_failed_first_page_retains_the_observed_ceiling_for_retry(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile, true ), $profile ); $stats->ceiling = 26; $stats->fail_due = true;
		$actor = $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ); $at = RuleTime::parse( '2026-10-06 13:00:00.000000' );
		$failed = $reader->scan_due( $actor, $profile->family(), RuleProofEnvelope::scope(), $at, RequestContext::create() );
		self::assertSame( 26, $failed['observed_ceiling'] ); self::assertFalse( $failed['complete'] ); self::assertInstanceOf( ContractError::class, $failed['error'] ); self::assertSame( [], $failed['items'] );
		$stats->fail_due = false; $stats->ceiling = 27; $stats->due_rows = [ [ 'id' => 27, 'effective_from' => $at->sql(), 'version_uuid' => RuleProofEnvelope::uuid( 1027 ) ] ];
		$retry = $reader->scan_due( $actor, $profile->family(), RuleProofEnvelope::scope(), $at, RequestContext::create(), $failed['observed_ceiling'] );
		self::assertSame( 26, $retry['observed_ceiling'] ); self::assertTrue( $retry['complete'] ); self::assertSame( [], $retry['items'] );
	}
	public function test_empty_due_scan_does_not_create_guard_or_completion(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( [ 'family' => null, 'logicals' => [], 'versions' => [] ], $profile );
		$page = $reader->scan_due( $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ), $profile->family(), RuleProofEnvelope::scope(), RuleTime::parse( '2026-10-06 13:00:00.000000' ), RequestContext::create() );
		self::assertTrue( $page['complete'] ); self::assertSame( 0, $page['observed_ceiling'] ); self::assertSame( [], $page['items'] ); self::assertSame( 1, $stats->opens );
	}
	public function test_cursor_rejects_ambiguous_pair_without_loading(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->rows( $profile ), $profile );
		$page = $reader->scan_due( $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ), $profile->family(), RuleProofEnvelope::scope(), RuleTime::parse( '2026-10-06 13:00:00.000000' ), RequestContext::create(), 20, null, 10 );
		self::assertFalse( $page['complete'] ); self::assertSame( 'invalid_input', $page['error']->code ); self::assertSame( 0, $stats->opens );
	}
	public function test_authorized_family_due_scan_recovers_each_exact_stored_scope(): void {
		$profile = new RuleProofFamily(); [ $reader, $stats ] = $this->fixture( $this->mixed_scope_rows( $profile ), $profile );
		$stats->ceiling = 301; $stats->due_rows = $this->mixed_due_rows();
		$page = $reader->scan_due( $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ), $profile->family(), RuleProofEnvelope::scope(), RuleTime::parse( '2026-10-06 13:00:00.000000' ), RequestContext::create() );
		self::assertTrue( $page['complete'] ); self::assertSame( 301, $page['cursor_id'] ); self::assertCount( 3, $page['items'] );
		foreach ( [ RuleProofEnvelope::scope(), RuleProofEnvelope::scope( 'product', 10 ), RuleProofEnvelope::scope( 'variation', 11, 10 ) ] as $index => $scope ) {
			$item = $page['items'][$index]; self::assertSame( 'candidate', $item['state'] ); self::assertSame( $profile->scope_schema()->encode( $scope, 4096 ), $profile->scope_schema()->encode( $item['original']['payload']['scope'], 4096 ) ); self::assertSame( 'rule-activation:' . $item['original']['payload']['version_uuid'], $item['original']['identity']->principal );
		}
		self::assertSame( 1, $stats->rollbacks );
	}
	public function test_family_scan_grant_does_not_replace_a_denied_candidate_grant(): void {
		$profile = new RuleProofFamily( static fn ( OperationIdentity $identity, array $scope ): bool => 'variation' !== $scope['type'] );
		[ $reader, $stats ] = $this->fixture( $this->mixed_scope_rows( $profile ), $profile ); $stats->ceiling = 301; $stats->due_rows = $this->mixed_due_rows();
		$page = $reader->scan_due( $this->actor( 'rule.activate', 'rule-activation:scan', 'rule.lifecycle.activation.v1' ), $profile->family(), RuleProofEnvelope::scope(), RuleTime::parse( '2026-10-06 13:00:00.000000' ), RequestContext::create() );
		self::assertTrue( $page['complete'] ); self::assertSame( 301, $page['cursor_id'] ); self::assertSame( 'candidate', $page['items'][0]['state'] ); self::assertSame( 'candidate', $page['items'][1]['state'] );
		self::assertSame( 'unavailable', $page['items'][2]['state'] ); self::assertNull( $page['items'][2]['original'] ); self::assertInstanceOf( ContractError::class, $page['items'][2]['error'] );
	}
	public function test_direct_activation_keeps_the_supplied_scope_binding(): void {
		$profile = new RuleProofFamily(); [ $reader ] = $this->fixture( $this->mixed_scope_rows( $profile ), $profile ); $uuid = RuleProofEnvelope::uuid( 201 );
		$actor = $this->actor( 'rule.activate', 'rule-activation:' . $uuid, 'rule.lifecycle.activation.v1' );
		$error = $reader->activation_envelope( $actor, $profile->family(), $uuid, RuleProofEnvelope::scope(), RequestContext::create() ); self::assertInstanceOf( ContractError::class, $error );
		$original = $reader->activation_envelope( $actor, $profile->family(), $uuid, RuleProofEnvelope::scope( 'product', 10 ), RequestContext::create() ); self::assertIsArray( $original ); self::assertSame( $profile->scope_schema()->encode( RuleProofEnvelope::scope( 'product', 10 ), 4096 ), $profile->scope_schema()->encode( $original['payload']['scope'], 4096 ) );
	}
	private function mixed_scope_rows( RuleProofFamily $profile ): array {
		$rows = $this->rows( $profile, true );
		foreach ( [ [ 20, 201, RuleProofEnvelope::scope( 'product', 10 ) ], [ 30, 301, RuleProofEnvelope::scope( 'variation', 11, 10 ) ] ] as [ $logical_id, $version_id, $scope ] ) {
			$logical = $rows['logicals'][0]; $logical['id'] = $logical_id; $logical['logical_uuid'] = RuleProofEnvelope::uuid( $logical_id ); $logical['scope_json'] = $profile->scope_schema()->encode( $scope, 4096 ); $logical['scope_hash'] = RuleContent::scope_hash( $profile, $scope );
			$logical['current_published_version_id'] = null; $logical['scheduled_version_id'] = $version_id; $logical['last_version_sequence'] = 1;
			$version = $rows['versions'][1]; $version['id'] = $version_id; $version['logical_rule_id'] = $logical_id; $version['version_uuid'] = RuleProofEnvelope::uuid( $version_id ); $version['version_sequence'] = 1; $version['supersedes_version_id'] = null; $version['scheduled_predecessor_row_revision'] = null;
			$version['content_hash'] = RuleContent::hash( $profile, [ 'availability' => 'deny' ], RuleStartMode::At, RuleTime::parse( $version['effective_from'] ), null, 0, null );
			$rows['logicals'][] = $logical; $rows['versions'][] = $version;
		}
		return $rows;
	}
	private function mixed_due_rows(): array {
		return array_map( static fn ( int $id ): array => [ 'id' => $id, 'version_uuid' => RuleProofEnvelope::uuid( $id ), 'effective_from' => '2026-10-06 13:00:00.000000' ], [ 101, 201, 301 ] );
	}
}
