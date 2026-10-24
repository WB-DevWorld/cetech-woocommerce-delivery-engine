<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\PolicyAssignment;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Native facts are captured outside SQL; retained owner callbacks are pure grants. */
final class NativePolicyAssignmentAuthority {

	public const AUTHORITY = 'policy_assignment.configuration';
	private readonly ?\Closure $product_loader;
	private readonly ?\Closure $binding_loader;

	/** Optional callbacks are trusted host/test ports, never form input. */
	public function __construct(
		private readonly ?DeliveryOfferRepositoryInterface $offers = null,
		?callable $product_loader = null,
		?callable $binding_loader = null
	) {
		$this->product_loader = null === $product_loader ? null : \Closure::fromCallable( $product_loader );
		$this->binding_loader = null === $binding_loader ? null : \Closure::fromCallable( $binding_loader );
	}

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

	/** Default native routing is observed before any owned SQL session exists. */
	public function binding(): array {
		$actor = $this->actor();
		if ( null !== $this->binding_loader ) { $binding = ( $this->binding_loader )(); }
		else {
			$db = $GLOBALS['wpdb'] ?? null;
			if ( ! is_object( $db ) || \wpdb::class !== get_class( $db ) || ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) )
				|| ! is_string( $db->prefix ?? null ) || ( $db->options ?? null ) !== $db->prefix . 'options' ) { self::deny(); }
			$binding = [ 'site_id' => $actor['site_id'], 'prefix' => $db->prefix ];
		}
		if ( ! is_array( $binding ) || count( $binding ) !== 2 || array_diff( [ 'site_id', 'prefix' ], array_keys( $binding ) )
			|| $binding['site_id'] !== $actor['site_id'] || ! is_string( $binding['prefix'] ) || strlen( $binding['prefix'] . 'options' ) > 64
			|| 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $binding['prefix'] ) || $this->actor() !== $actor ) { self::deny(); }
		return $binding;
	}

	/** Product ownership comes from WooCommerce, including the variation's real parent. */
	public function target( string $type, int $id ): array {
		$actor = $this->actor(); $parent = 0;
		if ( 'global' === $type ) { if ( 0 !== $id ) { self::deny(); } }
		elseif ( in_array( $type, [ 'product', 'variation' ], true ) && $id > 0 ) {
			$target = $this->product( $id );
			if ( ! current_user_can( 'edit_post', $id ) || ( 'variation' === $type ) !== $target['is_variation'] ) { self::deny(); }
			if ( 'variation' === $type ) {
				$parent = $target['parent_id']; $product = $this->product( $parent );
				if ( $product['is_variation'] || ! current_user_can( 'edit_post', $parent ) ) { self::deny(); }
			}
		} else { self::deny(); }
		$coordinate = PolicyAssignmentState::make_coordinate( $actor['site_id'], $type, $id, $parent );
		if ( $this->actor() !== $actor ) { self::deny(); }
		return $coordinate;
	}

	/** Zero identifies the editable default slot, never an effective delivery choice. */
	public function offer( int $id ): int {
		$actor = $this->actor();
		if ( 0 === $id ) { return 0; }
		if ( $id < 1 || null === $this->offers ) { self::deny(); }
		$row = $this->offers->findById( $id );
		if ( ! is_array( $row ) || ! self::same_positive( $row['id'] ?? null, $id ) || ! in_array( $row['status'] ?? null, [ 'active', 'inactive' ], true )
			|| $this->actor() !== $actor ) { self::deny(); }
		return $id;
	}

	/** Bounded native labels; these selectors grant no scope or policy access. */
	public function offer_choices(): array {
		$actor = $this->actor(); if ( null === $this->offers ) { self::deny(); }
		$rows = $this->offers->list(); if ( count( $rows ) > 1000 ) { self::deny(); }
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! self::positive( $row['id'] ) || ! in_array( $row['status'] ?? null, [ 'active', 'inactive' ], true ) ) { self::deny(); }
			$id = (int) $row['id']; if ( isset( $out[$id] ) ) { self::deny(); }
			$label = $row['public_label'] ?? $row['internal_name'] ?? '';
			if ( ! is_string( $label ) || strlen( $label ) > 512 || 1 !== preg_match( '//u', $label ) ) { self::deny(); }
			$out[$id] = [ 'id' => $id, 'label' => '' === $label ? 'Delivery choice #' . $id : $label ];
		}
		ksort( $out, SORT_NUMERIC ); if ( $this->actor() !== $actor ) { self::deny(); }
		return array_values( $out );
	}

	public function target_label( array $coordinate ): string {
		if ( $this->target( $coordinate['scope_type'] ?? '', $coordinate['scope_id'] ?? -1 ) !== $coordinate ) { self::deny(); }
		return 'global' === $coordinate['scope_type'] ? 'Global defaults' : $this->product( $coordinate['scope_id'] )['label'];
	}

	public function identity_data( string $operation, array $coordinate, int $offer, string $token ): array {
		$actor = $this->actor();
		$tuple = [ 'site_id' => $actor['site_id'], 'authority' => self::AUTHORITY, 'principal' => 'user:' . $actor['user_id'], 'operation' => $operation,
			'operation_version' => 1, 'target_key' => PolicyAssignmentCommand::make_target( $coordinate['scope_type'] ?? '', $coordinate['scope_id'] ?? -1, $offer ), 'idempotency_key' => $token ];
		$identity = $this->identity( $tuple ); if ( ! $this->authorize( $identity, $coordinate, $offer ) ) { self::deny(); }
		return $tuple;
	}

	public function identity( array $tuple ): OperationIdentity {
		$keys = [ 'site_id', 'authority', 'principal', 'operation', 'operation_version', 'target_key', 'idempotency_key' ];
		if ( count( $tuple ) !== 7 || array_diff( $keys, array_keys( $tuple ) ) || ! is_int( $tuple['site_id'] ) || ! is_int( $tuple['operation_version'] ) ) { self::deny(); }
		foreach ( [ 'authority', 'principal', 'operation', 'target_key', 'idempotency_key' ] as $key ) { if ( ! is_string( $tuple[$key] ) ) { self::deny(); } }
		$identity = new OperationIdentity( ...$tuple ); if ( ! $this->identity_allowed( $identity ) ) { self::deny(); }
		return $identity;
	}

	public function authorize( OperationIdentity $identity, array $coordinate, int $offer ): bool {
		try {
			return $this->identity_allowed( $identity ) && $this->target( $coordinate['scope_type'] ?? '', $coordinate['scope_id'] ?? -1 ) === $coordinate
				&& $coordinate['site_id'] === $identity->site_id && $this->offer( $offer ) === $offer
				&& $identity->target_key === PolicyAssignmentCommand::make_target( $coordinate['scope_type'], $coordinate['scope_id'], $offer )
				&& $this->binding()['site_id'] === $identity->site_id && $this->identity_allowed( $identity );
		} catch ( \Throwable ) { return false; }
	}

	public function capture( OperationIdentity $identity, array $coordinate, int $offer ): array {
		if ( ! $this->authorize( $identity, $coordinate, $offer ) ) { self::deny(); }
		$binding = $this->binding(); $actor = $this->actor();
		$authorize = static fn ( OperationIdentity $candidate, ?int $candidate_actor = null ): bool => $candidate === $identity
			&& ( null === $candidate_actor || $candidate_actor === $actor['user_id'] );
		$scope = static function ( OperationIdentity $candidate, array $value ) use ( $identity ): bool {
			ReturnPolicyFamily::checked_scope( $value ); return $candidate === $identity;
		};
		$out = [ 'authorize' => $authorize, 'return_family' => new ReturnPolicyFamily( $scope, static fn(): bool => false, static fn(): bool => false ),
			'refund_family' => new RefundPolicyFamily( $scope, static fn(): bool => false, static fn(): bool => false ) ];
		if ( $this->binding() !== $binding || ! $this->authorize( $identity, $coordinate, $offer ) ) { self::deny(); }
		return $out;
	}

	private function identity_allowed( OperationIdentity $identity ): bool {
		return $this->can_access() && $identity->site_id === get_current_blog_id() && self::AUTHORITY === $identity->authority
			&& 'user:' . get_current_user_id() === $identity->principal && 1 === $identity->operation_version
			&& in_array( $identity->operation, [ 'policy.assignment.read', 'policy.assignment.set' ], true );
	}

	private function product( int $id ): array {
		if ( $id < 1 ) { self::deny(); }
		if ( null !== $this->product_loader ) { $data = ( $this->product_loader )( $id ); }
		else {
			if ( ! function_exists( 'wc_get_product' ) ) { self::deny(); }
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || $product->get_id() !== $id || 'trash' === $product->get_status() ) { self::deny(); }
			$data = [ 'parent_id' => $product->get_parent_id(), 'is_variation' => $product->is_type( 'variation' ), 'label' => $product->get_name() ];
		}
		if ( ! is_array( $data ) || count( $data ) !== 3 || array_diff( [ 'parent_id', 'is_variation', 'label' ], array_keys( $data ) ) || ! is_int( $data['parent_id'] )
			|| $data['parent_id'] < 0 || ! is_bool( $data['is_variation'] ) || ! is_string( $data['label'] ) || strlen( $data['label'] ) > 512
			|| 1 !== preg_match( '//u', $data['label'] ) || ( $data['is_variation'] && ( $data['parent_id'] < 1 || $data['parent_id'] === $id ) )
			|| ( ! $data['is_variation'] && 0 !== $data['parent_id'] ) ) { self::deny(); }
		if ( '' === $data['label'] ) { $data['label'] = 'Product #' . $id; }
		return $data;
	}

	private static function positive( mixed $id ): bool { return is_int( $id ) ? $id > 0 : is_string( $id ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $id ) && (string) (int) $id === $id; }
	private static function same_positive( mixed $id, int $expected ): bool { return self::positive( $id ) && (int) $id === $expected; }
	private static function deny(): never { throw new \RuntimeException( 'Policy assignment administration is unavailable.' ); }
}
