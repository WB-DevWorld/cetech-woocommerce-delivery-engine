<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;

/**
 * Builds shipment plans from historical order snapshots only.
 *
 * Never calls the current resolver, Rate Cards, product configuration, or grouping engine.
 */
final class HistoricalShipmentPlanner {

	private const SELECTED_OFFER_METHOD_ID = 'delivery_engine_selected_offer';

	public function plan( HistoricalOrderShipmentContext $context ): ShipmentPlanResult {
		if ( $context->package_unreadable ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
		}

		foreach ( $context->lines as $line ) {
			if ( $line->snapshot_unreadable ) {
				return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
			}
		}

		$groups = $this->delivery_groups( $context );

		if ( null === $groups ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
		}

		$pickup_skipped = 0;
		$plans          = [];

		foreach ( $groups as $group_id => $group ) {
			if ( $group['is_pickup'] ) {
				++$pickup_skipped;
				continue;
			}

			$plan = $this->plan_delivery_group( $context, $group_id, $group );

			if ( $plan instanceof ShipmentPlanResult ) {
				return $plan;
			}

			$plans[] = $plan;
		}

		return ShipmentPlanResult::success( $plans, $pickup_skipped );
	}

	/**
	 * @return array<string, array{group_id: string, is_pickup: bool, display_index: int, package_amount: ?string, fulfilment_choice: string}>|null
	 */
	private function delivery_groups( HistoricalOrderShipmentContext $context ): ?array {
		$groups = [];

		if ( null !== $context->package ) {
			foreach ( $context->package->groups as $group ) {
				if ( ! $group instanceof OrderDeliveryGroupSnapshot || '' === $group->group_id ) {
					return null;
				}

				$groups[ $group->group_id ] = [
					'group_id'           => $group->group_id,
					'is_pickup'          => $group->is_pickup || DeliveryGroupIdentity::is_pickup_group( $group->group_id ),
					'display_index'      => $group->display_index,
					'package_amount'     => $group->package_total_delivery_amount,
					'fulfilment_choice'  => $group->fulfilment_choice,
				];
			}
		}

		foreach ( $context->lines as $line ) {
			if ( ! $line->has_snapshot || null === $line->snapshot ) {
				continue;
			}

			$group_id = (string) ( $line->snapshot->delivery_group_id ?? '' );

			if ( '' === $group_id ) {
				return null;
			}

			if ( isset( $groups[ $group_id ] ) ) {
				continue;
			}

			$is_pickup = DeliveryGroupIdentity::is_pickup_group( $group_id )
				|| FulfilmentChoice::StorePickup->value === $line->snapshot->fulfilment_choice;

			$groups[ $group_id ] = [
				'group_id'          => $group_id,
				'is_pickup'         => $is_pickup,
				'display_index'     => $is_pickup ? 0 : count( $groups ) + 1,
				'package_amount'    => null,
				'fulfilment_choice' => $line->snapshot->fulfilment_choice,
			];
		}

		return $groups;
	}

