<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Reads and parses protected order delivery snapshot meta (read-only, HPOS-compatible CRUD).
 */
final class OrderDeliverySnapshotReader {

	/** @var list<string> */
	private const LINE_REQUIRED_KEYS = [
		'contract_version',
		'snapshot_version',
		'product_id',
		'fulfilment_availability',
		'fulfilment_choice',
		'quantity',
		'currency_code',
		'quote_status',
		'snapshotted_at',
	];

	/** @var list<string> */
	private const PACKAGE_REQUIRED_KEYS = [
		'snapshot_version',
		'currency_code',
		'quote_status',
		'snapshotted_at',
	];

	public function read_line( WC_Order_Item_Product $item ): OrderDeliveryLineReadResult {
		$raw           = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		$stored_version = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new OrderDeliveryLineReadResult( false, null, OrderDeliveryLineReadResult::ERROR_MISSING, null );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_MALFORMED, $this->normalize_version( $stored_version ) );
		}

		$stored_version_normalized = $this->normalize_version( $stored_version );
		$snapshot_version          = isset( $decoded['snapshot_version'] ) ? (string) $decoded['snapshot_version'] : '';
		$contract_version          = isset( $decoded['contract_version'] ) ? (string) $decoded['contract_version'] : '';

		if (
			( null !== $stored_version_normalized && OrderDeliverySnapshot::VERSION !== $stored_version_normalized )
			|| OrderDeliverySnapshot::VERSION !== $snapshot_version
			|| ProductDeliverySelectionIntent::CONTRACT_VERSION !== $contract_version
		) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_VERSION_MISMATCH, $stored_version_normalized );
		}

		foreach ( self::LINE_REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $decoded ) || ! $this->is_non_empty_scalar( $decoded[ $key ] ) ) {
				return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_PARTIAL, $stored_version_normalized );
			}
		}

		$product_id = (int) $decoded['product_id'];

		if ( $product_id <= 0 ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_PARTIAL, $stored_version_normalized );
		}

		$quantity = (int) $decoded['quantity'];

		if ( $quantity <= 0 ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_PARTIAL, $stored_version_normalized );
		}

		$snapshot = new OrderDeliveryLineSnapshot(
			$contract_version,
			$snapshot_version,
			$product_id,
			$this->nullable_positive_int( $decoded['variation_id'] ?? null ),
			sanitize_key( (string) $decoded['fulfilment_availability'] ),
			sanitize_key( (string) $decoded['fulfilment_choice'] ),
			$this->nullable_positive_int( $decoded['delivery_offer_id'] ?? null ),
			$this->nullable_string( $decoded['delivery_offer_public_label'] ?? null ),
			$this->nullable_string( $decoded['delivery_offer_public_description'] ?? null ),
			$this->nullable_string( $decoded['estimate_text'] ?? null ),
			$this->nullable_positive_int( $decoded['rule_id'] ?? null ),
			$this->nullable_positive_int( $decoded['destination_zone_id'] ?? null ),
			$quantity,
			sanitize_text_field( (string) $decoded['currency_code'] ),
			$this->nullable_string( $decoded['quoted_amount'] ?? null ),
			sanitize_key( (string) $decoded['quote_status'] ),
			$this->nullable_positive_int( $decoded['rate_card_id'] ?? null ),
			$this->nullable_string( $decoded['rate_card_code'] ?? null ),
			sanitize_text_field( (string) $decoded['snapshotted_at'] ),
			$this->nullable_string( $decoded['delivery_group_id'] ?? null ),
			$this->nullable_string( $decoded['pickup_location_label'] ?? null ),
			$this->nullable_string( $decoded['pickup_address'] ?? null ),
			$this->nullable_string( $decoded['pickup_instructions'] ?? null )
		);

		return new OrderDeliveryLineReadResult( true, $snapshot, OrderDeliveryLineReadResult::ERROR_NONE, $stored_version_normalized );
	}

	public function read_package( WC_Order $order ): OrderDeliveryPackageReadResult {
		$raw            = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
		$stored_version = $order->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new OrderDeliveryPackageReadResult( false, null, OrderDeliveryPackageReadResult::ERROR_MISSING, null );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_MALFORMED, $this->normalize_version( $stored_version ) );
		}

		$stored_version_normalized = $this->normalize_version( $stored_version );
		$snapshot_version          = isset( $decoded['snapshot_version'] ) ? (string) $decoded['snapshot_version'] : '';

		if (
			( null !== $stored_version_normalized && OrderDeliverySnapshot::VERSION !== $stored_version_normalized )
			|| OrderDeliverySnapshot::VERSION !== $snapshot_version
		) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_VERSION_MISMATCH, $stored_version_normalized );
		}

		foreach ( self::PACKAGE_REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $decoded ) || ! $this->is_non_empty_scalar( $decoded[ $key ] ) ) {
				return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_PARTIAL, $stored_version_normalized );
			}
		}

		$snapshot = new OrderDeliveryPackageSnapshot(
			$snapshot_version,
			$this->nullable_string( $decoded['shipping_method_id'] ?? null ),
			$this->nullable_string( $decoded['shipping_method_label'] ?? null ),
			$this->nullable_string( $decoded['package_total_delivery_amount'] ?? null ),
			sanitize_text_field( (string) $decoded['currency_code'] ),
			$this->nullable_positive_int( $decoded['destination_zone_id'] ?? null ),
			sanitize_key( (string) $decoded['quote_status'] ),
			sanitize_text_field( (string) $decoded['snapshotted_at'] ),
			$this->parse_groups( $decoded['groups'] ?? null )
		);

		return new OrderDeliveryPackageReadResult( true, $snapshot, OrderDeliveryPackageReadResult::ERROR_NONE, $stored_version_normalized );
	}

	/**
	 * @param mixed $raw
	 *
	 * @return list<OrderDeliveryGroupSnapshot>
	 */
	private function parse_groups( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$groups = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$group_id = isset( $row['group_id'] ) ? sanitize_text_field( (string) $row['group_id'] ) : '';

			if ( '' === $group_id ) {
				continue;
			}

			$groups[] = new OrderDeliveryGroupSnapshot(
				$group_id,
				$this->nullable_string( $row['shipping_method_id'] ?? null ),
				$this->nullable_string( $row['shipping_method_label'] ?? null ),
				$this->nullable_string( $row['package_total_delivery_amount'] ?? null ),
				sanitize_key( (string) ( $row['fulfilment_choice'] ?? '' ) ),
				! empty( $row['is_pickup'] ),
				max( 0, (int) ( $row['display_index'] ?? 0 ) )
			);
		}

		return $groups;
	}

	/**
	 * @param mixed $value
	 */
	private function is_non_empty_scalar( mixed $value ): bool {
		if ( is_int( $value ) || is_float( $value ) ) {
			return true;
		}

		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * @param mixed $value
	 */
	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}

	/**
	 * @param mixed $value
	 */
	private function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = is_string( $value ) ? trim( $value ) : (string) $value;

		return '' !== $string ? $string : null;
	}

	/**
	 * @param mixed $value
	 */
	private function normalize_version( mixed $value ): ?string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$string = trim( (string) $value );

		return '' !== $string ? $string : null;
	}
}

/**
 * Parsed line snapshot read outcome (read-only).
 */
final class OrderDeliveryLineReadResult {

	public const ERROR_NONE = '';

	public const ERROR_MISSING = 'missing';

	public const ERROR_MALFORMED = 'malformed';

	public const ERROR_VERSION_MISMATCH = 'version_mismatch';

	public const ERROR_PARTIAL = 'partial';

	public function __construct(
		public readonly bool $has_meta,
		public readonly ?OrderDeliveryLineSnapshot $snapshot,
		public readonly string $error,
		public readonly ?string $stored_version
	) {
	}
}

/**
 * Parsed package snapshot read outcome (read-only).
 */
final class OrderDeliveryPackageReadResult {

	public const ERROR_NONE = '';

	public const ERROR_MISSING = 'missing';

	public const ERROR_MALFORMED = 'malformed';

	public const ERROR_VERSION_MISMATCH = 'version_mismatch';

	public const ERROR_PARTIAL = 'partial';

	public function __construct(
		public readonly bool $has_meta,
		public readonly ?OrderDeliveryPackageSnapshot $snapshot,
		public readonly string $error,
		public readonly ?string $stored_version
	) {
	}
}
