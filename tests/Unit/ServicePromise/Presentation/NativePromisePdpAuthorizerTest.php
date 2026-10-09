<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromiseSiteBinding};
use CetechDeliveryEngine\Integrations\ServicePromise\Presentation\NativePromisePdpAuthorizer;
use PHPUnit\Framework\TestCase;

final class NativePromisePdpAuthorizerTest extends TestCase {
	private function service(): array { return [ 'service_kind' => 'built_in', 'service_code' => 'standard', 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep' ]; }
	private function identity( PromiseSiteBinding $binding, array $scope, string $principal, ?array $service = null, string $operation = 'promise.assignment.read' ): OperationIdentity { return new OperationIdentity( $binding->site_id(), 'service_promise.pdp.public.v1', $principal, $operation, 1, PromiseAssignmentCommand::target_key( $binding, [ 'scope_kind' => $scope['kind'], 'scope_id' => $scope['target_id'] ] + ( $service ?? $this->service() ) ), 'pdp-preview' ); }
	public function test_exact_bound_native_product_variation_and_global_reads_are_allowed(): void {
		$binding = PromiseSiteBinding::bind( 1, 'site-1' ); $principal = hash( 'sha256', 'native-session' ); $authority = new NativePromisePdpAuthorizer( $binding, 10, 11, $this->service(), $principal, static fn(): bool => true );
		foreach ( [ [ 'kind' => 'global', 'target_id' => 0 ], [ 'kind' => 'product', 'target_id' => 10 ], [ 'kind' => 'variation', 'target_id' => 11 ] ] as $scope ) { self::assertTrue( $authority->authorize( $this->identity( $binding, $scope, $principal ), $binding, $scope, 0 ) ); }
	}
	public function test_foreign_product_service_site_session_and_mutation_authority_are_denied(): void {
		$binding = PromiseSiteBinding::bind( 1, 'site-1' ); $principal = hash( 'sha256', 'native-session' ); $authority = new NativePromisePdpAuthorizer( $binding, 10, null, $this->service(), $principal, static fn(): bool => true ); $scope = [ 'kind' => 'product', 'target_id' => 10 ];
		$foreign = [ 'kind' => 'product', 'target_id' => 99 ]; self::assertFalse( $authority->authorize( $this->identity( $binding, $foreign, $principal ), $binding, $foreign, 0 ) );
		self::assertFalse( $authority->authorize( $this->identity( $binding, $scope, hash( 'sha256', 'foreign-session' ) ), $binding, $scope, 0 ) );
		self::assertFalse( $authority->authorize( $this->identity( $binding, $scope, $principal, array_replace( $this->service(), [ 'service_code' => 'express' ] ) ), $binding, $scope, 0 ) );
		self::assertFalse( $authority->authorize( $this->identity( $binding, $scope, $principal ), PromiseSiteBinding::bind( 2, 'site-1' ), $scope, 0 ) );
		self::assertFalse( $authority->authorize( $this->identity( $binding, $scope, $principal, operation: 'promise.versions.read' ), $binding, $scope, 0 ) );
		self::assertFalse( $authority->authorize( $this->identity( $binding, $scope, $principal ), $binding, $scope, 5 ) ); self::assertFalse( $authority->authorize_author( $binding, 5, $scope ) );
	}
	public function test_native_visibility_or_session_change_revokes_same_request_read(): void {
		$binding = PromiseSiteBinding::bind( 1, 'site-1' ); $principal = hash( 'sha256', 'native-session' ); $native = true; $authority = new NativePromisePdpAuthorizer( $binding, 10, null, $this->service(), $principal, static function() use ( &$native ): bool { return $native; } ); $scope = [ 'kind' => 'product', 'target_id' => 10 ]; $identity = $this->identity( $binding, $scope, $principal );
		self::assertTrue( $authority->authorize( $identity, $binding, $scope, 0 ) ); $native = false; self::assertFalse( $authority->authorize( $identity, $binding, $scope, 0 ) );
	}
}
