<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;

/**
 * Persisted shipment record. Fields are historical snapshots, not live product config.
 *
 * Status is a machine code. Public and private notes are stored separately.
 */
final class Shipment {

	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $shipment_number,
		public readonly string $idempotency_key,
		public readonly string $delivery_group_id,
		public readonly ShipmentStatus $status,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_choice,
		public readonly ?int $delivery_offer_id,
		public readonly ?string $delivery_offer_public_label,
		public readonly ?string $route,
		public readonly ?string $service_level,
		public readonly ?string $carrier_visibility,
		public readonly ?string $public_carrier_name,
		public readonly ?int $destination_zone_id,
		public readonly ?int $logistics_profile_id,
		public readonly ?int $supplier_id,
		public readonly ?int $origin_id,
		public readonly string $currency_code,
		public readonly ?string $customer_paid_shipping_amount,
		public readonly ?int $rate_card_id,
		public readonly ?string $rate_card_code,
		public readonly ?string $internal_cost,
		public readonly ?string $eta_original,
		public readonly ?string $eta_current,
		public readonly ?string $tracking_number,
		public readonly ?string $tracking_url,
		public readonly ?string $tracking_carrier_display,
		public readonly ?string $dispatch_at,
		public readonly ?string $delivered_at,
		public readonly ?string $public_note,
		public readonly ?string $private_note,
		public readonly ?int $wc_fulfillment_id,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
		if ( $order_id <= 0 ) {
			throw new \InvalidArgumentException( 'Shipment requires a positive order_id.' );
		}

		if ( '' === $delivery_group_id ) {
			throw new \InvalidArgumentException( 'Shipment requires a delivery_group_id.' );
		}

