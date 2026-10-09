<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseNativeCaptureService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseHandoffSources;
use CetechDeliveryEngine\Integrations\ServicePromise\{NativePromiseRuntimeCapture, PromiseNativeServiceRegistry};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\TestCase;

final class PromiseNativeCaptureTest extends TestCase {
	private function entry( int $native = 30 ): array { return [ 'native_service_id' => $native, 'service_kind' => 'built_in', 'service_code' => 'standard', 'origin_endpoint' => 'dispatch-origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'customer-door', 'destination_kind' => 'doorstep' ]; }
	public function test_actual_runtime_bytes_are_stable_and_do_not_use_default_timezone_or_arbitrary_version_label(): void {
		$collector = new NativePromiseRuntimeCapture(); $before = $collector->capture(); $old = date_default_timezone_get();
		try { date_default_timezone_set( 'Pacific/Auckland' ); self::assertSame( $before, $collector->capture() ); } finally { date_default_timezone_set( $old ); }
		self::assertSame( timezone_version_get(), $before['timezone_data_version'] ); self::assertMatchesRegularExpression( '/\Aphp-[0-9]+-[a-f0-9]{24}\z/', $before['runtime_id'] ); self::assertNotSame( hash( 'sha256', $before['timezone_data_version'] ), $before['digest'] );
	}
	public function test_registry_is_closed_sorted_and_has_no_label_or_default_service_authority(): void {
		$registry = new PromiseNativeServiceRegistry( [ $this->entry( 31 ), $this->entry() ] ); self::assertSame( [ 30, 31 ], array_column( $registry->private_facts()['entries'], 'native_service_id' ) ); self::assertSame( $registry->private_facts(), PromiseNativeServiceRegistry::from_json( $registry->to_private_json() )->private_facts() );
		$demand = $registry->create_demands( Q::context() )[0]; self::assertSame( Q::digest( 'component' ), $demand->component_key() ); self::assertSame( Q::digest( 'endpoint' ), $demand->destination( Q::context() )['identity_digest'] );
		$this->expectException( \InvalidArgumentException::class ); new PromiseNativeServiceRegistry( [ $this->entry() + [ 'customer_text' => 'Same day guaranteed' ] ] );
	}
	public function test_unregistered_native_service_refuses_and_does_not_guess_from_display_labels(): void {
		$this->expectException( \RuntimeException::class ); ( new PromiseNativeServiceRegistry() )->create_demands( Q::context() );
	}
	public function test_duplicate_native_service_identity_refuses(): void { $this->expectException( \InvalidArgumentException::class ); new PromiseNativeServiceRegistry( [ $this->entry(), $this->entry() ] ); }
	public function test_effective_keys_derive_native_product_and_variation_without_caller_scope(): void {
		$base = Q::context(); $facts = $base->private_facts(); $facts['lines'][0]['variation_id'] = 11; $facts['lines'][0]['parent_id'] = 10; $base = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext::from_array( $facts );
		$keys = WpdbPromiseHandoffSources::keys( $base, Q::digest( 'component' ), ( new PromiseNativeServiceRegistry( [ $this->entry() ] ) )->create_demands( $base )[0]->service_endpoint(), PromiseSiteBinding::bind( 1, 'opaque-site' ) );
		self::assertSame( [ 'global', 'product', 'variation' ], array_column( $keys, 'scope_kind' ) ); self::assertSame( [ 0, 10, 11 ], array_column( $keys, 'scope_id' ) ); self::assertSame( [ 'opaque-site', 'opaque-site', 'opaque-site' ], array_column( $keys, 'site_key' ) );
	}
	public function test_revoked_native_grant_refuses_before_opening_any_source_owner(): void {
		$factory = new class implements OperationConnectionFactory { public int $opens = 0; public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'Unexpected source owner.' ); } };
		$authority = new class implements PromisePersistenceAuthorizer { public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool { return false; } public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; } };
		$service = new PromiseNativeCaptureService( PromiseSiteBinding::bind( 1, 'opaque-site' ), $factory, $authority );
		try { $service->capture( Q::context(), Q::owner(), RuleTime::parse( '2026-10-09 10:00:00.000000' ), ( new PromiseNativeServiceRegistry( [ $this->entry() ] ) )->create_demands( Q::context() ) ); self::fail( 'Revoked source capture escaped.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $factory->opens ); }
	}
}
