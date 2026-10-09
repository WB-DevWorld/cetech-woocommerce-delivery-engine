<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise\Configuration;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseVersionCommand};
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Native grants are captured by P02 before SQL ownership and rechecked after retirement. */
final class NativePromiseConfigurationAuthority implements PromisePersistenceAuthorizer {

	public const AUTHORITY = 'service_promise.configuration';
	private \Closure $mutation_allowed;
	public function __construct( ?callable $mutation_allowed = null ) {
		// Root supplies the current C07 reader; an absent or failed control refuses writes.
		$this->mutation_allowed = null === $mutation_allowed ? static fn(): bool => false : \Closure::fromCallable( $mutation_allowed );
	}

	public function can_access(): bool {
		return $this->native_context() && ( current_user_can( 'manage_delivery_settings' ) || current_user_can( 'manage_product_delivery_rules' ) );
	}

	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		try {
			if ( ! $this->native_context() || $identity->site_id !== $this->site() || $binding->site_id() !== $this->site()
				|| self::AUTHORITY !== $identity->authority || 'user:' . get_current_user_id() !== $identity->principal
				|| 1 !== $identity->operation_version || ! in_array( $identity->operation, [ ...PromiseVersionCommand::OPERATIONS, 'promise.assignment.set', 'promise.configuration.read', 'promise.preview' ], true )
				|| ( 0 !== $author_user_id && $author_user_id !== get_current_user_id() ) ) { return false; }
			if ( ! in_array( $identity->operation, [ 'promise.configuration.read', 'promise.preview' ], true ) && true !== ( $this->mutation_allowed )() ) { return false; }
			return $this->scope_allowed( $scope, static fn( string $cap, mixed ...$args ): bool => current_user_can( $cap, ...$args ) );
		} catch ( \Throwable ) { return false; }
	}

	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool {
		try {
			if ( ! $this->native_context() || $binding->site_id() !== $this->site() || $author_user_id < 1 || ! function_exists( 'user_can' ) ) { return false; }
			// A scheduled author's present native grant is checked, not the scheduler's grant.
			$author = get_userdata( $author_user_id );
			if ( ! $author || ( ! user_can( $author, 'manage_options' ) && array_intersect( [ 'wcfm_vendor', 'disable_vendor' ], (array) $author->roles ) )
				|| ( function_exists( 'wcfm_is_vendor' ) && ! user_can( $author, 'manage_options' ) && wcfm_is_vendor( $author_user_id ) ) ) { return false; }
			return $this->scope_allowed( $scope, static fn( string $cap, mixed ...$args ): bool => user_can( $author, $cap, ...$args ) );
		} catch ( \Throwable ) { return false; }
	}

	public function identity( PromiseSiteBinding $binding, string $operation, string $target, string $request_token ): OperationIdentity {
		if ( ! $this->can_access() || $binding->site_id() !== $this->site() ) { throw new \RuntimeException( 'Promise configuration is not authorized.' ); }
		return new OperationIdentity( $this->site(), self::AUTHORITY, 'user:' . get_current_user_id(), $operation, 1, $target, $request_token );
	}

	private function scope_allowed( array $scope, callable $can ): bool {
		$scope = PromiseVersionCommand::scope( $scope );
		if ( 'global' === $scope['kind'] ) { return $can( 'manage_delivery_settings' ) && $can( Capabilities::SITE_WIDE ); }
		if ( ! $can( 'manage_product_delivery_rules' ) || ! $can( 'edit_post', $scope['target_id'] ) || ! function_exists( 'wc_get_product' ) ) { return false; }
		$product = wc_get_product( $scope['target_id'] );
		if ( ! $product instanceof \WC_Product ) { return false; }
		if ( 'product' === $scope['kind'] ) { return ! $product->is_type( 'variation' ); }
		if ( ! $product->is_type( 'variation' ) || $product->get_parent_id() < 1 ) { return false; }
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent instanceof \WC_Product && ! $parent->is_type( 'variation' ) && $can( 'edit_post', $product->get_parent_id() );
	}

	private function native_context(): bool {
		return function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0
			&& function_exists( 'current_user_can' ) && ! AdminPageAccess::current_user_is_restricted();
	}
	private function site(): int { return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1; }
}
