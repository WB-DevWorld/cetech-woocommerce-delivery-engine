<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseConfigurationService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority as A;
use CetechDeliveryEngine\Presentation\Admin\{AdminPageAccess, PromiseConfigurationPage};
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture as F;
use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/stubs/woocommerce-product-stub.php';

final class NativePromiseConfigurationTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private mixed $previous_blog;
	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->previous_blog = $GLOBALS['blog_id'] ?? null; $GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_site_wide_defaults' => true, 'manage_product_delivery_rules' => true ];
		$GLOBALS['cetech_de_test_edit_posts'] = [ 10 => true, 11 => true ];
		$GLOBALS['cetech_de_test_wc_products'] = [ 10 => new \WC_Product( [ 'id' => 10, 'type' => 'variable' ] ), 11 => new \WC_Product( [ 'id' => 11, 'type' => 'variation', 'parent_id' => 10 ] ) ];
		AdminPageAccess::bind( null );
	}
	protected function tearDown(): void { AdminPageAccess::bind( null ); $this->restore_fixture_globals(); if ( null === $this->previous_blog ) { unset( $GLOBALS['blog_id'] ); } else { $GLOBALS['blog_id'] = $this->previous_blog; } }
	private function identity( string $operation = 'promise.version.create' ): OperationIdentity { return new OperationIdentity( 1, A::AUTHORITY, 'user:7', $operation, 1, 'promise-object:site-1:policy:policy-1', '5e71ce00-0000-4000-8000-000000000001' ); }
	private function allow( array $scope, ?A $authority = null, ?OperationIdentity $identity = null ): bool { return ( $authority ?? new A( static fn(): bool => true ) )->authorize( $identity ?? $this->identity(), PromiseSiteBinding::bind( 1, 'site-1' ), $scope, 7 ); }
	public function test_global_product_and_actual_parent_variation_grants_are_distinct(): void {
		self::assertTrue( $this->allow( [ 'kind' => 'global', 'target_id' => 0 ] ) ); self::assertTrue( $this->allow( [ 'kind' => 'product', 'target_id' => 10 ] ) ); self::assertTrue( $this->allow( [ 'kind' => 'variation', 'target_id' => 11 ] ) );
		$GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; self::assertFalse( $this->allow( [ 'kind' => 'global', 'target_id' => 0 ] ) ); self::assertTrue( $this->allow( [ 'kind' => 'product', 'target_id' => 10 ] ) );
		$GLOBALS['cetech_de_test_edit_posts'][10] = false; self::assertFalse( $this->allow( [ 'kind' => 'variation', 'target_id' => 11 ] ) );
	}
	#[DataProvider( 'bad_scope' )]
	public function test_forged_native_scope_never_becomes_a_grant( array $scope ): void { self::assertFalse( $this->allow( $scope ) ); }
	public static function bad_scope(): array { return [ [ [ 'kind' => 'global', 'target_id' => 10 ] ], [ [ 'kind' => 'product', 'target_id' => 11 ] ], [ [ 'kind' => 'variation', 'target_id' => 10 ] ], [ [ 'kind' => 'variation', 'target_id' => 999 ] ], [ [ 'kind' => 'product', 'target_id' => '10' ] ] ]; }
	public function test_unknown_pause_and_unavailable_controls_refuse_all_new_mutations(): void {
		self::assertFalse( $this->allow( [ 'kind' => 'global', 'target_id' => 0 ], new A() ) ); self::assertFalse( $this->allow( [ 'kind' => 'global', 'target_id' => 0 ], new A( static fn(): bool => false ) ) );
		self::assertTrue( $this->allow( [ 'kind' => 'global', 'target_id' => 0 ], new A(), $this->identity( 'promise.preview' ) ) );
	}
	public function test_actor_binding_and_wcfm_restriction_are_current_native_facts(): void {
		$authority = new A( static fn(): bool => true ); $scope = [ 'kind' => 'global', 'target_id' => 0 ];
		$forged = new OperationIdentity( 1, A::AUTHORITY, 'user:8', 'promise.version.publish', 1, 'promise-object:site-1:policy:policy-1', 'original' ); self::assertFalse( $this->allow( $scope, $authority, $forged ) );
		self::assertFalse( $authority->authorize( $this->identity(), PromiseSiteBinding::bind( 2, 'site-1' ), $scope, 7 ) ); self::assertFalse( $authority->authorize( $this->identity(), PromiseSiteBinding::bind( 1, 'site-1' ), $scope, 8 ) );
		AdminPageAccess::bind( new WcfmVendorIsolation( static fn(): bool => true, static fn(): bool => true ) ); self::assertFalse( $this->allow( $scope, $authority ) );
	}
	public function test_hypothetical_preview_uses_real_runtime_same_calculator_and_no_sql_or_admission(): void {
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$service = new PromiseConfigurationService( $factory, new A(), new PromiseQuotePlacementActivation( $factory, static fn(): bool => false ) );
		$result = $service->preview( $service->binding( 'site-1' ), F::policy()->to_private_json(), [] );
		self::assertTrue( $result['hypothetical'] ); self::assertFalse( $result['admission'] ); self::assertSame( 'absolute_window', $result['views'][0]['state'] );
		self::assertSame( 'Standard delivery', $result['views'][0]['service_label'] ); self::assertCount( 7, $result['views'][0] );
		$json = json_encode( $result, JSON_THROW_ON_ERROR ); foreach ( [ 'merchant-processing', 'merchant-origin', 'input_digest', 'source_receipts', 'runtime_id', 'principal_hash', 'policy_id', 'accepted_receipt', 'calendar_refs', 'graph' ] as $private ) { self::assertStringNotContainsString( $private, $json ); }
	}
	public function test_hypothetical_capacity_is_unknown_and_not_a_reservation(): void {
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$service = new PromiseConfigurationService( $factory, new A(), new PromiseQuotePlacementActivation( $factory, static fn(): bool => false ) );
		$result = $service->preview( $service->binding( 'site-1' ), F::policy( [ 'capacity_mode' => 'required', 'capacity_source' => F::source( 'capacity' ) ] )->to_private_json(), [] );
		self::assertSame( 'ineligible', $result['views'][0]['state'] ); self::assertSame( [ 'capacity_unknown' ], $result['views'][0]['reason_codes'] ); self::assertFalse( $result['admission'] );
	}
	public function test_preview_wrong_site_or_current_global_permission_discloses_no_result(): void {
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' ); $service = new PromiseConfigurationService( $factory, new A(), new PromiseQuotePlacementActivation( $factory, static fn(): bool => false ) );
		$GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; $this->expectException( OperationRefusal::class ); $service->preview( $service->binding( 'site-1' ), F::policy()->to_private_json(), [] );
	}
	public function test_prepared_private_projection_requires_present_exact_scope_permission_without_sql(): void {
		$factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' ); $service = new PromiseConfigurationService( $factory, new A(), new PromiseQuotePlacementActivation( $factory, static fn(): bool => false ) ); $binding = $service->binding( 'site-1' );
		self::assertTrue( $service->can_disclose( $binding, [ 'kind' => 'variation', 'target_id' => 11 ] ) ); $GLOBALS['cetech_de_test_edit_posts'][10] = false; self::assertFalse( $service->can_disclose( $binding, [ 'kind' => 'variation', 'target_id' => 11 ] ) );
		self::assertTrue( $service->can_disclose( $binding, [ 'kind' => 'global', 'target_id' => 0 ] ) ); $GLOBALS['cetech_de_test_caps']['manage_site_wide_defaults'] = false; self::assertFalse( $service->can_disclose( $binding, [ 'kind' => 'global', 'target_id' => 0 ] ) );
	}
	#[DataProvider( 'bad_forms' )]
	public function test_scalar_transport_refuses_client_author_identity_or_oversize( array $bad ): void { $this->expectException( \InvalidArgumentException::class ); PromiseConfigurationPage::parse_form( [ 'site_key' => 'site-1' ] + $bad ); }
	public static function bad_forms(): array { return [ [ [ 'author_user_id' => '8' ] ], [ [ 'site_id' => '2' ] ], [ [ 'principal' => 'user:8' ] ], [ [ 'scope_id' => [ '10' ] ] ], [ [ 'reason' => str_repeat( 'x', 513 ) ] ], [ [ 'body_json' => str_repeat( 'x', 65537 ) ] ] ]; }
	public function test_transport_preserves_original_uuid_reason_bytes_and_zero_precondition(): void {
		$input = [ 'site_key' => 'site-1', 'request_token' => '5e71ce00-0000-4000-8000-000000000001', 'object_revision' => '0', 'reason' => 'Original reason' ]; $result = PromiseConfigurationPage::parse_form( $input );
		foreach ( $input as $key => $value ) { self::assertSame( $value, $result[$key] ); }
	}
}
