<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/** Reads ownership without consulting selector, capture, ECR or shipping flags. */
final class EmergencyOwnershipClassifier {
	private ?\Closure $refresh;

	/** @param list<ProductDeliveryConfigurationSourceInterface> $authoritative_sources */
	public function __construct(
		private readonly array $authoritative_sources,
		private readonly ?VariationRelationshipInspectorInterface $variation_inspector = null,
		private readonly EmergencyConfigurationOwnershipProbeInterface|ScopedConfigurationRepositoryInterface|null $scopes = null,
		?callable $refresh = null
	) {
		foreach ( $authoritative_sources as $source ) {
			if ( ! $source instanceof ProductDeliveryConfigurationSourceInterface ) {
				throw new \InvalidArgumentException( 'Invalid ownership configuration source.' );
			}
		}
		$this->refresh = null === $refresh ? null : \Closure::fromCallable( $refresh );
	}

	public function product( int $product_id, ?int $variation_id = null ): EmergencyOwnership {
		try {
			if ( ! $this->valid_product( $product_id, $variation_id ) ) {
				return EmergencyOwnership::Unresolved;
			}
			if ( null !== $this->refresh ) {
				( $this->refresh )();
			}
			$scoped = $this->scoped_ownership( $product_id, $variation_id );
			if ( EmergencyOwnership::Managed === $scoped || EmergencyOwnership::Unresolved === $scoped ) {
				return $scoped;
			}
			$checked = null !== $scoped;
			$failed = false;
			$type = null === $variation_id ? 'product' : 'variation';
			$id = $variation_id ?? $product_id;
			foreach ( $this->authoritative_sources as $source ) {
				$runtime = $source->resolve( $type, $id );
				// Exact authored absence, rather than an ECR adapter error, permits native commerce.
				if ( EmergencyOwnership::Unmanaged === $scoped && RuntimeConfigurationSource::ECR === $runtime->source ) {
					continue;
				}
				$result = $runtime->result;
				if ( $result->input_target_type !== $type || $result->input_target_id !== $id || '1' !== $result->contract_version ) {
					$failed = true;
					continue;
				}
				if ( [] !== $result->matched_rules || [] !== $result->chosen_rules || [] !== $result->skipped_rules ) {
					return EmergencyOwnership::Managed;
				}
				if ( ! $result->success || null !== $result->error ) {
					$failed = true;
				} else {
					$checked = true;
				}
			}
			return $checked && ! $failed ? EmergencyOwnership::Unmanaged : EmergencyOwnership::Unresolved;
		} catch ( \Throwable ) {
			return EmergencyOwnership::Unresolved;
		}
	}

	public function line( string $key, array $line ): EmergencyOwnership {
		$identity = EmergencyCheckoutFacts::line_identity( $line );
		if ( '' === $key || strlen( $key ) > 200 || null === $identity ) {
			return EmergencyOwnership::Unresolved;
		}
		if ( isset( $line['data'] ) && ( ! $line['data'] instanceof \WC_Product || $line['data']->get_id() !== ( $identity['variation_id'] ?? $identity['product_id'] ) ) ) {
			return EmergencyOwnership::Unresolved;
		}
		if ( ! $this->valid_product( $identity['product_id'], $identity['variation_id'] ) ) {
			return EmergencyOwnership::Unresolved;
		}
		if ( EmergencyCheckoutFacts::has_line_evidence( $line ) ) {
			return EmergencyOwnership::Managed;
		}
		return $this->product( $identity['product_id'], $identity['variation_id'] );
	}

