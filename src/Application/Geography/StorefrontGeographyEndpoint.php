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

	public const RATE_LIMIT = 40;

	public const CACHE_TTL = 120;

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private CanonicalLocationResolver $resolver,
		private GeographyPackRepositoryInterface $packs,
		private ?\CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface $mappings = null,
		private ?GeographyPostcodeRelevance $postcodes = null
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
		if ( ! $this->allow_request() ) {
			wp_send_json_error( [ 'message' => __( 'Please wait and try again.', 'cetech-woocommerce-delivery-engine' ) ], 429 );
		}
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$type    = sanitize_key( (string) ( $_REQUEST['type'] ?? GeographyLocationType::Administrative->value ) );
		$location_type = GeographyLocationType::tryFrom( $type ) ?? GeographyLocationType::Administrative;

		$parent_supplied = '' !== $parent;
		$parent_location = $parent_supplied
			? $this->resolver->require_valid_key( $parent, $country )
			: $this->locations->find_country( $country );

		if ( $parent_supplied && null === $parent_location ) {
			wp_send_json_success( [ 'items' => [], 'error' => 'invalid_parent' ] );
		}

		if ( null === $parent_location ) {
			wp_send_json_success( [ 'items' => [] ] );
		}

		$cache_key = $this->cache_key( 'children', $country, $parent, $location_type->value, '', 1 );
		$cached    = $this->cache_get( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$items = [];
		foreach ( $this->locations->list_children( $parent_location->id, $location_type, 200, 0 ) as $child ) {
			$items[] = $this->customer_item( $child );
		}
		$payload = [
			'items' => $items,
			'label' => GeographyAdminLabels::administrative_area_label( $country ),
		];
		$this->cache_set( $cache_key, $payload );

		wp_send_json_success( $payload );
	}

	public function handle_search(): void {
		$this->verify( self::SEARCH_ACTION );
		if ( ! $this->allow_request() ) {
			wp_send_json_error( [ 'message' => __( 'Please wait and try again.', 'cetech-woocommerce-delivery-engine' ) ], 429 );
		}
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$query   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['q'] ?? '' ) ) );
		$page    = max( 1, (int) ( $_REQUEST['page'] ?? 1 ) );
		$limit   = 25;
		$token   = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['request_token'] ?? '' ) ) );

		$parent_supplied = '' !== $parent;
		$parent_location = $parent_supplied ? $this->resolver->require_valid_key( $parent, $country ) : null;
		if ( $parent_supplied && null === $parent_location ) {
			$payload = [
				'items'         => [],
				'page'          => $page,
				'request_token' => $token,
				'total'         => 0,
				'has_pack'      => $this->country_has_locality_pack( $country ),
				'error'         => 'invalid_parent',
			];
			wp_send_json_success( $payload );
		}

		$parent_id = $parent_location?->id;
		$cache_key = $this->cache_key( 'search', $country, $parent, $query, (string) $page, $page );
		$cached    = $this->cache_get( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['request_token'] = $token;
			wp_send_json_success( $cached );
		}

		$items = [];
		foreach ( $this->locations->search_localities( $country, $parent_id, $query, $limit, ( $page - 1 ) * $limit ) as $location ) {
			$items[] = $this->customer_item( $location );
		}
		$total = null !== $parent_id
			? $this->locations->count_descendants( $parent_id, GeographyLocationType::Locality, $query )
			: count( $items );

		$payload = [
			'items'         => $items,
			'page'          => $page,
			'request_token' => $token,
			'total'         => $total,
			'has_more'      => ( $page * $limit ) < $total,
			'has_pack'      => $this->country_has_locality_pack( $country ),
		];
		$this->cache_set( $cache_key, $payload );

		wp_send_json_success( $payload );
	}

	public function handle_postcode(): void {
		$this->verify( self::POSTCODE_ACTION );
		$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['country'] ?? '' ) ) ) );
		$parent  = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['parent_key'] ?? '' ) ) );
		$visible = $this->postcodes instanceof GeographyPostcodeRelevance
			? $this->postcodes->is_visible( $country, $parent )
			: $this->country_requires_postcode( $country );
		wp_send_json_success( [ 'required' => $visible, 'visible' => $visible ] );
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

	/**
	 * @return array<string, mixed>
	 */
	public function search_result( string $country, string $parent, string $query, int $page = 1, string $token = '' ): array {
		$country         = strtoupper( trim( $country ) );
		$parent_supplied = '' !== trim( $parent );
		$parent_location = $parent_supplied ? $this->resolver->require_valid_key( $parent, $country ) : null;
		if ( $parent_supplied && null === $parent_location ) {
			return [
				'items'         => [],
				'page'          => $page,
				'total'         => 0,
				'has_pack'      => $this->country_has_locality_pack( $country ),
				'error'         => 'invalid_parent',
				'request_token' => $token,
			];
		}

		$limit     = 25;
		$parent_id = $parent_location?->id;
		$items     = [];
		foreach ( $this->locations->search_localities( $country, $parent_id, $query, $limit, ( $page - 1 ) * $limit ) as $location ) {
			$items[] = $this->customer_item( $location );
		}
		$total = null !== $parent_id
			? $this->locations->count_descendants( $parent_id, GeographyLocationType::Locality, $query )
			: count( $items );

		return [
			'items'         => $items,
			'page'          => $page,
			'request_token' => $token,
			'total'         => $total,
			'has_more'      => ( $page * $limit ) < $total,
			'has_pack'      => $this->country_has_locality_pack( $country ),
		];
	}

	public function country_has_locality_pack( string $country_code ): bool {
		foreach ( $this->packs->list_all() as $pack ) {
			if ( $pack->country_code === strtoupper( $country_code ) && $pack->has_usable_dataset() ) {
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

	private function allow_request(): bool {
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '0' );
		$key = 'cetech_de_geo_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );

		return true;
	}

	private function cache_key( string $kind, string $country, string $parent, string $query, string $extra, int $page ): string {
		$revision = (string) get_option( GeographyPackService::REVISION_OPTION, '0' );

		return 'cetech_de_geo_' . md5( implode( '|', [ $kind, strtoupper( $country ), $parent, $query, $extra, (string) $page, $revision ] ) );
	}

	private function cache_get( string $key ): ?array {
		if ( function_exists( 'wp_cache_get' ) ) {
			$hit = wp_cache_get( $key, 'cetech_de_geography' );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$transient = get_transient( $key );

		return is_array( $transient ) ? $transient : null;
	}

	private function cache_set( string $key, array $payload ): void {
		$safe = $payload;
		unset( $safe['private'], $safe['internal'] );
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $key, $safe, 'cetech_de_geography', self::CACHE_TTL );
		}
		set_transient( $key, $safe, self::CACHE_TTL );
	}

	private function verify( string $action ): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( [ 'message' => __( 'Please refresh and try again.', 'cetech-woocommerce-delivery-engine' ) ], 400 );
		}
	}
}
