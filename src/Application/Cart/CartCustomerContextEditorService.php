<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Classic cart POST handler for per-line customer context editing.
 *
 * Presentation must not mutate cart arrays directly.
 */
final class CartCustomerContextEditorService {

	public const NONCE_ACTION = 'cetech_de_cart_context_edit';

	public const POST_ACTION = 'cetech_de_cart_context_edit';

	public const POST_CART_ITEM_KEY = 'cetech_de_cart_item_key';

	public const POST_APPLY_MODE = 'cetech_de_apply_mode';

	public const POST_SPLIT_QTY = 'cetech_de_split_qty';

	public const POST_USE_FOR_ALL = 'cetech_de_use_for_all';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartCustomerContextMutationService $mutation,
		private ProductDeliverySelectionValidatorInterface $selection_validator,
		private ApplyCustomerContextToEligibleLinesService $apply_all
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_loaded', [ $this, 'maybe_handle_classic_post' ], 21 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function maybe_handle_classic_post(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::POST_ACTION ] ) ) {
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

		$cart_item_key = isset( $_POST[ self::POST_CART_ITEM_KEY ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::POST_CART_ITEM_KEY ] ) )
			: '';
		$contents = WC()->cart->get_cart();
		$item     = is_array( $contents[ $cart_item_key ] ?? null ) ? $contents[ $cart_item_key ] : null;

		if ( ! is_array( $item ) ) {
			wc_add_notice( __( 'That cart item could not be found.', 'cetech-woocommerce-delivery-engine' ), 'error' );

			return;
		}

		$context = $this->contextFromInput(
			$item,
			[
				'display_key'            => isset( $_POST[ CartDeliverySelectionCapture::POST_FIELD ] )
					? ProductDeliveryOptionsBuilder::normalizeDisplayKey( wp_unslash( (string) $_POST[ CartDeliverySelectionCapture::POST_FIELD ] ) )
					: '',
				'matching_country'      => isset( $_POST['cetech_de_matching_country'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_country'] ) : '',
				'matching_state'        => isset( $_POST['cetech_de_matching_state'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_state'] ) : '',
				'matching_city'         => isset( $_POST['cetech_de_matching_city'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_city'] ) : '',
				'matching_postcode'     => isset( $_POST['cetech_de_matching_postcode'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_postcode'] ) : '',
				'matching_location_key' => isset( $_POST['cetech_de_matching_location_key'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_location_key'] ) : '',
				'address_1'            => isset( $_POST['cetech_de_address_1'] ) ? wp_unslash( (string) $_POST['cetech_de_address_1'] ) : '',
				'address_2'            => isset( $_POST['cetech_de_address_2'] ) ? wp_unslash( (string) $_POST['cetech_de_address_2'] ) : '',
				'first_name'           => isset( $_POST['cetech_de_first_name'] ) ? wp_unslash( (string) $_POST['cetech_de_first_name'] ) : '',
				'last_name'            => isset( $_POST['cetech_de_last_name'] ) ? wp_unslash( (string) $_POST['cetech_de_last_name'] ) : '',
				'company'              => isset( $_POST['cetech_de_company'] ) ? wp_unslash( (string) $_POST['cetech_de_company'] ) : '',
				'phone'                => isset( $_POST['cetech_de_phone'] ) ? wp_unslash( (string) $_POST['cetech_de_phone'] ) : '',
				'pickup_location_id'   => isset( $_POST['cetech_de_pickup_location_id'] ) ? (int) wp_unslash( (string) $_POST['cetech_de_pickup_location_id'] ) : 0,
			]
		);
		if ( ! $context instanceof CustomerCartContext ) {
			wc_add_notice( __( 'Please complete the delivery details for this item.', 'cetech-woocommerce-delivery-engine' ), 'error' );

			return;
		}

		if ( ! empty( $_POST[ self::POST_USE_FOR_ALL ] ) ) {
			$outcome = $this->apply_all->applyDeliveryLocation( $contents, $cart_item_key, $context );
			$this->mutation->commitToCart( $outcome['contents'] );
			$this->notice_apply_all( $outcome );

			return;
		}

		$qty       = (int) ( $item['quantity'] ?? 1 );
		$mode      = isset( $_POST[ self::POST_APPLY_MODE ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::POST_APPLY_MODE ] ) ) : 'all';
		$split_qty = isset( $_POST[ self::POST_SPLIT_QTY ] ) ? (int) wp_unslash( (string) $_POST[ self::POST_SPLIT_QTY ] ) : 1;

		if ( $qty > 1 && 'split' === $mode ) {
			$result = $this->mutation->splitQuantity( $contents, $cart_item_key, $split_qty, $context );
		} else {
			$result = $this->mutation->updateWholeLine( $contents, $cart_item_key, $context );
		}

		if ( ! $result->ok ) {
			wc_add_notice( __( 'That quantity could not be moved. No cart change was made.', 'cetech-woocommerce-delivery-engine' ), 'error' );

			return;
		}

		$this->mutation->commitToCart( $result->contents );
		wc_add_notice( __( 'Delivery details updated.', 'cetech-woocommerce-delivery-engine' ), 'success' );
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $input
	 */
	public function contextFromInput( array $item, array $input ): ?CustomerCartContext {
		$display_key = ProductDeliveryOptionsBuilder::normalizeDisplayKey( (string) ( $input['display_key'] ?? '' ) );

		if ( '' === $display_key ) {
			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);
			$display_key = is_array( $intent ) ? (string) ( $intent['display_key'] ?? '' ) : '';
		}

		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$variation_id = (int) ( $item['variation_id'] ?? 0 );
		$validated    = $this->selection_validator->validate(
			$product_id,
			$variation_id > 0 ? $variation_id : null,
			$display_key
		);

		if ( ! $validated->valid || ! is_array( $validated->intent ) ) {
			return null;
		}

		$option = ProductDeliveryOption::fromArray( is_array( $validated->matched_option ) ? $validated->matched_option : [] );

		if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
			$pickup_id = $option->pickup_location_id;
			if ( ( $pickup_id ?? 0 ) <= 0 ) {
				$pickup_id = (int) ( $input['pickup_location_id'] ?? 0 );
			}

			return CustomerCartContext::pickup( $pickup_id > 0 ? $pickup_id : null );
		}

		$matching_raw = is_array( $input['matching_location'] ?? null ) ? $input['matching_location'] : [];
		$matching     = MatchingLocation::fromInput(
			[
				'country'                 => (string) ( $matching_raw['country'] ?? $input['matching_country'] ?? '' ),
				'state'                   => (string) ( $matching_raw['state'] ?? $input['matching_state'] ?? '' ),
				'city'                    => (string) ( $matching_raw['city'] ?? $input['matching_city'] ?? '' ),
				'postcode'                => (string) ( $matching_raw['postcode'] ?? $input['matching_postcode'] ?? '' ),
				'canonical_location_key'  => (string) ( $matching_raw['canonical_location_key'] ?? $input['matching_location_key'] ?? '' ),
			]
		);

		$address_raw = is_array( $input['delivery_address'] ?? null ) ? $input['delivery_address'] : [];
		$street       = trim( (string) ( $address_raw['address_1'] ?? $input['address_1'] ?? '' ) );
		$address      = null;
		if ( '' !== $street ) {
			$address = DeliveryAddress::fromInput(
				[
					'country'    => $matching->country !== '' ? $matching->country : (string) ( $address_raw['country'] ?? '' ),
					'state'      => $matching->state !== '' ? $matching->state : (string) ( $address_raw['state'] ?? '' ),
					'city'       => $matching->city !== '' ? $matching->city : (string) ( $address_raw['city'] ?? '' ),
					'postcode'   => $matching->postcode !== '' ? $matching->postcode : (string) ( $address_raw['postcode'] ?? '' ),
					'address_1'  => $street,
					'address_2'  => (string) ( $address_raw['address_2'] ?? $input['address_2'] ?? '' ),
					'first_name' => (string) ( $address_raw['first_name'] ?? $input['first_name'] ?? '' ),
					'last_name'  => (string) ( $address_raw['last_name'] ?? $input['last_name'] ?? '' ),
					'company'    => (string) ( $address_raw['company'] ?? $input['company'] ?? '' ),
					'phone'      => (string) ( $address_raw['phone'] ?? $input['phone'] ?? '' ),
				]
			);
		}

		$offer_id = $option->delivery_offer_id ?? (int) ( $validated->intent['delivery_offer_id'] ?? 0 );

		return CustomerCartContext::delivery( $offer_id > 0 ? $offer_id : null, $matching, $address );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function context_from_post( array $item ): ?CustomerCartContext {
		return $this->contextFromInput(
			$item,
			[
				'display_key'          => isset( $_POST[ CartDeliverySelectionCapture::POST_FIELD ] )
					? ProductDeliveryOptionsBuilder::normalizeDisplayKey( wp_unslash( (string) $_POST[ CartDeliverySelectionCapture::POST_FIELD ] ) )
					: '',
				'matching_country'    => isset( $_POST['cetech_de_matching_country'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_country'] ) : '',
				'matching_state'      => isset( $_POST['cetech_de_matching_state'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_state'] ) : '',
				'matching_city'       => isset( $_POST['cetech_de_matching_city'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_city'] ) : '',
				'matching_postcode'   => isset( $_POST['cetech_de_matching_postcode'] ) ? wp_unslash( (string) $_POST['cetech_de_matching_postcode'] ) : '',
				'address_1'           => isset( $_POST['cetech_de_address_1'] ) ? wp_unslash( (string) $_POST['cetech_de_address_1'] ) : '',
				'address_2'           => isset( $_POST['cetech_de_address_2'] ) ? wp_unslash( (string) $_POST['cetech_de_address_2'] ) : '',
				'first_name'          => isset( $_POST['cetech_de_first_name'] ) ? wp_unslash( (string) $_POST['cetech_de_first_name'] ) : '',
				'last_name'           => isset( $_POST['cetech_de_last_name'] ) ? wp_unslash( (string) $_POST['cetech_de_last_name'] ) : '',
				'company'             => isset( $_POST['cetech_de_company'] ) ? wp_unslash( (string) $_POST['cetech_de_company'] ) : '',
				'phone'               => isset( $_POST['cetech_de_phone'] ) ? wp_unslash( (string) $_POST['cetech_de_phone'] ) : '',
				'pickup_location_id'  => isset( $_POST['cetech_de_pickup_location_id'] ) ? (int) wp_unslash( (string) $_POST['cetech_de_pickup_location_id'] ) : 0,
			]
		);
	}

	/**
	 * @param array{updated: list<array{key: string, name: string}>, skipped: list<array{key: string, name: string, reason: string}>} $outcome
	 */
	private function notice_apply_all( array $outcome ): void {
		$updated = array_filter( array_map( static fn ( array $row ): string => (string) ( $row['name'] ?? '' ), $outcome['updated'] ) );
		$skipped = $outcome['skipped'];

		if ( [] !== $updated ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: product names */
					__( 'Updated: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $updated )
				),
				'success'
			);
		}

		if ( [] !== $skipped ) {
			$lines = [];
			foreach ( $skipped as $row ) {
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
}
