<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Builds a staff-safe preview from the historical order snapshot only.
 *
 * Never consults current product configuration or the live resolver.
 */
final class ManualShipmentCreationPreviewFactory {

	public const ORDER_ID_QUERY = 'cetech_de_order_id';

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly HistoricalOrderShipmentContextFactory $context_factory,
		private readonly HistoricalShipmentPlanner $planner,
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly CodAwaitingShipmentEvaluator $evaluator
	) {
	}

	public function from_order_id( mixed $order_id ): ManualShipmentCreationPreview {
		$id = (int) $order_id;

		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_ORDER_NOT_FOUND );
		}

		$order = wc_get_order( $id );

		if ( ! $order instanceof WC_Order ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_ORDER_NOT_FOUND );
		}

		return $this->from_order( $order );
	}

	public function from_order( WC_Order $order ): ManualShipmentCreationPreview {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_FEATURE_DISABLED, $order );
		}

		if ( $this->evaluator->is_ineligible_status( $order ) ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_INELIGIBLE, $order );
		}

		$context = $this->context_factory->from_order( $order );

		if ( ! $context->has_delivery_engine_snapshot() ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_NO_SNAPSHOT, $order );
		}

		$plan_result = $this->planner->plan( $context );

		if ( ! $plan_result->ok ) {
			return $this->failure( ManualShipmentCreationPreview::CODE_INVALID_SNAPSHOT, $order );
		}

		$groups = $this->groups_from_plans( $plan_result );
		$items  = $this->items_from_plans( $plan_result );
		$facts  = $this->order_facts( $order );

		if ( [] === $plan_result->plans ) {
			return new ManualShipmentCreationPreview(
				ManualShipmentCreationPreview::CODE_PICKUP_ONLY,
				ManualShipmentCreationMessages::for_preview_code( ManualShipmentCreationPreview::CODE_PICKUP_ONLY ),
				$facts['order_id'],
				$facts['order_number'],
				$facts['customer_name'],
				$facts['order_date'],
				$facts['payment_method_label'],
				$facts['destination_summary'],
				$facts['order_url'],
				$groups,
				$items
			);
		}

		$needs_creation = false;

		foreach ( $groups as $group ) {
			if ( $group->needs_creation ) {
				$needs_creation = true;
				break;
			}
		}

		$code = $needs_creation
			? ManualShipmentCreationPreview::CODE_READY
			: ManualShipmentCreationPreview::CODE_ALREADY_EXISTS;

		return new ManualShipmentCreationPreview(
			$code,
			ManualShipmentCreationMessages::for_preview_code( $code ),
			$facts['order_id'],
			$facts['order_number'],
			$facts['customer_name'],
			$facts['order_date'],
			$facts['payment_method_label'],
			$facts['destination_summary'],
			$facts['order_url'],
			$groups,
			$items
		);
	}

	/**
	 * @return list<ManualShipmentPreviewGroup>
	 */
	private function groups_from_plans( ShipmentPlanResult $plan_result ): array {
		$groups = [];

		foreach ( $plan_result->plans as $plan ) {
			$existing = $this->shipments->findByOrderAndGroup( $plan->order_id, $plan->delivery_group_id );
			$number   = null;
			$id       = null;

			if ( null !== $existing ) {
				$number = $this->shipment_reference( $existing );
				$id     = $existing->id;
			}

			$groups[] = new ManualShipmentPreviewGroup(
				trim( (string) $plan->delivery_offer_public_label ) !== ''
					? (string) $plan->delivery_offer_public_label
					: __( 'Delivery option unavailable', 'cetech-woocommerce-delivery-engine' ),
				$this->fulfilment_label( $plan->fulfilment_choice ),
				trim( (string) $plan->eta_original ),
				false,
				null === $existing,
				$number,
				$id
			);
		}

		if ( $plan_result->pickup_groups_skipped > 0 ) {
			$groups[] = new ManualShipmentPreviewGroup(
				__( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
				__( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
				'',
				true,
				false,
				null,
				null
			);
		}

		return $groups;
	}

	/**
	 * @return list<ManualShipmentPreviewItem>
	 */
	private function items_from_plans( ShipmentPlanResult $plan_result ): array {
		$items = [];

		foreach ( $plan_result->plans as $plan ) {
			$option = trim( (string) $plan->delivery_offer_public_label );

			foreach ( $plan->items as $item ) {
				$items[] = new ManualShipmentPreviewItem(
					$item->product_name_snapshot,
					$item->quantity,
					'' !== $option ? $option : __( 'Delivery option unavailable', 'cetech-woocommerce-delivery-engine' )
				);
			}
		}

		return $items;
	}

	/**
	 * @return array{order_id: int, order_number: string, customer_name: string, order_date: string, payment_method_label: string, destination_summary: string, order_url: string}
	 */
	private function order_facts( WC_Order $order ): array {
		$order_id = (int) $order->get_id();
		$url      = method_exists( $order, 'get_edit_order_url' )
			? (string) $order->get_edit_order_url()
			: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		return [
			'order_id'             => $order_id,
			'order_number'         => (string) $order->get_order_number(),
			'customer_name'        => $this->customer_name( $order ),
			'order_date'           => $this->order_date( $order ),
			'payment_method_label' => $this->evaluator->payment_method_label( $order ),
			'destination_summary'  => $this->destination_summary( $order ),
			'order_url'            => $url,
		];
	}

	private function customer_name( WC_Order $order ): string {
		if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
			$name = trim( (string) $order->get_formatted_billing_full_name() );

			if ( '' !== $name ) {
				return $name;
			}
		}

		$first = method_exists( $order, 'get_billing_first_name' ) ? trim( (string) $order->get_billing_first_name() ) : '';
		$last  = method_exists( $order, 'get_billing_last_name' ) ? trim( (string) $order->get_billing_last_name() ) : '';
		$name  = trim( $first . ' ' . $last );

		return '' !== $name ? $name : __( 'Customer', 'cetech-woocommerce-delivery-engine' );
	}

	private function order_date( WC_Order $order ): string {
		if ( ! method_exists( $order, 'get_date_created' ) ) {
			return '';
		}

		$created = $order->get_date_created();

		if ( $created instanceof \DateTimeInterface ) {
			return $created->format( 'Y-m-d H:i' );
		}

		if ( is_string( $created ) ) {
			return trim( $created );
		}

		return '';
	}

	private function destination_summary( WC_Order $order ): string {
		$raw = '';

		if ( method_exists( $order, 'get_formatted_shipping_address' ) ) {
			$raw = (string) $order->get_formatted_shipping_address();
		}

		if ( '' === trim( wp_strip_all_tags( $raw ) ) && method_exists( $order, 'get_formatted_billing_address' ) ) {
			$raw = (string) $order->get_formatted_billing_address();
		}

		$summary = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) ?? '' );

		return $summary;
	}

	private function fulfilment_label( string $choice ): string {
		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			return __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
	}

	private function shipment_reference( Shipment $shipment ): string {
		$number = trim( $shipment->shipment_number );

		return '' !== $number ? $number : sprintf( 'SHP-%06d', max( 0, $shipment->id ) );
	}

	private function failure( string $code, ?WC_Order $order = null ): ManualShipmentCreationPreview {
		$facts = null !== $order
			? $this->order_facts( $order )
			: [
				'order_id'             => null,
				'order_number'         => '',
				'customer_name'        => '',
				'order_date'           => '',
				'payment_method_label' => '',
				'destination_summary'  => '',
				'order_url'            => '',
			];

		return new ManualShipmentCreationPreview(
			$code,
			ManualShipmentCreationMessages::for_preview_code( $code ),
			$facts['order_id'],
			$facts['order_number'],
			$facts['customer_name'],
			$facts['order_date'],
			$facts['payment_method_label'],
			$facts['destination_summary'],
			$facts['order_url']
		);
	}
}
