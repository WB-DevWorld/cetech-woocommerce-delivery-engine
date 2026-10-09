<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;

/** Server-only, request-local evidence captured before cart reconciliation. */
final class EmergencyOwnershipLatch implements \JsonSerializable {
	/** @var array<string,array{identity:?array,context:?string,site_id:?int,item:?\WC_Order_Item_Product,saved:?array{site_id:int,order_id:int,item_id:int}}> */
	private array $owned = [];
	private bool $overflow = false;
	private OrderDeliverySnapshotReader $reader;
	private ?EmergencyCheckoutLocalBinding $saved_binding = null;

	public function __construct( ?OrderDeliverySnapshotReader $reader = null ) {
		$this->reader = $reader ?? new OrderDeliverySnapshotReader();
	}

	public function capture_line( string $key, array $line, EmergencyOwnership $ownership ): void {
		if ( EmergencyOwnership::Unmanaged === $ownership || isset( $this->owned[ $key ] ) ) {
			return;
		}
		if ( null !== $this->saved_binding || '' === $key || strlen( $key ) > 200 || count( $this->owned ) >= EmergencyCheckoutFacts::MAX_LINES ) {
			$this->overflow = true;
			return;
		}
		$context_hash = EmergencyCheckoutFacts::bounded_hash( null );
		if ( array_key_exists( CustomerCartContext::CART_KEY, $line ) ) {
			$context_hash = $this->context_hash( $line[ CustomerCartContext::CART_KEY ] );
		}
		$site_id = get_current_blog_id();
		$this->owned[ $key ] = [ 'identity' => EmergencyCheckoutFacts::line_identity( $line ), 'context' => $context_hash, 'site_id' => is_int( $site_id ) && $site_id > 0 ? $site_id : null, 'item' => null, 'saved' => null ];
	}

	public function bind_order_line( string $key, \WC_Order_Item_Product $item ): void {
		if ( isset( $this->owned[ $key ] ) ) {
			if ( null !== $this->owned[ $key ]['saved'] && $this->owned[ $key ]['item'] !== $item ) {
				$this->overflow = true;
				return;
			}
			$this->owned[ $key ]['item'] = $item;
		}
	}

	/** Called with Woo's original saved order before Classic reloads it. Never creates persisted facts. */
	public function freeze_saved_order( \WC_Order $order ): void {
		if ( [] === $this->owned || $this->overflow ) {
			return;
		}
		try {
			$site_id = get_current_blog_id(); $order_id = $order->get_id();
			$items = $order->get_items( 'line_item' );
			if ( ! is_int( $site_id ) || $site_id < 1 || ! is_int( $order_id ) || $order_id < 1 || count( $items ) > EmergencyCheckoutFacts::MAX_LINES ) {
				$this->overflow = true; return;
			}
			$coordinates = []; $used = []; $used_ids = [];
			foreach ( $this->owned as $key => $entry ) {
				$item = $entry['item'];
				if ( $entry['site_id'] !== $site_id || null === $item || ! in_array( $item, $items, true ) || isset( $used[ spl_object_id( $item ) ] ) ) {
					$this->overflow = true; return;
				}
				$item_id = $item->get_id(); $owner_id = $item->get_order_id( 'edit' );
				if ( ! is_int( $item_id ) || $item_id < 1 || $owner_id !== $order_id || isset( $used_ids[ $item_id ] ) ) {
					$this->overflow = true; return;
				}
				$coordinates[ $key ] = [ 'site_id' => $site_id, 'order_id' => $order_id, 'item_id' => $item_id ];
				if ( null !== $entry['saved'] && $entry['saved'] !== $coordinates[ $key ] ) {
					$this->overflow = true; return;
				}
				$used[ spl_object_id( $item ) ] = true;
				$used_ids[ $item_id ] = true;
				$this->reader->read_line( $item ); // Prewarm protected native metadata outside the control lock.
			}
			$shipping = $order->get_items( 'shipping' );
			// Prewarm the native tax class and empty/populated group before raw freeze.
			$taxes = class_exists( 'WC_Order_Item_Tax' ) ? $order->get_items( 'tax' ) : [];
			foreach ( [ ...$shipping, ...$taxes ] as $item ) { if ( method_exists( $item, 'get_meta_data' ) ) { $item->get_meta_data(); } }
			$order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
			if ( null !== $this->saved_binding ) {
				if ( ! $this->saved_binding->unchanged() ) { $this->overflow = true; }
				return;
			}
			$binding = EmergencyCheckoutLocalBinding::capture( $order );
			if ( null === $binding ) { $this->overflow = true; return; }
			foreach ( $coordinates as $key => $coordinate ) { $this->owned[ $key ]['saved'] = $coordinate; }
			$this->saved_binding = $binding;
		} catch ( \Throwable ) {
			$this->overflow = true;
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
		if ( $this->overflow || null !== $this->saved_binding && ! $this->saved_binding->unchanged() ) {
			return false;
		}
		$items = $order->get_items( 'line_item' );
		if ( count( $items ) > EmergencyCheckoutFacts::MAX_LINES ) {
			return false;
		}
		$used = [];
		foreach ( $this->owned as $entry ) {
			if ( null === $entry['identity'] || null === $entry['context'] || null === $entry['site_id'] || get_current_blog_id() !== $entry['site_id'] ) {
				return false;
			}
			$matched = false;
			if ( null !== $entry['saved'] && ( get_current_blog_id() !== $entry['saved']['site_id'] || $order->get_id() !== $entry['saved']['order_id']
				|| null === $entry['item'] || $entry['item']->get_id() !== $entry['saved']['item_id'] || $entry['item']->get_order_id( 'edit' ) !== $entry['saved']['order_id'] ) ) {
				return false;
			}
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || isset( $used[ spl_object_id( $item ) ] ) ) {
					continue;
				}
				if ( null !== $entry['saved'] ? ( $item->get_id() !== $entry['saved']['item_id'] || $item->get_order_id( 'edit' ) !== $entry['saved']['order_id'] ) : ( null !== $entry['item'] && $entry['item'] !== $item ) ) {
					continue;
				}
				$identity = [ 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id() > 0 ? $item->get_variation_id() : null, 'quantity' => EmergencyCheckoutFacts::quantity( $item->get_quantity() ) ];
				if ( $identity === $entry['identity'] ) {
					$read = $this->reader->read_line( $item );
					$context_hash = EmergencyCheckoutFacts::bounded_hash( null );
					if ( null !== $read->snapshot && in_array( $read->snapshot->snapshot_version, [ OrderDeliverySnapshot::VERSION_V2, OrderDeliverySnapshot::VERSION_V3 ], true ) ) {
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
