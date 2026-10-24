<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Presentation\Admin\ReturnPolicyPage;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Form/escaping unit proofs use WordPress doubles; real nonce/HTTP proof is separate. */
final class ReturnPolicyPageTest extends TestCase {

	use RestoresWordPressFixtureGlobals;
	private array $server;
	protected function setUp(): void { $this->remember_fixture_globals(); $this->server = $_SERVER; }
	protected function tearDown(): void { $this->restore_fixture_globals(); $_SERVER = $this->server; }

	public function test_transport_preserves_authored_unicode_multiline_html_and_original_reason_bytes(): void {
		$fields = self::fields() + [ 'reason' => '  Original café reason <text>  ', 'ticket' => 'opaque-review-ticket' ];
		$parsed = ReturnPolicyPage::parse_form( $fields );
		foreach ( $fields as $key => $value ) { self::assertSame( $value, $parsed[ $key ] ); }
		self::assertSame( '', $parsed['policy_uuid'] );
	}

	#[DataProvider( 'authority_fields' )]
	public function test_no_posted_authority_or_command_material_enters_the_transport( string $key ): void {
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyPage::parse_form( self::fields() + [ $key => 'forged' ] );
	}
	public static function authority_fields(): array {
		return array_map( static fn ( string $key ): array => [ $key ], [ 'site_id', 'user_id', 'author_user_id', 'principal', 'authority', 'request_token', 'operation', 'payload', 'command_json', 'preconditions', 'family_revision', 'priority', 'reason_for_publish' ] );
	}

	#[DataProvider( 'bad_scalars' )]
	public function test_non_scalar_invalid_utf8_or_oversize_form_fails_closed( array $bad ): void {
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyPage::parse_form( array_replace( self::fields(), $bad ) );
	}
	public static function bad_scalars(): array {
		return [
			'array name' => [ [ 'name' => [ 'forged' ] ] ],
			'array nonce' => [ [ 'cetech_de_nonce' => [ 'forged' ] ] ],
			'integer ticket' => [ [ 'ticket' => 8 ] ],
			'invalid utf8' => [ [ 'full_text' => "\xFF" ] ],
			'large text' => [ [ 'full_text' => str_repeat( 'x', 16385 ) ] ],
			'large reason' => [ [ 'reason' => str_repeat( 'x', 513 ) ] ],
			'large ticket' => [ [ 'ticket' => str_repeat( 'x', 129 ) ] ],
			'large selector' => [ [ 'policy_uuid' => str_repeat( 'x', 37 ) ] ],
			'large transport' => [ [ 'summary' => str_repeat( 'x', 32769 ) ] ],
		];
	}

	#[DataProvider( 'ignored_routes' )]
	public function test_ignored_routes_never_reach_uninitialized_workflow_or_action_handler( string $method, mixed $action, mixed $nonce ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST = [ 'cetech_de_action' => $action, 'cetech_de_nonce' => $nonce ];
		$page = ( new \ReflectionClass( ReturnPolicyPage::class ) )->newInstanceWithoutConstructor();
		$page->handle_actions();
		self::assertSame( '', ( new \ReflectionProperty( $page, 'message' ) )->getValue( $page ) );
	}
	public static function ignored_routes(): array {
		return [
			'get mutation' => [ 'GET', ReturnPolicyPage::ACTION_PREFIX . 'create', 'nonce' ],
			'head mutation' => [ 'HEAD', ReturnPolicyPage::ACTION_PREFIX . 'publish', 'nonce' ],
			'unknown verb' => [ 'POST', ReturnPolicyPage::ACTION_PREFIX . 'activate', 'nonce' ],
			'foreign action' => [ 'POST', 'cetech_de_promise_create', 'nonce' ],
			'array action' => [ 'POST', [ ReturnPolicyPage::ACTION_PREFIX . 'create' ], 'nonce' ],
			'array nonce' => [ 'POST', ReturnPolicyPage::ACTION_PREFIX . 'create', [ 'nonce' ] ],
			'missing nonce' => [ 'POST', ReturnPolicyPage::ACTION_PREFIX . 'retire', null ],
		];
	}

