<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent, ReturnPolicyVersion};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleFamilyRegistry, RuleLifecycleCommand, RuleSnapshot, RuleState};
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\{ReturnPolicyCatalogFixture, ReturnPolicyFixture};
use PHPUnit\Framework\TestCase;

/** Exercises the Return adapter with real C01/C03 orchestration and an owned in-memory SQL transport. */
final class ReturnPolicyCatalogServiceTest extends TestCase {
	private ReturnPolicyCatalogFixture $fixture;
	protected function setUp(): void { $this->fixture = new ReturnPolicyCatalogFixture(); }
	protected function tearDown(): void { $this->fixture->close(); }

	public function test_authored_multiline_content_original_identity_and_audit_survive_fresh_replay(): void {
		$content = [ 'name' => 'Fixture café returns', 'customer_title' => 'Fixture return terms', 'summary' => "  Private summary\r\nsecond line\tΩ  ", 'full_text' => "<p>Fixture only — café</p>\n\tSupplied terms remain exact.", 'exceptions' => "Exception A\r\nException B" ];
		$payload = $this->fixture->payload( $content ); $identity = $this->fixture->identity( $payload );
		$first = $this->fixture->service()->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $first->outcome->state );
		$stored = $this->fixture->row( 'rule_versions' );
		$actual = ReturnPolicyContent::from_rule_payload( json_decode( $stored['payload_json'], true, 8, JSON_THROW_ON_ERROR ) );
		self::assertSame( $content, $actual->facts() ); self::assertSame( $payload['payload']['content_json'], $actual->json() );
		self::assertSame( $identity->namespace_digest(), $this->fixture->row( 'operation_records' )['namespace_hash'] );
		self::assertSame( $this->fixture->row( 'operation_records' )['id'], $this->fixture->row( 'operation_changes' )['operation_id'] );
		self::assertSame( $this->fixture->row( 'operation_changes' )['id'], $this->fixture->row( 'operation_records' )['audit_id'] );
		$replay = $this->fixture->service()->reconcile( $identity, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed );
		self::assertSame( $first->completion->to_json(), $replay->completion->to_json() ); self::assertSame( $stored, $this->fixture->row( 'rule_versions' ) );
		self::assertSame( 1, $this->fixture->count( 'logical_rules' ) ); self::assertSame( 1, $this->fixture->count( 'rule_versions' ) ); self::assertSame( 1, $this->fixture->count( 'operation_changes' ) );
	}

	public function test_unchanged_draft_has_no_new_audit_and_stale_editor_cannot_replace_a_later_edit(): void {
		$this->create(); $opened = $this->fixture->opened(); $before = $this->fixture->row( 'rule_versions' );
		$noop = $this->fixture->attempt( 'rule.draft.edit', $opened, 'same-content' );
		self::assertSame( 'not_applicable', $noop->outcome->state ); self::assertSame( 1, $this->fixture->count( 'operation_changes' ) );
		self::assertSame( $before, $this->fixture->row( 'rule_versions' ) ); self::assertSame( 2, (int) $this->fixture->row( 'rule_family_guards' )['revision'] );
		$edited = $opened; $edited['payload'] = ReturnPolicyFixture::content( [ 'full_text' => "New fixture terms\nwith a second line" ] )->to_rule_payload();
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.draft.edit', $edited, 'edit' )->outcome->state );
		$after = $this->fixture->row( 'rule_versions' );
		$stale = $this->fixture->attempt( 'rule.draft.edit', $opened, 'stale-editor' );
		self::assertSame( 'stale_revision', $stale->outcome->error->code ); self::assertSame( $after, $this->fixture->row( 'rule_versions' ) );
		self::assertSame( $edited['payload'], json_decode( $after['payload_json'], true, 8, JSON_THROW_ON_ERROR ) );
		self::assertSame( 2, $this->fixture->count( 'operation_changes' ) ); self::assertSame( 3, (int) $this->fixture->row( 'rule_family_guards' )['revision'] );
	}

	public function test_immediate_publish_reconciles_original_unsealed_envelope_after_result_hash_changes(): void {
		$this->create(); $original = $this->fixture->opened(); $identity = $this->fixture->identity( $original, 'rule.publish', 'publish' );
		$command = RuleLifecycleCommand::from_payload( $identity, $original, new RuleFamilyRegistry( [ $this->fixture->family->for_author( 9 ) ] ) );
		$published = $this->fixture->service()->attempt( $identity, $original, RequestContext::create() );
		self::assertSame( 'accepted', $published->outcome->state ); $stored = $this->fixture->row( 'rule_versions' );
		self::assertSame( 'published', $stored['state'] ); self::assertNull( $original['effective_from'] );
		self::assertSame( $this->fixture->factory->clock, $stored['published_at'] ); self::assertSame( $stored['published_at'], $stored['effective_from'] );
		self::assertNotSame( $command->content_hash, $stored['content_hash'] ); self::assertSame( $stored['content_hash'], $published->completion->result['content_hash'] );
		$recovered = $this->fixture->service()->reconcile( $identity, $original, RequestContext::create() );
		self::assertTrue( $recovered->replayed ); self::assertSame( $published->completion->to_json(), $recovered->completion->to_json() );
		self::assertSame( $stored, $this->fixture->row( 'rule_versions' ) ); self::assertSame( 2, $this->fixture->count( 'operation_changes' ) );
	}

	public function test_published_retirement_preserves_sealed_content_and_new_retire_token_is_no_change(): void {
		$this->create(); $this->publish(); $sealed = $this->fixture->row( 'rule_versions' ); $opened = $this->fixture->opened();
		$retired = $this->fixture->attempt( 'rule.retire', $opened, 'retire' ); self::assertSame( 'accepted', $retired->outcome->state );
		$row = $this->fixture->row( 'rule_versions' ); self::assertSame( 'retired', $row['state'] );
		foreach ( [ 'payload_json', 'content_hash', 'author_user_id', 'change_reason', 'effective_from', 'effective_until', 'published_at', 'sealed_at' ] as $field ) { self::assertSame( $sealed[$field], $row[$field] ); }
		self::assertNull( $this->fixture->row( 'logical_rules' )['current_published_version_id'] );
		$noop = $this->fixture->attempt( 'rule.retire', $this->fixture->opened(), 'already-retired' );
		self::assertSame( 'not_applicable', $noop->outcome->state ); self::assertSame( 3, $this->fixture->count( 'operation_changes' ) );
		self::assertSame( $row, $this->fixture->row( 'rule_versions' ) );
	}

	public function test_scheduled_catalog_capture_does_not_activate_when_clock_becomes_due(): void {
		$payload = $this->fixture->payload(); $payload['start_mode'] = 'at'; $payload['effective_from'] = '2026-10-24 11:00:00.000000'; $this->create( $payload );
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.schedule', $this->fixture->opened(), 'schedule' )->outcome->state );
		$before = $this->fixture->rows( 'rule_versions' ); $logical = $this->fixture->rows( 'logical_rules' ); $commits = $this->fixture->factory->commits; $sql = count( $this->fixture->factory->sql );
		$this->fixture->factory->clock = '2026-10-24 12:00:00.000000'; $snapshot = $this->capture();
		self::assertTrue( $snapshot->complete ); self::assertFalse( $snapshot->history_complete ); self::assertCount( 1, $snapshot->candidates() );
		self::assertSame( RuleState::Scheduled, $snapshot->versions[0]->state ); self::assertNull( $snapshot->versions[0]->published_at );
		self::assertSame( $before, $this->fixture->rows( 'rule_versions' ) ); self::assertSame( $logical, $this->fixture->rows( 'logical_rules' ) );
		self::assertSame( $commits, $this->fixture->factory->commits ); self::assertSame( 2, $this->fixture->count( 'operation_changes' ) );
		$queries = array_slice( $this->fixture->factory->sql, $sql ); self::assertCount( 1, $queries ); self::assertStringContainsString( 'LEFT JOIN', $queries[0] );
		self::assertStringStartsWith( 'SELECT ', $queries[0] );
	}

	public function test_retire_cancels_a_schedule_without_publication_or_content_replacement(): void {
		$payload = $this->fixture->payload(); $payload['start_mode'] = 'at'; $payload['effective_from'] = '2026-10-24 11:00:00.000000'; $this->create( $payload );
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.schedule', $this->fixture->opened(), 'schedule' )->outcome->state );
		$scheduled = $this->fixture->row( 'rule_versions' );
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.retire', $this->fixture->opened(), 'cancel-schedule' )->outcome->state );
		$retired = $this->fixture->row( 'rule_versions' ); self::assertSame( 'retired', $retired['state'] ); self::assertNull( $retired['published_at'] );
		foreach ( [ 'payload_json', 'content_hash', 'effective_from', 'sealed_at', 'scheduled_at' ] as $field ) { self::assertSame( $scheduled[$field], $retired[$field] ); }
		self::assertNull( $this->fixture->row( 'logical_rules' )['scheduled_version_id'] ); self::assertSame( [], $this->capture()->versions );
	}

	public function test_family_wide_grant_returns_both_policy_heads_and_rechecks_each_scope(): void {
		$first = $this->fixture->payload( [ 'name' => 'First catalog policy' ], 101, 1 ); $this->create( $first );
		$second = $this->fixture->payload( [ 'name' => 'Second catalog policy' ], 102, 2 ); $second['preconditions']['family_revision'] = 2; $this->create( $second );
		$seen = []; $this->fixture->on_scope = static function ( $identity, array $scope ) use ( &$seen ): void { $seen[] = $scope['policy_uuid']; };
		$commits = $this->fixture->factory->commits; $before = $this->fixture->rows( 'rule_versions' );
		$snapshot = $this->capture();
		self::assertTrue( $snapshot->complete ); self::assertFalse( $snapshot->history_complete ); self::assertCount( 2, $snapshot->logicals ); self::assertCount( 2, $snapshot->versions );
		self::assertContains( ReturnPolicyCatalogFixture::uuid( 101 ), $seen ); self::assertContains( ReturnPolicyCatalogFixture::uuid( 102 ), $seen );
		self::assertGreaterThanOrEqual( 2, $this->fixture->calls['catalog'] ); self::assertSame( $commits, $this->fixture->factory->commits );
		self::assertSame( $before, $this->fixture->rows( 'rule_versions' ) );
		$names = array_map( fn ( $candidate ): string => ReturnPolicyVersion::from_candidate( $candidate, $this->fixture->family )->content()->facts()['name'], $snapshot->candidates() );
		self::assertSame( [ 'First catalog policy', 'Second catalog policy' ], $names );
	}

	public function test_catalog_contains_current_head_and_immediate_predecessor_without_full_retired_archive(): void {
		$this->create(); $this->publish();
		$this->replacement( 2, 'Second policy wording', '2026-10-24 10:05:00.000000' );
		$this->replacement( 3, 'Third policy wording', '2026-10-24 10:10:00.000000' );
		$history = $this->fixture->rows( 'rule_versions' ); self::assertCount( 3, $history );
		$snapshot = $this->capture(); self::assertTrue( $snapshot->complete ); self::assertFalse( $snapshot->history_complete );
		$uuids = array_map( static fn ( $version ): string => $version->version_uuid, $snapshot->versions );
		self::assertCount( 2, $uuids ); self::assertNotContains( ReturnPolicyCatalogFixture::uuid( 1 ), $uuids );
		self::assertContains( ReturnPolicyCatalogFixture::uuid( 2 ), $uuids ); self::assertContains( ReturnPolicyCatalogFixture::uuid( 3 ), $uuids );
		self::assertCount( 1, $snapshot->candidates() );
		$head = ReturnPolicyVersion::from_candidate( $snapshot->candidates()[0], $this->fixture->family );
		self::assertSame( 'Third policy wording', $head->content()->facts()['full_text'] ); self::assertSame( 'published', $head->metadata()['state'] );
		self::assertSame( $history, $this->fixture->rows( 'rule_versions' ) ); self::assertSame( 6, $this->fixture->count( 'operation_changes' ) );
	}

	public function test_retired_only_policy_has_no_catalog_head_but_its_history_remains_stored(): void {
		$this->create(); $this->publish(); self::assertSame( 'accepted', $this->fixture->attempt( 'rule.retire', $this->fixture->opened(), 'retire' )->outcome->state );
		$snapshot = $this->capture(); self::assertTrue( $snapshot->complete ); self::assertFalse( $snapshot->history_complete );
		self::assertSame( [], $snapshot->logicals ); self::assertSame( [], $snapshot->versions );
		self::assertSame( 1, $this->fixture->count( 'rule_versions' ) ); self::assertSame( 'retired', $this->fixture->row( 'rule_versions' )['state'] );
	}

	public function test_lost_ack_reconciliation_keeps_original_intent_and_exactly_one_effect(): void {
		$payload = $this->fixture->payload(); $identity = $this->fixture->identity( $payload ); $this->fixture->factory->lost_ack_at = 2;
		$unknown = $this->fixture->service()->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'unconfirmed', $unknown->outcome->state ); self::assertNull( $unknown->completion );
		$stored = $this->fixture->row( 'rule_versions' ); self::assertSame( 1, $this->fixture->count( 'operation_changes' ) );
		$confirmed = $this->fixture->service()->reconcile( $identity, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $confirmed->outcome->state ); self::assertTrue( $confirmed->replayed );
		self::assertSame( $stored, $this->fixture->row( 'rule_versions' ) ); self::assertSame( 1, $this->fixture->count( 'operation_changes' ) );
		self::assertSame( $identity->namespace_digest(), $this->fixture->row( 'operation_records' )['namespace_hash'] );
	}

	public function test_failed_audit_does_not_leave_authored_rows_and_original_retry_can_succeed(): void {
		$payload = $this->fixture->payload(); $identity = $this->fixture->identity( $payload ); $this->fixture->factory->reject_audit = true;
		$failed = $this->fixture->service()->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'temporarily_unavailable', $failed->outcome->error->code ); self::assertSame( 'rejected', $failed->outcome->state );
		self::assertSame( 'rejected', $failed->completion->state ); self::assertNull( $failed->completion->result );
		self::assertSame( 0, $this->fixture->count( 'logical_rules' ) ); self::assertSame( 0, $this->fixture->count( 'rule_versions' ) ); self::assertSame( 0, $this->fixture->count( 'operation_changes' ) );
		$this->fixture->factory->reject_audit = false;
		$accepted = $this->fixture->service()->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $accepted->outcome->state ); self::assertSame( 1, $this->fixture->count( 'rule_versions' ) ); self::assertSame( 1, $this->fixture->count( 'operation_changes' ) );
	}

	private function create( ?array $payload = null ): void { self::assertSame( 'accepted', $this->fixture->attempt( 'rule.draft.create', $payload ?? $this->fixture->payload() )->outcome->state ); }
	private function publish(): void { self::assertSame( 'accepted', $this->fixture->attempt( 'rule.publish', $this->fixture->opened(), 'publish' )->outcome->state ); }
	private function capture(): RuleSnapshot {
		$payload = $this->fixture->payload(); $result = $this->fixture->service()->capture( $this->fixture->identity( $payload, 'rule.catalog.read', 'read-catalog' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( RuleSnapshot::class, $result ); return $result;
	}
	private function replacement( int $version, string $wording, string $clock ): void {
		$this->fixture->factory->clock = $clock; $opened = $this->fixture->opened(); $prior = $this->fixture->rows( 'rule_versions' ); $prior = $prior[array_key_last( $prior )];
		$opened['version_uuid'] = ReturnPolicyCatalogFixture::uuid( $version ); $opened['payload'] = ReturnPolicyFixture::content( [ 'full_text' => $wording ] )->to_rule_payload();
		$opened['effective_from'] = null; $opened['predecessor_uuid'] = $prior['version_uuid'];
		$opened['preconditions']['version_id'] = 0; $opened['preconditions']['version_revision'] = 0;
		$opened['preconditions']['predecessor_id'] = (int) $prior['id']; $opened['preconditions']['predecessor_revision'] = (int) $prior['row_revision'];
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.draft.create', $opened, 'create-version-' . $version )->outcome->state );
		self::assertSame( 'accepted', $this->fixture->attempt( 'rule.publish', $this->fixture->opened(), 'publish-version-' . $version )->outcome->state );
	}
}
