<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Application\CustomerContext\CustomerFacingDeliveryPrice;

/**
 * Customer-safe product-page delivery option (display-only contract).
 *
 * Does not contain supplier/origin data, rate cards, or cart persistence fields.
 * Optional customer-facing price fields are additive and remain empty until an
 * authoritative server quote is attached. Pickup location fields are public catalog copy only.
 */
final class ProductDeliveryOption {

	public const CONTRACT_VERSION = '1';

	public function __construct(
		public readonly string $display_key,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_availability_label,
		public readonly string $fulfilment_choice,
		public readonly string $fulfilment_choice_label,
		public readonly ?int $delivery_offer_id,
		public readonly ?string $delivery_offer_public_label,
		public readonly ?string $delivery_offer_public_description,
		public readonly ?string $estimate_text,
		public readonly bool $is_available,
		public readonly ?string $unavailable_reason,
		public readonly string $contract_version = self::CONTRACT_VERSION,
		public readonly bool $is_default = false,
		public readonly ?string $pickup_location_label = null,
		public readonly ?string $pickup_address = null,
		public readonly ?string $pickup_instructions = null,
		public readonly ?int $pickup_location_id = null,
		public readonly ?string $price_amount = null,
		public readonly ?string $price_currency = null,
		public readonly ?string $price_text = null,
		public readonly ?string $price_basis = null
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'contract_version'                  => $this->contract_version,
			'display_key'                       => $this->display_key,
			'fulfilment_availability'           => $this->fulfilment_availability,
			'fulfilment_availability_label'     => $this->fulfilment_availability_label,
			'fulfilment_choice'                 => $this->fulfilment_choice,
			'fulfilment_choice_label'           => $this->fulfilment_choice_label,
			'delivery_offer_id'                 => $this->delivery_offer_id,
			'delivery_offer_public_label'       => $this->delivery_offer_public_label,
			'delivery_offer_public_description' => $this->delivery_offer_public_description,
			'estimate_text'                     => $this->estimate_text,
			'is_available'                      => $this->is_available,
			'unavailable_reason'                => $this->unavailable_reason,
			'is_default'                        => $this->is_default,
			'pickup_location_label'             => $this->pickup_location_label,
			'pickup_address'                    => $this->pickup_address,
			'pickup_instructions'               => $this->pickup_instructions,
			'pickup_location_id'                => $this->pickup_location_id,
			'price_amount'                      => $this->price_amount,
			'price_currency'                    => $this->price_currency,
			'price_text'                        => $this->price_text,
			'price_basis'                       => $this->price_basis,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		return new self(
			(string) ( $data['display_key'] ?? '' ),
			(string) ( $data['fulfilment_availability'] ?? '' ),
			(string) ( $data['fulfilment_availability_label'] ?? '' ),
			(string) ( $data['fulfilment_choice'] ?? '' ),
			(string) ( $data['fulfilment_choice_label'] ?? '' ),
			isset( $data['delivery_offer_id'] ) ? (int) $data['delivery_offer_id'] : null,
			isset( $data['delivery_offer_public_label'] ) ? (string) $data['delivery_offer_public_label'] : null,
			isset( $data['delivery_offer_public_description'] ) ? (string) $data['delivery_offer_public_description'] : null,
			isset( $data['estimate_text'] ) ? (string) $data['estimate_text'] : null,
			! empty( $data['is_available'] ),
			isset( $data['unavailable_reason'] ) ? (string) $data['unavailable_reason'] : null,
			(string) ( $data['contract_version'] ?? self::CONTRACT_VERSION ),
			! empty( $data['is_default'] ),
			isset( $data['pickup_location_label'] ) ? (string) $data['pickup_location_label'] : null,
			isset( $data['pickup_address'] ) ? (string) $data['pickup_address'] : null,
			isset( $data['pickup_instructions'] ) ? (string) $data['pickup_instructions'] : null,
			isset( $data['pickup_location_id'] ) ? (int) $data['pickup_location_id'] : null,
			isset( $data['price_amount'] ) ? (string) $data['price_amount'] : null,
			isset( $data['price_currency'] ) ? (string) $data['price_currency'] : null,
			isset( $data['price_text'] ) ? (string) $data['price_text'] : null,
			isset( $data['price_basis'] ) ? (string) $data['price_basis'] : null
		);
	}

	public function withDefault( bool $is_default ): self {
		return new self(
			$this->display_key,
			$this->fulfilment_availability,
			$this->fulfilment_availability_label,
			$this->fulfilment_choice,
			$this->fulfilment_choice_label,
			$this->delivery_offer_id,
			$this->delivery_offer_public_label,
			$this->delivery_offer_public_description,
			$this->estimate_text,
			$this->is_available,
			$this->unavailable_reason,
			$this->contract_version,
			$is_default,
			$this->pickup_location_label,
			$this->pickup_address,
			$this->pickup_instructions,
			$this->pickup_location_id,
			$this->price_amount,
			$this->price_currency,
			$this->price_text,
			$this->price_basis
		);
	}

	public function withCustomerPrice( CustomerFacingDeliveryPrice $price ): self {
		$public = $price->to_public_array();

		return new self(
			$this->display_key,
			$this->fulfilment_availability,
			$this->fulfilment_availability_label,
			$this->fulfilment_choice,
			$this->fulfilment_choice_label,
			$this->delivery_offer_id,
			$this->delivery_offer_public_label,
			$this->delivery_offer_public_description,
			$this->estimate_text,
			$this->is_available,
			$this->unavailable_reason,
			$this->contract_version,
			$this->is_default,
			$this->pickup_location_label,
			$this->pickup_address,
			$this->pickup_instructions,
			$this->pickup_location_id,
			$public['price_amount'],
			$public['price_currency'],
			$public['price_text'],
			$public['price_basis']
		);
	}
}
