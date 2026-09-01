<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Integrations\WPML\WpmlLivePublicCopyPresenter;
use WC_Order;

/**
 * Builds stable order-time delivery snapshots from cart item data.
 *
 * Does not mutate cart, order totals, or payment flow.
 */
final class OrderDeliverySnapshotBuilder {

	public function __construct(
		private CartDeliverySelectionRevalidator $cart_revalidator,
		private PackageDestinationZoneResolver $destination_resolver,
		private SelectedOfferShippingRateCalculator $shipping_rate_calculator,
		private RateQuoteEngine $quote_engine,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private ?WpmlLivePublicCopyPresenter $wpml_presenter = null
	) {
	}

	/**
	 * @param array<string, mixed> $cart_item_values
	 */
	public function build_line_snapshot(
		string $cart_item_key,
		array $cart_item_values,
		WC_Order $order
	): ?OrderDeliveryLineSnapshot {
		if ( ! isset( $cart_item_values[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
			return null;
		}

		$revalidation = $this->cart_revalidator->revalidate_cart_item( $cart_item_key, $cart_item_values );

		if ( CartDeliverySelectionRevalidationResult::STATUS_VALID !== $revalidation->status ) {
			return null;
		}

		$intent = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item_values[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);

		if ( null === $intent ) {
			return null;
		}

		$summary = CartDeliverySelectionSessionData::normalizeSummary(
			$cart_item_values[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
		);

		$destination_zone_id = $this->resolve_order_destination_zone_id( $order );
		$currency_code       = $order->get_currency();
		$quantity            = (int) ( $cart_item_values['quantity'] ?? 0 );

		if ( $quantity <= 0 || '' === $currency_code ) {
			return null;
		}

		$delivery_offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;
		$quoted_amount     = null;
		$quote_status      = OrderDeliverySnapshot::QUOTE_STATUS_SELECTION_ONLY;
		$rate_card_id      = null;
		$rate_card_code    = null;
		$choice            = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );

		if ( $delivery_offer_id > 0 && FulfilmentChoice::StorePickup->value !== $choice ) {
			if ( null === $destination_zone_id ) {
				return null;
			}

			$request = $this->shipping_rate_calculator->build_quote_request(
				$cart_item_values,
				$intent,
				$destination_zone_id,
				strtoupper( $currency_code )
			);

			if ( null !== $request ) {
				$quote_result = $this->quote_engine->quote( $request );

				if ( $quote_result->success && null !== $quote_result->amount ) {
					$quoted_amount  = $quote_result->amount->amount();
					$quote_status   = OrderDeliverySnapshot::QUOTE_STATUS_QUOTED;
					$rate_card_id   = $quote_result->matched_rate_card_id;
					$rate_card_code = $quote_result->matched_rate_card_code;
				} else {
					return null;
				}
			}
		}

		$summary = is_array( $summary ) ? $summary : [];

		if ( null !== $this->wpml_presenter ) {
			$summary = $this->wpml_presenter->localize_summary( $summary, $intent );
		}

		$offer_label = $summary['delivery_offer_public_label'] ?? null;
		$estimate    = $summary['estimate_text'] ?? null;
		$group_id    = DeliveryGroupIdentity::fromIntent( $intent );
		$description = $this->public_description( $delivery_offer_id > 0 ? $delivery_offer_id : null );

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			$instructions = isset( $summary['pickup_instructions'] ) ? (string) $summary['pickup_instructions'] : '';
			$description  = '' !== $instructions ? sanitize_text_field( $instructions ) : $description;
		}

		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION,
			(int) ( $intent['product_id'] ?? $cart_item_values['product_id'] ?? 0 ),
			$this->nullable_positive_int( $intent['variation_id'] ?? $cart_item_values['variation_id'] ?? null ),
			sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) ),
			$choice,
			$delivery_offer_id > 0 ? $delivery_offer_id : null,
			null !== $offer_label ? sanitize_text_field( (string) $offer_label ) : null,
			$description,
			null !== $estimate ? sanitize_text_field( (string) $estimate ) : null,
			$this->nullable_positive_int( $intent['rule_id'] ?? null ),
			$destination_zone_id,
			$quantity,
			strtoupper( $currency_code ),
			$quoted_amount,
			$quote_status,
			$rate_card_id,
			null !== $rate_card_code ? sanitize_text_field( $rate_card_code ) : null,
			gmdate( 'c' ),
			$group_id,
			FulfilmentChoice::StorePickup->value === $choice
				? $this->nullable_summary_text( $summary['pickup_location_label'] ?? null )
				: null,
			FulfilmentChoice::StorePickup->value === $choice
				? $this->nullable_summary_text( $summary['pickup_address'] ?? null )
				: null,
			FulfilmentChoice::StorePickup->value === $choice
				? $this->nullable_summary_text( $summary['pickup_instructions'] ?? null )
				: null
		);
	}

	public function build_package_snapshot( WC_Order $order ): ?OrderDeliveryPackageSnapshot {
		$currency_code = $order->get_currency();

		if ( '' === $currency_code ) {
			return null;
		}

		$destination_zone_id   = $this->resolve_order_destination_zone_id( $order );
		$shipping_method_id    = null;
		$shipping_method_label = null;
		$package_total         = null;
		$quote_status          = OrderDeliverySnapshot::PACKAGE_STATUS_NOT_APPLICABLE;
		$groups                = [];
		$running_total         = '0.0000';
		$delivery_index        = 0;
		$pickup_index          = 0;
		$group_count           = 0;

		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! is_object( $shipping_item ) || ! method_exists( $shipping_item, 'get_method_id' ) ) {
				continue;
			}

			if ( SelectedOfferShippingMethod::METHOD_ID !== (string) $shipping_item->get_method_id() ) {
				continue;
			}

			++$group_count;
			$amount = function_exists( 'wc_format_decimal' )
				? wc_format_decimal( (string) $shipping_item->get_total(), 4 )
				: number_format( (float) $shipping_item->get_total(), 4, '.', '' );

			$label = method_exists( $shipping_item, 'get_name' )
				? trim( (string) $shipping_item->get_name() )
				: SelectedOfferShippingMethod::RATE_LABEL;

			if ( '' === $label ) {
				$label = SelectedOfferShippingMethod::RATE_LABEL;
			}

			$group_meta = method_exists( $shipping_item, 'get_meta' )
				? (string) $shipping_item->get_meta( 'cetech_de_group_id', true )
				: '';

			$is_pickup = str_contains( strtolower( $label ), 'pickup' )
				|| ( '' !== $group_meta && DeliveryGroupIdentity::is_pickup_group( $group_meta ) );

			if ( $is_pickup ) {
				++$pickup_index;
				$display_index = $pickup_index;
				$choice        = FulfilmentChoice::StorePickup->value;
			} else {
				++$delivery_index;
				$display_index = $delivery_index;
				$choice        = FulfilmentChoice::Delivery->value;
			}

			$group_id = '' !== $group_meta
				? $group_meta
				: 'shipping-line-' . (string) $group_count;

			$groups[] = new OrderDeliveryGroupSnapshot(
				$group_id,
				SelectedOfferShippingMethod::METHOD_ID,
				$label,
				$amount,
				$choice,
				$is_pickup,
				$display_index
			);

			$shipping_method_id    = SelectedOfferShippingMethod::METHOD_ID;
			$shipping_method_label = 1 === $group_count ? $label : SelectedOfferShippingMethod::RATE_LABEL;
			$running_total         = $this->add_amounts( $running_total, (string) $amount );
			$quote_status          = OrderDeliverySnapshot::PACKAGE_STATUS_SUCCESS;
		}

		if ( OrderDeliverySnapshot::PACKAGE_STATUS_SUCCESS === $quote_status ) {
			$package_total = $running_total;
		}

		if ( $group_count > 1 ) {
			$shipping_method_label = __( 'Multiple deliveries', 'cetech-woocommerce-delivery-engine' );
		}

		return new OrderDeliveryPackageSnapshot(
			OrderDeliverySnapshot::VERSION,
			$shipping_method_id,
			$shipping_method_label,
			$package_total,
			strtoupper( $currency_code ),
			$destination_zone_id,
			$quote_status,
			gmdate( 'c' ),
			$groups
		);
	}

	private function add_amounts( string $left, string $right ): string {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $left, $right, 4 );
		}

		return number_format( (float) $left + (float) $right, 4, '.', '' );
	}

	private function resolve_order_destination_zone_id( WC_Order $order ): ?int {
		return $this->destination_resolver->resolve_zone_id(
			[
				'country'  => (string) $order->get_shipping_country(),
				'state'    => (string) $order->get_shipping_state(),
				'city'     => (string) $order->get_shipping_city(),
				'postcode' => (string) $order->get_shipping_postcode(),
			]
		);
	}

	private function public_description( ?int $delivery_offer_id ): ?string {
		if ( null === $delivery_offer_id || $delivery_offer_id <= 0 ) {
			return null;
		}

		$offer = $this->delivery_offer_repository->findById( $delivery_offer_id );

		if ( null === $offer ) {
			return null;
		}

		$description = trim( (string) ( $offer['public_description'] ?? '' ) );

		if ( '' === $description ) {
			return null;
		}

		if ( null !== $this->wpml_presenter ) {
			$description = $this->wpml_presenter->translate_offer_description( $delivery_offer_id, $description );
		}

		return '' !== $description ? sanitize_text_field( $description ) : null;
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}

	private function nullable_summary_text( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$text = sanitize_text_field( (string) $value );

		return '' !== $text ? $text : null;
	}
}
