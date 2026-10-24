<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RefundPolicy;

use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyAdminWorkflow;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyContent;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRefundPolicyAdminIntent;
use CetechDeliveryEngine\Integrations\RefundPolicy\NativeRefundPolicyAuthority;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\RefundPolicy\{RefundPolicyAdminIntentDatabase, RefundPolicyCatalogFixture, RefundPolicyCatalogUnitReadiness, RefundPolicyFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/RefundPolicy/RefundPolicyAdminNativeFixtures.php';

/** Real C01/C03 with owned in-memory SQL and an option/fence model; no physical WP/MySQL claim. */
final class RefundPolicyAdminSecurityTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private RefundPolicyCatalogFixture $fixture;
	private RefundPolicyAdminIntentDatabase $options;
	private mixed $old_blog;

	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->old_blog = $GLOBALS['blog_id'] ?? null; $GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 9;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_site_wide_defaults' => true ];
		foreach ( [ 9, 10 ] as $id ) { $GLOBALS['cetech_de_test_users'][$id] = (object) [ 'ID' => $id, 'roles' => [ 'custom_manager' ], 'allcaps' => $GLOBALS['cetech_de_test_caps'] ]; }
		AdminPageAccess::bind( null ); $this->fixture = new RefundPolicyCatalogFixture(); $this->options = new RefundPolicyAdminIntentDatabase();
	}
	protected function tearDown(): void {
		$this->fixture->close(); AdminPageAccess::bind( null ); $this->restore_fixture_globals();
		if ( null === $this->old_blog ) { unset( $GLOBALS['blog_id'] ); } else { $GLOBALS['blog_id'] = $this->old_blog; }
	}

	public function test_catalog_grant_covers_two_real_policy_heads_without_mutating_them(): void {
		$f = $this->fixture; $first = $f->payload( [ 'name' => 'PRIVATE_FIRST_POLICY' ], 101, 1 );
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $first )->outcome->state );
		$second = $f->payload( [ 'name' => 'PRIVATE_SECOND_POLICY' ], 102, 2 ); $second['preconditions']['family_revision'] = 2;
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $second, 'second' )->outcome->state );
		$before = $f->rows( 'rule_versions' ); $commits = $f->factory->commits;
		$view = $this->workflow()->view(); self::assertCount( 2, $view['rows'] ); self::assertFalse( $view['blocked'] );
		self::assertSame( $before, $f->rows( 'rule_versions' ) ); self::assertSame( $commits, $f->factory->commits );
	}

	public function test_native_grants_are_preloaded_before_owned_sql_and_rechecked_after_retirement(): void {
		$f = $this->fixture; $native_calls_during_sql = 0;
		$GLOBALS['cetech_de_test_return_native_observer'] = static function () use ( $f, &$native_calls_during_sql ): void {
			foreach ( $f->factory->sessions as $session ) { if ( ! $session->is_retired() ) { ++$native_calls_during_sql; } }
		};
		$workflow = $this->workflow(); $review = $workflow->open();
		$result = $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'accepted', $result->outcome->state ); self::assertSame( 0, $native_calls_during_sql );
		self::assertGreaterThan( 2, $GLOBALS['cetech_de_test_return_native_calls'] ); self::assertSame( [], $this->options->rows );
	}

	#[DataProvider( 'forged_authority_fields' )]
	public function test_client_cannot_supply_identity_author_or_cas( string $key, mixed $value ): void {
		$workflow = $this->workflow(); $review = $workflow->open(); $before = $this->options->rows;
		$this->refused( fn() => $workflow->submit( $review['ticket'], 'create', $this->fields() + [ $key => $value ], RequestContext::create() ) );
		self::assertSame( $before, $this->options->rows ); self::assertSame( 0, $this->fixture->count( 'operation_records' ) );
		self::assertSame( 0, $this->fixture->count( 'rule_versions' ) );
	}
	public static function forged_authority_fields(): array {
		return [ 'author' => [ 'author_user_id', 10 ], 'site' => [ 'site_id', 2 ], 'principal' => [ 'principal', 'user:10' ],
			'identity' => [ 'identity', [ 'principal' => 'user:10' ] ], 'payload' => [ 'payload', [] ], 'revision' => [ 'family_revision', 999 ],
			'token' => [ 'idempotency_key', 'forged-token' ], 'target' => [ 'target_key', 'forged-target' ] ];
	}

	public function test_wrong_review_ticket_does_not_replace_server_owned_original_state(): void {
		$workflow = $this->workflow(); $review = $workflow->open(); $before = $this->options->rows;
		$this->refused( fn() => $workflow->submit( RequestContext::create()->request_id, 'create', $this->fields(), RequestContext::create() ) );
		self::assertSame( $before, $this->options->rows ); self::assertSame( $review['ticket'], $workflow->view()['review']['ticket'] );
		self::assertSame( 0, $this->fixture->count( 'operation_records' ) );
	}

	public function test_post_commit_authority_loss_is_uncertain_without_disclosing_or_clearing_original(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open();
		$f->factory->on_commit = static function ( $factory ): void {
			if ( 2 === $factory->commits ) { $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; }
		};
		$result = $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion );
		self::assertSame( 1, $f->count( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		$original = $this->record(); self::assertContains( $original['state'], [ 'inflight', 'uncertain' ] );
		$GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = true;
		$f->factory->on_commit = null; $recovered = $this->workflow()->recover( 'reconcile', RequestContext::create() );
		self::assertSame( 'accepted', $recovered->outcome->state ); self::assertSame( [], $this->options->rows );
		self::assertSame( 1, $f->count( 'operation_changes' ) ); self::assertSame( 1, $f->count( 'rule_versions' ) );
	}

	public function test_lost_effect_ack_blocks_fresh_writes_across_workflow_instances_and_replays_original(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open(); $f->factory->lost_ack_at = 2;
		$unknown = $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $unknown->outcome->state ); $record = $this->record(); $stored = $f->rows( 'rule_versions' );
		$fresh = $this->workflow(); self::assertTrue( $fresh->view()['blocked'] );
		$this->refused( fn() => $fresh->open() );
		$blocked = $fresh->submit( $review['ticket'], 'create', $this->fields( [ 'full_text' => 'ATTEMPTED_REPLACEMENT' ] ), RequestContext::create() );
		self::assertSame( 'unconfirmed', $blocked->outcome->state ); self::assertNull( $blocked->completion );
		self::assertSame( $record['identity'], $this->record()['identity'] ); self::assertSame( $record['payload'], $this->record()['payload'] );
		$recovered = $this->workflow()->recover( 'reconcile', RequestContext::create() );
		self::assertSame( 'accepted', $recovered->outcome->state ); self::assertTrue( $recovered->replayed );
		self::assertSame( $stored, $f->rows( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		self::assertSame( [], $this->options->rows );
	}

	public function test_abandoned_reservation_stays_sticky_after_rejected_reconcile_until_same_original_retry(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open(); $f->factory->lost_ack_at = 1;
		self::assertSame( 'unconfirmed', $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		$original = $this->record(); self::assertSame( 0, $f->count( 'rule_versions' ) );
		$reconciled = $this->workflow()->recover( 'reconcile', RequestContext::create() );
		self::assertSame( 'unconfirmed', $reconciled->outcome->state ); self::assertNull( $reconciled->completion );
		self::assertTrue( $this->workflow()->view()['blocked'] ); self::assertSame( $original['identity'], $this->record()['identity'] ); self::assertSame( $original['payload'], $this->record()['payload'] );
		$retried = $this->workflow()->recover( 'retry', RequestContext::create() );
		self::assertSame( 'accepted', $retried->outcome->state ); self::assertSame( 1, $f->count( 'operation_records' ) );
		self::assertSame( 1, $f->count( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		self::assertSame( [], $this->options->rows );
	}

	public function test_other_manager_cannot_rebind_an_uncertain_original_owner_namespace(): void {
		$workflow = $this->workflow(); $review = $workflow->open(); $this->fixture->factory->lost_ack_at = 2;
		self::assertSame( 'unconfirmed', $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		$before = $this->options->rows; $rows = $this->fixture->rows( 'rule_versions' );
		$GLOBALS['cetech_de_test_user_id'] = 10;
		$this->refused( fn() => $this->workflow()->recover( 'reconcile', RequestContext::create() ) );
		self::assertSame( $before, $this->options->rows ); self::assertSame( $rows, $this->fixture->rows( 'rule_versions' ) );
		$GLOBALS['cetech_de_test_user_id'] = 9;
		self::assertSame( 'accepted', $this->workflow()->recover( 'reconcile', RequestContext::create() )->outcome->state );
	}

	public function test_delegate_publish_preserves_saved_author_reason_and_original_unsealed_dates_on_recovery(): void {
		$f = $this->fixture; self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $f->payload() )->outcome->state );
		$GLOBALS['cetech_de_test_user_id'] = 10; $workflow = $this->workflow(); $review = $workflow->open( RefundPolicyCatalogFixture::uuid( 101 ), RefundPolicyCatalogFixture::uuid( 1 ) );
		$f->factory->lost_ack_at = $f->factory->commits + 2;
		$result = $workflow->submit( $review['ticket'], 'publish', $this->fields( [ 'reason' => 'CLIENT_REPLACEMENT_REASON', 'full_text' => 'CLIENT_REPLACEMENT_CONTENT' ] ), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); $record = $this->record( 10 );
		self::assertSame( 'user:10', $record['identity']['principal'] ); self::assertSame( 9, $record['payload']['author_user_id'] );
		self::assertSame( 'PRIVATE_REFUND_CATALOG_REASON', $record['payload']['change_reason'] ); self::assertNull( $record['payload']['effective_from'] );
		$physical = $f->row( 'rule_versions' ); self::assertSame( 'published', $physical['state'] ); self::assertNotNull( $physical['effective_from'] );
		self::assertSame( 9, (int) $physical['author_user_id'] ); self::assertSame( $record['payload']['change_reason'], $physical['change_reason'] );
		self::assertSame( 'accepted', $this->workflow()->recover( 'reconcile', RequestContext::create() )->outcome->state );
		self::assertSame( $physical, $f->row( 'rule_versions' ) ); self::assertSame( 2, $f->count( 'operation_changes' ) ); self::assertSame( [], $this->options->rows );
	}

	public function test_unchanged_edit_is_a_live_no_change_completion_and_releases_the_barrier(): void {
		$f = $this->fixture; self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $f->payload() )->outcome->state );
		$workflow = $this->workflow(); $review = $workflow->open( RefundPolicyCatalogFixture::uuid( 101 ), RefundPolicyCatalogFixture::uuid( 1 ) ); $before = $f->row( 'rule_versions' );
		$result = $workflow->submit( $review['ticket'], 'edit', $review['fields'] + [ 'reason' => $review['reason'] ], RequestContext::create() );
		self::assertSame( 'not_applicable', $result->outcome->state ); self::assertNotNull( $result->completion );
		self::assertSame( $before, $f->row( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		self::assertSame( [], $this->options->rows ); self::assertFalse( $this->workflow()->view()['blocked'] );
	}

	public function test_lost_inflight_journal_ack_never_dispatches_and_keeps_same_original_for_explicit_retry(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open(); $opens = $f->factory->opens;
		$hook = null;
		$hook = function ( string $sql, array $args ) use ( &$hook ): void {
			$record = str_starts_with( $sql, 'UPDATE ' ) ? json_decode( $args[0], true, 32, JSON_THROW_ON_ERROR ) : [];
			if ( 'inflight' === ( $record['state'] ?? null ) ) { $this->options->fail_next_read = true; }
			else { $this->options->after_write = $hook; }
		};
		$this->options->after_write = $hook;
		self::assertSame( 'unconfirmed', $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		self::assertSame( $opens, $f->factory->opens ); self::assertSame( 0, $f->count( 'operation_records' ) );
		$original = $this->record(); self::assertSame( 'inflight', $original['state'] );
		self::assertSame( 'unconfirmed', $this->workflow()->recover( 'reconcile', RequestContext::create() )->outcome->state );
		self::assertSame( $original['identity'], $this->record()['identity'] ); self::assertSame( $original['payload'], $this->record()['payload'] );
		self::assertSame( 'accepted', $this->workflow()->recover( 'retry', RequestContext::create() )->outcome->state );
		self::assertSame( 1, $f->count( 'operation_records' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) ); self::assertSame( [], $this->options->rows );
	}

	public function test_catalog_revocation_during_owned_read_hides_the_projection_after_retirement(): void {
		$f = $this->fixture; self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $f->payload( [ 'full_text' => 'PRIVATE_SAVED_POLICY_BODY' ] ) )->outcome->state );
		$before = $f->rows( 'rule_versions' ); $f->factory->on_query = static function (): void { $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; };
		try { $this->workflow()->view(); self::fail( 'Revoked native authority must hide the catalog.' ); }
		catch ( \RuntimeException $error ) { self::assertStringNotContainsString( 'PRIVATE_', $error->getMessage() ); }
		self::assertSame( $before, $f->rows( 'rule_versions' ) );
		foreach ( $f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}

	public function test_no_change_lost_ack_is_reconciled_live_without_an_extra_business_audit(): void {
		$f = $this->fixture; self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $f->payload() )->outcome->state );
		$workflow = $this->workflow(); $review = $workflow->open( RefundPolicyCatalogFixture::uuid( 101 ), RefundPolicyCatalogFixture::uuid( 1 ) );
		$before = $f->row( 'rule_versions' ); $f->factory->lost_ack_at = $f->factory->commits + 2;
		self::assertSame( 'unconfirmed', $workflow->submit( $review['ticket'], 'edit', $review['fields'] + [ 'reason' => $review['reason'] ], RequestContext::create() )->outcome->state );
		self::assertTrue( $this->workflow()->view()['blocked'] ); $opens = $f->factory->opens;
		$resolved = $this->workflow()->recover( 'reconcile', RequestContext::create() );
		self::assertSame( 'not_applicable', $resolved->outcome->state ); self::assertTrue( $resolved->replayed );
		self::assertGreaterThan( $opens, $f->factory->opens ); self::assertSame( $before, $f->row( 'rule_versions' ) );
		self::assertSame( 1, $f->count( 'operation_changes' ) ); self::assertSame( [], $this->options->rows );
	}

	public function test_persisted_complete_flag_is_neither_a_receipt_nor_permission_to_clear(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open(); $f->factory->lost_ack_at = 2;
		self::assertSame( 'unconfirmed', $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		$name = WpdbRefundPolicyAdminIntent::name( 1, 9 ); $record = $this->record(); $record['state'] = 'complete';
		$this->options->rows[$name] = json_encode( $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$before = $this->options->rows; $opens = $f->factory->opens;
		$result = $this->workflow()->recover( 'reconcile', RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion );
		self::assertSame( $before, $this->options->rows ); self::assertSame( $opens, $f->factory->opens );
	}

	public function test_stale_server_opened_cas_cannot_overwrite_a_later_edit(): void {
		$f = $this->fixture; self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $f->payload() )->outcome->state );
		$workflow = $this->workflow(); $review = $workflow->open( RefundPolicyCatalogFixture::uuid( 101 ), RefundPolicyCatalogFixture::uuid( 1 ) );
		$changed = $f->opened(); $changed['payload'] = RefundPolicyFixture::content( [ 'full_text' => 'LATER_AUTHENTIC_EDITOR' ] )->to_rule_payload();
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.edit', $changed, 'other-editor' )->outcome->state ); $current = $f->row( 'rule_versions' );
		$stale = $workflow->submit( $review['ticket'], 'edit', $this->fields( [ 'full_text' => 'STALE_EDITOR_OVERWRITE' ] ), RequestContext::create() );
		self::assertSame( 'rejected', $stale->outcome->state ); self::assertSame( 'stale_revision', $stale->outcome->error->code );
		self::assertSame( $current, $f->row( 'rule_versions' ) ); self::assertSame( 2, $f->count( 'operation_changes' ) );
		self::assertSame( [], $this->options->rows );
	}

	#[DataProvider( 'foreign_transaction_states' )]
	public function test_editor_refuses_an_unowned_options_transaction_before_any_service_connection( int $autocommit, int $in_transaction ): void {
		$this->options->autocommit = $autocommit; $this->options->in_transaction = $in_transaction;
		try { $this->workflow()->open(); self::fail( 'An unowned transaction cannot durably acknowledge the original editor request.' ); }
		catch ( \RuntimeException $error ) { self::assertStringNotContainsString( 'PRIVATE_', $error->getMessage() ); }
		self::assertSame( 0, $this->fixture->factory->opens ); self::assertSame( [], $this->options->rows );
		self::assertSame( $autocommit, $this->options->autocommit ); self::assertSame( $in_transaction, $this->options->in_transaction );
		self::assertSame( [], array_values( array_filter( $this->options->queries, static fn( string $sql ): bool => 1 === preg_match( '/\A(?:START TRANSACTION|COMMIT|ROLLBACK)\b/', $sql ) ) ) );
	}
	public static function foreign_transaction_states(): array { return [ 'manual_commit_mode' => [ 0, 0 ], 'foreign_active_transaction' => [ 1, 1 ] ]; }

	public function test_transaction_started_during_inflight_ack_cannot_dispatch_on_an_uncommitted_journal(): void {
		$f = $this->fixture; $workflow = $this->workflow(); $review = $workflow->open(); $opens = $f->factory->opens;
		$hook = null;
		$hook = function ( string $sql, array $args ) use ( &$hook ): void {
			$record = str_starts_with( $sql, 'UPDATE ' ) ? json_decode( $args[0], true, 32, JSON_THROW_ON_ERROR ) : [];
			if ( 'inflight' === ( $record['state'] ?? null ) ) { $this->options->in_transaction = 1; }
			else { $this->options->after_write = $hook; }
		};
		$this->options->after_write = $hook;
		$result = $workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion );
		self::assertSame( $opens, $f->factory->opens ); self::assertSame( 0, $f->count( 'operation_records' ) );
		self::assertSame( 1, $this->options->in_transaction ); self::assertSame( 'inflight', $this->record()['state'] );
	}

	public function test_native_identity_of_one_policy_cannot_be_rebound_to_the_other_authority(): void {
		$refund = new NativeRefundPolicyAuthority();
		$return = new \CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority();
		$refund_data = $refund->identity_data( 'refund.catalog.read', 'refund-policy:catalog', 'private-refund-native-original' );
		$return_data = $return->identity_data( 'return.catalog.read', 'return-policy:catalog', 'private-return-native-original' );
		self::assertSame( $refund_data['site_id'], $return_data['site_id'] );
		self::assertSame( $refund_data['principal'], $return_data['principal'] );
		self::assertNotSame( $refund_data['authority'], $return_data['authority'] );
		foreach ( [ [ $refund, $return_data ], [ $return, $refund_data ] ] as [ $authority, $foreign ] ) {
			try { $authority->identity( $foreign ); self::fail( 'A native actor is not authority to relabel another policy original.' ); }
			catch ( \RuntimeException|\InvalidArgumentException $error ) { self::assertStringNotContainsString( 'private-', $error->getMessage() ); }
		}
		self::assertSame( [], $this->options->rows ); self::assertSame( 0, $this->fixture->factory->opens );
	}

	public function test_each_byte_journal_rejects_foreign_family_keys_without_touching_either_original(): void {
		$refund = new WpdbRefundPolicyAdminIntent( $this->options );
		$return = new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent( $this->options );
		$refund_name = WpdbRefundPolicyAdminIntent::name( 1, 9 );
		$return_name = \CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent::name( 1, 9 );
		self::assertNotSame( $refund_name, $return_name );
		$refund->locked( $refund_name, fn(): bool => $refund->insert( $refund_name, 'PRIVATE_REFUND_ORIGINAL' ) );
		$return->locked( $return_name, fn(): bool => $return->insert( $return_name, 'PRIVATE_RETURN_ORIGINAL' ) );
		$before = $this->options->rows;
		foreach ( [ [ $refund, $return_name ], [ $return, $refund_name ] ] as [ $journal, $foreign ] ) {
			try { $journal->locked( $foreign, fn(): bool => $journal->remove( $foreign, $before[$foreign] ) ); self::fail( 'A family must refuse another family key before mutation.' ); }
			catch ( \RuntimeException|\InvalidArgumentException $error ) { self::assertStringNotContainsString( 'PRIVATE_', $error->getMessage() ); }
		}
		self::assertSame( $before, $this->options->rows ); self::assertSame( [], $this->options->locks );
		self::assertSame( 0, $this->fixture->factory->opens );
	}

	private function workflow(): RefundPolicyAdminWorkflow {
		return new RefundPolicyAdminWorkflow( new NativeRefundPolicyAuthority(), new WpdbRefundPolicyAdminIntent( $this->options ), $this->fixture->factory, new RefundPolicyCatalogUnitReadiness() );
	}
	private function fields( array $changes = [] ): array { return array_replace( RefundPolicyFixture::content()->facts() + [ 'reason' => 'PRIVATE_EDITOR_ORIGINAL_REASON' ], $changes ); }
	private function record( int $owner = 9 ): array {
		$name = WpdbRefundPolicyAdminIntent::name( 1, $owner ); self::assertArrayHasKey( $name, $this->options->rows );
		return json_decode( $this->options->rows[$name], true, 32, JSON_THROW_ON_ERROR );
	}
	/** Failure can be a transport exception or known refusal, but it must never accept/claim uncertainty. */
	private function refused( callable $work ): void {
		try { $result = $work(); } catch ( \Throwable $error ) { self::assertStringNotContainsString( 'PRIVATE_', $error->getMessage() ); return; }
		self::assertInstanceOf( OperationAttemptResult::class, $result ); self::assertSame( 'rejected', $result->outcome->state ); self::assertNull( $result->completion );
	}
}
