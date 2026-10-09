<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentAggregateWriteResult;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use WC_Order;

/**
 * Creates delivery shipments from historical paid-order snapshots.
 */
final class ShipmentService {

	/** @var list<string> */
	private const INELIGIBLE_STATUSES = [
		'cancelled',
		'refunded',
		'failed',
		'trash',
		'checkout-draft',
	];

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly HistoricalOrderShipmentContextFactory $context_factory,
		private readonly HistoricalShipmentPlanner $planner,
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly ShipmentCreationFailureStore $failures,
		private readonly AuditLogRepositoryInterface $audit,
		private readonly Logger $logger,
		private readonly ?CodAwaitingShipmentEvaluator $cod_awaiting = null,
		private readonly ?\CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromisePort $promise_service = null
	) {
	}

	public function create_for_paid_order(
		WC_Order $order,
		ShipmentEventSource $source = ShipmentEventSource::System,
		bool $from_payment_complete_event = false
	): ShipmentCreationResult {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::FeatureDisabled );
		}

		if ( (int) $order->get_id() <= 0 ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::NotPaid );
		}

		if ( ! $this->is_payment_confirmed( $order, $from_payment_complete_event ) ) {
			$this->refresh_cod_awaiting( $order );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::NotPaid );
		}

		if ( $this->is_ineligible( $order ) ) {
			$this->refresh_cod_awaiting( $order );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::Ineligible );
		}

		$context = $this->context_factory->from_order( $order );

		if ( ! $context->has_delivery_engine_snapshot() ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::NotDeliveryEngineOrder );
		}

		$plan_result = $this->planner->plan( $context );

		if ( ! $plan_result->ok ) {
			$error = $plan_result->error_code ?? ShipmentCreationErrorCode::MalformedGroupSnapshot;
			$this->record_failure( $order, $error, $source );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::InvalidSnapshot, [], $error );
		}

		if ( [] === $plan_result->plans ) {
			$metadata_order = $this->metadata_order( $order );
			if ( null !== $metadata_order ) { $this->failures->mark_succeeded( $metadata_order ); }
			$this->refresh_cod_awaiting( $order );
			$this->audit_success( $order, ShipmentCreationOutcome::ZeroShipmentsPickupOnly, $source, [] );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::ZeroShipmentsPickupOnly );
		}

		try {
			$shipments = [];
			$statuses  = [];

			foreach ( $plan_result->plans as $plan ) {
				$write       = $this->persist_plan( $plan, $source, null, $order );
				$shipments[] = $write->shipment;
				$statuses[]  = $write->status;
			}

			$metadata_order = $this->metadata_order( $order );
			if ( null !== $metadata_order ) { $this->failures->mark_succeeded( $metadata_order ); }
			$this->refresh_cod_awaiting( $order );

			$outcome = $this->outcome_from_writes( $statuses );
			$this->audit_success( $order, $outcome, $source, $shipments );

			return ShipmentCreationResult::of( $outcome, $shipments );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Shipment creation failed.',
				[
					'order_id'   => (int) $order->get_id(),
					'error_code' => ShipmentCreationErrorCode::RepositoryWriteFailed->value,
				]
			);
			unset( $exception );
			$this->record_failure( $order, ShipmentCreationErrorCode::RepositoryWriteFailed, $source );

			return ShipmentCreationResult::of(
				ShipmentCreationOutcome::CreationFailed,
				[],
				ShipmentCreationErrorCode::RepositoryWriteFailed
			);
		}
	}

	/**
	 * Staff-authorised creation from the historical order snapshot.
	 *
	 * Does not require payment confirmation. Does not use current product configuration.
	 */
	public function create_from_historical_order_for_staff( WC_Order $order, ?int $actor_user_id ): ShipmentCreationResult {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::FeatureDisabled );
		}

		if ( (int) $order->get_id() <= 0 ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::NotDeliveryEngineOrder );
		}

		if ( $this->is_ineligible( $order ) ) {
			$this->refresh_cod_awaiting( $order );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::Ineligible );
		}

		$context = $this->context_factory->from_order( $order );

		if ( ! $context->has_delivery_engine_snapshot() ) {
			return ShipmentCreationResult::of( ShipmentCreationOutcome::NotDeliveryEngineOrder );
		}

		$plan_result = $this->planner->plan( $context );

		if ( ! $plan_result->ok ) {
			$error = $plan_result->error_code ?? ShipmentCreationErrorCode::MalformedGroupSnapshot;
			$this->record_failure( $order, $error, ShipmentEventSource::Staff );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::InvalidSnapshot, [], $error );
		}

		if ( [] === $plan_result->plans ) {
			$metadata_order = $this->metadata_order( $order );
			if ( null !== $metadata_order ) { $this->failures->mark_succeeded( $metadata_order ); }
			$this->refresh_cod_awaiting( $order );
			$this->audit_success( $order, ShipmentCreationOutcome::ZeroShipmentsPickupOnly, ShipmentEventSource::Staff, [] );

			return ShipmentCreationResult::of( ShipmentCreationOutcome::ZeroShipmentsPickupOnly );
		}

		try {
			$shipments = [];
			$statuses  = [];

			foreach ( $plan_result->plans as $plan ) {
				$write       = $this->persist_plan( $plan, ShipmentEventSource::Staff, $actor_user_id, $order );
				$shipments[] = $write->shipment;
				$statuses[]  = $write->status;
			}

			$metadata_order = $this->metadata_order( $order );
			if ( null !== $metadata_order ) { $this->failures->mark_succeeded( $metadata_order ); }
			$this->refresh_cod_awaiting( $order );

			$outcome = $this->outcome_from_writes( $statuses );
			$this->audit_success( $order, $outcome, ShipmentEventSource::Staff, $shipments );

			return ShipmentCreationResult::of( $outcome, $shipments );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Manual shipment creation failed.',
				[
					'order_id'   => (int) $order->get_id(),
					'error_code' => ShipmentCreationErrorCode::RepositoryWriteFailed->value,
				]
			);
			unset( $exception );
			$this->record_failure( $order, ShipmentCreationErrorCode::RepositoryWriteFailed, ShipmentEventSource::Staff );

			return ShipmentCreationResult::of(
				ShipmentCreationOutcome::CreationFailed,
				[],
				ShipmentCreationErrorCode::RepositoryWriteFailed
			);
		}
	}

	private function persist_plan(
		ShipmentPlan $plan,
		ShipmentEventSource $source = ShipmentEventSource::System,
		?int $actor_user_id = null,
		?WC_Order $order = null
	): ShipmentAggregateWriteResult {
		$draft = Shipment::create(
			order_id: $plan->order_id,
			delivery_group_id: $plan->delivery_group_id,
			status: ShipmentStatus::AwaitingFulfilment,
			fulfilment_availability: $plan->fulfilment_availability,
			fulfilment_choice: $plan->fulfilment_choice,
			delivery_offer_id: $plan->delivery_offer_id,
			delivery_offer_public_label: $plan->delivery_offer_public_label,
			destination_zone_id: $plan->destination_zone_id,
			currency_code: $plan->currency_code,
			customer_paid_shipping_amount: $plan->customer_paid_shipping_amount,
			rate_card_id: $plan->rate_card_id,
			rate_card_code: $plan->rate_card_code,
			eta_original: $plan->eta_original,
			shipment_number: $plan->shipment_number
		);

		$items = [];

		foreach ( $plan->items as $item ) {
			$items[] = ShipmentItem::create(
				0,
				$item->order_id,
				$item->order_item_id,
				$item->quantity,
				$item->product_id,
				$item->variation_id,
				$item->product_name_snapshot
			);
		}

		$created_source = ShipmentEventSource::Staff === $source
			? ShipmentEventSource::Staff
			: ShipmentEventSource::System;
		$created_actor  = ShipmentEventSource::Staff === $source && null !== $actor_user_id && $actor_user_id > 0
			? $actor_user_id
			: null;

		if ( null !== $this->promise_service && null !== $order ) {
			$promise = $this->promise_service->create_aggregate( $order, $draft, $items, $created_source, $created_actor );
			if ( null !== $promise ) { return $promise; }
		}
		if ( null === $this->promise_service && null !== $order ) {
			$package=(new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader())->read_package($order);
			if ($package->delivery_quote?->envelope?->is_promise()) { throw new \RuntimeException('Shipment promise runtime unavailable.'); }
		}
		return $this->shipments->ensureCompleteAggregate( $draft, $items, $created_source, $created_actor );
	}

	/** Promise refusals never save incidental mutations on a caller-supplied native object. */
	private function metadata_order( WC_Order $order ): ?WC_Order {
		$package = ( new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader() )->read_package( $order );
		$declared = $package->delivery_quote?->envelope?->is_promise()
			|| '3' === (string) $order->get_meta( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true )
			|| '2' === (string) $order->get_meta( \CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope::META_FORMAT, true );
		// Woo's order cache may return the supplied dirty instance. Loading by ID
		// creates an independent native data-store read instead of saving that object.
		try { $fresh = new WC_Order( $order->get_id() ); }
		catch ( \Throwable ) {
			$cached = function_exists( 'wc_get_order' ) ? wc_get_order( $order->get_id() ) : null;
			$cached_promise = $cached instanceof WC_Order && ( '3' === (string) $cached->get_meta( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true ) || '2' === (string) $cached->get_meta( \CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope::META_FORMAT, true ) );
			return $declared || $cached_promise ? null : $order;
		}
		if ( $fresh->get_id() !== $order->get_id() ) { return $declared ? null : $order; }
		$fresh_package = ( new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader() )->read_package( $fresh );
		$persisted_promise = $fresh_package->delivery_quote?->envelope?->is_promise()
			|| '3' === (string) $fresh->get_meta( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true )
			|| '2' === (string) $fresh->get_meta( \CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope::META_FORMAT, true );
		return $declared || $persisted_promise ? $fresh : $order;
	}

	private function refresh_cod_awaiting( WC_Order $order ): void {
		$order = $this->metadata_order( $order );
		if ( null === $order ) { return; }
		if ( $this->cod_awaiting instanceof CodAwaitingShipmentEvaluator ) {
			$this->cod_awaiting->sync( $order );
		}
	}

	/**
	 * @param list<string> $statuses
	 */
	private function outcome_from_writes( array $statuses ): ShipmentCreationOutcome {
		$created  = in_array( ShipmentAggregateWriteResult::CREATED, $statuses, true );
		$repaired = in_array( ShipmentAggregateWriteResult::REPAIRED, $statuses, true );

		if ( $repaired && ! $created ) {
			return ShipmentCreationOutcome::CompletedExistingIncomplete;
		}

		if ( $created ) {
			return ShipmentCreationOutcome::Created;
		}

		return ShipmentCreationOutcome::AlreadyExistsComplete;
	}

	/**
	 * Payment-complete is the authoritative WooCommerce signal.
	 * Status fallbacks require persisted paid-date evidence, not WC_Order::is_paid().
	 */
	private function is_payment_confirmed( WC_Order $order, bool $from_payment_complete_event ): bool {
		if ( $from_payment_complete_event ) {
			return true;
		}

		return $this->has_persisted_paid_date( $order );
	}

	private function has_persisted_paid_date( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_date_paid' ) ) {
			return false;
		}

		$paid = $order->get_date_paid();

		if ( $paid instanceof \DateTimeInterface ) {
			return $paid->getTimestamp() > 0;
		}

		if ( is_numeric( $paid ) ) {
			return (int) $paid > 0;
		}

		if ( ! is_string( $paid ) ) {
			return false;
		}

		$trimmed = trim( $paid );

		if ( '' === $trimmed || '0' === $trimmed || '0000-00-00 00:00:00' === $trimmed ) {
			return false;
		}

		$timestamp = strtotime( $trimmed );

		return false !== $timestamp && $timestamp > 0;
	}

	private function is_ineligible( WC_Order $order ): bool {
		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		return in_array( $status, self::INELIGIBLE_STATUSES, true );
	}

	private function record_failure( WC_Order $order, ShipmentCreationErrorCode $error, ShipmentEventSource $source ): void {
		$metadata_order = $this->metadata_order( $order );
		if ( null !== $metadata_order ) { $this->failures->mark_failed( $metadata_order, $error ); }
		$this->audit->append(
			[
				'action'       => 'shipment_creation_failed',
				'entity_type'  => 'order',
				'entity_id'    => (int) $order->get_id(),
				'new_value'    => [
					'error_code' => $error->value,
					'source'     => $source->value,
				],
			]
		);
		$this->logger->warning(
			'Shipment creation recorded as failed.',
			[
				'order_id'   => (int) $order->get_id(),
				'error_code' => $error->value,
			]
		);
	}

	/**
	 * @param list<Shipment> $shipments
	 */
	private function audit_success(
		WC_Order $order,
		ShipmentCreationOutcome $outcome,
		ShipmentEventSource $source,
		array $shipments
	): void {
		$ids = [];

		foreach ( $shipments as $shipment ) {
			$ids[] = $shipment->id;
		}

		$action = ShipmentEventSource::Retry === $source
			? 'shipment_creation_retry_succeeded'
			: 'shipment_creation_succeeded';

		if ( ShipmentCreationOutcome::Created !== $outcome && ShipmentCreationOutcome::CompletedExistingIncomplete !== $outcome && ShipmentCreationOutcome::ZeroShipmentsPickupOnly !== $outcome ) {
			$action = 'shipment_creation_idempotent';
		}

		$this->audit->append(
			[
				'action'      => $action,
				'entity_type' => 'order',
				'entity_id'   => (int) $order->get_id(),
				'new_value'   => [
					'outcome'      => $outcome->value,
					'source'       => $source->value,
					'shipment_ids' => $ids,
				],
			]
		);
	}
}
