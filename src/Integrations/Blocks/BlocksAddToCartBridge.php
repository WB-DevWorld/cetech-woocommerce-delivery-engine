<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;

/**
 * Maps Store API add-to-cart extension data onto the existing cart-capture contract.
 *
 * Does not replace Classic form POST capture. Classic remains unchanged when
 * Store API extensions are absent.
 */
final class BlocksAddToCartBridge {

	public const EXTENSION_OPTION_KEY = 'delivery_option_key';

	public const EXTENSION_VARIATION_ID = 'variation_id';

	public const EXTENSION_MATCHING_LOCATION = 'matching_location';

	public function __construct(
		private CartDeliverySelectionCapture $cart_capture
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'prime_from_request' ], 5, 2 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ $this, 'prime_cart_item_data' ], 5, 2 );
		add_filter( 'cetech_de_submitted_delivery_option_key', [ $this, 'filter_submitted_option_key' ] );
		add_filter( 'cetech_de_submitted_delivery_variation_id', [ $this, 'filter_submitted_variation_id' ] );
		add_filter( 'cetech_de_submitted_matching_location', [ $this, 'filter_submitted_matching_location' ] );
	}

	/**
	 * @param mixed $product
	 * @param mixed $request
	 */
	public function prime_from_request( $product, $request ): void {
		unset( $product );
		$this->store_from_request( $request );
	}

	/**
	 * @param array<string, mixed> $cart_item_data
	 * @param mixed                $request
	 *
	 * @return array<string, mixed>
	 */
	public function prime_cart_item_data( array $cart_item_data, $request ): array {
		$this->store_from_request( $request );

		return $cart_item_data;
	}

	public function filter_submitted_option_key( string $key ): string {
		$stored = $this->request_option_key();

		return '' !== $stored ? $stored : $key;
	}

	public function filter_submitted_variation_id( int $variation_id ): int {
		$stored = $this->request_variation_id();

		return $stored > 0 ? $stored : $variation_id;
	}

	/**
	 * @param mixed $current
	 *
	 * @return mixed
	 */
	public function filter_submitted_matching_location( $current ) {
		$stored = $this->request_matching_location();

		return $stored instanceof MatchingLocation ? $stored : $current;
	}

	/**
	 * @param mixed $request
	 */
	private function store_from_request( $request ): void {
		$key = $this->read_option_key( $request );
		$variation_id = $this->read_variation_id( $request );
		$matching     = $this->read_matching_location( $request );

		$GLOBALS['cetech_de_blocks_delivery_option_key'] = $key;
		$GLOBALS['cetech_de_blocks_delivery_variation_id'] = $variation_id;
		$GLOBALS['cetech_de_blocks_matching_location']     = $matching;
	}

	private function request_option_key(): string {
		$raw = $GLOBALS['cetech_de_blocks_delivery_option_key'] ?? '';

		return is_string( $raw ) ? ProductDeliveryOptionsBuilder::normalizeDisplayKey( $raw ) : '';
	}

	private function request_variation_id(): int {
		$raw = $GLOBALS['cetech_de_blocks_delivery_variation_id'] ?? 0;

		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	private function request_matching_location(): ?MatchingLocation {
		$raw = $GLOBALS['cetech_de_blocks_matching_location'] ?? null;

		return $raw instanceof MatchingLocation ? $raw : null;
	}

	/**
	 * @param mixed $request
	 */
	private function read_option_key( $request ): string {
		$extensions = $this->extensions( $request );
		$raw        = $extensions[ self::EXTENSION_OPTION_KEY ] ?? '';

		if ( is_string( $raw ) && '' !== $raw ) {
			return ProductDeliveryOptionsBuilder::normalizeDisplayKey( $raw );
		}

		$payload = $extensions['customer_context'] ?? $extensions['pdp_context'] ?? null;
		if ( is_array( $payload ) && isset( $payload['display_key'] ) && is_string( $payload['display_key'] ) ) {
			$from_payload = ProductDeliveryOptionsBuilder::normalizeDisplayKey( $payload['display_key'] );
			if ( '' !== $from_payload ) {
				return $from_payload;
			}
		}

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$direct = $request->get_param( CartDeliverySelectionCapture::POST_FIELD );

			if ( is_string( $direct ) && '' !== $direct ) {
				return ProductDeliveryOptionsBuilder::normalizeDisplayKey( $direct );
			}
		}

		return '';
	}

	/**
	 * @param mixed $request
	 */
	private function read_variation_id( $request ): int {
		$extensions = $this->extensions( $request );
		$raw        = $extensions[ self::EXTENSION_VARIATION_ID ] ?? 0;

		if ( is_numeric( $raw ) && (int) $raw > 0 ) {
			return (int) $raw;
		}

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$id = (int) $request->get_param( 'variation_id' );

			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * @param mixed $request
	 */
	private function read_matching_location( $request ): ?MatchingLocation {
		$extensions = $this->extensions( $request );
		$raw        = $extensions[ self::EXTENSION_MATCHING_LOCATION ] ?? null;

		if ( ! is_array( $raw ) ) {
			$payload = $extensions['customer_context'] ?? $extensions['pdp_context'] ?? null;
			if ( is_array( $payload ) ) {
				$raw = $payload['matching_location'] ?? null;
			}
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$location = MatchingLocation::fromInput(
			[
				'country'  => (string) ( $raw['country'] ?? '' ),
				'state'    => (string) ( $raw['state'] ?? '' ),
				'city'     => (string) ( $raw['city'] ?? '' ),
				'postcode' => (string) ( $raw['postcode'] ?? '' ),
			]
		);

		return $location->isPresent() ? $location : null;
	}

	/**
	 * @param mixed $request
	 *
	 * @return array<string, mixed>
	 */
	private function extensions( $request ): array {
		$payload = [];

		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$extensions = $request->get_param( 'extensions' );

			if ( is_array( $extensions ) ) {
				$payload = $extensions[ BlocksCheckoutAdapter::NAMESPACE ] ?? [];
			}
		} elseif ( is_array( $request ) ) {
			$payload = $request['extensions'][ BlocksCheckoutAdapter::NAMESPACE ] ?? [];
		}

		return is_array( $payload ) ? $payload : [];
	}
}