		if ( $idempotency_key !== ShipmentIdentity::key( $order_id, $delivery_group_id ) ) {
			throw new \InvalidArgumentException( 'Shipment idempotency_key must equal order_id|delivery_group_id.' );
		}
	}

	public static function create(
		int $order_id,
		string $delivery_group_id,
		ShipmentStatus $status = ShipmentStatus::AwaitingFulfilment,
		string $fulfilment_availability = '',
		string $fulfilment_choice = '',
		?int $delivery_offer_id = null,
		?string $delivery_offer_public_label = null,
		?string $route = null,
		?string $service_level = null,
		?string $carrier_visibility = null,
		?string $public_carrier_name = null,
		?int $destination_zone_id = null,
		?int $logistics_profile_id = null,
		?int $supplier_id = null,
		?int $origin_id = null,
		string $currency_code = '',
		?string $customer_paid_shipping_amount = null,
		?int $rate_card_id = null,
		?string $rate_card_code = null,
		?string $internal_cost = null,
		?string $eta_original = null,
		?string $eta_current = null,
		?string $tracking_number = null,
		?string $tracking_url = null,
		?string $tracking_carrier_display = null,
		?string $dispatch_at = null,
		?string $delivered_at = null,
		?string $public_note = null,
		?string $private_note = null,
		?int $wc_fulfillment_id = null,
		int $id = 0,
		?string $shipment_number = null,
		string $created_at = '',
		string $updated_at = ''
	): self {
		return new self(
			$id,
			$order_id,
			$shipment_number ?? ShipmentIdentity::stable_shipment_number( $order_id, $delivery_group_id ),
			ShipmentIdentity::key( $order_id, $delivery_group_id ),
			$delivery_group_id,
			$status,
			$fulfilment_availability,
			$fulfilment_choice,
			$delivery_offer_id,
			$delivery_offer_public_label,
			$route,
			$service_level,
			$carrier_visibility,
			$public_carrier_name,
			$destination_zone_id,
			$logistics_profile_id,
			$supplier_id,
			$origin_id,
			$currency_code,
			$customer_paid_shipping_amount,
			$rate_card_id,
			$rate_card_code,
			$internal_cost,
			$eta_original,
			$eta_current ?? $eta_original,
			$tracking_number,
			$tracking_url,
			$tracking_carrier_display,
			$dispatch_at,
			$delivered_at,
			$public_note,
			$private_note,
			$wc_fulfillment_id,
			$created_at,
			$updated_at
		);
	}

	public function identity(): ShipmentIdentity {
		return new ShipmentIdentity( $this->order_id, $this->delivery_group_id );
	}

	public function hasPublicTracking(): bool {
		return '' !== trim( (string) $this->tracking_number )
			|| '' !== trim( (string) $this->tracking_url )
			|| '' !== trim( (string) $this->tracking_carrier_display );
	}

	public function withId( int $id ): self {
		return $this->with( id: $id );
	}

	public function withStatus( ShipmentStatus $status ): self {
		return $this->with(
			status: $status,
			updated_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	public function withCurrentEta( ?string $eta_current ): self {
		return $this->with(
			eta_current: $eta_current,
			updated_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	public function withTracking(
		?string $tracking_number,
		?string $tracking_url,
		?string $tracking_carrier_display
	): self {
		return $this->with(
			tracking_number: $tracking_number,
			tracking_url: $tracking_url,
			tracking_carrier_display: $tracking_carrier_display,
			updated_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	public function withTrackingDetails(
		?string $tracking_number,
		?string $tracking_url,
		?string $tracking_carrier_display,
		?string $dispatch_at,
		?string $public_note
	): self {
		return $this->with(
			tracking_number: $tracking_number,
			tracking_url: $tracking_url,
			tracking_carrier_display: $tracking_carrier_display,
			dispatch_at: $dispatch_at,
			public_note: $public_note,
			updated_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	public function withNotes( ?string $public_note, ?string $private_note ): self {
		return $this->with(
			public_note: $public_note,
			private_note: $private_note,
			updated_at: gmdate( 'Y-m-d H:i:s' )
		);
	}

	private function with(
		?int $id = null,
		?ShipmentStatus $status = null,
		mixed $eta_current = false,
		mixed $tracking_number = false,
		mixed $tracking_url = false,
		mixed $tracking_carrier_display = false,
		mixed $dispatch_at = false,
		mixed $public_note = false,
		mixed $private_note = false,
		?string $updated_at = null
	): self {
		return new self(
			$id ?? $this->id,
			$this->order_id,
			$this->shipment_number,
			$this->idempotency_key,
			$this->delivery_group_id,
			$status ?? $this->status,
			$this->fulfilment_availability,
			$this->fulfilment_choice,
			$this->delivery_offer_id,
			$this->delivery_offer_public_label,
			$this->route,
			$this->service_level,
			$this->carrier_visibility,
			$this->public_carrier_name,
			$this->destination_zone_id,
			$this->logistics_profile_id,
			$this->supplier_id,
			$this->origin_id,
			$this->currency_code,
			$this->customer_paid_shipping_amount,
			$this->rate_card_id,
			$this->rate_card_code,
			$this->internal_cost,
			$this->eta_original,
			false === $eta_current ? $this->eta_current : ( null === $eta_current ? null : (string) $eta_current ),
			false === $tracking_number ? $this->tracking_number : ( null === $tracking_number ? null : (string) $tracking_number ),
			false === $tracking_url ? $this->tracking_url : ( null === $tracking_url ? null : (string) $tracking_url ),
			false === $tracking_carrier_display ? $this->tracking_carrier_display : ( null === $tracking_carrier_display ? null : (string) $tracking_carrier_display ),
			false === $dispatch_at ? $this->dispatch_at : ( null === $dispatch_at ? null : (string) $dispatch_at ),
			$this->delivered_at,
			false === $public_note ? $this->public_note : ( null === $public_note ? null : (string) $public_note ),
			false === $private_note ? $this->private_note : ( null === $private_note ? null : (string) $private_note ),
			$this->wc_fulfillment_id,
			$this->created_at,
			$updated_at ?? $this->updated_at
		);
	}
}
