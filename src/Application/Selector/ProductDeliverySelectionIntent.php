<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

/**
 * Server-side handoff contract for a validated product delivery selection.
 *
 * May be stored in WooCommerce cart item data when cart capture is enabled. It carries only numeric
 * quote dimensions (logistics profile, supplier, origin ids) so that delivery grouping and pricing use
 * the same tuple (D1); never names, addresses or costs.
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
		public readonly ?string $configuration_fingerprint = null,
		public readonly ?int $logistics_profile_id = null,
		public readonly ?int $supplier_id = null,
		public readonly ?int $origin_id = null
	) {
	}

	/**
	 * @param array{logistics_profile_id?: int|null, supplier_id?: int|null, origin_id?: int|null} $quote_dimensions
	 */
	public static function fromValidatedOption(
		int $product_id,
		?int $variation_id,
		string $target_type,
		int $target_id,
		ProductDeliveryOption $option,
		?int $rule_id,
		?string $configuration_fingerprint = null,
		array $quote_dimensions = []
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
			$configuration_fingerprint,
			self::positive_or_null( $quote_dimensions['logistics_profile_id'] ?? null ),
			self::positive_or_null( $quote_dimensions['supplier_id'] ?? null ),
			self::positive_or_null( $quote_dimensions['origin_id'] ?? null )
		);
	}

	public static function positive_or_null( mixed $value ): ?int {
		if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
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

		foreach ( [ 'logistics_profile_id', 'supplier_id', 'origin_id' ] as $dimension ) {
			if ( null !== $this->{$dimension} ) {
				$data[ $dimension ] = $this->{$dimension};
			}
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
			$fingerprint,
			self::positive_or_null( $data['logistics_profile_id'] ?? null ),
			self::positive_or_null( $data['supplier_id'] ?? null ),
			self::positive_or_null( $data['origin_id'] ?? null )
		);
	}
}
