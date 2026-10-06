<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use WC_Order;
use WC_Order_Item_Product;

/** Reads protected historical facts through Woo CRUD; never writes or recalculates. */
final class OrderDeliverySnapshotReader {

	private const MAX_GROUPS = 1000;

	/** Legacy availability values stay verbatim; they are not normalized to new policy. */
	private const AVAILABILITIES = [ 'international_fulfilment', 'in_store', 'in_warehouse', 'international', 'local' ];

	private const CHOICES = [ 'delivery', 'store_pickup' ];

	private readonly SnapshotExtensionParser $extension_parser;

	public function __construct( ?SnapshotExtensionParser $extension_parser = null ) {
		$this->extension_parser = $extension_parser ?? new SnapshotExtensionParser();
	}

	public function read_line( WC_Order_Item_Product $item ): OrderDeliveryLineReadResult {
		$raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		$meta = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true );
		$stored = $this->version( $meta, true );
		if ( $this->missing( $raw ) ) {
			return new OrderDeliveryLineReadResult( false, null, OrderDeliveryLineReadResult::ERROR_MISSING, null );
		}
		try {
			if ( ! is_string( $raw ) ) {
				throw new \InvalidArgumentException();
			}
			$decoded = OrderDeliverySnapshotJson::decode( $raw );
		} catch ( \InvalidArgumentException ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_MALFORMED, $stored );
		}

		$format = $this->version( $decoded['snapshot_version'] ?? null );
		$contract = $this->version( $decoded['contract_version'] ?? null );
		if ( ! $this->supported_format( $format ) || ! $this->metadata_agrees( $meta, $stored, $format )
			|| ProductDeliverySelectionIntent::CONTRACT_VERSION !== $contract ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_VERSION_MISMATCH, $stored );
		}
		$extensions = $this->extension_parser->read( $decoded );
		if ( ! $extensions->required_semantics_supported() ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_VERSION_MISMATCH, $stored, $extensions );
		}

		try {
			$context_version = $this->positive_int( $decoded['customer_context_version'] ?? null, true );
			if ( null !== $context_version && CustomerCartContext::CONTRACT_VERSION !== $context_version ) {
				return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_VERSION_MISMATCH, $stored, $extensions );
			}
			$snapshot = new OrderDeliveryLineSnapshot(
				$contract,
				$format,
				$this->positive_int( $decoded['product_id'] ?? null ),
				$this->positive_int( $decoded['variation_id'] ?? null, true ),
				$this->enum( $decoded['fulfilment_availability'] ?? null, self::AVAILABILITIES ),
				$this->enum( $decoded['fulfilment_choice'] ?? null, self::CHOICES ),
				$this->positive_int( $decoded['delivery_offer_id'] ?? null, true ),
				$this->text( $decoded['delivery_offer_public_label'] ?? null ),
				$this->text( $decoded['delivery_offer_public_description'] ?? null ),
				$this->text( $decoded['estimate_text'] ?? null ),
				$this->positive_int( $decoded['rule_id'] ?? null, true ),
				$this->positive_int( $decoded['destination_zone_id'] ?? null, true ),
				$this->positive_int( $decoded['quantity'] ?? null ),
				$this->currency( $decoded['currency_code'] ?? null ),
				$this->amount( $decoded['quoted_amount'] ?? null ),
				$this->enum( $decoded['quote_status'] ?? null, [ 'quoted', 'selection_only', 'skipped' ] ),
				$this->positive_int( $decoded['rate_card_id'] ?? null, true ),
				$this->text( $decoded['rate_card_code'] ?? null, 256 ),
				$this->timestamp( $decoded['snapshotted_at'] ?? null ),
				$this->text( $decoded['delivery_group_id'] ?? null, 2048 ),
				$this->text( $decoded['pickup_location_label'] ?? null ),
				$this->text( $decoded['pickup_address'] ?? null ),
				$this->text( $decoded['pickup_instructions'] ?? null ),
				$context_version,
				$this->location( $decoded['matching_location'] ?? null, false ),
				$this->location( $decoded['delivery_address'] ?? null, true ),
				$this->identity( $decoded['matching_identity'] ?? null ),
				$this->identity( $decoded['delivery_location_identity'] ?? null ),
				$this->positive_int( $decoded['pickup_location_id'] ?? null, true )
			);
		} catch ( \InvalidArgumentException ) {
			return new OrderDeliveryLineReadResult( true, null, OrderDeliveryLineReadResult::ERROR_PARTIAL, $stored, $extensions );
		}
		return new OrderDeliveryLineReadResult( true, $snapshot, OrderDeliveryLineReadResult::ERROR_NONE, $stored, $extensions );
	}

	public function read_package( WC_Order $order ): OrderDeliveryPackageReadResult {
		$raw = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
		$meta = $order->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true );
		$stored = $this->version( $meta, true );
		if ( $this->missing( $raw ) ) {
			return new OrderDeliveryPackageReadResult( false, null, OrderDeliveryPackageReadResult::ERROR_MISSING, null );
		}
		try {
			if ( ! is_string( $raw ) ) {
				throw new \InvalidArgumentException();
			}
			$decoded = OrderDeliverySnapshotJson::decode( $raw );
		} catch ( \InvalidArgumentException ) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_MALFORMED, $stored );
		}
		$format = $this->version( $decoded['snapshot_version'] ?? null );
		if ( ! $this->supported_format( $format ) || ! $this->metadata_agrees( $meta, $stored, $format ) ) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_VERSION_MISMATCH, $stored );
		}
		$extensions = $this->extension_parser->read( $decoded );
		if ( ! $extensions->required_semantics_supported() ) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_VERSION_MISMATCH, $stored, $extensions );
		}
		try {
			$snapshot = new OrderDeliveryPackageSnapshot(
				$format,
				$this->text( $decoded['shipping_method_id'] ?? null, 256 ),
				$this->text( $decoded['shipping_method_label'] ?? null ),
				$this->amount( $decoded['package_total_delivery_amount'] ?? null ),
				$this->currency( $decoded['currency_code'] ?? null ),
				$this->positive_int( $decoded['destination_zone_id'] ?? null, true ),
				// quoted is an existing historical package-fixture format.
				$this->enum( $decoded['quote_status'] ?? null, [ 'success', 'not_applicable', 'failure', 'quoted' ] ),
				$this->timestamp( $decoded['snapshotted_at'] ?? null ),
				$this->groups( $decoded['groups'] ?? null )
			);
		} catch ( \InvalidArgumentException ) {
			return new OrderDeliveryPackageReadResult( true, null, OrderDeliveryPackageReadResult::ERROR_PARTIAL, $stored, $extensions );
		}
		return new OrderDeliveryPackageReadResult( true, $snapshot, OrderDeliveryPackageReadResult::ERROR_NONE, $stored, $extensions );
	}

	/** @return list<OrderDeliveryGroupSnapshot> */
	private function groups( mixed $raw ): array {
		if ( null === $raw ) {
			return [];
		}
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > self::MAX_GROUPS ) {
			throw new \InvalidArgumentException();
		}
		$groups = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw new \InvalidArgumentException();
			}
			$id = $this->text( $row['group_id'] ?? null, 2048 );
			$pickup = $row['is_pickup'] ?? false;
			$index = $this->nonnegative_int( $row['display_index'] ?? 0 );
			if ( null === $id || ! is_bool( $pickup ) || isset( $seen[ $id ] ) ) {
				throw new \InvalidArgumentException();
			}
			$seen[ $id ] = true;
			$choice = $this->enum( $row['fulfilment_choice'] ?? null, self::CHOICES );
			if ( $pickup !== ( 'store_pickup' === $choice ) ) {
				throw new \InvalidArgumentException();
			}
			$groups[] = new OrderDeliveryGroupSnapshot(
				$id,
				$this->text( $row['shipping_method_id'] ?? null, 256 ),
				$this->text( $row['shipping_method_label'] ?? null ),
				$this->amount( $row['package_total_delivery_amount'] ?? null ),
				$choice,
				$pickup,
				$index
			);
		}
		return $groups;
	}

	private function missing( mixed $value ): bool {
		return null === $value || '' === $value;
	}

	private function version( mixed $value, bool $metadata = false ): ?string {
		if ( is_int( $value ) && $value > 0 ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = $metadata ? trim( $value ) : $value;
		return 1 === preg_match( '/\A[1-9][0-9]{0,15}\z/D', $value ) ? $value : null;
	}

	private function supported_format( ?string $value ): bool {
		return OrderDeliverySnapshot::VERSION === $value || OrderDeliverySnapshot::VERSION_V2 === $value;
	}

	private function metadata_agrees( mixed $raw, ?string $stored, string $format ): bool {
		return null === $raw || '' === $raw || ( null !== $stored && $stored === $format );
	}

	private function positive_int( mixed $value, bool $nullable = false ): ?int {
		if ( $nullable && ( null === $value || '' === $value ) ) {
			return null;
		}
		$int = $this->nonnegative_int( $value );
		if ( $int < 1 ) {
			throw new \InvalidArgumentException();
		}
		return $int;
	}

	private function nonnegative_int( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) ) {
			$ceiling = (string) PHP_INT_MAX;
			if ( strlen( $value ) < strlen( $ceiling ) || ( strlen( $value ) === strlen( $ceiling ) && strcmp( $value, $ceiling ) <= 0 ) ) {
				return (int) $value;
			}
		}
		throw new \InvalidArgumentException();
	}

	/** @param list<string> $allowed */
	private function enum( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException();
		}
		return $value;
	}

	private function text( mixed $value, int $max = 65536 ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || strlen( $value ) > $max || str_contains( $value, "\0" ) ) {
			throw new \InvalidArgumentException();
		}
		return '' !== trim( $value ) ? $value : null;
	}

	private function currency( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Z]{3}\z/D', $value ) ) {
			throw new \InvalidArgumentException();
		}
		return $value;
	}

	private function amount( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! OrderDeliverySnapshotJson::is_decimal_amount( $value ) ) {
			throw new \InvalidArgumentException();
		}
		return $value;
	}

	private function identity( mixed $value ): ?string {
		$value = $this->text( $value, 64 );
		if ( null !== $value && 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $value ) ) {
			throw new \InvalidArgumentException();
		}
		return $value;
	}

	private function timestamp( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 40 || str_contains( $value, "\0" ) ) {
			throw new \InvalidArgumentException();
		}
		foreach ( [ 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s' ] as $format ) {
			$time = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( false !== $time && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $time->format( $format ) === $value ) {
				return $value;
			}
		}
		throw new \InvalidArgumentException();
	}

	/** @return array<string, mixed>|null */
	private function location( mixed $value, bool $address ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new \InvalidArgumentException();
		}
		$keys = [ 'country', 'state', 'city', 'postcode', 'country_identity', 'state_identity', 'city_identity', 'postcode_identity' ];
		if ( $address ) {
			$keys = array_merge( $keys, [ 'address_1', 'address_2', 'address_1_identity', 'address_2_identity' ] );
		}
		$out = [];
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) || ! is_string( $value[ $key ] ) || strlen( $value[ $key ] ) > 4096 || str_contains( $value[ $key ], "\0" ) ) {
				throw new \InvalidArgumentException();
			}
			$out[ $key ] = $value[ $key ];
		}
		if ( array_key_exists( 'canonical_location_key', $value ) ) {
			if ( ! is_string( $value['canonical_location_key'] ) || strlen( $value['canonical_location_key'] ) > 4096 || str_contains( $value['canonical_location_key'], "\0" ) ) {
				throw new \InvalidArgumentException();
			}
			$out['canonical_location_key'] = $value['canonical_location_key'];
		}
		if ( $address ) {
			$recipient = $value['recipient'] ?? null;
			if ( ! is_array( $recipient ) || array_is_list( $recipient ) ) {
				throw new \InvalidArgumentException();
			}
			$out['recipient'] = [];
			foreach ( [ 'first_name', 'last_name', 'company', 'phone' ] as $key ) {
				if ( ! array_key_exists( $key, $recipient ) || ! is_string( $recipient[ $key ] ) || strlen( $recipient[ $key ] ) > 4096 || str_contains( $recipient[ $key ], "\0" ) ) {
					throw new \InvalidArgumentException();
				}
				$out['recipient'][ $key ] = $recipient[ $key ];
			}
			$out['recipient'] = array_intersect_key( $recipient, $out['recipient'] );
		}
		// Retain the insertion order of known recorded facts, not a new ordering.
		$ordered = [];
		foreach ( $value as $key => $_value ) {
			if ( array_key_exists( $key, $out ) ) {
				$ordered[$key] = $out[$key];
			}
		}
		return $ordered;
	}
}

/** Parsed line snapshot read outcome. Optional-extension failures stay separate. */
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
		public readonly ?string $stored_version,
		public readonly ?SnapshotExtensionSet $extensions = null
	) {
	}
}

/** Parsed package snapshot read outcome. */
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
		public readonly ?string $stored_version,
		public readonly ?SnapshotExtensionSet $extensions = null
	) {
	}
}
