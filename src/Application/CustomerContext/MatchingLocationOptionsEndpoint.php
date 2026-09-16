<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;

/**
 * AJAX: resolve delivery options for a PDP matching location.
 */
final class MatchingLocationOptionsEndpoint {

	public const ACTION = 'cetech_de_matching_location_options';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartDeliverySelectionCapture $cart_capture,
		private LocationAwareDeliveryOptions $location_options,
		private CustomerBrowsingLocationStore $browsing_store
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_send_json_error( [ 'message' => __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ) ], 400 );
		}

		$product_id   = isset( $_REQUEST['product_id'] ) ? absint( wp_unslash( (string) $_REQUEST['product_id'] ) ) : 0;
		$variation_id = isset( $_REQUEST['variation_id'] ) ? absint( wp_unslash( (string) $_REQUEST['variation_id'] ) ) : 0;
		$location     = MatchingLocation::fromInput(
			[
				'country'  => isset( $_REQUEST['country'] ) ? wp_unslash( (string) $_REQUEST['country'] ) : '',
				'state'    => isset( $_REQUEST['state'] ) ? wp_unslash( (string) $_REQUEST['state'] ) : '',
				'city'     => isset( $_REQUEST['city'] ) ? wp_unslash( (string) $_REQUEST['city'] ) : '',
				'postcode' => isset( $_REQUEST['postcode'] ) ? wp_unslash( (string) $_REQUEST['postcode'] ) : '',
			]
		);

		if ( $location->isPresent() ) {
			$this->browsing_store->save( $location );
		}

		wp_send_json_success( $this->build_payload( $product_id, $variation_id, $location->isPresent() ? $location : null ) );
	}

	/**
	 * @return array{
	 *     status: string,
	 *     message: string,
	 *     options: list<array<string, mixed>>,
	 *     requires_location: bool
	 * }
	 */
	public function build_payload( int $product_id, int $variation_id, ?MatchingLocation $location ): array {
		if ( ! $this->requirements->is_woocommerce_active() || ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return [
				'status'            => 'error',
				'message'           => __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ),
				'options'           => [],
				'requires_location' => false,
			];
		}

		$assessment = $this->cart_capture->assess_product_selection( $product_id, $variation_id );
		$all        = $assessment['options'];
		$requires   = $this->location_options->delivery_requires_matching_location( $all );
		$currency   = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'GHS';
		$filtered   = $this->location_options->filter( $all, $location, $currency );
		$public     = [];

		foreach ( $filtered as $option ) {
			$row = $option->toArray();
			$row['estimate_line'] = \CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy::compact_estimate(
				(string) ( $option->estimate_text ?? '' )
			);
			$public[] = $row;
		}

		if ( 'blocked' === $assessment['requirement'] ) {
			return [
				'status'            => 'unavailable',
				'message'           => __( 'Delivery is currently unavailable for this product.', 'cetech-woocommerce-delivery-engine' ),
				'options'           => $public,
				'requires_location' => $requires,
			];
		}

		$delivery_visible = array_filter(
			$filtered,
			static fn ( ProductDeliveryOption $option ): bool => FulfilmentChoice::Delivery->value === $option->fulfilment_choice
		);

		if ( $requires && ( ! $location instanceof MatchingLocation || ! $location->isPresent() ) ) {
			return [
				'status'            => 'need_location',
				'message'           => '',
				'options'           => $public,
				'requires_location' => true,
				'locality'          => '',
			];
		}

		if ( $requires && [] === $delivery_visible && $location instanceof MatchingLocation ) {
			$pickup_only = array_filter(
				$filtered,
				static fn ( ProductDeliveryOption $option ): bool => FulfilmentChoice::StorePickup->value === $option->fulfilment_choice
			);

			if ( [] === $pickup_only ) {
				return [
					'status'            => 'unavailable',
					'message'           => __( 'Delivery is not available to this location.', 'cetech-woocommerce-delivery-engine' ),
					'options'           => [],
					'requires_location' => true,
				];
			}
		}

		return [
			'status'            => 'ok',
			'message'           => '',
			'options'           => $public,
			'requires_location' => $requires,
			'default_key'       => ProductDeliveryOptionsBuilder::defaultDisplayKey( $filtered ),
			'locality'          => $location instanceof MatchingLocation ? $location->publicLocalityLabel() : '',
		];
	}
}
