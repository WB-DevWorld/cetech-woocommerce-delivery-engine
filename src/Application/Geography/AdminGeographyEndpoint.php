<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;

/**
 * Capability-gated admin search for the coverage builder.
 */
final class AdminGeographyEndpoint {

	public const SEARCH_ACTION = 'cetech_de_admin_geography_search';

	public const PACK_ACTION = 'cetech_de_admin_geography_pack';

	public function __construct(
		private CanonicalLocationResolver $resolver,
		private \CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface $locations,
		private GeographyPackService $packs,
		private CoverageGroupRepositoryInterface $groups
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, [ $this, 'handle_search' ] );
		add_action( 'wp_ajax_' . self::PACK_ACTION, [ $this, 'handle_pack' ] );
	}

	public function handle_search(): void {
		$this->verify( self::SEARCH_ACTION, 'manage_delivery_zones' );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$query   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['q'] ?? '' ) ) );
		$select_all = ! empty( $_REQUEST['select_all'] );
		$page    = max( 1, (int) ( $_REQUEST['page'] ?? 1 ) );
		$token   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['request_token'] ?? '' ) ) );
		$limit   = $select_all ? 100 : 25;

		$parent_location = '' !== $parent ? $this->resolver->require_valid_key( $parent, $country ) : $this->locations->find_country( $country );
		$parent_id       = $parent_location?->id;
		$items           = [];
		foreach ( $this->locations->search_localities( $country, $parent_id, $query, $limit, ( $page - 1 ) * $limit ) as $location ) {
			$items[] = [
				'key'  => $location->location_key,
				'id'   => $location->id,
				'name' => $location->canonical_name,
			];
		}

		wp_send_json_success(
			[
				'items'         => $items,
				'page'          => $page,
				'request_token' => $token,
				'total'         => null !== $parent_id ? $this->locations->count_children( $parent_id, GeographyLocationType::Locality, $query ) : count( $items ),
			]
		);
	}

	public function handle_pack(): void {
		$this->verify( self::PACK_ACTION, 'manage_delivery_zones' );
		$pack_id = (int) ( $_REQUEST['pack_id'] ?? 0 );
		$op      = sanitize_key( (string) ( $_REQUEST['op'] ?? 'status' ) );
		if ( 'tick' === $op && $pack_id > 0 ) {
			$result = $this->packs->tick( $pack_id, '', 100 );
			wp_send_json_success( $result );
		}

		$pack = $this->packs->find( $pack_id );
		wp_send_json_success( [ 'pack' => $pack?->publicAdminRow() ] );
	}

	private function verify( string $action, string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot manage Delivery Areas.', 'cetech-woocommerce-delivery-engine' ) ], 403 );
		}
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( [ 'message' => __( 'Please refresh and try again.', 'cetech-woocommerce-delivery-engine' ) ], 400 );
		}
	}
}
