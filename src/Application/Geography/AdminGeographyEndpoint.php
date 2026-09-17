<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageConfigurationValidator;
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
		private CoverageGroupRepositoryInterface $groups,
		private ?Schema6CoverageUpgradeService $upgrade = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, [ $this, 'handle_search' ] );
		add_action( 'wp_ajax_' . self::PACK_ACTION, [ $this, 'handle_pack' ] );
	}

	public function handle_search(): void {
		$this->verify( self::SEARCH_ACTION, 'manage_delivery_zones' );
		$op      = sanitize_key( (string) ( $_REQUEST['op'] ?? 'search' ) );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$query   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['q'] ?? '' ) ) );
		$page    = max( 1, (int) ( $_REQUEST['page'] ?? 1 ) );
		$token   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['request_token'] ?? '' ) ) );
		$select_all = ! empty( $_REQUEST['select_all'] ) || 'descendants' === $op;

		if ( 2 !== strlen( $country ) ) {
			wp_send_json_success(
				[
					'items'         => [],
					'page'          => $page,
					'request_token' => $token,
					'total'         => 0,
					'label'         => GeographyAdminLabels::administrative_area_label( $country ),
				]
			);
		}

		if ( 'children' === $op ) {
			$this->send_children( $country, $parent, $token );
		}

		$parent_supplied = '' !== $parent;
		$parent_location = $parent_supplied ? $this->resolver->require_valid_key( $parent, $country ) : $this->locations->find_country( $country );
		if ( $parent_supplied && null === $parent_location ) {
			wp_send_json_success(
				[
					'items'         => [],
					'page'          => $page,
					'request_token' => $token,
					'total'         => 0,
					'error'         => 'invalid_parent',
				]
			);
		}

		$parent_id = $parent_location?->id;
		$limit     = $select_all ? CoverageConfigurationValidator::SELECT_ALL_LIMIT : 25;
		$total     = $this->locations->count_localities( $country, $parent_id, $query );

		if ( $select_all && $total > CoverageConfigurationValidator::SELECT_ALL_LIMIT ) {
			wp_send_json_success(
				[
					'items'                  => [],
					'page'                   => 1,
					'request_token'          => $token,
					'total'                  => $total,
					'recommend_entire_area'  => true,
					'select_all_limit'       => CoverageConfigurationValidator::SELECT_ALL_LIMIT,
				]
			);
		}

		$items = [];
		foreach ( $this->locations->search_localities( $country, $parent_id, $query, $limit, ( $page - 1 ) * $limit ) as $location ) {
			$breadcrumb = $this->locations->display_breadcrumb( $location );
			$label      = '' !== $breadcrumb ? $location->canonical_name . ' — ' . $breadcrumb : $location->canonical_name;
			$items[]    = [
				'key'        => $location->location_key,
				'id'         => $location->id,
				'name'       => $location->canonical_name,
				'label'      => $label,
				'breadcrumb' => $breadcrumb,
			];
		}

		wp_send_json_success(
			[
				'items'         => $items,
				'page'          => $page,
				'request_token' => $token,
				'total'         => $total,
				'has_more'      => ( $page * $limit ) < $total,
				'label'         => GeographyAdminLabels::administrative_area_label( $country ),
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
		if ( 'retry' === $op && $pack_id > 0 ) {
			$pack = $this->packs->retry( $pack_id );
			wp_send_json_success( [ 'pack' => $pack->publicAdminRow() ] );
		}

		if ( 'reconcile' === $op ) {
			$result = $this->upgrade instanceof Schema6CoverageUpgradeService
				? $this->upgrade->reconcile( true )
				: [ 'skipped' => true ];
			wp_send_json_success( $result );
		}

		$pack = $this->packs->find( $pack_id );
		wp_send_json_success( [ 'pack' => $pack?->publicAdminRow() ] );
	}

	private function send_children( string $country, string $parent, string $token ): void {
		$parent_location = '' !== $parent
			? $this->resolver->require_valid_key( $parent, $country )
			: $this->locations->find_country( $country );
		if ( null === $parent_location ) {
			wp_send_json_success(
				[
					'items'         => [],
					'request_token' => $token,
					'error'         => '' !== $parent ? 'invalid_parent' : 'country_missing',
					'label'         => GeographyAdminLabels::administrative_area_label( $country ),
				]
			);
		}

		$items = [];
		foreach ( $this->locations->list_children( $parent_location->id, GeographyLocationType::Administrative, 250, 0 ) as $child ) {
			$items[] = [
				'key'          => $child->location_key,
				'id'           => $child->id,
				'name'         => $child->canonical_name,
				'has_children' => $this->locations->count_children( $child->id, GeographyLocationType::Administrative ) > 0,
				'level'        => $child->administrative_level,
			];
		}
		if ( $parent_location->isCountry() ) {
			array_unshift(
				$items,
				[
					'key'          => $parent_location->location_key,
					'id'           => $parent_location->id,
					'name'         => sprintf(
						/* translators: %s country name */
						__( 'Entire %s', 'cetech-woocommerce-delivery-engine' ),
						$parent_location->canonical_name
					),
					'entire_country' => true,
					'has_children'   => false,
				]
			);
		}

		wp_send_json_success(
			[
				'items'         => $items,
				'request_token' => $token,
				'has_more'      => false,
				'root'          => [
					'key'  => $parent_location->location_key,
					'id'   => $parent_location->id,
					'name' => $parent_location->canonical_name,
				],
				'can_select_root' => true,
				'label'         => GeographyAdminLabels::administrative_area_label( $country ),
			]
		);
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
