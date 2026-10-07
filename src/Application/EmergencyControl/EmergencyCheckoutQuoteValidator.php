<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;

/** Fresh quote proof against protected facts. It never reprices or persists an order. */
final class EmergencyCheckoutQuoteValidator implements EmergencyOrderQuoteValidatorInterface {
	private OrderDeliverySnapshotReader $reader;
	private ?\Closure $refresh;

	public function __construct(
		private readonly ProductDeliveryConfigurationSourceInterface $current_source,
		private readonly ProductDeliveryOptionsBuilder $options,
		private readonly PackageDestinationZoneResolverInterface $destinations,
		private readonly RateQuoteEngine $quotes,
		?OrderDeliverySnapshotReader $reader = null,
		?callable $refresh = null
	) {
		$this->reader = $reader ?? new OrderDeliverySnapshotReader();
		$this->refresh = null === $refresh ? null : \Closure::fromCallable( $refresh );
	}

	public function fingerprint( \WC_Order $order ): ?string {
		return EmergencyCheckoutFacts::order_fingerprint( $order );
	}

	public function validate_order( \WC_Order $order, string $route ): bool {
		try {
			if ( ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) || null === $this->fingerprint( $order ) ) {
				return false;
			}
			if ( null !== $this->refresh ) {
				( $this->refresh )();
			}
			$currency = $order->get_currency( 'edit' );
			if ( ! is_string( $currency ) || preg_match( '/\A[A-Z]{3}\z/D', $currency ) !== 1 ) {
				return false;
			}
			$groups = [];
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					return false;
				}
				$variation = $item->get_variation_id() > 0 ? $item->get_variation_id() : null;
				$runtime = $this->current_source->resolve( null === $variation ? 'product' : 'variation', $variation ?? $item->get_product_id() );
				if ( $runtime->result->input_target_type !== ( null === $variation ? 'product' : 'variation' ) || $runtime->result->input_target_id !== ( $variation ?? $item->get_product_id() ) ) {
					return false;
				}
				$read = $this->reader->read_line( $item );
				if ( ! $read->has_meta && $runtime->result->success && [] === $runtime->result->chosen_rules && [] === $runtime->result->matched_rules && [] === $runtime->result->skipped_rules ) {
					continue; // A proven residual native line has no engine price.
				}
				$line = $read->snapshot;
				if ( OrderDeliveryLineReadResult::ERROR_NONE !== $read->error || null === $line || ! $runtime->result->success
					|| $line->product_id !== $item->get_product_id() || $line->variation_id !== $variation
					|| $line->quantity !== EmergencyCheckoutFacts::quantity( $item->get_quantity() ) || $line->currency_code !== $currency ) {
					return false;
				}
				$eligible = false;
				foreach ( $this->options->buildFromResolution( $runtime->result ) as $option ) {
					if ( $option->is_available && $option->fulfilment_availability === $line->fulfilment_availability
						&& $option->fulfilment_choice === $line->fulfilment_choice && $option->delivery_offer_id === $line->delivery_offer_id
						&& ( FulfilmentChoice::StorePickup->value !== $line->fulfilment_choice || $option->pickup_location_id === $line->pickup_location_id ) ) {
						$eligible = true;
						break;
					}
				}
				if ( ! $eligible ) {
					return false;
				}
				$context = $this->context( $line );
				$group_id = $line->delivery_group_id;
				$intent = [ 'fulfilment_availability' => $line->fulfilment_availability, 'fulfilment_choice' => $line->fulfilment_choice, 'delivery_offer_id' => $line->delivery_offer_id ];
				$expected_group = null === $context ? DeliveryGroupIdentity::fromIntent( $intent ) : DeliveryGroupIdentity::forHistorical( $intent, $context );
				if ( null === $expected_group || ( null !== $group_id && $group_id !== $expected_group ) ) {
					return false;
				}
				$group_id = $expected_group;
				$destination = $context?->toWcPackageDestination() ?? $this->order_destination( $order );
				$dimensions = $runtime->quote_dimensions_for( $line->fulfilment_availability );
				if ( FulfilmentChoice::StorePickup->value === $line->fulfilment_choice ) {
					if ( OrderDeliverySnapshot::QUOTE_STATUS_SELECTION_ONLY !== $line->quote_status || null !== $line->quoted_amount ) {
						return false;
					}
				} elseif ( OrderDeliverySnapshot::QUOTE_STATUS_QUOTED !== $line->quote_status || null === self::money_units( $line->quoted_amount )
					|| self::money_units( $this->quote_group( $line, $line->quantity, $destination, $currency, $dimensions ) ) !== self::money_units( $line->quoted_amount ) ) {
					return false;
				}
				$dimension_hash = EmergencyCheckoutFacts::bounded_hash( [ $line->fulfilment_availability, $line->fulfilment_choice, $line->delivery_offer_id, $line->pickup_location_id, $destination, $dimensions ] );
				if ( null === $dimension_hash || ( isset( $groups[ $group_id ] ) && $groups[ $group_id ]['dimensions'] !== $dimension_hash ) ) {
					return false;
				}
				if ( ! isset( $groups[ $group_id ] ) ) {
					$groups[ $group_id ] = [ 'line' => $line, 'quantity' => 0, 'destination' => $destination, 'dimensions' => $dimension_hash, 'quote_dimensions' => $dimensions ];
				}
				if ( $groups[ $group_id ]['quantity'] > 2147483647 - $line->quantity ) {
					return false;
				}
				$groups[ $group_id ]['quantity'] += $line->quantity;
			}
			if ( [] === $groups || count( $groups ) > EmergencyCheckoutFacts::MAX_PACKAGES ) {
				return false;
			}
			$package_read = $this->reader->read_package( $order );
			$package = $package_read->snapshot;
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package_read->error || null === $package
				|| $package->currency_code !== $currency || ! in_array( $package->quote_status, [ 'success', 'quoted' ], true ) ) {
				return false;
			}
			$stored_groups = [];
			foreach ( $package->groups as $stored ) {
				$id = $stored->group_id;
				// The original V1 package writer used this fixed fallback when no group metadata existed.
				if ( ! isset( $groups[ $id ] ) && OrderDeliverySnapshot::VERSION === $package->snapshot_version && 1 === count( $groups ) && 1 === count( $package->groups ) && 'shipping-line-1' === $id ) {
					$id = array_key_first( $groups );
				}
				if ( ! isset( $groups[ $id ] ) || isset( $stored_groups[ $id ] ) ) {
					return false;
				}
				$stored_groups[ $id ] = $stored;
			}
			$shipping_groups = [];
			$all_shipping = 0;
			$all_shipping_taxes = [];
			foreach ( $order->get_items( 'shipping' ) as $shipping ) {
				$physical_units = self::money_units( $shipping->get_total( 'edit' ) );
				$physical_tax = self::tax_decimal( $shipping->get_total_tax( 'edit' ) );
				if ( null === $physical_units || null === $physical_tax || $all_shipping > PHP_INT_MAX - $physical_units ) {
					return false;
				}
				$all_shipping += $physical_units;
				$all_shipping_taxes[] = $physical_tax;
				if ( SelectedOfferShippingMethod::METHOD_ID !== $shipping->get_method_id() ) {
					continue;
				}
				$id = $shipping->get_meta( 'cetech_de_group_id', true );
				if ( ! is_string( $id ) || '' === $id ) {
					if ( count( $groups ) !== 1 ) {
						return false;
					}
					$id = array_key_first( $groups );
				}
				$id = DeliveryGroupIdentity::stripRuntimeSuffix( $id ) ?? $id;
				if ( isset( $shipping_groups[ $id ] ) || ! isset( $groups[ $id ] ) ) {
					return false;
				}
				$shipping_groups[ $id ] = $shipping;
			}
			if ( count( $shipping_groups ) !== count( $groups ) || ( [] !== $stored_groups && count( $stored_groups ) !== count( $groups ) ) ) {
				return false;
			}
			$total = 0;
			foreach ( $groups as $id => $group ) {
				$line = $group['line'];
				$amount = $this->quote_group( $line, $group['quantity'], $group['destination'], $currency, $group['quote_dimensions'] );
				$shipping = $shipping_groups[ $id ];
				$units = self::money_units( $amount );
				if ( null === $units || $units !== self::money_units( $shipping->get_total( 'edit' ) ) || ! $this->tax_matches( $order, $shipping, $amount ) ) {
					return false;
				}
				if ( isset( $stored_groups[ $id ] ) && ( $units !== self::money_units( $stored_groups[ $id ]->package_total_delivery_amount ) || $stored_groups[ $id ]->is_pickup !== ( FulfilmentChoice::StorePickup->value === $line->fulfilment_choice ) || $stored_groups[ $id ]->fulfilment_choice !== $line->fulfilment_choice ) ) {
					return false;
				}
				if ( $total > PHP_INT_MAX - $units ) {
					return false;
				}
				$total += $units;
			}
			return $total === self::money_units( $package->package_total_delivery_amount )
				&& $all_shipping === self::money_units( $order->get_shipping_total( 'edit' ) )
				&& self::tax_decimal( wc_format_decimal( array_sum( $all_shipping_taxes ) ) ) === self::tax_decimal( $order->get_shipping_tax( 'edit' ) );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function quote_group( OrderDeliveryLineSnapshot $line, int $quantity, array $destination, string $currency, array $dimensions ): ?string {
		if ( FulfilmentChoice::StorePickup->value === $line->fulfilment_choice ) {
			return '0.0000';
		}
		if ( null === $line->delivery_offer_id ) {
			return null;
		}
		$zones = $this->destinations->resolve_zone_ids( $destination );
		if ( count( $zones ) > 200 ) {
			return null;
		}
		foreach ( $zones as $zone ) {
			if ( ! is_int( $zone ) || $zone < 1 ) {
				return null;
			}
			$request = new RateQuoteRequest( $line->delivery_offer_id, $zone, $quantity, new CurrencyCode( $currency ), $line->product_id, $line->variation_id, $line->rule_id, $dimensions['logistics_profile_id'], $dimensions['supplier_id'], $dimensions['origin_id'], $line->fulfilment_availability, $line->fulfilment_choice );
			$result = $this->quotes->quote( $request );
			if ( $result->success && null !== $result->amount ) {
				return $result->amount->amount();
			}
			if ( RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD !== $result->error_code ) {
				return null;
			}
		}
		return null;
	}

	private function context( OrderDeliveryLineSnapshot $line ): ?CustomerCartContext {
		if ( OrderDeliverySnapshot::VERSION_V2 !== $line->snapshot_version ) {
			return null;
		}
		$context = CustomerCartContext::fromArray( [ 'contract_version' => $line->customer_context_version, 'fulfilment_choice' => $line->fulfilment_choice, 'delivery_offer_id' => $line->delivery_offer_id, 'pickup_location_id' => $line->pickup_location_id, 'matching_location' => $line->matching_location, 'delivery_address' => $line->delivery_address ] );
		if ( null === $context || ( $context->isDelivery() && ! $context->hasCompleteDeliveryAddress() ) || ( $context->isPickup() && ! $context->isPickupComplete() ) || $context->matching_identity !== $line->matching_identity || $context->delivery_location_identity !== $line->delivery_location_identity ) {
			throw new \UnexpectedValueException( 'Unavailable stored delivery context.' );
		}
		return $context;
	}

	private function order_destination( \WC_Order $order ): array {
		return [ 'country' => $order->get_shipping_country( 'edit' ), 'state' => $order->get_shipping_state( 'edit' ), 'city' => $order->get_shipping_city( 'edit' ), 'postcode' => $order->get_shipping_postcode( 'edit' ), 'address' => $order->get_shipping_address_1( 'edit' ), 'address_2' => $order->get_shipping_address_2( 'edit' ) ];
	}

	/** Strict four-decimal integer arithmetic; no binary-float price comparison. */
	public static function money_units( mixed $amount ): ?int {
		if ( ! is_string( $amount ) || preg_match( '/\A(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,4}))?\z/D', $amount, $matches ) !== 1 ) {
			return null;
		}
		return (int) $matches[1] * 10000 + (int) str_pad( $matches[2] ?? '', 4, '0' );
	}

	private function tax_matches( \WC_Order $order, object $shipping, string $amount ): bool {
		$stored_total = self::tax_decimal( $shipping->get_total_tax( 'edit' ) );
		$taxes = $shipping->get_taxes( 'edit' );
		if ( null === $stored_total || ! is_array( $taxes ) || ! isset( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return false;
		}
		$stored = [];
		foreach ( $taxes['total'] as $id => $tax ) {
			$decimal = self::tax_decimal( $tax );
			if ( null === $decimal ) {
				return false;
			}
			$stored[ (string) $id ] = $decimal;
		}
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return '0' === $stored_total && [] === array_filter( $stored, static fn ( string $value ): bool => '0' !== $value );
		}
		if ( ! class_exists( '\WC_Tax' ) || ! method_exists( $shipping, 'get_tax_status' ) || ! method_exists( $order, 'get_taxable_location' ) || ! method_exists( $order, 'get_items_tax_classes' ) ) {
			return false;
		}
		if ( 'taxable' !== $shipping->get_tax_status( 'edit' ) ) {
			return '0' === $stored_total && [] === $stored;
		}
		$vat_exempt = 'yes' === $order->get_meta( 'is_vat_exempt', true );
		if ( function_exists( 'apply_filters' ) ) {
			$vat_exempt = apply_filters( 'woocommerce_order_is_vat_exempt', $vat_exempt, $order );
		}
		if ( true === $vat_exempt ) {
			return '0' === $stored_total && [] === $stored;
		}
		$class = get_option( 'woocommerce_shipping_tax_class', 'inherit' );
		if ( ! is_string( $class ) ) {
			return false;
		}
		if ( 'inherit' === $class ) {
			$classes = array_intersect( array_merge( [ '' ], \WC_Tax::get_tax_class_slugs() ), $order->get_items_tax_classes() );
			$class = count( $classes ) > 0 ? current( $classes ) : false;
			if ( [] === $order->get_items( 'line_item' ) ) {
				$class = '';
			}
		}
		if ( false === $class ) {
			return '0' === $stored_total && [] === $stored;
		}
		$location = $order->get_taxable_location();
		if ( ! is_array( $location ) ) {
			return false;
		}
		$rates = \WC_Tax::find_shipping_rates( array_merge( $location, [ 'tax_class' => $class ] ) );
		$fresh = \WC_Tax::calc_tax( (float) $amount, $rates, false );
		$expected = [];
		foreach ( $fresh as $id => $tax ) {
			$decimal = self::tax_decimal( wc_format_decimal( $tax ) );
			if ( null === $decimal ) {
				return false;
			}
			$expected[ (string) $id ] = $decimal;
		}
		$totals = array_values( $expected );
		if ( 'yes' !== get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
			if ( ! function_exists( 'wc_round_tax_total' ) ) {
				return false;
			}
			$totals = array_map( 'wc_round_tax_total', $totals );
		}
		$expected_total = self::tax_decimal( wc_format_decimal( array_sum( $totals ) ) );
		ksort( $stored );
		ksort( $expected );
		return null !== $expected_total && $stored_total === $expected_total && $stored === $expected;
	}

	private static function tax_decimal( mixed $amount ): ?string {
		if ( ! is_string( $amount ) || preg_match( '/\A(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,12}))?\z/D', $amount, $parts ) !== 1 ) {
			return null;
		}
		$fraction = rtrim( $parts[2] ?? '', '0' );
		return $parts[1] . ( '' !== $fraction ? '.' . $fraction : '' );
	}
}
