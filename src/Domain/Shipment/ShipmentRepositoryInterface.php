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
	public function ensureCompleteAggregate( Shipment $draft, array $items ): ShipmentAggregateWriteResult;
}
