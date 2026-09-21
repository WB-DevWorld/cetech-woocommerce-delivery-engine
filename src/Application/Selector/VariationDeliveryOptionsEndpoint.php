<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\ProductPageQuoteContext;
use CetechDeliveryEngine\Application\CustomerContext\ShopperDeliveryLocationPrecision;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\CustomerContext\ShopperLocationPrecision;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Product;

/**
 * Customer-facing AJAX endpoint for variation delivery options (Stage 6A).
 *
 * Returns only customer-safe option projections. Server remains authoritative.
 */
final class VariationDeliveryOptionsEndpoint {

	public const ACTION = 'cetech_de_variation_delivery_options';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliveryConfigurationSourceInterface $configuration_source,
		private ProductDeliveryOptionsBuilder $options_builder,
		private VariationRelationshipInspectorInterface $variation_inspector,
		private ?LocationAwareDeliveryOptions $location_options = null,
		private ?ShopperDeliveryLocationPrecision $precision = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			$this->respond_error( __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ) );
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			$this->respond_error( __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ) );
		}

		if (
			! $this->feature_flags->is_enabled( 'enable_effective_configuration_runtime' )
			|| ! $this->feature_flags->is_enabled( 'enable_variable_product_ecr_runtime' )
		) {
			$this->respond_unavailable(
				__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			$this->respond_error( __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$product_id   = isset( $_REQUEST['product_id'] ) ? absint( wp_unslash( (string) $_REQUEST['product_id'] ) ) : 0;
		$variation_id = isset( $_REQUEST['variation_id'] ) ? absint( wp_unslash( (string) $_REQUEST['variation_id'] ) ) : 0;
		$quantity     = isset( $_REQUEST['quantity'] ) ? (int) wp_unslash( (string) $_REQUEST['quantity'] ) : 1;
		$location     = MatchingLocation::fromInput(
			[
				'country'                 => isset( $_REQUEST['country'] ) ? wp_unslash( (string) $_REQUEST['country'] ) : '',
				'state'                   => isset( $_REQUEST['state'] ) ? wp_unslash( (string) $_REQUEST['state'] ) : '',
				'city'                    => isset( $_REQUEST['city'] ) ? wp_unslash( (string) $_REQUEST['city'] ) : '',
				'postcode'                => isset( $_REQUEST['postcode'] ) ? wp_unslash( (string) $_REQUEST['postcode'] ) : '',
				'canonical_location_key'  => isset( $_REQUEST['location_key'] ) ? wp_unslash( (string) $_REQUEST['location_key'] ) : '',
			]
		);

		$payload = $this->build_payload( $product_id, $variation_id, $location->isPresent() ? $location : null, $quantity );

		wp_send_json_success( $payload );
	}

	/**
	 * Testable core without WordPress AJAX wrappers.
	 *
	 * @return array{
	 *     status: string,
	 *     product_id: int,
	 *     variation_id: int,
	 *     message: string,
	 *     options: list<array<string, mixed>>
	 * }
	 */
	public function build_payload( int $product_id, int $variation_id, ?MatchingLocation $location = null, int $quantity = 1 ): array {
		if ( $product_id <= 0 || $variation_id <= 0 ) {
			return $this->payload(
				'error',
				$product_id,
				$variation_id,
				__( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ),
				[]
			);
		}

		if ( ! $this->variation_inspector->belongs_to_parent( $variation_id, $product_id ) ) {
			return $this->payload(
				'error',
				$product_id,
				$variation_id,
				__( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ),
				[]
			);
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof WC_Product ) {
				if ( method_exists( $variation, 'is_purchasable' ) && ! $variation->is_purchasable() ) {
					return $this->payload(
						'unavailable',
						$product_id,
						$variation_id,
						__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
						[]
					);
				}

				if ( method_exists( $variation, 'is_virtual' ) && $variation->is_virtual() ) {
					return $this->payload(
						'unavailable',
						$product_id,
						$variation_id,
						__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
						[]
					);
				}
			}
		}

		$runtime = $this->configuration_source->resolve( ProductTargetType::Variation->value, $variation_id );
		$result  = $runtime->result;

		if ( ! $result->success ) {
			return $this->payload(
				'unavailable',
				$product_id,
				$variation_id,
				__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
				[]
			);
		}

		$options = $this->options_builder->buildFromResolution( $result );
		$caps    = ProductDeliveryFulfilmentCapabilities::from_options( $options );

		if ( [] === $options ) {
			return $this->payload(
				'unavailable',
				$product_id,
				$variation_id,
				__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
				[],
				$caps
			);
		}

		$requires_location = $this->location_options instanceof LocationAwareDeliveryOptions
			? $this->location_options->delivery_requires_matching_location( $options )
			: false;

		if ( $requires_location && $this->location_options instanceof LocationAwareDeliveryOptions ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
			$context  = ProductPageQuoteContext::from_request( $product_id, $variation_id, $quantity );
			if ( ! $location instanceof MatchingLocation || ! $location->isPresent() ) {
				$pickup_only = $this->location_options->filter( $options, null, $currency, $context );
				$public_pickup = [];
				foreach ( $pickup_only as $option ) {
					$public_pickup[] = $this->public_option_array( $option );
				}

				return $this->payload(
					'need_location',
					$product_id,
					$variation_id,
					'',
					$public_pickup,
					$caps
				);
			}

			$precision = $this->precision instanceof ShopperDeliveryLocationPrecision
				? $this->precision->evaluate( $location )
				: null;
			if ( $precision instanceof ShopperLocationPrecision && ! $precision->sufficient ) {
				$pickup_only = $this->location_options->filter( $options, $location, $currency, $context );
				$public_pickup = [];
				foreach ( $pickup_only as $option ) {
					if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
						$public_pickup[] = $this->public_option_array( $option );
					}
				}

				return $this->payload(
					'need_precision',
					$product_id,
					$variation_id,
					$precision->public_message,
					$public_pickup,
					$caps,
					$precision
				);
			}

			$options = $this->location_options->filter( $options, $location, $currency, $context );
		}

		$public_options = [];

		foreach ( $options as $option ) {
			$public_options[] = $this->public_option_array( $option );
		}

		$available = array_filter(
			$options,
			static fn ( ProductDeliveryOption $option ): bool => $option->is_available
		);

		if ( [] === $available ) {
			return $this->payload(
				'unavailable',
				$product_id,
				$variation_id,
				__( 'Delivery options are not available for this variation.', 'cetech-woocommerce-delivery-engine' ),
				$public_options,
				$caps
			);
		}

		return $this->payload(
			'ok',
			$product_id,
			$variation_id,
			'',
			$public_options,
			$caps
		);
	}

	/**
	 * @param list<array<string, mixed>> $options
	 *
	 * @return array{
	 *     status: string,
	 *     product_id: int,
	 *     variation_id: int,
	 *     message: string,
	 *     options: list<array<string, mixed>>
	 * }
	 */
	private function payload( string $status, int $product_id, int $variation_id, string $message, array $options, array $capabilities = [], ?ShopperLocationPrecision $precision = null ): array {
		$caps = [] === $capabilities
			? ProductDeliveryFulfilmentCapabilities::from_options( [] )
			: $capabilities;

		$payload = [
			'status'             => $status,
			'product_id'         => $product_id,
			'variation_id'       => $variation_id,
			'message'            => $message,
			'options'            => $options,
			'has_delivery'       => ! empty( $caps['has_delivery'] ),
			'has_pickup'         => ! empty( $caps['has_pickup'] ),
			'available_choices'  => $caps['available_choices'] ?? [],
		];
		if ( $precision instanceof ShopperLocationPrecision ) {
			$payload['precision'] = $precision->toArray();
		}

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function public_option_array( ProductDeliveryOption $option ): array {
		return [
			'display_key'                       => $option->display_key,
			'fulfilment_availability_label'     => $option->fulfilment_availability_label,
			'fulfilment_choice_label'           => $option->fulfilment_choice_label,
			'fulfilment_choice'                 => $option->fulfilment_choice,
			'delivery_offer_public_label'       => $option->delivery_offer_public_label,
			'delivery_offer_public_description' => $option->delivery_offer_public_description,
			'estimate_text'                     => $option->estimate_text,
			'estimate_line'                     => DeliveryPresentationLabels::format_product_estimate_line(
				(string) ( $option->estimate_text ?? '' ),
				$option->fulfilment_choice
			),
			'is_available'                      => $option->is_available,
			'unavailable_reason'                => $option->is_available ? null : $option->unavailable_reason,
			'is_default'                        => $option->is_default,
			'pickup_location_label'             => $option->pickup_location_label,
			'pickup_address'                    => $option->pickup_address,
			'pickup_instructions'               => $option->pickup_instructions,
			'pickup_location_id'                => $option->pickup_location_id,
			'price_amount'                      => $option->price_amount,
			'price_currency'                    => $option->price_currency,
			'price_text'                        => $option->price_text,
			'price_basis'                       => $option->price_basis,
		];
	}

	/**
	 * @return never
	 */
	private function respond_error( string $message ): void {
		wp_send_json_error(
			[
				'status'  => 'error',
				'message' => $message,
				'options' => [],
			],
			400
		);
	}

	/**
	 * @return never
	 */
	private function respond_unavailable( string $message ): void {
		wp_send_json_success(
			[
				'status'       => 'unavailable',
				'product_id'   => 0,
				'variation_id' => 0,
				'message'      => $message,
				'options'      => [],
			]
		);
	}
}
