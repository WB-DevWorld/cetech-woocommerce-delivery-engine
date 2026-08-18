<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentAggregateWriteResult;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Domain\Shipment\ShipmentListResult;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/**
 * Canonical V1 shipment store. Does not dual-write to WooCommerce Fulfillments.
 */
final class WpdbShipmentRepository extends AbstractWpdbRepository implements ShipmentRepositoryInterface {

	protected function table_suffix(): string {
		return ShipmentSchema::SHIPMENTS_SUFFIX;
	}

	public function create( Shipment $shipment ): Shipment {
		$existing = $this->findByOrderAndGroup( $shipment->order_id, $shipment->delivery_group_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $this->to_shipment_row( $shipment, $now, true );

		$id = $this->insert_row( $row['data'], $row['formats'] );

		if ( $id <= 0 ) {
			if ( $this->is_duplicate_key_error() ) {
				$existing = $this->findByOrderAndGroup( $shipment->order_id, $shipment->delivery_group_id );

				if ( null !== $existing ) {
					return $existing;
				}
			}

			throw new \RuntimeException( 'Failed to create shipment record.' );
		}

		$created = $this->findById( $id );

		if ( null === $created ) {
			throw new \RuntimeException( 'Shipment insert succeeded but the row could not be reloaded.' );
		}

		return $created;
	}

	public function findById( int $id ): ?Shipment {
		if ( $id <= 0 ) {
			return null;
		}

		$row = $this->fetch_row_by_id( $id );

		return is_array( $row ) ? $this->hydrate_shipment( $row ) : null;
	}

	public function findByOrderAndGroup( int $order_id, string $delivery_group_id ): ?Shipment {
		if ( $order_id <= 0 || '' === $delivery_group_id ) {
			return null;
		}

		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE `order_id` = %d AND `delivery_group_id` = %s LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $order_id, $delivery_group_id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_shipment( $row ) : null;
	}

	public function findByIdempotencyKey( string $idempotency_key ): ?Shipment {
		if ( '' === $idempotency_key ) {
			return null;
		}

		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE `idempotency_key` = %s LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $idempotency_key ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_shipment( $row ) : null;
	}

	public function findByOrderId( int $order_id ): array {
		if ( $order_id <= 0 ) {
			return [];
		}

		$result = $this->list( [ 'order_id' => $order_id ], 1, 100 );

		return $result->items;
	}

	public function list( array $criteria = [], int $page = 1, int $per_page = 20 ): ShipmentListResult {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$table = $this->table_name();
		$where = '1=1';
		$args  = [];

		if ( isset( $criteria['status'] ) && '' !== (string) $criteria['status'] ) {
			$status = ShipmentStatus::tryFromMachineCode( (string) $criteria['status'] );

			if ( null === $status ) {
				return new ShipmentListResult( [], 0, $page, $per_page );
			}

			$where .= ' AND `status` = %s';
			$args[] = $status->value;
		}

		if ( isset( $criteria['order_id'] ) ) {
			$order_id = (int) $criteria['order_id'];

			if ( $order_id <= 0 ) {
				return new ShipmentListResult( [], 0, $page, $per_page );
			}

			$where .= ' AND `order_id` = %d';
			$args[] = $order_id;
		}

		$search = isset( $criteria['search'] ) ? trim( (string) $criteria['search'] ) : '';

		if ( '' !== $search ) {
			$like = $this->like_prefix( $search );
			$where .= ' AND ( `shipment_number` LIKE %s OR `tracking_number` LIKE %s';
			$args[] = $like;
			$args[] = $like;

			if ( ctype_digit( $search ) ) {
				$where .= ' OR `id` = %d OR `order_id` = %d';
				$args[] = (int) $search;
				$args[] = (int) $search;
			}

			$where .= ' )';
		}

		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
		$list_sql  = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY `updated_at` DESC, `id` DESC LIMIT %d OFFSET %d";

		if ( [] === $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $per_page, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
			$list_args = array_merge( $args, [ $per_page, $offset ] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_args ), ARRAY_A );
		}

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$items[] = $this->hydrate_shipment( $row );
			}
		}

		return new ShipmentListResult( $items, $total, $page, $per_page );
	}

	public function update( Shipment $shipment ): Shipment {
		if ( $shipment->id <= 0 ) {
			throw new \InvalidArgumentException( 'Cannot update a shipment that has not been persisted.' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $this->to_shipment_row( $shipment, $now, false );

		if ( ! $this->update_row( $shipment->id, $row['data'], $row['formats'] ) ) {
			throw new \RuntimeException( sprintf( 'Failed to update shipment %d.', $shipment->id ) );
		}

		$updated = $this->findById( $shipment->id );

		if ( null === $updated ) {
			throw new \RuntimeException( sprintf( 'Shipment %d could not be reloaded after update.', $shipment->id ) );
		}

		return $updated;
	}

	public function replaceItems( int $shipment_id, array $items ): void {
		if ( $shipment_id <= 0 ) {
			throw new \InvalidArgumentException( 'replaceItems requires a persisted shipment_id.' );
		}

		if ( null === $this->findById( $shipment_id ) ) {
			throw new \RuntimeException( sprintf( 'Cannot replace items for missing shipment %d.', $shipment_id ) );
		}

		global $wpdb;

		$table = TableNames::for( ShipmentSchema::ITEMS_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'shipment_id' => $shipment_id ], [ '%d' ] );

		foreach ( $items as $item ) {
			if ( ! $item instanceof ShipmentItem ) {
				throw new \InvalidArgumentException( 'replaceItems expects ShipmentItem instances.' );
			}

			$now = gmdate( 'Y-m-d H:i:s' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table,
				[
					'shipment_id'           => $shipment_id,
					'order_id'              => $item->order_id,
					'order_item_id'         => $item->order_item_id,
					'product_id'            => $item->product_id,
					'variation_id'          => $item->variation_id,
					'quantity'              => $item->quantity,
					'product_name_snapshot' => $item->product_name_snapshot,
					'created_at'            => '' !== $item->created_at ? $item->created_at : $now,
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				throw new \RuntimeException( 'Failed to persist shipment item.' );
			}
		}
	}

	public function findItems( int $shipment_id ): array {
		if ( $shipment_id <= 0 ) {
			return [];
		}

		global $wpdb;

		$table = TableNames::for( ShipmentSchema::ITEMS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE `shipment_id` = %d ORDER BY `id` ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $shipment_id ), ARRAY_A );

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$items[] = $this->hydrate_item( $row );
			}
		}

		return $items;
	}

	public function countItemsByShipmentIds( array $shipment_ids ): array {
		$ids = [];

		foreach ( $shipment_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( [] === $ids ) {
			return [];
		}

		global $wpdb;

		$ids           = array_values( $ids );
		$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$table         = TableNames::for( ShipmentSchema::ITEMS_SUFFIX );
		$sql           = "SELECT `shipment_id`, COUNT(*) AS `item_count` FROM `{$table}` WHERE `shipment_id` IN ({$placeholders}) GROUP BY `shipment_id`";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );

		$counts = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$shipment_id = (int) ( $row['shipment_id'] ?? 0 );

			if ( $shipment_id > 0 ) {
				$counts[ $shipment_id ] = (int) ( $row['item_count'] ?? 0 );
			}
		}

		return $counts;
	}

	public function appendEvent( ShipmentEvent $event ): ShipmentEvent {
		if ( $event->shipment_id <= 0 ) {
			throw new \InvalidArgumentException( 'Cannot append an event without a shipment_id.' );
		}

		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = TableNames::for( ShipmentSchema::EVENTS_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'shipment_id'   => $event->shipment_id,
				'event_type'    => $event->event_type->value,
				'from_status'   => $event->from_status?->value,
				'to_status'     => $event->to_status?->value,
				'public_note'   => $event->public_note,
				'internal_note' => $event->internal_note,
				'actor_user_id' => $event->actor_user_id,
				'source'        => $event->source->value,
				'event_at'      => $event->event_at,
				'created_at'    => '' !== $event->created_at ? $event->created_at : $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to append shipment event.' );
		}

		$id     = (int) $wpdb->insert_id;
		$stored = $this->find_event_by_id( $id );

		if ( null === $stored ) {
			throw new \RuntimeException( 'Shipment event insert succeeded but the row could not be reloaded.' );
		}

		return $stored;
	}

	public function findEvents( int $shipment_id ): array {
		if ( $shipment_id <= 0 ) {
			return [];
		}

		global $wpdb;

		$table = TableNames::for( ShipmentSchema::EVENTS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE `shipment_id` = %d ORDER BY `event_at` ASC, `id` ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $shipment_id ), ARRAY_A );

		$events = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$events[] = $this->hydrate_event( $row );
			}
		}

		return $events;
	}

	public function ensureCompleteAggregate( Shipment $draft, array $items ): ShipmentAggregateWriteResult {
		return $this->transact(
			function () use ( $draft, $items ): ShipmentAggregateWriteResult {
				$existing = $this->findByOrderAndGroup( $draft->order_id, $draft->delivery_group_id );
				$status   = ShipmentAggregateWriteResult::CREATED;

				if ( null === $existing ) {
					$existing = $this->insert_new_shipment( $draft );
				} else {
					$status = ShipmentAggregateWriteResult::ALREADY_COMPLETE;
				}

				$changed = $this->ensure_items( $existing->id, $items );

				if ( $this->ensure_created_event( $existing->id ) ) {
					$changed = true;
				}

				if ( ! $this->aggregate_is_complete( $existing->id, $items ) ) {
					throw new \RuntimeException( 'Shipment aggregate is incomplete after write.' );
				}

				if ( ShipmentAggregateWriteResult::CREATED !== $status && $changed ) {
					$status = ShipmentAggregateWriteResult::REPAIRED;
				}

				$reloaded = $this->findById( $existing->id );

				if ( null === $reloaded ) {
					throw new \RuntimeException( 'Shipment aggregate could not be reloaded.' );
				}

				return new ShipmentAggregateWriteResult( $reloaded, $status );
			}
		);
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function transact( callable $work ): mixed {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $work();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );

			return $result;
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );

			throw $exception;
		}
	}

	private function insert_new_shipment( Shipment $shipment ): Shipment {
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = $this->to_shipment_row( $shipment, $now, true );
		$id  = $this->insert_row( $row['data'], $row['formats'] );

		if ( $id <= 0 ) {
			if ( $this->is_duplicate_key_error() ) {
				$existing = $this->findByOrderAndGroup( $shipment->order_id, $shipment->delivery_group_id );

				if ( null !== $existing ) {
					return $existing;
				}
			}

			throw new \RuntimeException( 'Failed to create shipment record.' );
		}

		$created = $this->findById( $id );

		if ( null === $created ) {
			throw new \RuntimeException( 'Shipment insert succeeded but the row could not be reloaded.' );
		}

		return $created;
	}

	/**
	 * @param list<ShipmentItem> $items
	 */
	private function ensure_items( int $shipment_id, array $items ): bool {
		$existing     = $this->findItems( $shipment_id );
		$existing_ids = [];

		foreach ( $existing as $item ) {
			$existing_ids[ $item->order_item_id ] = true;
		}

		$changed = false;

		foreach ( $items as $item ) {
			if ( isset( $existing_ids[ $item->order_item_id ] ) ) {
				continue;
			}

			$this->insert_item( $shipment_id, $item );
			$changed = true;
		}

		return $changed;
	}

	private function insert_item( int $shipment_id, ShipmentItem $item ): void {
		global $wpdb;

		$table = TableNames::for( ShipmentSchema::ITEMS_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'shipment_id'           => $shipment_id,
				'order_id'              => $item->order_id,
				'order_item_id'         => $item->order_item_id,
				'product_id'            => $item->product_id,
				'variation_id'          => $item->variation_id,
				'quantity'              => $item->quantity,
				'product_name_snapshot' => $item->product_name_snapshot,
				'created_at'            => '' !== $item->created_at ? $item->created_at : $now,
			],
			[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			if ( $this->is_duplicate_key_error() ) {
				return;
			}

			throw new \RuntimeException( 'Failed to persist shipment item.' );
		}
	}

	private function ensure_created_event( int $shipment_id ): bool {
		foreach ( $this->findEvents( $shipment_id ) as $event ) {
			if ( ShipmentEventType::Created === $event->event_type ) {
				return false;
			}
		}

		$this->appendEvent(
			ShipmentEvent::create(
				$shipment_id,
				ShipmentEventType::Created,
				ShipmentEventSource::System,
				null,
				ShipmentStatus::AwaitingFulfilment
			)
		);

		return true;
	}

	/**
	 * @param list<ShipmentItem> $expected
	 */
	private function aggregate_is_complete( int $shipment_id, array $expected ): bool {
		$stored     = $this->findItems( $shipment_id );
		$stored_ids = [];

		foreach ( $stored as $item ) {
			$stored_ids[ $item->order_item_id ] = true;
		}

		foreach ( $expected as $item ) {
			if ( ! isset( $stored_ids[ $item->order_item_id ] ) ) {
				return false;
			}
		}

		foreach ( $this->findEvents( $shipment_id ) as $event ) {
			if ( ShipmentEventType::Created === $event->event_type ) {
				return true;
			}
		}

		return false;
	}

	private function find_event_by_id( int $id ): ?ShipmentEvent {
		global $wpdb;

		$table = TableNames::for( ShipmentSchema::EVENTS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_event( $row ) : null;
	}

	/**
	 * @return array{data: array<string, mixed>, formats: list<string>}
	 */
	private function to_shipment_row( Shipment $shipment, string $now, bool $for_insert ): array {
		$data = [
			'order_id'                      => $shipment->order_id,
			'shipment_number'               => $shipment->shipment_number,
			'idempotency_key'               => $shipment->idempotency_key,
			'delivery_group_id'             => $shipment->delivery_group_id,
			'status'                        => $shipment->status->value,
			'fulfilment_availability'       => $shipment->fulfilment_availability,
			'fulfilment_choice'             => $shipment->fulfilment_choice,
			'delivery_offer_id'             => $shipment->delivery_offer_id,
			'delivery_offer_public_label'   => $shipment->delivery_offer_public_label,
			'route'                         => $shipment->route,
			'service_level'                 => $shipment->service_level,
			'carrier_visibility'            => $shipment->carrier_visibility,
			'public_carrier_name'           => $shipment->public_carrier_name,
			'destination_zone_id'           => $shipment->destination_zone_id,
			'logistics_profile_id'          => $shipment->logistics_profile_id,
			'supplier_id'                   => $shipment->supplier_id,
			'origin_id'                     => $shipment->origin_id,
			'currency_code'                 => $shipment->currency_code,
			'customer_paid_shipping_amount' => $shipment->customer_paid_shipping_amount,
			'rate_card_id'                  => $shipment->rate_card_id,
			'rate_card_code'                => $shipment->rate_card_code,
			'internal_cost'                 => $shipment->internal_cost,
			'eta_original'                  => $shipment->eta_original,
			'eta_current'                   => $shipment->eta_current,
			'tracking_number'               => $shipment->tracking_number,
			'tracking_url'                  => $shipment->tracking_url,
			'tracking_carrier_display'      => $shipment->tracking_carrier_display,
			'dispatch_at'                   => $shipment->dispatch_at,
			'delivered_at'                  => $shipment->delivered_at,
			'public_note'                   => $shipment->public_note,
			'private_note'                  => $shipment->private_note,
			'wc_fulfillment_id'             => $shipment->wc_fulfillment_id,
			'updated_at'                    => '' !== $shipment->updated_at && ! $for_insert ? $shipment->updated_at : $now,
		];

		$formats = [
			'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s',
			'%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%d', '%s',
		];

		if ( $for_insert ) {
			$data['created_at'] = '' !== $shipment->created_at ? $shipment->created_at : $now;
			$formats[]          = '%s';
		}

		return [
			'data'    => $data,
			'formats' => $formats,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_shipment( array $row ): Shipment {
		$status = ShipmentStatus::tryFromMachineCode( (string) ( $row['status'] ?? '' ) );

		if ( null === $status ) {
			throw new \RuntimeException( 'Persisted shipment status is not a recognised machine code.' );
		}

		return new Shipment(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(string) ( $row['shipment_number'] ?? '' ),
			(string) ( $row['idempotency_key'] ?? '' ),
			(string) ( $row['delivery_group_id'] ?? '' ),
			$status,
			(string) ( $row['fulfilment_availability'] ?? '' ),
			(string) ( $row['fulfilment_choice'] ?? '' ),
			$this->nullable_int( $row['delivery_offer_id'] ?? null ),
			$this->nullable_string( $row['delivery_offer_public_label'] ?? null ),
			$this->nullable_string( $row['route'] ?? null ),
			$this->nullable_string( $row['service_level'] ?? null ),
			$this->nullable_string( $row['carrier_visibility'] ?? null ),
			$this->nullable_string( $row['public_carrier_name'] ?? null ),
			$this->nullable_int( $row['destination_zone_id'] ?? null ),
			$this->nullable_int( $row['logistics_profile_id'] ?? null ),
			$this->nullable_int( $row['supplier_id'] ?? null ),
			$this->nullable_int( $row['origin_id'] ?? null ),
			(string) ( $row['currency_code'] ?? '' ),
			$this->nullable_string( $row['customer_paid_shipping_amount'] ?? null ),
			$this->nullable_int( $row['rate_card_id'] ?? null ),
			$this->nullable_string( $row['rate_card_code'] ?? null ),
			$this->nullable_string( $row['internal_cost'] ?? null ),
			$this->nullable_string( $row['eta_original'] ?? null ),
			$this->nullable_string( $row['eta_current'] ?? null ),
			$this->nullable_string( $row['tracking_number'] ?? null ),
			$this->nullable_string( $row['tracking_url'] ?? null ),
			$this->nullable_string( $row['tracking_carrier_display'] ?? null ),
			$this->nullable_string( $row['dispatch_at'] ?? null ),
			$this->nullable_string( $row['delivered_at'] ?? null ),
			$this->nullable_string( $row['public_note'] ?? null ),
			$this->nullable_string( $row['private_note'] ?? null ),
			$this->nullable_int( $row['wc_fulfillment_id'] ?? null ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_item( array $row ): ShipmentItem {
		return new ShipmentItem(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['shipment_id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(int) ( $row['order_item_id'] ?? 0 ),
			$this->nullable_int( $row['product_id'] ?? null ),
			$this->nullable_int( $row['variation_id'] ?? null ),
			max( 1, (int) ( $row['quantity'] ?? 1 ) ),
			(string) ( $row['product_name_snapshot'] ?? '' ),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_event( array $row ): ShipmentEvent {
		$type = ShipmentEventType::tryFrom( (string) ( $row['event_type'] ?? '' ) );
		$source = ShipmentEventSource::tryFrom( (string) ( $row['source'] ?? '' ) );

		if ( null === $type || null === $source ) {
			throw new \RuntimeException( 'Persisted shipment event uses an unrecognised machine code.' );
		}

		$from = isset( $row['from_status'] ) && '' !== (string) $row['from_status']
			? ShipmentStatus::tryFromMachineCode( (string) $row['from_status'] )
			: null;
		$to = isset( $row['to_status'] ) && '' !== (string) $row['to_status']
			? ShipmentStatus::tryFromMachineCode( (string) $row['to_status'] )
			: null;

		if ( ( isset( $row['from_status'] ) && '' !== (string) $row['from_status'] && null === $from )
			|| ( isset( $row['to_status'] ) && '' !== (string) $row['to_status'] && null === $to ) ) {
			throw new \RuntimeException( 'Persisted shipment event status is not a recognised machine code.' );
		}

		return new ShipmentEvent(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['shipment_id'] ?? 0 ),
			$type,
			$from,
			$to,
			$this->nullable_string( $row['public_note'] ?? null ),
			$this->nullable_string( $row['internal_note'] ?? null ),
			$this->nullable_int( $row['actor_user_id'] ?? null ),
			$source,
			(string) ( $row['event_at'] ?? '' ),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	private function is_duplicate_key_error(): bool {
		global $wpdb;

		$error = strtolower( (string) ( $wpdb->last_error ?? '' ) );

		return str_contains( $error, 'duplicate' ) || str_contains( $error, 'unique constraint' );
	}

	private function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	private function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	private function like_prefix( string $term ): string {
		global $wpdb;

		$escaped = method_exists( $wpdb, 'esc_like' )
			? (string) $wpdb->esc_like( $term )
			: addcslashes( $term, "_%\\" );

		return $escaped . '%';
	}
}