	public function test_editor_escapes_literal_text_in_its_correct_context_and_has_named_native_controls(): void {
		$fields = self::fields(); $reason = 'Original "quoted" <reason>';
		$html = $this->render( 'render_fields', $fields, $reason );
		foreach ( [ 'name', 'customer_title', 'summary', 'full_text', 'exceptions', 'reason' ] as $key ) {
			self::assertStringContainsString( 'name="' . $key . '"', $html );
			self::assertStringContainsString( '<label for="' . $key . '">', $html );
		}
		self::assertStringContainsString( 'value="' . esc_attr( $fields['name'] ) . '"', $html );
		self::assertStringContainsString( esc_textarea( $fields['full_text'] ) . '</textarea>', $html );
		self::assertStringContainsString( 'value="' . esc_attr( $reason ) . '"', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( 'name="content_json"', $html );
		self::assertStringNotContainsString( 'name="author_user_id"', $html );
	}

	public function test_private_read_projection_renders_html_as_text_and_preserves_multiline(): void {
		$fields = self::fields(); $html = $this->render( 'render_private_text', $fields );
		self::assertStringContainsString( esc_html( $fields['full_text'] ), $html );
		self::assertStringContainsString( 'white-space:pre-wrap;overflow-wrap:anywhere', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img', $html );
	}

	#[DataProvider( 'verbs' )]
	public function test_each_form_has_an_action_bound_nonce_and_native_post( string $verb ): void {
		$html = $this->render( 'form_start', $verb ); $action = ReturnPolicyPage::ACTION_PREFIX . $verb;
		self::assertStringContainsString( '<form method="post" action=""', $html );
		self::assertStringContainsString( 'name="cetech_de_action" value="' . $action . '"', $html );
		self::assertStringContainsString( 'name="cetech_de_nonce" value="test-nonce-' . $action . '"', $html );
	}
	public static function verbs(): array { return array_map( static fn ( string $verb ): array => [ $verb ], ReturnPolicyPage::VERBS ); }

	public function test_recovery_panel_exposes_only_same_original_check_and_retry_without_payload_fields(): void {
		$html = $this->render( 'render_recovery' );
		self::assertStringContainsString( ReturnPolicyPage::ACTION_PREFIX . 'reconcile', $html );
		self::assertStringContainsString( ReturnPolicyPage::ACTION_PREFIX . 'retry', $html );
		self::assertStringNotContainsString( ReturnPolicyPage::ACTION_PREFIX . 'create', $html );
		self::assertStringNotContainsString( ReturnPolicyPage::ACTION_PREFIX . 'edit', $html );
		foreach ( [ 'ticket', 'reason', 'full_text', 'command_json', 'request_token' ] as $key ) { self::assertStringNotContainsString( 'name="' . $key . '"', $html ); }
	}

	public function test_read_only_review_preserves_saved_reason_and_only_offers_the_allowed_transition(): void {
		$review = [ 'ticket' => 'opaque-"<ticket>', 'mode' => 'scheduled', 'fields' => self::fields(), 'reason' => 'Original <reason>', 'verbs' => [ 'retire' ] ];
		$html = $this->render( 'render_review', $review );
		self::assertStringContainsString( 'Original &lt;reason&gt;', $html );
		self::assertStringContainsString( 'value="' . esc_attr( $review['ticket'] ) . '"', $html );
		self::assertStringContainsString( ReturnPolicyPage::ACTION_PREFIX . 'retire', $html );
		self::assertStringNotContainsString( ReturnPolicyPage::ACTION_PREFIX . 'publish', $html );
		self::assertStringNotContainsString( '<textarea', $html );
		self::assertStringNotContainsString( 'name="reason"', $html );
	}

	public function test_current_version_cards_have_native_open_selectors_and_no_editable_command_or_history_claim(): void {
		$rows = [ [ 'policy_uuid' => '00000000-0000-4000-8000-000000000001', 'version_uuid' => '00000000-0000-4000-8000-000000000002', 'state' => 'published', 'sequence' => 2, 'fields' => self::fields(), 'reason' => 'Private reason' ] ];
		$html = $this->render( 'render_rows', $rows );
		self::assertStringContainsString( 'Version 2 · published', $html );
		self::assertStringContainsString( 'current draft, scheduled and published versions', $html );
		self::assertStringContainsString( 'name="policy_uuid" value="' . $rows[0]['policy_uuid'] . '"', $html );
		self::assertStringContainsString( 'name="version_uuid" value="' . $rows[0]['version_uuid'] . '"', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( $rows[0]['fields']['full_text'], $html );
	}

	private function render( string $method, mixed ...$arguments ): string {
		$page = ( new \ReflectionClass( ReturnPolicyPage::class ) )->newInstanceWithoutConstructor();
		ob_start();
		try { ( new \ReflectionMethod( $page, $method ) )->invoke( $page, ...$arguments ); return (string) ob_get_contents(); }
		finally { ob_end_clean(); }
	}

	private static function fields(): array {
		return [ 'name' => '  Café "returns" <script>  ', 'customer_title' => 'Literal <img src=x onerror="x">', 'summary' => "  Summary\r\nsecond line\tΩ  ", 'full_text' => "<script>alert(\"fixture\")</script>\nSupplied line 2\r\n\tCafé", 'exceptions' => "Exception <b>A</b>\n" ];
	}
}
