<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

/**
 * Server-side handoff contract for a validated product delivery selection.
 *
 * May be stored in WooCommerce cart item data when cart capture is enabled. No private supplier/origin data.
 */
final class ProductDeliverySelectionIntent {

	public const CONTRACT_VERSION = '1';

	public const COMPATIBLE_OPTION_CONTRACT_VERSION = ProductDeliveryOption::CONTRACT_VERSION;

	public function __construct(
		public readonly string $contract_version,
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $target_type,
		public readonly int $target_id,
		public readonly string $display_key,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_choice,
		public readonly ?int $delivery_offer_id,
		public readonly ?int $rule_id,
		public readonly string $issued_at,
		public readonly ?string $configuration_fingerprint = null
	) {
	}

	public static function fromValidatedOption(
		int $product_id,
		?int $variation_id,
		string $target_type,
		int $target_id,
		ProductDeliveryOption $option,
		?int $rule_id,
		?string $configuration_fingerprint = null
	): self {
		return new self(
			self::CONTRACT_VERSION,
			$product_id,
			$variation_id,
			$target_type,
			$target_id,
			$option->display_key,
			$option->fulfilment_availability,
			$option->fulfilment_choice,
			$option->delivery_offer_id,
			$rule_id,
			gmdate( 'c' ),
			$configuration_fingerprint
		);
	}

	public static function isCompatibleWithOptionContract( string $option_contract_version ): bool {
		return self::COMPATIBLE_OPTION_CONTRACT_VERSION === $option_contract_version;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$data = [
			'contract_version'        => $this->contract_version,
			'product_id'              => $this->product_id,
			'variation_id'            => $this->variation_id,
			'target_type'             => $this->target_type,
			'target_id'               => $this->target_id,
			'display_key'             => $this->display_key,
			'fulfilment_availability' => $this->fulfilment_availability,
			'fulfilment_choice'       => $this->fulfilment_choice,
			'delivery_offer_id'       => $this->delivery_offer_id,
			'rule_id'                 => $this->rule_id,
			'issued_at'               => $this->issued_at,
		];

		if ( null !== $this->configuration_fingerprint && '' !== $this->configuration_fingerprint ) {
			$data['configuration_fingerprint'] = $this->configuration_fingerprint;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$fingerprint = isset( $data['configuration_fingerprint'] ) && '' !== $data['configuration_fingerprint']
			? (string) $data['configuration_fingerprint']
			: null;

		return new self(
			(string) ( $data['contract_version'] ?? self::CONTRACT_VERSION ),
			(int) ( $data['product_id'] ?? 0 ),
			isset( $data['variation_id'] ) && '' !== $data['variation_id'] ? (int) $data['variation_id'] : null,
			(string) ( $data['target_type'] ?? '' ),
			(int) ( $data['target_id'] ?? 0 ),
			(string) ( $data['display_key'] ?? '' ),
			(string) ( $data['fulfilment_availability'] ?? '' ),
			(string) ( $data['fulfilment_choice'] ?? '' ),
			isset( $data['delivery_offer_id'] ) && '' !== $data['delivery_offer_id'] ? (int) $data['delivery_offer_id'] : null,
			isset( $data['rule_id'] ) && '' !== $data['rule_id'] ? (int) $data['rule_id'] : null,
			(string) ( $data['issued_at'] ?? '' ),
			$fingerprint
		);
	}
}
