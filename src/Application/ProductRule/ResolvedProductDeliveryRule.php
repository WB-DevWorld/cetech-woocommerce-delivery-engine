<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ProductRule;

/**
 * Safe admin-facing view of a product delivery rule (no private notes).
 */
final class ResolvedProductDeliveryRule {

	/**
	 * @param list<int> $delivery_offer_ids
	 */
	public function __construct(
		public readonly int $rule_id,
		public readonly string $target_type,
		public readonly int $target_id,
		public readonly ?string $target_label_snapshot,
		public readonly int $target_specificity,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_choice,
		public readonly array $delivery_offer_ids,
		public readonly ?int $logistics_profile_id,
		public readonly ?int $supplier_id,
		public readonly ?int $origin_id,
		public readonly int $priority,
		public readonly ?int $pickup_location_id = null
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<int>            $delivery_offer_ids
	 */
	public static function from_row( array $row, array $delivery_offer_ids, int $target_specificity ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['target_type'] ?? '' ),
			(int) ( $row['target_id'] ?? 0 ),
			isset( $row['target_label_snapshot'] ) ? (string) $row['target_label_snapshot'] : null,
			$target_specificity,
			(string) ( $row['fulfilment_availability'] ?? '' ),
			(string) ( $row['fulfilment_choice'] ?? '' ),
			$delivery_offer_ids,
			self::nullable_int( $row['logistics_profile_id'] ?? null ),
			self::nullable_int( $row['supplier_id'] ?? null ),
			self::nullable_int( $row['origin_id'] ?? null ),
			(int) ( $row['priority'] ?? 100 ),
			self::nullable_int( $row['pickup_location_id'] ?? null )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'rule_id'                 => $this->rule_id,
			'target_type'             => $this->target_type,
			'target_id'               => $this->target_id,
			'target_label_snapshot'   => $this->target_label_snapshot,
			'target_specificity'      => $this->target_specificity,
			'fulfilment_availability' => $this->fulfilment_availability,
			'fulfilment_choice'       => $this->fulfilment_choice,
			'delivery_offer_ids'      => $this->delivery_offer_ids,
			'logistics_profile_id'    => $this->logistics_profile_id,
			'supplier_id'             => $this->supplier_id,
			'origin_id'               => $this->origin_id,
			'priority'                => $this->priority,
			'pickup_location_id'      => $this->pickup_location_id,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$offer_ids = [];

		if ( isset( $data['delivery_offer_ids'] ) && is_array( $data['delivery_offer_ids'] ) ) {
			foreach ( $data['delivery_offer_ids'] as $value ) {
				$int = (int) $value;

				if ( $int > 0 ) {
					$offer_ids[] = $int;
				}
			}
		}

		return new self(
			(int) ( $data['rule_id'] ?? 0 ),
			(string) ( $data['target_type'] ?? '' ),
			(int) ( $data['target_id'] ?? 0 ),
			isset( $data['target_label_snapshot'] ) ? (string) $data['target_label_snapshot'] : null,
			(int) ( $data['target_specificity'] ?? 0 ),
			(string) ( $data['fulfilment_availability'] ?? '' ),
			(string) ( $data['fulfilment_choice'] ?? '' ),
			array_values( array_unique( $offer_ids ) ),
			self::nullable_int( $data['logistics_profile_id'] ?? null ),
			self::nullable_int( $data['supplier_id'] ?? null ),
			self::nullable_int( $data['origin_id'] ?? null ),
			(int) ( $data['priority'] ?? 100 ),
			self::nullable_int( $data['pickup_location_id'] ?? null )
		);
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}
}
