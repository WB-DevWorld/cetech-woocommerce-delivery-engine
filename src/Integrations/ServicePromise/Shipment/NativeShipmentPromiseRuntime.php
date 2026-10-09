<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise\Shipment;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteSavedOrderPlacementEvidenceReader;
use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshotReader, OrderDeliveryPackageReadResult, QuoteNativeOrderStager};
use CetechDeliveryEngine\Application\ServicePromise\Shipment\{ShipmentPromisePort, ShipmentPromiseService};
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\Shipment\{Shipment, ShipmentAggregateWriteResult, ShipmentRepositoryInterface};
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseRuntimeCapture;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Native event and exact-order composition. Reading history never requires current adoption. */
final class NativeShipmentPromiseRuntime implements ShipmentPromisePort {

	/** @var array<string,int> */
	private array $events = [];
	private \Closure $prediction_enabled;
	private \Closure $persisted_order_reader;

	public function __construct( private OperationConnectionFactory $connections, private ShipmentRepositoryInterface $shipments, ?callable $prediction_enabled = null, ?callable $persisted_order_reader = null ) {
		$this->prediction_enabled = null === $prediction_enabled ? static fn(): bool => false : \Closure::fromCallable( $prediction_enabled );
		// Woo's factory cache may return the caller's dirty object. Load a separate persisted carrier.
		$this->persisted_order_reader = null === $persisted_order_reader ? static fn( int $id ): \WC_Order => new \WC_Order( $id ) : \Closure::fromCallable( $persisted_order_reader );
	}

	public function register(): void {
		foreach ( [ 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ] as $hook ) {
			add_action( $hook, [ $this, 'enter_event' ], 5, 1 );
			add_action( $hook, [ $this, 'leave_event' ], PHP_INT_MAX, 1 );
		}
	}

	public function enter_event( mixed $order_id ): void {
		$hook = current_filter(); $id = $order_id instanceof \WC_Order ? $order_id->get_id() : (int) $order_id;
		if ( in_array( $hook, [ 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ], true ) && $id > 0 ) { $this->events[$hook] = $id; }
	}

	public function leave_event( mixed $order_id ): void { unset( $this->events[current_filter()] ); }

