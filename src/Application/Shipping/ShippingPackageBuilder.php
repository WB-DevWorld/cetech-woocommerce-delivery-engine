<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
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
		$pickup_location = is_array( $summary )
			? trim( (string) ( $summary['pickup_location_label'] ?? '' ) )
			: '';
		$pickup_address = is_array( $summary )
			? PickupLocationAddressFormatter::format( (string) ( $summary['pickup_address'] ?? '' ) )
			: '';
		$estimate = is_array( $summary )
			? trim( (string) ( $summary['estimate_text'] ?? '' ) )
			: '';
		$instructions = is_array( $summary )
			? trim( (string) ( $summary['pickup_instructions'] ?? $summary['pickup_public_instructions'] ?? '' ) )
			: '';

		$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = [
			'managed'                 => true,
			'group_id'                => $group_id,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id > 0 ? $offer_id : null,
			'is_pickup'               => $is_pickup,
			'offer_public_label'      => '' !== $offer_label ? $offer_label : null,
			'pickup_location_label'   => '' !== $pickup_location ? $pickup_location : null,
			'pickup_address'          => '' !== $pickup_address ? $pickup_address : null,
			'estimate_text'           => '' !== $estimate ? $estimate : null,
			'pickup_instructions'     => '' !== $instructions ? $instructions : null,
			'display_index'           => null,
			'locality_label'          => $this->first_locality_label( $contents ),
			// Stage 13F: genuine WC shipping rate label uses the selected public option label.
			'rate_label'              => $this->customer_rate_label( $is_pickup, $offer_label, null, $this->first_locality_label( $contents ), $pickup_location ),
		];

		$context = $this->first_customer_context( $contents );
		if ( $context instanceof CustomerCartContext ) {
			$package['destination'] = $context->toWcPackageDestination();
		}

		return $package;
	}

	/**
	 * Customer-facing WC shipping rate label for a managed package.
	 *
	 * Preserves method ID / grouping / snapshots; presentation only.
	 */
	private function customer_rate_label(
		bool $is_pickup,
		string $offer_label,
		?int $display_index,
		string $locality = '',
		string $pickup_location = ''
	): string {
		if ( $is_pickup ) {
			$base = '' !== $pickup_location
				? sprintf(
					/* translators: %s: pickup location name */
					__( 'Store Pickup — %s', 'cetech-woocommerce-delivery-engine' ),
					$pickup_location
				)
				: __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );

			if ( null !== $display_index && $display_index > 0 ) {
				return sprintf(
					/* translators: 1: pickup label, 2: package number */
					__( '%1$s (%2$d)', 'cetech-woocommerce-delivery-engine' ),
					$base,
					$display_index
				);
			}

			return $base;
		}

		if ( '' !== $locality ) {
			$base = sprintf(
				/* translators: %s: city or locality */
				__( 'Delivery — %s', 'cetech-woocommerce-delivery-engine' ),
				$locality
			);
		} elseif ( '' !== $offer_label ) {
			$base = $offer_label;
		} else {
			$base = SelectedOfferShippingMethodLabel::default_delivery_label();
		}

		if ( null !== $display_index && $display_index > 0 ) {
			return sprintf(
				/* translators: 1: delivery label, 2: package number */
				__( '%1$s (%2$d)', 'cetech-woocommerce-delivery-engine' ),
				$base,
				$display_index
			);
		}

		return $base;
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
	 * @param array<string, array<string, mixed>> $contents
	 */
	private function first_customer_context( array $contents ): ?CustomerCartContext {
		foreach ( $contents as $cart_item ) {
			$context = CustomerCartContext::fromCartItem( $cart_item );

			if ( $context instanceof CustomerCartContext ) {
				return $context;
			}
		}

		return null;
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	private function first_locality_label( array $contents ): string {
		$context = $this->first_customer_context( $contents );

		return $context instanceof CustomerCartContext ? $context->publicLocalityLabel() : '';
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
			$offer_label = trim( (string) ( $meta['offer_public_label'] ?? '' ) );

			if ( $is_pickup ) {
				++$pickup_index;
				$display_index = $pickup_index;
			} else {
				++$delivery_index;
				$display_index = $delivery_index;
			}

			$meta['display_index'] = $display_index;
			$meta['rate_label']    = $this->customer_rate_label(
				$is_pickup,
				$offer_label,
				$display_index,
				trim( (string) ( $meta['locality_label'] ?? '' ) ),
				trim( (string) ( $meta['pickup_location_label'] ?? '' ) )
			);
			$packages[ $index ][ DeliveryGroupIdentity::PACKAGE_META_KEY ] = $meta;
		}

		return $packages;
	}
}
