<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Splits WooCommerce cart shipping packages into delivery groups.
 *
 * Compatible selected deliveries consolidate; incompatible paths split.
 * Does not create shipment records.
 */
final class ShippingPackageBuilder {

	public function __construct(
		private ShippingRateCalculationGate $gate,
		private CartDeliverySelectionCapture $cart_capture
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'filter_packages' ], 20 );
	}

	public function is_runtime_active(): bool {
		return $this->gate->is_runtime_active();
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 *
	 * @return list<array<string, mixed>>
	 */
	public function filter_packages( array $packages ): array {
		if ( ! $this->is_runtime_active() ) {
			return $packages;
		}

		$built = [];

		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			foreach ( $this->split_package( $package ) as $split ) {
				$built[] = $split;
			}
		}

		return $this->assign_customer_labels( $built );
	}

	/**
	 * Split one WooCommerce package into managed delivery groups + residual.
	 *
	 * @param array<string, mixed> $package
	 *
	 * @return list<array<string, mixed>>
	 */
	public function split_package( array $package ): array {
		$contents = is_array( $package['contents'] ?? null ) ? $package['contents'] : [];

		if ( [] === $contents ) {
			return [ $package ];
		}

		/** @var array<string, array<string, mixed>> $groups */
		$groups = [];
		$residual = [];

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$key = (string) $cart_item_key;

			if ( ! $this->is_managed_cart_item( $cart_item ) ) {
				$residual[ $key ] = $cart_item;
				continue;
			}

			$group_id = DeliveryGroupIdentity::fromCartItem( $cart_item );

			if ( null === $group_id ) {
				$residual[ $key ] = $cart_item;
				continue;
			}

			if ( ! isset( $groups[ $group_id ] ) ) {
				$groups[ $group_id ] = [];
			}

			$groups[ $group_id ][ $key ] = $cart_item;
		}

		if ( [] === $groups ) {
			return [ $package ];
		}

		$split = [];

		foreach ( $groups as $group_id => $group_contents ) {
			$split[] = $this->build_managed_package( $package, $group_contents, $group_id );
		}

		if ( [] !== $residual ) {
			$split[] = $this->build_residual_package( $package, $residual );
		}

		return $split;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function is_managed_cart_item( array $cart_item ): bool {
		if ( isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]
			);

			return null !== $intent;
		}

		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			return false;
		}

		return $this->cart_capture->should_apply_capture_to_line( $product_id, $variation_id );
	}

	/**
	 * @param array<string, mixed>              $source
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>
	 */
	private function build_managed_package( array $source, array $contents, string $group_id ): array {
		$package = $this->clone_package_shell( $source, $contents );
		$intent  = $this->first_intent( $contents );
		$choice  = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) : '';
		$availability = is_array( $intent ) ? sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) ) : '';
		$offer_id = is_array( $intent ) && isset( $intent['delivery_offer_id'] )
			? (int) $intent['delivery_offer_id']
			: 0;
		$is_pickup = DeliveryGroupIdentity::is_pickup_group( $group_id )
			|| FulfilmentChoice::StorePickup->value === $choice;

		$summary = $this->first_summary( $contents );
		$offer_label = is_array( $summary )
			? trim( (string) ( $summary['delivery_offer_public_label'] ?? '' ) )
			: '';

		$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = [
			'managed'                 => true,
			'group_id'                => $group_id,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id > 0 ? $offer_id : null,
			'is_pickup'               => $is_pickup,
			'offer_public_label'      => '' !== $offer_label ? $offer_label : null,
			'display_index'           => null,
			'rate_label'              => $is_pickup
				? __( 'Store pickup', 'cetech-woocommerce-delivery-engine' )
				: SelectedOfferShippingMethodLabel::default_delivery_label(),
		];

		return $package;
	}

	/**
	 * @param array<string, mixed>                $source
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>
	 */
	private function build_residual_package( array $source, array $contents ): array {
		$package = $this->clone_package_shell( $source, $contents );
		unset( $package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] );

		return $package;
	}

	/**
	 * @param array<string, mixed>                $source
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>
	 */
	private function clone_package_shell( array $source, array $contents ): array {
		$package             = $source;
		$package['contents'] = $contents;
		$package['contents_cost'] = $this->contents_cost( $contents );

		return $package;
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	private function contents_cost( array $contents ): float {
		$total = 0.0;

		foreach ( $contents as $cart_item ) {
			$line_total = $cart_item['line_total'] ?? null;

			if ( is_numeric( $line_total ) ) {
				$total += (float) $line_total;
			}
		}

		return $total;
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>|null
	 */
	private function first_intent( array $contents ): ?array {
		foreach ( $contents as $cart_item ) {
			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);

			if ( null !== $intent ) {
				return $intent;
			}
		}

		return null;
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, string|null>|null
	 */
	private function first_summary( array $contents ): ?array {
		foreach ( $contents as $cart_item ) {
			$summary = CartDeliverySelectionSessionData::normalizeSummary(
				$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
			);

			if ( null !== $summary ) {
				return $summary;
			}
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 *
	 * @return list<array<string, mixed>>
	 */
	private function assign_customer_labels( array $packages ): array {
		$managed_count = 0;

		foreach ( $packages as $package ) {
			if ( DeliveryGroupIdentity::is_managed_package( $package ) ) {
				++$managed_count;
			}
		}

		if ( $managed_count <= 1 ) {
			return $packages;
		}

		$delivery_index = 0;
		$pickup_index   = 0;

		foreach ( $packages as $index => $package ) {
			$meta = DeliveryGroupIdentity::package_meta( $package );

			if ( null === $meta ) {
				continue;
			}

			$is_pickup = ! empty( $meta['is_pickup'] );

			if ( $is_pickup ) {
				++$pickup_index;
				$label = sprintf(
					/* translators: %d: pickup group number */
					__( 'Store pickup %d', 'cetech-woocommerce-delivery-engine' ),
					$pickup_index
				);
				$display_index = $pickup_index;
			} else {
				++$delivery_index;
				$label = sprintf(
					/* translators: %d: delivery group number */
					__( 'Delivery %d', 'cetech-woocommerce-delivery-engine' ),
					$delivery_index
				);
				$display_index = $delivery_index;
			}

			$meta['display_index'] = $display_index;
			$meta['rate_label']    = $label;
			$packages[ $index ][ DeliveryGroupIdentity::PACKAGE_META_KEY ] = $meta;
		}

		return $packages;
	}
}
