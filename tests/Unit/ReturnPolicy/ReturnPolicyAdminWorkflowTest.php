<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyAdminWorkflow;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent;
use CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\{ReturnPolicyAdminIntentDatabase, ReturnPolicyCatalogFixture, ReturnPolicyCatalogUnitReadiness};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/ReturnPolicy/ReturnPolicyAdminNativeFixtures.php';

/** Sequential memory model only, never native WordPress or MariaDB acceptance. */
final class ReturnPolicyAdminWorkflowTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private ReturnPolicyCatalogFixture $catalog;
	private ReturnPolicyAdminIntentDatabase $database;
	private ReturnPolicyAdminWorkflow $workflow;
	private mixed $previous_blog;
	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->previous_blog = $GLOBALS['blog_id'] ?? null; $GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 9;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, Capabilities::SITE_WIDE => true ];
		$GLOBALS['cetech_de_test_users'] = [ 9 => (object) [ 'ID' => 9, 'roles' => [ 'delivery_manager' ], 'allcaps' => $GLOBALS['cetech_de_test_caps'] ] ];
		AdminPageAccess::bind( null ); $this->catalog = new ReturnPolicyCatalogFixture(); $this->database = new ReturnPolicyAdminIntentDatabase();
		$this->workflow = $this->fresh();
	}
	protected function tearDown(): void {
		$this->catalog->close(); AdminPageAccess::bind( null ); $this->restore_fixture_globals();
		if ( null === $this->previous_blog ) { unset( $GLOBALS['blog_id'] ); } else { $GLOBALS['blog_id'] = $this->previous_blog; }
	}
	private function fresh(): ReturnPolicyAdminWorkflow { return new ReturnPolicyAdminWorkflow( new NativeReturnPolicyAuthority(), new WpdbReturnPolicyAdminIntent( $this->database ), $this->catalog->factory, new ReturnPolicyCatalogUnitReadiness() ); }
	private function fields( array $changes = [] ): array { return $changes + [ 'name' => 'Returns café', 'customer_title' => 'Return terms <sample>', 'summary' => "Summary\nnext line", 'full_text' => "Authored body <b>not markup</b>\r\n第二行", 'exceptions' => '', 'reason' => 'Original author supplied reason' ]; }
	private function save_new(): array {
		$review = $this->workflow->open(); self::assertSame( 'new', $review['mode'] ); self::assertSame( [ 'create' ], $review['verbs'] );
		$result = $this->workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() ); self::assertSame( 'accepted', $result->outcome->state );
		self::assertSame( [], $this->database->rows ); return $this->workflow->view()['rows'][0];
	}
	public function test_native_editor_create_reopen_edit_publish_replacement_and_retire(): void {
		$row = $this->save_new(); self::assertSame( 'draft', $row['state'] ); self::assertSame( 1, $row['sequence'] );
		$expected = $this->fields(); unset( $expected['reason'] ); self::assertSame( $expected, $row['fields'] );
		$draft = $this->workflow->open( $row['policy_uuid'], $row['version_uuid'] ); self::assertSame( [ 'edit', 'publish', 'retire' ], $draft['verbs'] );
		self::assertSame( 'accepted', $this->workflow->submit( $draft['ticket'], 'edit', $this->fields( [ 'name' => 'Edited name', 'reason' => 'Edited reason' ] ), RequestContext::create() )->outcome->state );
		$draft = $this->workflow->open( $row['policy_uuid'] );
		self::assertSame( 'accepted', $this->workflow->submit( $draft['ticket'], 'publish', [ 'author_user_id' => 17, 'reason' => 'ignored replacement' ], RequestContext::create() )->outcome->state );
		$published = $this->workflow->view()['rows'][0]; self::assertSame( 'published', $published['state'] ); self::assertSame( 'Edited reason', $published['reason'] );
		$review = $this->workflow->open( $published['policy_uuid'], $published['version_uuid'] ); self::assertSame( [ 'create', 'retire' ], $review['verbs'] );
		self::assertSame( 'accepted', $this->workflow->submit( $review['ticket'], 'create', $this->fields( [ 'name' => 'Replacement', 'reason' => 'Replace published terms' ] ), RequestContext::create() )->outcome->state );
		$rows = $this->workflow->view()['rows']; self::assertCount( 2, $rows ); $replacement = array_values( array_filter( $rows, static fn ( array $v ): bool => 'draft' === $v['state'] ) )[0];
		self::assertSame( $published['policy_uuid'], $replacement['policy_uuid'] ); self::assertNotSame( $published['version_uuid'], $replacement['version_uuid'] ); self::assertSame( 2, $replacement['sequence'] );
		$review = $this->workflow->open( $replacement['policy_uuid'], $replacement['version_uuid'] );
		self::assertSame( 'accepted', $this->workflow->submit( $review['ticket'], 'publish', [], RequestContext::create() )->outcome->state );
		self::assertSame( 'retired', $this->catalog->rows( 'rule_versions' )[0]['state'] );
		$review = $this->workflow->open( $replacement['policy_uuid'] );
		self::assertSame( 'accepted', $this->workflow->submit( $review['ticket'], 'retire', [], RequestContext::create() )->outcome->state );
		self::assertSame( [], $this->workflow->view()['rows'] ); self::assertCount( 2, $this->catalog->rows( 'rule_versions' ) );
	}
	public function test_identical_edit_is_authenticated_no_change_and_does_not_strand_journal(): void {
		$row = $this->save_new(); $review = $this->workflow->open( $row['policy_uuid'] );
		$result = $this->workflow->submit( $review['ticket'], 'edit', $this->fields(), RequestContext::create() );
		self::assertSame( 'not_applicable', $result->outcome->state ); self::assertNotNull( $result->completion ); self::assertSame( [], $this->database->rows );
		self::assertSame( 1, $this->catalog->count( 'operation_changes' ) ); self::assertSame( 2, $this->catalog->count( 'operation_records' ) );
	}
	public function test_persisted_review_reopens_from_new_workflow_without_caller_cas_or_author(): void {
		$review = $this->workflow->open(); $fresh = $this->fresh(); self::assertSame( $review, $fresh->view()['review'] );
		self::assertSame( 'accepted', $fresh->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		self::assertSame( 9, (int) $this->catalog->row( 'rule_versions' )['author_user_id'] ); self::assertSame( [], $this->database->rows );
	}
	public function test_old_tab_ticket_cannot_replace_newer_opened_review(): void {
		$old = $this->workflow->open(); $new = $this->workflow->open();
		self::assertNotSame( $old['ticket'], $new['ticket'] );
		$result = $this->workflow->submit( $old['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( 'stale_revision', $result->outcome->error->code ); self::assertSame( 0, $this->catalog->count( 'rule_versions' ) ); self::assertSame( $new, $this->workflow->view()['review'] );
	}
	#[DataProvider( 'invalid_fields' )]
	public function test_invalid_authored_fields_are_refused_before_dispatch_and_review_survives( string $case ): void {
		$review = $this->workflow->open(); $fields = $this->fields();
		switch ( $case ) { case 'extra': $fields['author_user_id'] = 14; break; case 'missing': unset( $fields['exceptions'] ); break; case 'blank': $fields['name'] = ' '; break; case 'nontext': $fields['summary'] = []; break; case 'oversize': $fields['full_text'] = str_repeat( 'x', 17000 ); break; case 'blankreason': $fields['reason'] = ' '; break; case 'reasoncontrol': $fields['reason'] = "reason\nline"; break; }
		self::assertSame( 'rejected', $this->workflow->submit( $review['ticket'], 'create', $fields, RequestContext::create() )->outcome->state );
		self::assertSame( 0, $this->catalog->count( 'operation_records' ) ); self::assertSame( $review, $this->workflow->view()['review'] );
	}
	public static function invalid_fields(): array { return array_map( static fn ( string $case ): array => [ $case ], [ 'extra', 'missing', 'blank', 'nontext', 'oversize', 'blankreason', 'reasoncontrol' ] ); }
	public function test_original_opened_cas_is_not_silently_refreshed_after_family_advances(): void {
		$review = $this->workflow->open(); $other = $this->catalog->payload( [], 777, 778 );
		self::assertSame( 'accepted', $this->catalog->attempt( 'rule.draft.create', $other, 'other actor unrelated policy' )->outcome->state );
		$result = $this->workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( 'stale_revision', $result->outcome->error->code ); self::assertSame( 1, $this->catalog->count( 'rule_versions' ) ); self::assertSame( [], $this->database->rows );
	}
	public function test_review_can_be_replaced_but_unknown_original_blocks_new_review(): void {
		$review = $this->workflow->open(); $this->catalog->factory->lost_ack_at = $this->catalog->factory->commits + 2;
		$result = $this->workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertTrue( $this->fresh()->view()['blocked'] );
		$this->expectException( \RuntimeException::class ); $this->fresh()->open();
	}
	public function test_native_actor_cannot_change_between_review_and_durable_dispatch(): void {
		$review = $this->workflow->open();
		$GLOBALS['cetech_de_test_users'][7] = (object) [ 'ID' => 7, 'roles' => [ 'delivery_manager' ], 'allcaps' => $GLOBALS['cetech_de_test_caps'] ];
		$this->database->after_write = static function (): void { $GLOBALS['cetech_de_test_user_id'] = 7; };
		$result = $this->workflow->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertSame( 0, $this->catalog->count( 'operation_records' ) );
		self::assertCount( 1, $this->database->rows ); $retained = json_decode( array_values( $this->database->rows )[0], true );
		self::assertSame( 9, $retained['owner_id'] ); self::assertSame( 'user:9', $retained['identity']['principal'] );
		$GLOBALS['cetech_de_test_user_id'] = 9;
		self::assertSame( 'accepted', $this->fresh()->recover( 'retry', RequestContext::create() )->outcome->state ); self::assertSame( [], $this->database->rows );
	}
	public function test_corrupt_recovery_record_is_never_relabelled_a_known_rejection(): void {
		$review = $this->workflow->open(); $name = array_key_first( $this->database->rows );
		$data = json_decode( $this->database->rows[$name], true ); $data['unrecognised'] = 'cannot discard';
		$this->database->rows[$name] = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $raw = $this->database->rows[$name];
		self::assertSame( 'unconfirmed', $this->fresh()->submit( $review['ticket'], 'create', $this->fields(), RequestContext::create() )->outcome->state );
		self::assertSame( $raw, $this->database->rows[$name] ); self::assertSame( 0, $this->catalog->count( 'operation_records' ) );
	}
	public function test_unknown_action_cannot_activate_or_schedule(): void {
		$review = $this->workflow->open();
		foreach ( [ 'schedule', 'activate', 'configure', 'enable', 'delete' ] as $verb ) { self::assertSame( 'rejected', $this->workflow->submit( $review['ticket'], $verb, $this->fields(), RequestContext::create() )->outcome->state ); }
		self::assertSame( 0, $this->catalog->count( 'operation_records' ) ); self::assertSame( $review, $this->workflow->view()['review'] );
	}
}
