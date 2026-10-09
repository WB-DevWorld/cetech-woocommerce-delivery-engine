<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\ServicePromise\Presentation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePersistenceAuthorizer, PromiseSiteBinding};

/** Public product read grant, restricted to this native product, service and request binding. */
final readonly class NativePromisePdpAuthorizer implements PromisePersistenceAuthorizer {
	private \Closure $native_current;
	public function __construct( private PromiseSiteBinding $binding, private int $product_id, private ?int $variation_id, private array $service, private string $principal, callable $native_current ) { $this->native_current = \Closure::fromCallable( $native_current ); }
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		try {
			if ( 0 !== $author_user_id || 'service_promise.pdp.public.v1' !== $identity->authority || 'promise.assignment.read' !== $identity->operation || 1 !== $identity->operation_version || $identity->site_id !== $this->binding->site_id() || $binding->digest() !== $this->binding->digest() || $identity->principal !== $this->principal || true !== ( $this->native_current )() || count( $scope ) !== 2 || ! is_int( $scope['target_id'] ?? null ) ) { return false; }
			$allowed = match ( $scope['kind'] ?? null ) { 'global' => 0 === $scope['target_id'], 'product' => $this->product_id === $scope['target_id'], 'variation' => null !== $this->variation_id && $this->variation_id === $scope['target_id'], default => false };
			return $allowed && $identity->target_key === PromiseAssignmentCommand::target_key( $binding, [ 'scope_kind' => $scope['kind'], 'scope_id' => $scope['target_id'] ] + $this->service );
		} catch ( \Throwable ) { return false; }
	}
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; }
}
