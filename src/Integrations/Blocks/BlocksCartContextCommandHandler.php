<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService;
use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;

/**
 * Store API cart-update commands. Calls Classic-qualified mutation services.
 *
 * Does not write WooCommerce cart arrays directly.
 */
final class BlocksCartContextCommandHandler {

	public const ACTION_RESELECT = 'reselect_option';

	public const ACTION_SET_ITEM = 'set_item_context';

	public const ACTION_SPLIT = 'split_item_context';

	public const ACTION_USE_FOR_ALL = 'use_for_all';

	public const ACTION_APPLY_CHECKOUT_ADDRESS = 'apply_checkout_address';

	public const RESULT_GLOBAL = 'cetech_de_blocks_mutation_result';

	public function __construct(
		private CartCustomerContextEditorService $editor,
		private CartCustomerContextMutationService $mutation,
		private ApplyCustomerContextToEligibleLinesService $apply_all,
		private CheckoutAddressPolicy $address_policy
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function handle( array $data ): void {
		$action = sanitize_key( (string) ( $data['action'] ?? '' ) );

		switch ( $action ) {
			case self::ACTION_SET_ITEM:
				$this->set_item_context( $data );
				return;
			case self::ACTION_SPLIT:
				$this->split_item_context( $data );
				return;
			case self::ACTION_USE_FOR_ALL:
				$this->use_for_all( $data );
				return;
			case self::ACTION_APPLY_CHECKOUT_ADDRESS:
				$this->apply_checkout_address( $data );
				return;
			default:
				$this->reject( __( 'That cart update is not recognised.', 'cetech-woocommerce-delivery-engine' ) );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function set_item_context( array $data ): void {
		$cart_item_key = $this->cart_item_key( $data );
		$item          = $this->cart_item( $cart_item_key );
		$context       = $this->context_from_data( $item, $data );

		if ( ! $context instanceof CustomerCartContext ) {
			$this->reject( __( 'Please complete the delivery details for this item.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$result = $this->mutation->updateWholeLine( WC()->cart->get_cart(), $cart_item_key, $context );
		if ( ! $result->ok ) {
			$this->reject( __( 'That cart item could not be updated.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->mutation->commitToCart( $result->contents );
		$this->store_result(
			[
				'action'  => self::ACTION_SET_ITEM,
				'updated' => [ [ 'key' => $result->target_key, 'name' => $this->line_name( $item ) ] ],
				'skipped' => [],
			]
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function split_item_context( array $data ): void {
		$cart_item_key = $this->cart_item_key( $data );
		$item          = $this->cart_item( $cart_item_key );
		$context       = $this->context_from_data( $item, $data );
		$quantity      = (int) ( $data['quantity'] ?? $data['split_qty'] ?? 0 );

		if ( ! $context instanceof CustomerCartContext ) {
			$this->reject( __( 'Please complete the delivery details for this item.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$result = $this->mutation->splitQuantity( WC()->cart->get_cart(), $cart_item_key, $quantity, $context );
		if ( ! $result->ok ) {
			$this->reject( __( 'That quantity could not be moved. No cart change was made.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->mutation->commitToCart( $result->contents );
		$this->store_result(
			[
				'action'  => self::ACTION_SPLIT,
				'updated' => [ [ 'key' => $result->target_key, 'name' => $this->line_name( $item ) ] ],
				'skipped' => [],
			]
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function use_for_all( array $data ): void {
		$cart_item_key = $this->cart_item_key( $data );
		$item          = $this->cart_item( $cart_item_key );
		$context       = $this->context_from_data( $item, $data );

		if ( ! $context instanceof CustomerCartContext ) {
			$this->reject( __( 'Please complete the delivery details for this item.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$outcome = $this->apply_all->applyDeliveryLocation( WC()->cart->get_cart(), $cart_item_key, $context );
		$this->mutation->commitToCart( $outcome['contents'] );
		$this->store_result( $this->line_outcomes( self::ACTION_USE_FOR_ALL, $outcome ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function apply_checkout_address( array $data ): void {
		unset( $data );

		$address = $this->address_policy->read_checkout_shipping_address();
		$outcome = $this->apply_all->applyCheckoutAddressToIncomplete( WC()->cart->get_cart(), $address );
		if ( [] !== ( $outcome['updated'] ?? [] ) ) {
			$this->mutation->commitToCart( $outcome['contents'] );
		}
		$this->store_result( $this->line_outcomes( self::ACTION_APPLY_CHECKOUT_ADDRESS, $outcome ) );
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $data
	 */
	private function context_from_data( array $item, array $data ): ?CustomerCartContext {
		$matching = is_array( $data['matching_location'] ?? null ) ? $data['matching_location'] : [];
		$address  = is_array( $data['delivery_address'] ?? null ) ? $data['delivery_address'] : [];

		return $this->editor->contextFromInput(
			$item,
			[
				'display_key'         => ProductDeliveryOptionsBuilder::normalizeDisplayKey( (string) ( $data['display_key'] ?? '' ) ),
				'matching_location'  => $matching,
				'delivery_address'   => $address,
				'pickup_location_id' => (int) ( $data['pickup_location_id'] ?? 0 ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function cart_item_key( array $data ): string {
		$key = sanitize_text_field( (string) ( $data['cart_item_key'] ?? '' ) );
		if ( '' === $key ) {
			$this->reject( __( 'That cart item could not be found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		return $key;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cart_item( string $cart_item_key ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			$this->reject( __( 'Your cart could not be updated. Please try again.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$item = WC()->cart->get_cart_item( $cart_item_key );
		if ( is_array( $item ) ) {
			return $item;
		}

		foreach ( WC()->cart->get_cart() as $key => $candidate ) {
			if ( (string) $key === $cart_item_key && is_array( $candidate ) ) {
				return $candidate;
			}
		}

		$this->reject( __( 'That cart item could not be found.', 'cetech-woocommerce-delivery-engine' ) );

		return [];
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function line_name( array $item ): string {
		$data = $item['data'] ?? null;
		if ( is_object( $data ) && method_exists( $data, 'get_name' ) ) {
			$name = trim( (string) $data->get_name() );
			if ( '' !== $name ) {
				return $name;
			}
		}

		return __( 'a product in your cart', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function store_result( array $result ): void {
		$GLOBALS[ self::RESULT_GLOBAL ] = $result;
	}

	/**
	 * @param array{updated?: list<array<string, mixed>>, skipped?: list<array<string, mixed>>} $outcome
	 *
	 * @return array<string, mixed>
	 */
	private function line_outcomes( string $action, array $outcome ): array {
		$updated = is_array( $outcome['updated'] ?? null ) ? $outcome['updated'] : [];
		$skipped = is_array( $outcome['skipped'] ?? null ) ? $outcome['skipped'] : [];
		$preserved = is_array( $outcome['preserved'] ?? null ) ? $outcome['preserved'] : [];
		$failed  = [];
		$unchanged = $preserved;

		foreach ( $skipped as $row ) {
			$reason = strtolower( (string) ( $row['reason'] ?? '' ) );
			if (
				str_contains( $reason, 'pickup' )
				|| str_contains( $reason, 'already' )
				|| str_contains( $reason, 'unchanged' )
				|| str_contains( $reason, 'will be kept' )
				|| str_contains( $reason, 'different destination' )
			) {
				$unchanged[] = $row;
			} else {
				$failed[] = $row;
			}
		}

		return [
			'action'    => $action,
			'updated'   => $updated,
			'failed'    => $failed,
			'unchanged' => $unchanged,
			'skipped'   => $skipped,
			'blocked'   => ! empty( $outcome['blocked'] ),
		];
	}

	private function reject( string $message ): void {
		$class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $class ) ) {
			throw new $class( 'cetech_de_customer_context', $message, 400 );
		}

		throw new \RuntimeException( $message );
	}
}
