<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;

/**
 * Customer-safe cascading geography endpoints.
 */
final class StorefrontGeographyEndpoint {

	public const CHILDREN_ACTION = 'cetech_de_geography_children';

	public const SEARCH_ACTION = 'cetech_de_geography_locality_search';

	public const POSTCODE_ACTION = 'cetech_de_geography_postcode_relevance';

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private CanonicalLocationResolver $resolver,
		private GeographyPackRepositoryInterface $packs,
		private ?\CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface $mappings = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::CHILDREN_ACTION, [ $this, 'handle_children' ] );
		add_action( 'wp_ajax_nopriv_' . self::CHILDREN_ACTION, [ $this, 'handle_children' ] );
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, [ $this, 'handle_search' ] );
		add_action( 'wp_ajax_nopriv_' . self::SEARCH_ACTION, [ $this, 'handle_search' ] );
		add_action( 'wp_ajax_' . self::POSTCODE_ACTION, [ $this, 'handle_postcode' ] );
		add_action( 'wp_ajax_nopriv_' . self::POSTCODE_ACTION, [ $this, 'handle_postcode' ] );
	}

	public function handle_children(): void {
		$this->verify( self::CHILDREN_ACTION );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$type    = sanitize_key( (string) ( $_REQUEST['type'] ?? GeographyLocationType::Administrative->value ) );
		$location_type = GeographyLocationType::tryFrom( $type ) ?? GeographyLocationType::Administrative;

		$parent_location = '' !== $parent
			? $this->resolver->require_valid_key( $parent, $country )
			: $this->locations->find_country( $country );

		if ( null === $parent_location ) {
			wp_send_json_success( [ 'items' => [] ] );
		}

		$items = [];
		foreach ( $this->locations->list_children( $parent_location->id, $location_type, 200, 0 ) as $child ) {
			$items[] = $this->customer_item( $child );
		}

		wp_send_json_success( [ 'items' => $items ] );
	}

	public function handle_search(): void {
		$this->verify( self::SEARCH_ACTION );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$query   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['q'] ?? '' ) ) );
		$page    = max( 1, (int) ( $_REQUEST['page'] ?? 1 ) );
		$limit   = 25;
		$token   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['request_token'] ?? '' ) ) );

		$parent_location = '' !== $parent ? $this->resolver->require_valid_key( $parent, $country ) : null;
		$parent_id       = $parent_location?->id;
		$items           = [];
		foreach ( $this->locations->search_localities( $country, $parent_id, $query, $limit, ( $page - 1 ) * $limit ) as $location ) {
			$items[] = $this->customer_item( $location );
		}

		wp_send_json_success(
			[
				'items'         => $items,
				'page'          => $page,
				'request_token' => $token,
				'has_pack'      => $this->country_has_locality_pack( $country ),
			]
		);
	}

	public function handle_postcode(): void {
		$this->verify( self::POSTCODE_ACTION );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$required = $this->country_requires_postcode( $country );
		wp_send_json_success( [ 'required' => $required, 'visible' => $required ] );
	}

	/**
	 * @return array{key:string,name:string,type:string,code?:string}
	 */
	public function customer_item( \CetechDeliveryEngine\Domain\Geography\CanonicalLocation $location ): array {
		$item = [
			'key'  => $location->location_key,
			'name' => $location->canonical_name,
			'type' => $location->location_type->value,
		];
		$code = $this->public_woo_code( $location );
		if ( '' !== $code ) {
			$item['code'] = $code;
		}

		return $item;
	}

	private function public_woo_code( \CetechDeliveryEngine\Domain\Geography\CanonicalLocation $location ): string {
		if ( ! $this->mappings instanceof \CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface ) {
			return '';
		}

		$external = $this->mappings->find_external_id( $location->id, \CetechDeliveryEngine\Domain\Enum\GeographyProvider::WooCommerce );
		if ( ! is_string( $external ) || '' === $external ) {
			return '';
		}

		if ( $location->isCountry() ) {
			return strtoupper( $location->country_code );
		}

		$parts = explode( ':', $external );
		$code  = strtoupper( trim( (string) end( $parts ) ) );

		return '' !== $code && ctype_alnum( $code ) ? $code : '';
	}

	public function country_has_locality_pack( string $country_code ): bool {
		foreach ( $this->packs->list_all() as $pack ) {
			if ( $pack->country_code === strtoupper( $country_code ) && 'ready' === $pack->status->value ) {
				return true;
			}
		}

		return false;
	}

	private function country_requires_postcode( string $country_code ): bool {
		$countries = apply_filters( 'cetech_de_postcode_required_countries', [] );
		if ( is_array( $countries ) && in_array( strtoupper( $country_code ), array_map( 'strtoupper', $countries ), true ) ) {
			return true;
		}

		return false;
	}

	private function verify( string $action ): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( [ 'message' => __( 'Please refresh and try again.', 'cetech-woocommerce-delivery-engine' ) ], 400 );
		}
	}
}
