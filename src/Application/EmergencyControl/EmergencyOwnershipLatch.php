<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;

/** Server-only, request-local evidence captured before cart reconciliation. */
final class EmergencyOwnershipLatch implements \JsonSerializable {
	/** @var array<string,array{identity:?array,context:?string,item:?\WC_Order_Item_Product}> */
	private array $owned = [];
	private bool $overflow = false;
	private OrderDeliverySnapshotReader $reader;

	public function __construct( ?OrderDeliverySnapshotReader $reader = null ) {
		$this->reader = $reader ?? new OrderDeliverySnapshotReader();
	}

	public function capture_line( string $key, array $line, EmergencyOwnership $ownership ): void {
		if ( EmergencyOwnership::Unmanaged === $ownership || isset( $this->owned[ $key ] ) ) {
			return;
		}
		if ( '' === $key || strlen( $key ) > 200 || count( $this->owned ) >= EmergencyCheckoutFacts::MAX_LINES ) {
			$this->overflow = true;
			return;
		}
		$context_hash = EmergencyCheckoutFacts::bounded_hash( null );
		if ( array_key_exists( CustomerCartContext::CART_KEY, $line ) ) {
			$context_hash = $this->context_hash( $line[ CustomerCartContext::CART_KEY ] );
		}
		$this->owned[ $key ] = [ 'identity' => EmergencyCheckoutFacts::line_identity( $line ), 'context' => $context_hash, 'item' => null ];
	}

	public function bind_order_line( string $key, \WC_Order_Item_Product $item ): void {
		if ( isset( $this->owned[ $key ] ) ) {
			$this->owned[ $key ]['item'] = $item;
		}
	}

	public function has_possible_ownership(): bool {
		return $this->overflow || [] !== $this->owned;
	}

	public function contains_line( string $key ): bool {
		return $this->overflow || isset( $this->owned[ $key ] );
	}

	/** A verified server remove-cart-item hook may forget a removed draft line. */
	public function forget_line( string $key ): void {
		unset( $this->owned[ $key ] );
	}

	public function matches_order( \WC_Order $order ): bool {
		if ( $this->overflow ) {
			return false;
		}
		$items = $order->get_items( 'line_item' );
		if ( count( $items ) > EmergencyCheckoutFacts::MAX_LINES ) {
			return false;
		}
		$used = [];
		foreach ( $this->owned as $entry ) {
			if ( null === $entry['identity'] || null === $entry['context'] ) {
				return false;
			}
			$matched = false;
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || isset( $used[ spl_object_id( $item ) ] ) ) {
					continue;
				}
				if ( null !== $entry['item'] && $entry['item'] !== $item ) {
					continue;
				}
				$identity = [ 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id() > 0 ? $item->get_variation_id() : null, 'quantity' => EmergencyCheckoutFacts::quantity( $item->get_quantity() ) ];
				if ( $identity === $entry['identity'] ) {
					$read = $this->reader->read_line( $item );
					$context_hash = EmergencyCheckoutFacts::bounded_hash( null );
					if ( null !== $read->snapshot && OrderDeliverySnapshot::VERSION_V2 === $read->snapshot->snapshot_version ) {
						$snapshot = $read->snapshot;
						$context_hash = $this->context_hash( [ 'contract_version' => $snapshot->customer_context_version, 'fulfilment_choice' => $snapshot->fulfilment_choice, 'delivery_offer_id' => $snapshot->delivery_offer_id, 'pickup_location_id' => $snapshot->pickup_location_id, 'matching_location' => $snapshot->matching_location, 'delivery_address' => $snapshot->delivery_address, 'matching_identity' => $snapshot->matching_identity, 'delivery_location_identity' => $snapshot->delivery_location_identity ] );
					}
					if ( null === $context_hash || $context_hash !== $entry['context'] ) {
						continue;
					}
					$used[ spl_object_id( $item ) ] = true;
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
	}

	public function jsonSerialize(): never {
		throw new \LogicException( 'Request ownership evidence is private.' );
	}

	private function context_hash( mixed $raw ): ?string {
		try {
			if ( ! is_array( $raw ) || null === EmergencyCheckoutFacts::bounded_hash( $raw ) || CustomerCartContext::CONTRACT_VERSION !== ( $raw['contract_version'] ?? null )
				|| ! in_array( $raw['fulfilment_choice'] ?? null, [ 'delivery', 'store_pickup' ], true ) ) {
				return null;
			}
			foreach ( [ 'delivery_offer_id', 'pickup_location_id' ] as $key ) {
				if ( isset( $raw[ $key ] ) && ( ! is_int( $raw[ $key ] ) || $raw[ $key ] < 1 ) ) {
					return null;
				}
			}
			$context = CustomerCartContext::fromArray( $raw );
			if ( null === $context || $context->matching_identity !== ( $raw['matching_identity'] ?? null ) || $context->delivery_location_identity !== ( $raw['delivery_location_identity'] ?? null ) ) {
				return null;
			}
			return EmergencyCheckoutFacts::bounded_hash( [ $context->contract_version, $context->fulfilment_choice, $context->delivery_offer_id, $context->pickup_location_id, $context->matching_identity, $context->delivery_location_identity ] );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
