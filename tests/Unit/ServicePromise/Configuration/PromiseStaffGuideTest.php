<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\{PromiseConfigurationReadService, PromiseConfigurationService, PromiseStaffDraft, PromiseStaffIntent};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseSiteBinding, PromiseVersionCommand};
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority;
use CetechDeliveryEngine\Presentation\Admin\{AdminActionHandler, AdminNoticeService, AdminPageAccess, PromiseStaffGuide};
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StaffIntentFixtures.php';
require_once dirname( __DIR__ ) . '/StorageReadinessTest.php';
require_once dirname( __DIR__, 3 ) . '/stubs/woocommerce-product-stub.php';

/** Native guide handler/authority + real immutable command parsing; SQL failures are explicit fixtures. */
final class PromiseStaffGuideTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private mixed $old_database;
	private mixed $old_blog;
	private mixed $old_method;
	private StaffIntentDatabase $database;
	private int $dispatches = 0;
	private array $observed = [];
	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->old_database = $GLOBALS['wpdb'] ?? null; $this->old_blog = $GLOBALS['blog_id'] ?? null; $this->old_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$GLOBALS['wpdb'] = $this->database = new StaffIntentDatabase(); $GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_site_wide_defaults' => true, 'manage_product_delivery_rules' => true ];
		$GLOBALS['cetech_de_test_edit_posts'] = [ 10 => true ]; $GLOBALS['cetech_de_test_wc_products'] = [ 10 => new \WC_Product( [ 'id' => 10, 'type' => 'simple' ] ) ];
		$_SERVER['REQUEST_METHOD'] = 'POST'; AdminPageAccess::bind( null );
	}
	protected function tearDown(): void {
		AdminPageAccess::bind( null ); $this->restore_fixture_globals();
		foreach ( [ 'wpdb' => $this->old_database, 'blog_id' => $this->old_blog ] as $key => $value ) { if ( null === $value ) { unset( $GLOBALS[ $key ] ); } else { $GLOBALS[ $key ] = $value; } }
		if ( null === $this->old_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $this->old_method; }
	}
	private function fields( array $extra = [] ): array {
		return $extra + [ 'cetech_de_action' => PromiseStaffGuide::ACTION, 'cetech_de_nonce' => 'test-nonce-' . PromiseStaffGuide::ACTION, 'guide_step' => 'draft', 'offer_code' => 'local-van', 'unit' => 'elapsed_minutes', 'processing_min' => '0', 'processing_max' => '1', 'delivery_min' => '1', 'delivery_max' => '2', 'estimate_required' => 'no', 'scope' => 'global' ];
	}
	private function journal_name(): string { return 'cetech_de_staff_intent_' . hash( 'sha256', '1:store:7' ); }
	private function guide( bool $read_failure = false, bool $revoke_on_read = false ): PromiseStaffGuide {
		$write_factory = $this->createMock( OperationConnectionFactory::class );
		$write_factory->method( 'open' )->willReturnCallback( function (): never {
			++$this->dispatches;
			$bytes = $this->database->rows[ $this->journal_name() ] ?? null;
			self::assertIsString( $bytes ); $plan = PromiseStaffIntent::decode( $bytes );
			self::assertSame( 'inflight', $plan['state'] ); $this->observed[] = $plan;
			// Reached the real native lifecycle service, with its already-fenced immutable request.
			throw new \RuntimeException( 'Owned SQL fixture unavailable before any native mutation.' );
		} );
		$authority = new NativePromiseConfigurationAuthority( static fn(): bool => true );
		$service = new PromiseConfigurationService( $write_factory, $authority, new PromiseQuotePlacementActivation( $write_factory, static fn(): bool => false ) );
		$read_factory = $this->createMock( OperationConnectionFactory::class );
		$read_factory->method( 'open' )->willReturnCallback( function () use ( $read_failure, $revoke_on_read ): OperationSession {
			if ( $read_failure ) { throw new \RuntimeException( 'Read unavailable, not absence.' ); }
			if ( $revoke_on_read ) { $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; }
			return new \CetechDeliveryEngine\Tests\Unit\ServicePromise\PromiseMetadataSession( new \CetechDeliveryEngine\Tests\Unit\ServicePromise\PromiseMetadataWpdb() );
		} );
		$readiness = $this->createMock( OperationReadiness::class );
		( new \ReflectionProperty( $service, 'reader' ) )->setValue( $service, new PromiseConfigurationReadService( $read_factory, $authority, $readiness ) );
		return new PromiseStaffGuide( $service, new AdminActionHandler( new AdminNoticeService() ) );
	}
	private function property( object $target, string $name ): mixed { return ( new \ReflectionProperty( $target, $name ) )->getValue( $target ); }
	private function invoke( object $target, string $name, mixed ...$args ): mixed { return ( new \ReflectionMethod( $target, $name ) )->invokeArgs( $target, $args ); }

	public function test_unavailable_native_adoption_capture_never_claims_customer_estimates_are_off(): void {
		$factory = $this->createMock( OperationConnectionFactory::class );
		$factory->expects( self::once() )->method( 'open' )->willThrowException( new \RuntimeException( 'Native adoption capture unavailable.' ) );
		$authority = new NativePromiseConfigurationAuthority( static fn(): bool => true );
		$activation = new PromiseQuotePlacementActivation( $factory, static fn(): bool => false );
		$service = new PromiseConfigurationService( $factory, $authority, $activation );
		$guide = new PromiseStaffGuide( $service, new AdminActionHandler( new AdminNoticeService() ) );
		ob_start(); $this->invoke( $guide, 'adoption' ); $html = ob_get_clean();
		self::assertStringContainsString( 'Customer estimates: status could not be confirmed. No change was made.', $html );
		self::assertStringNotContainsString( 'Customer estimates are off.', $html );
		self::assertStringNotContainsString( 'Customer estimates are on.', $html );
		self::assertSame( [], $this->database->rows );
	}

	public function test_get_never_verifies_nonce_or_dispatches_a_native_write(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = $this->fields();
		$this->guide()->handle(); self::assertSame( [], $this->database->rows ); self::assertSame( 0, $this->dispatches );
	}

	public function test_invalid_nonce_uses_native_admin_refusal_before_any_journal_or_write(): void {
		$_POST = $this->fields( [ 'cetech_de_nonce' => 'invalid' ] ); $guide = $this->guide();
		try { $guide->handle(); self::fail( 'Native invalid-nonce redirect expected.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'cetech_de_test_redirect', $error->getMessage() ); }
		self::assertSame( [], $this->database->rows ); self::assertSame( 0, $this->dispatches );
	}

	public function test_real_handler_preview_keeps_no_journal_or_native_write(): void {
		$_POST = $this->fields( [ 'guide_step' => 'preview' ] ); $guide = $this->guide(); $guide->handle();
		self::assertSame( [], $this->database->rows ); self::assertSame( 0, $this->dispatches ); self::assertStringContainsString( 'Preview only', $this->property( $guide, 'message' ) );
	}

	public function test_real_handler_fences_exact_command_before_native_write_and_retains_refusal(): void {
		$_POST = $this->fields(); $guide = $this->guide(); $guide->handle();
		self::assertSame( 1, $this->dispatches ); $plan = PromiseStaffIntent::decode( $this->database->rows[ $this->journal_name() ] );
		self::assertSame( 'rejected', $plan['state'] ); self::assertSame( 0, $plan['cursor'] ); self::assertSame( $plan['steps'], $this->observed[0]['steps'] );
		$step = $plan['steps'][0]; $binding = PromiseSiteBinding::bind( 1, 'store' );
		$identity = new OperationIdentity( 1, NativePromiseConfigurationAuthority::AUTHORITY, 'user:7', $step['operation'], 1, PromiseVersionCommand::target_key( $binding, 'policy', $step['payload']['logical_id'] ), $step['token'] );
		$command = PromiseVersionCommand::from_array( $identity, $binding, $step['payload'] + [ 'author_user_id' => 7 ] );
		self::assertSame( $step['payload']['body_digest'], $command->private_facts()['body_digest'] );
		self::assertSame( [ 'object_revision' => 0, 'version_revision' => 0, 'published_version_id' => 0 ], $step['payload']['preconditions'] );
		self::assertArrayNotHasKey( 'cetech_de_promise_staff_index', $this->database->rows );
		$before = $this->database->rows[ $this->journal_name() ]; $guide->handle();
		self::assertSame( 1, $this->dispatches ); self::assertSame( $before, $this->database->rows[ $this->journal_name() ] );
	}

	public function test_first_calendar_refusal_acknowledges_without_indexing_a_nonexistent_calendar(): void {
		$_POST = $this->fields( [ 'unit' => 'business_days', 'timezone' => 'UTC', 'mon_open' => '09:00', 'mon_close' => '17:00' ] ); $guide = $this->guide(); $guide->handle();
		$plan = PromiseStaffIntent::decode( $this->database->rows[ $this->journal_name() ] ); self::assertCount( 2, $plan['steps'] );
		$calendar = $plan['steps'][0]['payload']; $policy = json_decode( $plan['steps'][1]['payload']['body_json'], true );
		self::assertSame( 'calendar', $calendar['kind'] ); self::assertSame( $calendar['body_digest'], $policy['calendars'][0]['digest'] ); self::assertSame( $calendar['domain_version'], $policy['calendars'][0]['version'] );
		$_POST = $this->fields( [ 'guide_step' => 'acknowledge' ] ); $guide->handle(); self::assertArrayNotHasKey( $this->journal_name(), $this->database->rows ); self::assertArrayNotHasKey( 'cetech_de_promise_staff_index', $this->database->rows );
		$_POST = $this->fields(); $guide->handle(); self::assertSame( 2, $this->dispatches );
	}

	public function test_read_failure_never_supplies_new_create_authority(): void {
		$_POST = $this->fields(); $guide = $this->guide( true ); $guide->handle(); self::assertSame( 0, $this->dispatches ); self::assertSame( [], $this->database->rows );
	}

	public function test_product_only_grant_does_not_create_a_global_calendar(): void {
		$GLOBALS['cetech_de_test_caps']['manage_delivery_settings'] = false; $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false;
		$_POST = $this->fields( [ 'scope' => 'product', 'product_id' => '10', 'unit' => 'business_days', 'timezone' => 'UTC', 'mon_open' => '09:00', 'mon_close' => '17:00' ] );
		$guide = $this->guide(); $guide->handle(); self::assertSame( 0, $this->dispatches ); self::assertSame( [], $this->database->rows );
	}

	public function test_saved_index_does_not_disclose_an_ungranted_rule_label(): void {
		$GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false;
		$this->database->rows['cetech_de_promise_staff_index'] = serialize( [ 'secret-rule' => [ 'policy_id' => 'secret-rule', 'scope_kind' => 'global', 'scope_id' => 0, 'label' => 'Private merchant name', 'applies' => 'Private target' ] ] );
		$guide = $this->guide(); ob_start(); $this->invoke( $guide, 'saved_rules' ); $html = ob_get_clean();
		self::assertStringNotContainsString( 'Private merchant name', $html ); self::assertStringNotContainsString( 'Private target', $html );
	}

	public function test_scope_revoked_during_native_read_hides_the_prepared_index_label(): void {
		$this->database->rows['cetech_de_promise_staff_index'] = serialize( [ 'secret-rule' => [ 'policy_id' => 'secret-rule', 'version' => 1, 'digest' => str_repeat( 'a', 64 ), 'scope_kind' => 'global', 'scope_id' => 0, 'label' => 'Prepared private label', 'applies' => 'Private target' ] ] );
		$guide = $this->guide( false, true ); ob_start(); $this->invoke( $guide, 'saved_rules' ); $html = ob_get_clean();
		self::assertStringNotContainsString( 'Prepared private label', $html ); self::assertStringNotContainsString( 'Private target', $html );
		self::assertFalse( $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] );
	}

	public function test_replacement_without_explicit_staff_intent_cannot_queue_retirement(): void {
		$guide = $this->guide(); ( new \ReflectionProperty( $guide, 'values' ) )->setValue( $guide, [ 'replace_draft' => '' ] );
		$steps = []; $pre = [ 'object_revision' => 2, 'version_revision' => 0, 'published_version_id' => 0 ];
		$args = [ &$steps, 'policy', 'rule', [ 'kind' => 'global', 'target_id' => 0 ], [ 'version' => [ 'state' => 'draft' ] ], &$pre ];
		try { ( new \ReflectionMethod( $guide, 'replacement_step' ) )->invokeArgs( $guide, $args ); self::fail( 'Explicit retirement intent required.' ); }
		catch ( \RuntimeException ) { self::assertSame( [], $steps ); self::assertSame( 2, $pre['object_revision'] ); }
	}

	public function test_explicit_replacement_uses_native_retirement_and_independent_cas(): void {
		$guide = $this->guide(); ( new \ReflectionProperty( $guide, 'values' ) )->setValue( $guide, [ 'replace_draft' => 'yes' ] );
		$opened = [ 'object_revision' => 8, 'published_version_id' => 91, 'next_version' => 3, 'version' => [ 'state' => 'draft', 'domain_version' => 2, 'version_uuid' => RequestContext::create()->request_id, 'body_digest' => str_repeat( 'a', 64 ), 'row_revision' => 1, 'declared_from' => '2026-10-01 00:00:00.000000', 'declared_until' => null ] ];
		$steps = []; $pre = [ 'object_revision' => 8, 'version_revision' => 0, 'published_version_id' => 91 ];
		$args = [ &$steps, 'calendar', 'working-hours', [ 'kind' => 'global', 'target_id' => 0 ], $opened, &$pre ]; ( new \ReflectionMethod( $guide, 'replacement_step' ) )->invokeArgs( $guide, $args );
		self::assertSame( 'promise.version.retire', $steps[0]['operation'] ); self::assertSame( [ 'object_revision' => 8, 'version_revision' => 1, 'published_version_id' => 91 ], $steps[0]['payload']['preconditions'] ); self::assertSame( 9, $pre['object_revision'] );
		self::assertSame( $opened['version']['version_uuid'], $steps[0]['payload']['version_uuid'] ); self::assertNull( $steps[0]['payload']['body_json'] );
	}
}
