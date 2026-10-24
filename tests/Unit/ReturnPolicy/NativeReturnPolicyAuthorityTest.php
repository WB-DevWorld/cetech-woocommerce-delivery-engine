<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand};
use CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority as Authority;
use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyCatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/ReturnPolicy/ReturnPolicyAdminNativeFixtures.php';

/** Native doubles only: no SQL owner, WordPress boot or physical acceptance. */
final class NativeReturnPolicyAuthorityTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private Authority $authority;
	private mixed $previous_blog;
	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->previous_blog = $GLOBALS['blog_id'] ?? null;
		$GLOBALS['blog_id'] = 1; $GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, Capabilities::SITE_WIDE => true ];
		$GLOBALS['cetech_de_test_users'] = [ 7 => $this->user( 7 ), 9 => $this->user( 9 ) ];
		AdminPageAccess::bind( null ); $this->authority = new Authority();
	}
	protected function tearDown(): void {
		AdminPageAccess::bind( null ); $this->restore_fixture_globals();
		if ( null === $this->previous_blog ) { unset( $GLOBALS['blog_id'] ); } else { $GLOBALS['blog_id'] = $this->previous_blog; }
	}
	private function user( int $id, array $changes = [], array $roles = [ 'custom_delivery_manager' ] ): object {
		return (object) [ 'ID' => $id, 'roles' => $roles, 'allcaps' => $changes + [ 'manage_delivery_settings' => true, Capabilities::SITE_WIDE => true ] ];
	}
	private function scope( int $id = 101 ): array { return [ 'policy_uuid' => ReturnPolicyCatalogFixture::uuid( $id ) ]; }
	private function tuple( string $operation = 'rule.draft.create', ?array $scope = null ): array {
		$scope ??= $this->scope(); $family = new ReturnPolicyFamily( static fn(): bool => false, static fn(): bool => false, static fn(): bool => false );
		$target = 'return.catalog.read' === $operation ? 'return-policy:catalog' : RuleLifecycleCommand::make_target_key( $family->family(), RuleContent::scope_hash( $family, $scope ), $scope['policy_uuid'] );
		return $this->authority->identity_data( $operation, $target, 'private-original-token-7' );
	}
	private function identity( string $operation = 'rule.draft.create' ): OperationIdentity { return $this->authority->identity( $this->tuple( $operation ) ); }

	public function test_actor_and_original_seven_primitives_are_server_minted(): void {
		self::assertTrue( $this->authority->can_access() ); self::assertSame( [ 'site_id' => 1, 'user_id' => 7 ], $this->authority->actor() );
		$tuple = $this->tuple(); self::assertCount( 7, $tuple ); self::assertSame( 1, $tuple['site_id'] );
		self::assertSame( Authority::AUTHORITY, $tuple['authority'] ); self::assertSame( 'user:7', $tuple['principal'] );
		self::assertSame( 1, $tuple['operation_version'] ); self::assertSame( 'private-original-token-7', $tuple['idempotency_key'] );
		$first = $this->authority->identity( $tuple ); $second = $this->authority->identity( $tuple );
		self::assertNotSame( $first, $second ); self::assertSame( $first->namespace_digest(), $second->namespace_digest() );
	}
	#[DataProvider( 'native_refusals' )]
	public function test_native_access_requires_both_catalog_capabilities( string $case ): void {
		switch ( $case ) {
			case 'settings_only': $GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true ]; break;
			case 'site_only': $GLOBALS['cetech_de_test_caps'] = [ Capabilities::SITE_WIDE => true ]; break;
			case 'view_only': $GLOBALS['cetech_de_test_caps'] = [ Capabilities::VIEW => true ]; break;
			case 'product_only': $GLOBALS['cetech_de_test_caps'] = [ 'manage_product_delivery_rules' => true ]; break;
			case 'woocommerce_only': $GLOBALS['cetech_de_test_caps'] = [ 'manage_woocommerce' => true ]; break;
			case 'options_only': $GLOBALS['cetech_de_test_caps'] = [ 'manage_options' => true ]; break;
			case 'guest': $GLOBALS['cetech_de_test_user_id'] = 0; break;
			case 'frontend': $GLOBALS['cetech_de_test_is_admin'] = false; break;
			case 'invalid_site': $GLOBALS['blog_id'] = 0; break;
			case 'restricted_vendor': AdminPageAccess::bind( new WcfmVendorIsolation( static fn(): bool => true, static fn(): bool => true ) ); break;
		}
		self::assertFalse( $this->authority->can_access() ); $this->expectException( \RuntimeException::class ); $this->authority->actor();
	}
	public static function native_refusals(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'settings_only', 'site_only', 'view_only', 'product_only', 'woocommerce_only', 'options_only', 'guest', 'frontend', 'invalid_site', 'restricted_vendor' ] );
	}
	public function test_administrator_vendor_exception_preserves_required_catalog_caps(): void {
		AdminPageAccess::bind( new WcfmVendorIsolation( static fn(): bool => true, static fn(): bool => true ) ); $GLOBALS['cetech_de_test_caps']['manage_options'] = true;
		self::assertTrue( $this->authority->can_access() ); unset( $GLOBALS['cetech_de_test_caps'][ Capabilities::SITE_WIDE ] ); self::assertFalse( $this->authority->can_access() );
	}
	#[DataProvider( 'forged_tuples' )]
	public function test_original_journal_cannot_rebind_identity_or_open_activation( string $case ): void {
		$tuple = $this->tuple();
		switch ( $case ) {
			case 'other_site': $tuple['site_id'] = 2; break;
			case 'other_actor': $tuple['principal'] = 'user:9'; break;
			case 'other_authority': $tuple['authority'] = 'wordpress'; break;
			case 'future_version': $tuple['operation_version'] = 2; break;
			case 'activate': $tuple['operation'] = 'rule.activate'; break;
			case 'unknown_operation': $tuple['operation'] = 'return.delete'; break;
			case 'read_target': $tuple['operation'] = 'return.catalog.read'; break;
			case 'site_string': $tuple['site_id'] = '1'; break;
			case 'version_string': $tuple['operation_version'] = '1'; break;
			case 'token_array': $tuple['idempotency_key'] = []; break;
			case 'token_empty': $tuple['idempotency_key'] = ''; break;
			case 'extra': $tuple['author_user_id'] = 9; break;
			case 'missing': unset( $tuple['idempotency_key'] ); break;
		}
		$this->expectException( \Throwable::class ); $this->authority->identity( $tuple );
	}
	public static function forged_tuples(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'other_site', 'other_actor', 'other_authority', 'future_version', 'activate', 'unknown_operation', 'read_target', 'site_string', 'version_string', 'token_array', 'token_empty', 'extra', 'missing' ] );
	}
	public function test_captured_write_grant_is_pure_request_local_and_exactly_bound(): void {
		$identity = $this->identity(); $scope = $this->scope(); $grant = $this->authority->capture( $identity, $scope, 9 );
		self::assertFalse( $grant['family']->authorize( $identity, $scope ) ); $family = $grant['family']->for_author( 9 );
		self::assertTrue( $family->authorize( $identity, $scope ) ); self::assertFalse( ( $grant['catalog'] )( $identity ) );
		self::assertFalse( $family->authorize( $identity, $this->scope( 202 ) ) );
		self::assertFalse( $grant['family']->for_author( 7 )->authorize( $identity, $scope ) );
		self::assertFalse( $family->authorize( $this->authority->identity( $this->tuple() ), $scope ) );
		$GLOBALS['cetech_de_test_caps'] = []; $GLOBALS['cetech_de_test_users'] = [];
		$calls = $GLOBALS['cetech_de_test_return_native_calls'];
		// Frozen C03 callbacks never reenter native capability/cache/user APIs under SQL ownership.
		self::assertTrue( $family->authorize( $identity, $scope ) );
		self::assertSame( $calls, $GLOBALS['cetech_de_test_return_native_calls'] );
		self::assertFalse( $this->authority->authorize( $identity, $scope, 9 ) );
	}
	public function test_catalog_read_grant_covers_two_real_scopes_under_one_explicit_read_identity(): void {
		$identity = $this->identity( 'return.catalog.read' ); $scope = $this->scope(); $grant = $this->authority->capture( $identity, $scope, 0 );
		self::assertTrue( ( $grant['catalog'] )( $identity ) ); self::assertTrue( $grant['family']->authorize( $identity, $scope ) );
		self::assertTrue( $grant['family']->authorize( $identity, $this->scope( 202 ) ) );
		self::assertFalse( ( $grant['catalog'] )( $this->identity( 'return.catalog.read' ) ) );
		self::assertFalse( $grant['family']->authorize_author( 1, 9, $scope ) ); self::assertFalse( $this->authority->authorize( $identity, $scope, 9 ) );
		$GLOBALS['cetech_de_test_caps'] = []; $calls = $GLOBALS['cetech_de_test_return_native_calls'] ?? 0;
		self::assertTrue( $grant['family']->authorize( $identity, $this->scope( 303 ) ) );
		self::assertSame( $calls, $GLOBALS['cetech_de_test_return_native_calls'] ?? 0 );
		self::assertFalse( $this->authority->authorize( $identity, $scope, 0 ) );
	}
	#[DataProvider( 'author_refusals' )]
	public function test_saved_author_eligibility_does_not_substitute_the_current_manager( string $case ): void {
		$identity = $this->identity( 'rule.publish' ); $scope = $this->scope(); self::assertTrue( $this->authority->authorize( $identity, $scope, 9 ) );
		switch ( $case ) {
			case 'missing': unset( $GLOBALS['cetech_de_test_users'][9] ); break;
			case 'no_settings': $GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [ 'manage_delivery_settings' => false ] ); break;
			case 'no_site_cap': $GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [ Capabilities::SITE_WIDE => false ] ); break;
			case 'vendor_role': $GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [], [ 'wcfm_vendor' ] ); break;
			case 'disabled_vendor': $GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [], [ 'disable_vendor' ] ); break;
			case 'native_failure': $GLOBALS['cetech_de_test_return_user_can_throw'] = true; break;
		}
		self::assertFalse( $this->authority->authorize( $identity, $scope, 9 ) ); $this->expectException( \RuntimeException::class ); $this->authority->capture( $identity, $scope, 9 );
	}
	public static function author_refusals(): array { return array_map( static fn ( string $case ): array => [ $case ], [ 'missing', 'no_settings', 'no_site_cap', 'vendor_role', 'disabled_vendor', 'native_failure' ] ); }
	public function test_explicit_eligible_customer_or_administrator_is_not_rejected_by_a_blanket_role_rule(): void {
		$identity = $this->identity( 'rule.retire' ); $scope = $this->scope(); $GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [], [ 'customer' ] );
		self::assertTrue( $this->authority->authorize( $identity, $scope, 9 ) );
		$GLOBALS['cetech_de_test_users'][9] = $this->user( 9, [ 'manage_options' => true ], [ 'wcfm_vendor' ] ); self::assertTrue( $this->authority->authorize( $identity, $scope, 9 ) );
	}
	public function test_wrong_policy_target_zero_author_and_malformed_scope_refuse_before_capture(): void {
		$identity = $this->identity(); self::assertFalse( $this->authority->authorize( $identity, $this->scope( 202 ), 9 ) );
		self::assertFalse( $this->authority->authorize( $identity, $this->scope(), 0 ) ); self::assertFalse( $this->authority->authorize( $identity, [ 'product_id' => 1 ], 9 ) );
		$this->expectException( \RuntimeException::class ); $this->authority->capture( $identity, $this->scope( 202 ), 9 );
	}
	#[DataProvider( 'native_changes_during_author' )]
	public function test_author_lookup_callbacks_cannot_change_native_context_before_the_pure_grant( string $case ): void {
		$identity = $this->identity();
		$GLOBALS['cetech_de_test_return_native_observer'] = static function () use ( $case ): void {
			match ( $case ) {
				'actor' => $GLOBALS['cetech_de_test_user_id'] = 9,
				'site' => $GLOBALS['blog_id'] = 2,
				'capability' => $GLOBALS['cetech_de_test_caps'] = [],
			};
		};
		self::assertFalse( $this->authority->authorize( $identity, $this->scope(), 9 ) );
	}
	public static function native_changes_during_author(): array { return [ [ 'actor' ], [ 'site' ], [ 'capability' ] ]; }
}
