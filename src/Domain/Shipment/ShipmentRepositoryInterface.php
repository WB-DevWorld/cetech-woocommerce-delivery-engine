<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * Persistence contract for shipment records, items, and events.
 *
 * V1 canonical implementation is WpdbShipmentRepository. Native WooCommerce
 * Fulfillments is reserved and unwired — no dual-write.
 */
interface ShipmentRepositoryInterface {

	/**
	 * Insert a shipment, or return the existing row for the same order_id + delivery_group_id.
	 */
	public function create( Shipment $shipment ): Shipment;

	public function findById( int $id ): ?Shipment;

	public function findByOrderAndGroup( int $order_id, string $delivery_group_id ): ?Shipment;

	public function findByIdempotencyKey( string $idempotency_key ): ?Shipment;

	/**
	 * @return list<Shipment>
	 */
	public function findByOrderId( int $order_id ): array;

	/**
	 * @param array{status?: string, order_id?: int, search?: string} $criteria Status must be a machine code.
	 */
	public function list( array $criteria = [], int $page = 1, int $per_page = 20 ): ShipmentListResult;

	/**
	 * Batch item counts for list pages. Keys are shipment ids.
	 *
	 * @param list<int> $shipment_ids
	 * @return array<int, int>
	 */
	public function countItemsByShipmentIds( array $shipment_ids ): array;

	public function update( Shipment $shipment ): Shipment;

	/**
	 * @param list<ShipmentItem> $items
	 */
	public function replaceItems( int $shipment_id, array $items ): void;

	/**
	 * @return list<ShipmentItem>
	 */
	public function findItems( int $shipment_id ): array;

	public function appendEvent( ShipmentEvent $event ): ShipmentEvent;

	/**
	 * @return list<ShipmentEvent>
	 */
	public function findEvents( int $shipment_id ): array;

	/**
	 * Atomically persist a shipment plus its items and initial created event.
	 *
	 * Repairs an incomplete aggregate. Does not duplicate complete items/events.
	 *
	 * @param list<ShipmentItem> $items
	 */
	public function ensureCompleteAggregate(
		Shipment $draft,
		array $items,
		\CetechDeliveryEngine\Domain\Enum\ShipmentEventSource $created_source = \CetechDeliveryEngine\Domain\Enum\ShipmentEventSource::System,
		?int $created_actor_user_id = null
	): ShipmentAggregateWriteResult;

	/**
	 * Highest persisted shipment-event ID. 0 when no events exist.
	 */
	/**
	 * COR-031: whether the stored aggregate equals the frozen required item set exactly
	 * (same order items, order ids and quantities, nothing extra) and carries a Created event.
	 *
	 * @param list<ShipmentItem> $expected_items
	 */
	public function aggregateIsComplete( int $shipment_id, array $expected_items ): bool;

	/**
	 * COR-032: write only the given columns and the attributable event(s) in one transaction,
	 * guarded by the values the editor loaded for those columns. When the guard fails because
	 * the row already holds the target values (a partial earlier write or an identical retry)
	 * the write is treated as applied and the events are appended; any other change since the
	 * load raises ShipmentConcurrentEditException and nothing is written.
	 *
	 * @param array<string, mixed> $fields   Column => new value.
	 * @param array<string, mixed> $expected Column => value the editor loaded.
	 */
	public function updateFieldsWithEvent( int $shipment_id, array $fields, array $expected, ShipmentEvent ...$events ): Shipment;

	public function maxEventId(): int;

	/**
	 * Distinct shipments that have at least one event after the given event ID.
	 * Uses the events primary key. Does not scan WooCommerce orders.
	 */
	public function countDistinctShipmentsWithEventsAfter( int $after_event_id ): int;
}