	/**
	 * @param array{group_id: string, is_pickup: bool, display_index: int, package_amount: ?string, fulfilment_choice: string} $group
	 */
	private function plan_delivery_group(
		HistoricalOrderShipmentContext $context,
		string $group_id,
		array $group
	): ShipmentPlan|ShipmentPlanResult {
		$parsed = DeliveryGroupIdentity::parse( $group_id );

		if ( ! is_array( $parsed ) || $parsed['reselect'] ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
		}

		$availability = $parsed['availability'];
		$choice       = $parsed['choice'];
		$offer_id     = ctype_digit( $parsed['offer_segment'] ) ? (int) $parsed['offer_segment'] : null;

		if ( FulfilmentChoice::Delivery->value !== $choice ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
		}

		$items      = [];
		$label      = null;
		$eta        = null;
		$zone_id    = $context->package?->destination_zone_id;
		$currency   = $context->package?->currency_code ?? '';
		$rate_id    = null;
		$rate_code  = null;
		$rate_ids   = [];
		$rate_codes = [];
		$labels     = [];
		$etas       = [];

		foreach ( $context->lines as $line ) {
			if ( ! $line->has_snapshot || null === $line->snapshot ) {
				continue;
			}

			if ( (string) $line->snapshot->delivery_group_id !== $group_id ) {
				continue;
			}

			if ( $line->order_item_id <= 0 ) {
				return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MissingOrderItem );
			}

			if ( $line->snapshot->fulfilment_availability !== $availability
				|| $line->snapshot->fulfilment_choice !== $choice
			) {
				return ShipmentPlanResult::failure( ShipmentCreationErrorCode::GroupItemMismatch );
			}

			if ( null !== $line->snapshot->delivery_offer_id && $line->snapshot->delivery_offer_id !== $offer_id ) {
				return ShipmentPlanResult::failure( ShipmentCreationErrorCode::GroupItemMismatch );
			}

			$snapshot = $line->snapshot;

			if ( '' === $currency ) {
				$currency = $snapshot->currency_code;
			} elseif ( $snapshot->currency_code !== $currency ) {
				return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
			}

			if ( null === $zone_id ) {
				$zone_id = $snapshot->destination_zone_id;
			}

			if ( null !== $snapshot->delivery_offer_public_label ) {
				$labels[ $snapshot->delivery_offer_public_label ] = true;
			}

			if ( null !== $snapshot->estimate_text ) {
				$etas[ $snapshot->estimate_text ] = true;
			}

			if ( null !== $snapshot->rate_card_id ) {
				$rate_ids[ $snapshot->rate_card_id ] = true;
			}

			if ( null !== $snapshot->rate_card_code ) {
				$rate_codes[ $snapshot->rate_card_code ] = true;
			}

			$items[] = new ShipmentPlanItem(
				$line->order_item_id,
				$context->order_id,
				$snapshot->product_id > 0 ? $snapshot->product_id : null,
				$snapshot->variation_id,
				$snapshot->quantity,
				$line->product_name
			);
		}

		if ( [] === $items ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MissingOrderItem );
		}

		if ( count( $labels ) > 1 || count( $etas ) > 1 ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::MalformedGroupSnapshot );
		}

		$label     = [] !== $labels ? (string) array_key_first( $labels ) : null;
		$eta       = [] !== $etas ? (string) array_key_first( $etas ) : null;
		$rate_id   = 1 === count( $rate_ids ) ? (int) array_key_first( $rate_ids ) : null;
		$rate_code = 1 === count( $rate_codes ) ? (string) array_key_first( $rate_codes ) : null;

		$amount = $this->resolve_paid_amount( $context, $group_id, $group['package_amount'] );

		if ( null === $amount ) {
			return ShipmentPlanResult::failure( ShipmentCreationErrorCode::ShippingAmountMismatch );
		}

		$display = max( 1, $group['display_index'] );
		$number  = sprintf( '%s-D%d', $context->order_number !== '' ? $context->order_number : (string) $context->order_id, $display );

		return new ShipmentPlan(
			$context->order_id,
			$group_id,
			$number,
			$availability,
			$choice,
			$offer_id,
			$label,
			$zone_id,
			strtoupper( $currency ),
			$amount,
			$rate_id,
			$rate_code,
			$eta,
			$items
		);
	}

	private function resolve_paid_amount(
		HistoricalOrderShipmentContext $context,
		string $group_id,
		?string $package_amount
	): ?string {
		$shipping_amount = null;

		foreach ( $context->shipping_lines as $shipping ) {
			if ( $shipping->group_id !== $group_id ) {
				continue;
			}

			if ( '' !== $shipping->method_id && self::SELECTED_OFFER_METHOD_ID !== $shipping->method_id ) {
				continue;
			}

			$shipping_amount = $this->normalize_amount( $shipping->total );
			break;
		}

		$group_amount = $this->normalize_amount( $package_amount );

		if ( null !== $group_amount && null !== $shipping_amount ) {
			return $group_amount === $shipping_amount ? $group_amount : null;
		}

		if ( null !== $group_amount ) {
			return $group_amount;
		}

		return $shipping_amount;
	}

	private function normalize_amount( ?string $amount ): ?string {
		if ( null === $amount || '' === trim( $amount ) ) {
			return null;
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			$formatted = wc_format_decimal( $amount, 4 );

			return is_string( $formatted ) && '' !== $formatted ? $formatted : null;
		}

		if ( ! is_numeric( $amount ) ) {
			return null;
		}

		return number_format( (float) $amount, 4, '.', '' );
	}
}