	public function cart( array $lines, array $packages = [] ): EmergencyOwnership {
		if ( count( $lines ) > EmergencyCheckoutFacts::MAX_LINES || count( $packages ) > EmergencyCheckoutFacts::MAX_PACKAGES ) {
			return EmergencyOwnership::Unresolved;
		}
		$owned = false;
		foreach ( $lines as $key => $line ) {
			$ownership = is_array( $line ) ? $this->line( (string) $key, $line ) : EmergencyOwnership::Unresolved;
			if ( EmergencyOwnership::Unresolved === $ownership ) {
				return $ownership;
			}
			$owned = $owned || EmergencyOwnership::Managed === $ownership;
		}
		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) ) {
				return EmergencyOwnership::Unresolved;
			}
			if ( array_key_exists( DeliveryGroupIdentity::PACKAGE_META_KEY, $package ) ) {
				$owned = true; // Malformed owned metadata still cannot be a residual package.
			}
		}
		return $owned ? EmergencyOwnership::Managed : EmergencyOwnership::Unmanaged;
	}

	public function order( \WC_Order $order ): EmergencyOwnership {
		try {
			$items = $order->get_items( 'line_item' );
			$shipping = $order->get_items( 'shipping' );
			if ( count( $items ) > EmergencyCheckoutFacts::MAX_LINES || count( $shipping ) > EmergencyCheckoutFacts::MAX_PACKAGES ) {
				return EmergencyOwnership::Unresolved;
			}
			$owned = self::meta_present( $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) )
				|| self::meta_present( $order->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true ) );
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || null === EmergencyCheckoutFacts::quantity( $item->get_quantity() ) ) {
					return EmergencyOwnership::Unresolved;
				}
				$variation = $item->get_variation_id() > 0 ? $item->get_variation_id() : null;
				$ownership = $this->product( $item->get_product_id(), $variation );
				if ( EmergencyOwnership::Unresolved === $ownership ) {
					return $ownership;
				}
				$owned = $owned || EmergencyOwnership::Managed === $ownership
					|| self::meta_present( $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) )
					|| self::meta_present( $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true ) )
					|| self::meta_present( $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
			}
			foreach ( $shipping as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) || ! method_exists( $item, 'get_meta' ) ) {
					return EmergencyOwnership::Unresolved;
				}
				$owned = $owned || SelectedOfferShippingMethod::METHOD_ID === $item->get_method_id()
					|| self::meta_present( $item->get_meta( 'cetech_de_group_id', true ) );
			}
			return $owned ? EmergencyOwnership::Managed : EmergencyOwnership::Unmanaged;
		} catch ( \Throwable ) {
			return EmergencyOwnership::Unresolved;
		}
	}

	private function valid_product( int $product_id, ?int $variation_id ): bool {
		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || $product->get_id() !== $product_id || $product->is_type( 'variation' ) ) {
			return false;
		}
		if ( null === $variation_id ) {
			return true;
		}
		$variation = wc_get_product( $variation_id );
		return $variation_id > 0 && $variation instanceof \WC_Product && $variation->get_id() === $variation_id
			&& $variation->is_type( 'variation' ) && $variation->get_parent_id() === $product_id
			&& ( null === $this->variation_inspector || $this->variation_inspector->belongs_to_parent( $variation_id, $product_id ) );
	}

	private function scoped_ownership( int $product_id, ?int $variation_id ): ?EmergencyOwnership {
		if ( $this->scopes instanceof EmergencyConfigurationOwnershipProbeInterface ) {
			return $this->scopes->ownership( $product_id, $variation_id );
		}
		if ( null === $this->scopes ) {
			return null;
		}
		$product = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
		$variation = null === $variation_id ? [] : $this->scopes->findByScope( ConfigurationScopeType::Variation, $variation_id );
		if ( count( $product ) + count( $variation ) > 200 ) {
			return EmergencyOwnership::Unresolved;
		}
		foreach ( array_merge( $product, $variation ) as $scope ) {
			if ( ! $scope instanceof ScopedConfiguration ) {
				return EmergencyOwnership::Unresolved;
			}
		}
		$global = $this->scopes->getGlobalConfiguration();
		return [] !== $product || [] !== $variation || ( null !== $global && ( [] !== $global->scalars || [] !== $global->collections ) )
			? EmergencyOwnership::Managed : EmergencyOwnership::Unmanaged;
	}

	private static function meta_present( mixed $value ): bool {
		return ! in_array( $value, [ '', null ], true );
	}
}