	public function create_aggregate( \WC_Order $order, Shipment $draft, array $items, ShipmentEventSource $source, ?int $actor ): ?ShipmentAggregateWriteResult {
		$package = ( new OrderDeliverySnapshotReader() )->read_package( $order );
		if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package->error ) { throw new \RuntimeException( 'Shipment original promise unavailable.' ); }
		if ( null === $package->delivery_quote?->envelope || ! $package->delivery_quote->envelope->is_promise() ) {
			$fresh = ( $this->persisted_order_reader )( $order->get_id() );
			if ( ! $fresh instanceof \WC_Order || $fresh->get_id() !== $order->get_id() ) { throw new \RuntimeException( 'Shipment original promise unavailable.' ); }
			$saved = ( new OrderDeliverySnapshotReader() )->read_package( $fresh );
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $saved->error || $saved->delivery_quote?->envelope?->is_promise() ) { throw new \RuntimeException( 'Shipment original promise unavailable.' ); }
			return null;
		}
		$service = $this->service_for_order( $order );
		if ( null === $service ) { throw new \RuntimeException( 'Shipment original promise unavailable.' ); }
		return $service->create_aggregate( $order, $draft, $items, $source, $actor );
	}

	public function predict_paid_order( \WC_Order $order ): void { if ( true === ( $this->prediction_enabled )() ) { $this->service_for_order( $order )?->predict_paid_order( $order ); } }

	/** A saved packet establishes its structural site key; acknowledged reads still establish authority. */
	public function service_for_order( \WC_Order $order ): ?ShipmentPromiseService {
		try {
			if ( $order->get_id() < 1 || ! $this->may_access( $order, 'read', null ) ) { return null; }
			$package = ( new OrderDeliverySnapshotReader() )->read_package( $order ); $envelope = $package->delivery_quote?->envelope;
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package->error || null === $envelope || ! $envelope->is_promise() ) { return null; }
			$site_key = null; $references = [];
			foreach ( $envelope->promise_packet()->private_facts()['groups'] as $group ) {
				$input = PromiseHistoricalPacket::from_array( $group['packet'] )->input_facts();
				if ( null !== $site_key && $site_key !== $input['site_id'] ) { return null; }
				$site_key = $input['site_id']; foreach ( $input['calendar_refs'] as $reference ) { $references[] = $reference; }
			}
			if ( null === $site_key ) { return null; }
			$binding = PromiseSiteBinding::bind( get_current_blog_id(), $site_key ); $bound_id = $order->get_id(); $snapshot = $envelope->to_private_json();
			$authorize = function( \WC_Order $requested, string $action, ?int $actor ) use ( $bound_id, $snapshot ): bool {
				if ( $requested->get_id() !== $bound_id || ! $this->may_access( $requested, $action, $actor ) ) { return false; }
				$read = ( new OrderDeliverySnapshotReader() )->read_package( $requested );
				return OrderDeliveryPackageReadResult::ERROR_NONE === $read->error && $read->delivery_quote?->envelope?->to_private_json() === $snapshot;
			};
			$original = new QuoteSavedOrderPlacementEvidenceReader( $this->connections, new QuoteNativeOrderStager( $this->connections ), static fn( \WC_Order $requested ): bool => $authorize( $requested, 'read', null ) );
			$calendars = new NativeShipmentPromiseCalendarLoader( $binding, $this->connections, $order, $authorize, $references, hash( 'sha256', $snapshot ) );
			return new ShipmentPromiseService( $binding, $this->connections, $original, $this->shipments, $authorize, $calendars, static fn(): array => ( new NativePromiseRuntimeCapture() )->capture() );
		} catch ( \Throwable ) { return null; }
	}

	private function may_access( \WC_Order $order, string $action, ?int $actor ): bool {
		try {
			if ( ! in_array( $action, [ 'create', 'read', 'payment_confirmed', 'update_current' ], true ) || $order->get_id() < 1 || ! function_exists( 'wc_get_order' ) ) { return false; }
			$fresh = ( $this->persisted_order_reader )( $order->get_id() );
			if ( ! $fresh instanceof \WC_Order || $fresh->get_id() !== $order->get_id() || $fresh->get_customer_id() !== $order->get_customer_id() || $fresh->get_order_key() !== $order->get_order_key() ) { return false; }
			$reader = new OrderDeliverySnapshotReader(); $saved = $reader->read_package( $fresh ); $supplied = $reader->read_package( $order );
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $saved->error || OrderDeliveryPackageReadResult::ERROR_NONE !== $supplied->error || null === $saved->delivery_quote?->envelope || ! $saved->delivery_quote->envelope->is_promise() || $saved->delivery_quote->envelope->to_private_json() !== $supplied->delivery_quote?->envelope?->to_private_json() ) { return false; }
			$staff = is_admin() && ! AdminPageAccess::current_user_is_restricted() && get_current_user_id() > 0 && current_user_can( 'manage_shipments' ) && current_user_can( 'edit_shop_order', $fresh->get_id() );
			if ( 'update_current' === $action ) { return $staff && $actor === get_current_user_id(); }
			if ( 'create' === $action && null !== $actor ) { return $staff && $actor === get_current_user_id(); }
			$event = false; $payment = false;
			foreach ( $this->events as $hook => $id ) { if ( $id === $fresh->get_id() && doing_action( $hook ) ) { $event = true; $payment = $payment || 'woocommerce_payment_complete' === $hook; } }
			$paid = $fresh->get_date_paid(); $confirmed = $paid instanceof \DateTimeInterface && $paid->getTimestamp() > 0 && in_array( $fresh->get_status(), [ 'processing', 'completed' ], true );
			if ( 'payment_confirmed' === $action ) { return null === $actor && $payment && $confirmed; }
			if ( 'create' === $action ) { return null === $actor && $event && $confirmed; }
			if ( $staff || ( $event && $confirmed ) ) { return true; }
			$user = get_current_user_id(); if ( $user > 0 ) { return $user === $fresh->get_customer_id(); }
			$key = $_GET['key'] ?? null;
			return 0 === $fresh->get_customer_id() && is_string( $key ) && '' !== $key && strlen( $key ) <= 200
				&& function_exists( 'is_order_received_page' ) && is_order_received_page() && (int) get_query_var( 'order-received' ) === $fresh->get_id() && hash_equals( $fresh->get_order_key(), $key );
		} catch ( \Throwable ) { return false; }
	}
}
