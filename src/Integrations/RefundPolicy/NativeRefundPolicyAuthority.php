<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\RefundPolicy;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand};
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Native grants are captured before C03 owns SQL, and checked again after retirement. */
final class NativeRefundPolicyAuthority {

	public const AUTHORITY = 'refund_policy.configuration';
	private const READ = 'refund.catalog.read';
	private const OPERATIONS = [ 'rule.draft.create', 'rule.draft.edit', 'rule.schedule', 'rule.publish', 'rule.retire', self::READ ];
	public function can_access(): bool {
		try {
			return function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0
				&& function_exists( 'get_current_blog_id' ) && get_current_blog_id() > 0 && function_exists( 'current_user_can' )
				&& ! AdminPageAccess::current_user_is_restricted()
				&& current_user_can( 'manage_delivery_settings' ) && current_user_can( Capabilities::SITE_WIDE );
		} catch ( \Throwable ) { return false; }
	}

	/** @return array{site_id:int,user_id:int} */
	public function actor(): array {
		if ( ! $this->can_access() ) { self::deny(); }
		return [ 'site_id' => get_current_blog_id(), 'user_id' => get_current_user_id() ];
	}

	/** Original seven primitives are retained by the server journal, including its private token. */
	public function identity_data( string $operation, string $target, string $token ): array {
		$actor = $this->actor();
		$data = [ 'site_id' => $actor['site_id'], 'authority' => self::AUTHORITY, 'principal' => 'user:' . $actor['user_id'],
			'operation' => $operation, 'operation_version' => 1, 'target_key' => $target, 'idempotency_key' => $token ];
		$this->identity( $data );
		return $data;
	}

	/** Reconstruct only the original current owner's namespace, never rebind another staff member. */
	public function identity( array $tuple ): OperationIdentity {
		$keys = [ 'site_id', 'authority', 'principal', 'operation', 'operation_version', 'target_key', 'idempotency_key' ];
		if ( count( $tuple ) !== count( $keys ) || array_diff( $keys, array_keys( $tuple ) )
			|| ! is_int( $tuple['site_id'] ) || ! is_int( $tuple['operation_version'] ) ) { self::deny(); }
		foreach ( [ 'authority', 'principal', 'operation', 'target_key', 'idempotency_key' ] as $key ) {
			if ( ! is_string( $tuple[ $key ] ) ) { self::deny(); }
		}
		$identity = new OperationIdentity( ...$tuple );
		if ( ! $this->identity_allowed( $identity ) ) { self::deny(); }
		return $identity;
	}

	/** A request-local pure grant. Its callbacks cannot load WordPress, users, cache or SQL. */
	public function capture( OperationIdentity $identity, array $scope, int $author ): array {
		if ( ! $this->authorize( $identity, $scope, $author ) ) { self::deny(); }
		$scope = RefundPolicyFamily::checked_scope( $scope );
		$read = self::READ === $identity->operation;
		$scope_grant = static function ( OperationIdentity $candidate, array $candidate_scope ) use ( $identity, $scope, $read ): bool {
			$candidate_scope = RefundPolicyFamily::checked_scope( $candidate_scope );
			// A catalog scan checks every real policy scope; the separate read grant covers the family.
			return $candidate === $identity && ( $read || $candidate_scope === $scope );
		};
		$family = new RefundPolicyFamily( $scope_grant,
			static fn ( int $site, int $candidate_author, array $candidate_scope ): bool => ! $read && $site === $identity->site_id && $candidate_author === $author && $candidate_scope === $scope,
			static fn ( OperationIdentity $candidate, int $candidate_author, array $candidate_scope ): bool => ! $read && $candidate === $identity && $candidate_author === $author && $candidate_scope === $scope
		);
		return [ 'family' => $family, 'catalog' => static fn ( OperationIdentity $candidate ): bool => $read && $candidate === $identity ];
	}

	/** Native permission check before ownership or after all C03 owners have retired. */
	public function authorize( OperationIdentity $identity, array $scope, int $author ): bool {
		try {
			$scope = RefundPolicyFamily::checked_scope( $scope );
			if ( ! $this->identity_allowed( $identity ) ) { return false; }
			if ( self::READ === $identity->operation ) { return 0 === $author; }
			if ( $author < 1 || $identity->target_key !== RuleLifecycleCommand::make_target_key( RefundPolicyFamily::CODE,
				RuleContent::scope_hash( new RefundPolicyFamily( static fn(): bool => false, static fn(): bool => false, static fn(): bool => false ), $scope ), $scope['policy_uuid'] ) ) { return false; }
			// The capability pair explicitly permits managers to act on an eligible saved author.
			// It never replaces that original author with the current manager's id.
			return $this->author_allowed( $author ) && $this->identity_allowed( $identity );
		} catch ( \Throwable ) { return false; }
	}

	private function identity_allowed( OperationIdentity $identity ): bool {
		return $this->can_access() && $identity->site_id === get_current_blog_id() && self::AUTHORITY === $identity->authority
			&& 'user:' . get_current_user_id() === $identity->principal && 1 === $identity->operation_version
			&& in_array( $identity->operation, self::OPERATIONS, true )
			&& ( self::READ !== $identity->operation || 'refund-policy:catalog' === $identity->target_key );
	}

	private function author_allowed( int $author_id ): bool {
		if ( ! function_exists( 'get_userdata' ) || ! function_exists( 'user_can' ) ) { return false; }
		$author = get_userdata( $author_id );
		if ( ! $author || ! user_can( $author, 'manage_delivery_settings' ) || ! user_can( $author, Capabilities::SITE_WIDE ) ) { return false; }
		if ( user_can( $author, 'manage_options' ) ) { return true; }
		if ( array_intersect( [ 'wcfm_vendor', 'disable_vendor' ], (array) ( $author->roles ?? [] ) ) ) { return false; }
		return ! function_exists( 'wcfm_is_vendor' ) || ! wcfm_is_vendor( $author_id );
	}

	private static function deny(): never { throw new \RuntimeException( 'Refund policy administration is unavailable.' ); }
}
