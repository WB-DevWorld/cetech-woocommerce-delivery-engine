<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Checkout;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

/**
 * Classic checkout address policy for per-item Delivery Engine context.
 *
 * WooCommerce still owns billing and one checkout shipping address.
 * Delivery Engine owns per-item delivery addresses. Never silently copy.
 */
final class CheckoutAddressPolicy {

	public const POST_USE_CHECKOUT_ADDRESS = 'cetech_de_use_checkout_address';

	public const NONCE_ACTION = 'cetech_de_use_checkout_address';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ApplyCustomerContextToEligibleLinesService $apply_all,
		private CartCustomerContextMutationService $mutation
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_loaded', [ $this, 'maybe_handle_use_checkout_address' ], 22 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'render_notices' ], 8 );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_use_checkout_address_action' ], 12 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'maybe_align_posted_shipping' ], 20 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' )
			&& $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function maybe_handle_use_checkout_address(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::POST_USE_CHECKOUT_ADDRESS ] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wc_add_notice( __( 'Your cart could not be updated. Please try again.', 'cetech-woocommerce-delivery-engine' ), 'error' );

			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$address = $this->read_checkout_shipping_address();
		$outcome = $this->apply_all->applyCheckoutAddressToIncomplete( WC()->cart->get_cart(), $address );

		if ( ! empty( $outcome['blocked'] ) ) {
			wc_add_notice( CustomerStorefrontCopy::heterogeneous_incomplete_destinations(), 'notice' );

			return;
		}

		if ( [] !== ( $outcome['updated'] ?? [] ) ) {
			$this->mutation->commitToCart( $outcome['contents'] );
			$names = array_filter( array_map( static fn ( array $row ): string => (string) ( $row['name'] ?? '' ), $outcome['updated'] ) );
			wc_add_notice(
				sprintf(
					/* translators: %s: product names */
					__( 'Updated: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $names )
				),
				'success'
			);
		}

		if ( [] !== ( $outcome['preserved'] ?? [] ) ) {
			$lines = [];
			foreach ( $outcome['preserved'] as $row ) {
				$lines[] = trim( (string) ( $row['name'] ?? '' ) . ' — ' . (string) ( $row['reason'] ?? '' ) );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: preserved product reasons */
					__( 'Kept: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ' ', $lines )
				),
				'notice'
			);
		}

		if ( [] !== ( $outcome['skipped'] ?? [] ) ) {
			$lines = [];
			foreach ( $outcome['skipped'] as $row ) {
				$lines[] = trim( (string) ( $row['name'] ?? '' ) . ' — ' . (string) ( $row['reason'] ?? '' ) );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: skipped product reasons */
					__( 'Could not update: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ' ', $lines )
				),
				'notice'
			);
		}
	}

	public function render_notices(): void {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$summary = $this->summarize_cart( WC()->cart->get_cart() );

		if ( $summary['multi_destination'] ) {
			echo '<div class="woocommerce-info cetech-de-checkout-multi-destination" role="status">';
			echo esc_html( CustomerStorefrontCopy::multi_destination() );
			echo '</div>';
		}

		if ( $summary['has_pickup'] && $summary['has_delivery'] ) {
			echo '<div class="woocommerce-info cetech-de-checkout-mixed-fulfilment" role="status">';
			echo esc_html( CustomerStorefrontCopy::mixed_fulfilment() );
			echo '</div>';
		}

		if ( $summary['incomplete_delivery'] > 0 ) {
			echo '<div class="woocommerce-error cetech-de-checkout-incomplete-address" role="alert">';
			echo '<p>' . esc_html( CustomerStorefrontCopy::incomplete_address( $summary['incomplete_delivery'] ) ) . '</p>';
			if ( ! empty( $summary['heterogeneous_incomplete_destinations'] ) ) {
				echo '<p class="cetech-de-checkout-heterogeneous-destinations">'
					. esc_html( CustomerStorefrontCopy::heterogeneous_incomplete_destinations() )
					. '</p>';
			}
			echo $this->render_incomplete_actions( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
			echo '</div>';
		}

		if ( $summary['has_delivery'] && [] !== $summary['complete_identities'] ) {
			echo '<p class="cetech-de-checkout-keep-address">'
				. esc_html( CustomerStorefrontCopy::items_keep_own_address() )
				. '</p>';
		}
	}

	public function render_use_checkout_address_action(): void {
		// Secondary action is rendered next to the incomplete-address notice.
	}

	/**
	 * Case A: when every managed Delivery line shares one complete address,
	 * align WooCommerce shipping fields to that address. Never overwrite
	 * per-item Delivery Engine context.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_align_posted_shipping( array $data ): array {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $data;
		}

		$shared = $this->shared_complete_delivery_address( WC()->cart->get_cart() );
		if ( ! $shared instanceof DeliveryAddress ) {
			return $data;
		}

		$data['shipping_country']   = $shared->matching->country;
		$data['shipping_state']    = $shared->matching->state;
		$data['shipping_city']     = $shared->matching->city;
		$data['shipping_postcode'] = $shared->matching->postcode;
		$data['shipping_address_1'] = $shared->address_1;
		$data['shipping_address_2'] = $shared->address_2;

		if ( '' !== $shared->recipient->first_name ) {
			$data['shipping_first_name'] = $shared->recipient->first_name;
		}
		if ( '' !== $shared->recipient->last_name ) {
			$data['shipping_last_name'] = $shared->recipient->last_name;
		}
		if ( '' !== $shared->recipient->company ) {
			$data['shipping_company'] = $shared->recipient->company;
		}
		if ( '' !== $shared->recipient->phone ) {
			$data['shipping_phone'] = $shared->recipient->phone;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	public function render_incomplete_actions( array $summary ): string {
		$html  = '<div class="cetech-de-checkout-incomplete-address__actions">';
		$href  = $this->primary_add_address_url( $summary );
		if ( '' !== $href ) {
			$html .= '<a class="button cetech-de-checkout-incomplete-address__primary" href="' . esc_url( $href ) . '">'
				. esc_html( CustomerStorefrontCopy::add_delivery_address() )
				. '</a> ';
		}
		if ( ! empty( $summary['can_apply_checkout_address'] ) ) {
			$action = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
			$html  .= '<form class="cetech-de-use-checkout-address" method="post" action="' . esc_url( (string) $action ) . '">';
			$html  .= '<input type="hidden" name="' . esc_attr( self::POST_USE_CHECKOUT_ADDRESS ) . '" value="1" />';
			$html  .= wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false );
			$html  .= '<button type="submit" class="cetech-de-use-checkout-address__button cetech-de-use-checkout-address__button--secondary">';
			$html  .= esc_html( CustomerStorefrontCopy::use_my_checkout_address() );
			$html  .= '</button>';
			$html  .= '</form>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	public function primary_add_address_url( array $summary ): string {
		$cart_url = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';

		return CartDeliveryUiAnchor::cart_url( $cart_url, (string) ( $summary['first_incomplete_anchor'] ?? '' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array{
	 *     multi_destination: bool,
	 *     has_pickup: bool,
	 *     has_delivery: bool,
	 *     incomplete_delivery: int,
	 *     complete_identities: list<string>,
	 *     incomplete_matching_identities: list<string>,
	 *     heterogeneous_incomplete_destinations: bool,
	 *     can_apply_checkout_address: bool,
	 *     first_incomplete_anchor: string
	 * }
	 */
	public function summarize_cart( array $contents ): array {
		$complete_identities            = [];
		$incomplete_matching_identities = [];
		$has_pickup                     = false;
		$has_delivery                   = false;
		$incomplete_delivery            = 0;
		$first_incomplete_anchor        = '';

		foreach ( $contents as $cart_item_key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);
			if ( null === $intent ) {
				continue;
			}

			$choice  = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
			$context = CustomerCartContext::fromCartItem( $item );

			if ( FulfilmentChoice::StorePickup->value === $choice ) {
				$has_pickup = true;
				continue;
			}

			if ( FulfilmentChoice::Delivery->value !== $choice ) {
				continue;
			}

			$has_delivery = true;

			if ( $context instanceof CustomerCartContext && $context->hasCompleteDeliveryAddress() ) {
				if ( is_string( $context->delivery_location_identity ) && '' !== $context->delivery_location_identity ) {
					$complete_identities[] = $context->delivery_location_identity;
				}
				continue;
			}

			++$incomplete_delivery;
			$needs_reselection = ! empty( $item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] );
			if ( '' === $first_incomplete_anchor && ! $needs_reselection ) {
				$first_incomplete_anchor = CartDeliveryUiAnchor::for_cart_item_key( (string) $cart_item_key );
			}

			if (
				$context instanceof CustomerCartContext
				&& $context->hasMatchingLocation()
				&& is_string( $context->matching_identity )
				&& '' !== $context->matching_identity
			) {
				$incomplete_matching_identities[] = $context->matching_identity;
			}
		}

		$unique_complete       = array_values( array_unique( $complete_identities ) );
		$unique_incomplete     = array_values( array_unique( $incomplete_matching_identities ) );
		$selected_destinations = array_values( array_unique( array_merge( $unique_complete, $unique_incomplete ) ) );
		$heterogeneous         = count( $unique_incomplete ) > 1;

		return [
			'multi_destination'                     => count( $selected_destinations ) > 1,
			'has_pickup'                            => $has_pickup,
			'has_delivery'                          => $has_delivery,
			'incomplete_delivery'                   => $incomplete_delivery,
			'complete_identities'                   => $unique_complete,
			'incomplete_matching_identities'        => $unique_incomplete,
			'heterogeneous_incomplete_destinations' => $heterogeneous,
			'can_apply_checkout_address'            => $incomplete_delivery > 0 && ! $heterogeneous,
			'first_incomplete_anchor'               => $first_incomplete_anchor,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public function shared_complete_delivery_address( array $contents ): ?DeliveryAddress {
		$summary = $this->summarize_cart( $contents );
		if ( $summary['incomplete_delivery'] > 0 || count( $summary['complete_identities'] ) !== 1 ) {
			return null;
		}

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$context = CustomerCartContext::fromCartItem( $item );
			if ( $context instanceof CustomerCartContext && $context->hasCompleteDeliveryAddress() && $context->delivery_address instanceof DeliveryAddress ) {
				return $context->delivery_address;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function read_checkout_shipping_address(): array {
		$posted = $this->posted_shipping_fields();
		if ( '' !== (string) ( $posted['country'] ?? '' ) ) {
			return $posted;
		}

		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->customer ) || ! is_object( WC()->customer ) ) {
			return $posted;
		}

		$customer = WC()->customer;

		return [
			'country'    => method_exists( $customer, 'get_shipping_country' ) ? (string) $customer->get_shipping_country() : '',
			'state'      => method_exists( $customer, 'get_shipping_state' ) ? (string) $customer->get_shipping_state() : '',
			'city'       => method_exists( $customer, 'get_shipping_city' ) ? (string) $customer->get_shipping_city() : '',
			'postcode'   => method_exists( $customer, 'get_shipping_postcode' ) ? (string) $customer->get_shipping_postcode() : '',
			'address_1'  => method_exists( $customer, 'get_shipping_address_1' ) ? (string) $customer->get_shipping_address_1() : '',
			'address_2'  => method_exists( $customer, 'get_shipping_address_2' ) ? (string) $customer->get_shipping_address_2() : '',
			'first_name' => method_exists( $customer, 'get_shipping_first_name' ) ? (string) $customer->get_shipping_first_name() : '',
			'last_name'  => method_exists( $customer, 'get_shipping_last_name' ) ? (string) $customer->get_shipping_last_name() : '',
			'company'    => method_exists( $customer, 'get_shipping_company' ) ? (string) $customer->get_shipping_company() : '',
			'phone'      => method_exists( $customer, 'get_shipping_phone' ) ? (string) $customer->get_shipping_phone() : '',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function posted_shipping_fields(): array {
		$use_shipping = ! empty( $_POST['ship_to_different_address'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prefix       = $use_shipping || isset( $_POST['shipping_country'] ) ? 'shipping_' : 'billing_'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return [
			'country'    => $this->posted_field( $prefix . 'country' ),
			'state'      => $this->posted_field( $prefix . 'state' ),
			'city'       => $this->posted_field( $prefix . 'city' ),
			'postcode'   => $this->posted_field( $prefix . 'postcode' ),
			'address_1'  => $this->posted_field( $prefix . 'address_1' ),
			'address_2'  => $this->posted_field( $prefix . 'address_2' ),
			'first_name' => $this->posted_field( $prefix . 'first_name' ),
			'last_name'  => $this->posted_field( $prefix . 'last_name' ),
			'company'    => $this->posted_field( $prefix . 'company' ),
			'phone'      => $this->posted_field( $prefix . 'phone' ),
		];
	}

	private function posted_field( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}
}
