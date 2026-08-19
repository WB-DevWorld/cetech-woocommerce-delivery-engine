<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipment\ShipmentActivityCursor;
use CetechDeliveryEngine\Application\Shipment\ShipmentDispatchDate;
use CetechDeliveryEngine\Application\Shipment\ShipmentEtaService;
use CetechDeliveryEngine\Application\Shipment\ShipmentListRow;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusTransitionPolicy;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingInput;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceDetail;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;

/**
 * WordPress-native staff Shipments list and detail workspace.
 *
 * Tracking, status, and current ETA may be edited when shipment records are enabled.
 */
final class ShipmentsPage {

	public const SLUG = 'cetech-delivery-engine-shipments';

	public const ACTION_SAVE_TRACKING   = 'cetech_de_save_shipment_tracking';
	public const ACTION_CHANGE_STATUS   = 'cetech_de_change_shipment_status';
	public const ACTION_CORRECT_STATUS  = 'cetech_de_correct_shipment_status';
	public const ACTION_UPDATE_ETA      = 'cetech_de_update_shipment_eta';

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentWorkspaceQuery $query,
		private readonly ?AdminActionHandler $actions = null,
		private readonly ?ShipmentTrackingService $tracking = null,
		private readonly ?ShipmentStatusService $status = null,
		private readonly ?ShipmentEtaService $eta = null,
		private readonly ?ShipmentActivityCursor $activity = null
	) {
	}

	public function handle_actions(): void {
		if ( ! $this->actions instanceof AdminActionHandler ) {
			return;
		}

		$this->handle_tracking_action();
		$this->handle_status_action();
		$this->handle_correction_action();
		$this->handle_eta_action();
	}

	private function handle_tracking_action(): void {
		if ( ! $this->tracking instanceof ShipmentTrackingService ) {
			return;
		}

		if ( ! $this->actions->verify_post(
			self::ACTION_SAVE_TRACKING,
			self::ACTION_SAVE_TRACKING,
			'manage_shipments',
			self::SLUG
		) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( wp_unslash( (string) $_POST['shipment_id'] ) ) : 0;

		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			$this->actions->notices()->flash_error(
				__( 'You do not have permission to perform this action.', 'cetech-woocommerce-delivery-engine' )
			);
			$this->actions->redirect( self::SLUG );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$result = $this->tracking->save(
			$shipment_id,
			ShipmentTrackingInput::from_post( $_POST ),
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null
		);

		if ( $result->ok ) {
			$this->actions->notices()->flash_success( $result->message );
		} else {
			$this->actions->notices()->flash_error( $result->message );
		}

		$this->actions->redirect(
			self::SLUG,
			[ 'shipment' => (string) max( 0, $shipment_id ) ]
		);
	}

	private function handle_status_action(): void {
		if ( ! $this->status instanceof ShipmentStatusService ) {
			return;
		}

		if ( ! $this->actions->verify_post(
			self::ACTION_CHANGE_STATUS,
			self::ACTION_CHANGE_STATUS,
			'update_shipment_status',
			self::SLUG
		) ) {
			return;
		}

		$this->apply_status_post( false );
	}

	private function handle_correction_action(): void {
		if ( ! $this->status instanceof ShipmentStatusService ) {
			return;
		}

		if ( ! $this->actions->verify_post(
			self::ACTION_CORRECT_STATUS,
			self::ACTION_CORRECT_STATUS,
			'update_shipment_status',
			self::SLUG
		) ) {
			return;
		}

		$this->apply_status_post( true );
	}

	private function apply_status_post( bool $correction ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( wp_unslash( (string) $_POST['shipment_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$target_raw = isset( $_POST['target_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['target_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$reason = isset( $_POST['status_reason'] ) ? (string) wp_unslash( (string) $_POST['status_reason'] ) : '';

		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			$this->actions->notices()->flash_error(
				__( 'You do not have permission to perform this action.', 'cetech-woocommerce-delivery-engine' )
			);
			$this->actions->redirect( self::SLUG, [ 'shipment' => (string) max( 0, $shipment_id ) ] );
		}

		$target = $this->status->target_from_request( $target_raw );

		if ( ! $target instanceof ShipmentStatus ) {
			$this->actions->notices()->flash_error(
				__( 'That shipment status is not recognised.', 'cetech-woocommerce-delivery-engine' )
			);
			$this->actions->redirect( self::SLUG, [ 'shipment' => (string) max( 0, $shipment_id ) ] );
		}

		$request = $correction
			? ShipmentStatusChangeRequest::staff_correction( $reason, $this->actor_id() )
			: ShipmentStatusChangeRequest::staff_normal( $reason, $this->actor_id() );

		$result = $this->status->change( $shipment_id, $target, $request );

		if ( $result->ok ) {
			$this->actions->notices()->flash_success( $result->message );
		} else {
			$this->actions->notices()->flash_error( $result->message );
		}

		$this->actions->redirect(
			self::SLUG,
			[ 'shipment' => (string) max( 0, $shipment_id ) ]
		);
	}

	private function handle_eta_action(): void {
		if ( ! $this->eta instanceof ShipmentEtaService ) {
			return;
		}

		if ( ! $this->actions->verify_post(
			self::ACTION_UPDATE_ETA,
			self::ACTION_UPDATE_ETA,
			'update_shipment_status',
			self::SLUG
		) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( wp_unslash( (string) $_POST['shipment_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$eta = isset( $_POST['eta_current'] ) ? (string) wp_unslash( (string) $_POST['eta_current'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$reason = isset( $_POST['eta_reason'] ) ? (string) wp_unslash( (string) $_POST['eta_reason'] ) : '';

		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			$this->actions->notices()->flash_error(
				__( 'You do not have permission to perform this action.', 'cetech-woocommerce-delivery-engine' )
			);
			$this->actions->redirect( self::SLUG, [ 'shipment' => (string) max( 0, $shipment_id ) ] );
		}

		$result = $this->eta->update_current( $shipment_id, $eta, $reason, $this->actor_id() );

		if ( $result->ok ) {
			$this->actions->notices()->flash_success( $result->message );
		} else {
			$this->actions->notices()->flash_error( $result->message );
		}

		$this->actions->redirect(
			self::SLUG,
			[ 'shipment' => (string) max( 0, $shipment_id ) ]
		);
	}

	private function actor_id(): ?int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null;
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_shipments' );

		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to access this page.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}

		if ( $this->activity instanceof ShipmentActivityCursor ) {
			$this->activity->mark_reviewed_for_current_user();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$shipment_id = isset( $_GET['shipment'] ) ? absint( wp_unslash( (string) $_GET['shipment'] ) ) : 0;

		if ( $shipment_id > 0 ) {
			$this->render_detail( $shipment_id );
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		$search = $this->request_search();
		$status = $this->request_status();
		$page   = $this->request_page();
		$result = $this->query->list( $search, $status, $page );

		AdminPageLayout::open_page();
		if ( $this->actions instanceof AdminActionHandler ) {
			$this->actions->notices()->render_notices();
		}
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Shipments', 'cetech-woocommerce-delivery-engine' ),
			__( 'Review delivery shipments created from paid WooCommerce orders.', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_filters( $search, $status );

		if ( [] === $result->rows ) {
			if ( '' === $search && '' === $status ) {
				AdminPageLayout::render_empty_state(
					__( 'No shipments have been created yet.', 'cetech-woocommerce-delivery-engine' ),
					__( 'Paid Delivery Engine orders will appear here when shipment creation is enabled and succeeds.', 'cetech-woocommerce-delivery-engine' )
				);
			} else {
				AdminPageLayout::render_empty_state(
					__( 'No shipments match these filters.', 'cetech-woocommerce-delivery-engine' ),
					__( 'Try a different shipment reference, order number, tracking number, or status.', 'cetech-woocommerce-delivery-engine' )
				);
			}

			AdminPageLayout::close_page();
			return;
		}

		$rows = [];

		foreach ( $result->rows as $row ) {
			$rows[] = $this->list_row_cells( $row );
		}

		AdminPageRenderer::render_table(
			[
				__( 'Shipment', 'cetech-woocommerce-delivery-engine' ),
				__( 'Order', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customer', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ),
				__( 'Items', 'cetech-woocommerce-delivery-engine' ),
				__( 'Status', 'cetech-woocommerce-delivery-engine' ),
				__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ),
				__( 'Tracking', 'cetech-woocommerce-delivery-engine' ),
				__( 'Updated', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		$this->render_pagination( $result->page, $result->total_pages(), $search, $status );

		AdminPageLayout::close_page();
	}

	private function render_detail( int $shipment_id ): void {
		$detail = $this->query->detail( $shipment_id );

		AdminPageLayout::open_page();
		if ( $this->actions instanceof AdminActionHandler ) {
			$this->actions->notices()->render_notices();
		}
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Shipment', 'cetech-woocommerce-delivery-engine' ),
			__( 'Shipment details from the historical paid-order record. Tracking, status, and the current estimate can be updated here. The original checkout estimate and paid delivery charge stay unchanged.', 'cetech-woocommerce-delivery-engine' ),
			null,
			[
				'label' => __( 'Back to shipments', 'cetech-woocommerce-delivery-engine' ),
				'url'   => $this->list_url(),
			]
		);

		if ( null === $detail ) {
			AdminPageLayout::render_empty_state(
				__( 'Shipment not found.', 'cetech-woocommerce-delivery-engine' ),
				__( 'That shipment record is not available.', 'cetech-woocommerce-delivery-engine' )
			);
			AdminPageLayout::close_page();
			return;
		}

		$shipment = $detail->shipment;

		AdminPageLayout::open_section( __( 'Shipment summary', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_definition_table(
			[
				[ __( 'Shipment', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::staff_reference( $shipment ) ) ],
				[ __( 'Status', 'cetech-woocommerce-delivery-engine' ), $this->status_html( $shipment ) ],
				[ __( 'Order', 'cetech-woocommerce-delivery-engine' ), $this->order_html( $detail->order_number, $detail->order_edit_url ) ],
				[ __( 'Customer', 'cetech-woocommerce-delivery-engine' ), esc_html( $detail->customer_label ) ],
				[ __( 'Created', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::timestamp( $shipment->created_at ) ) ],
				[ __( 'Updated', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::timestamp( $shipment->updated_at ) ) ],
			]
		);
		AdminPageLayout::close_section();

		AdminPageLayout::open_section( __( 'Delivery', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_definition_table( $this->delivery_rows( $shipment ) );
		AdminPageLayout::close_section();

		if ( $this->can_change_status() ) {
			$this->render_status_controls( $shipment );
		}

		if ( $this->can_update_eta() ) {
			$this->render_eta_form( $shipment );
		}

		AdminPageLayout::open_section( __( 'Items', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_items( $detail );
		AdminPageLayout::close_section();

		AdminPageLayout::open_section( __( 'Tracking', 'cetech-woocommerce-delivery-engine' ) );
		if ( $this->can_edit_tracking() ) {
			$this->render_tracking_form( $shipment );
		} else {
			$this->render_definition_table( $this->tracking_rows( $shipment ) );
		}
		AdminPageLayout::close_section();

		$operations = $this->operations_rows( $shipment );

		if ( [] !== $operations ) {
			AdminPageLayout::open_section( __( 'Operations', 'cetech-woocommerce-delivery-engine' ) );
			$this->render_definition_table( $operations );
			AdminPageLayout::close_section();
		}

		AdminPageLayout::open_section( __( 'History', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_history( $detail );
		AdminPageLayout::close_section();

		AdminPageLayout::close_page();
	}

	private function render_filters( string $search, string $status ): void {
		echo '<form method="get" class="cetech-de-list-toolbar" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label class="screen-reader-text" for="cetech-de-shipment-search">' . esc_html__( 'Search shipments', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<input id="cetech-de-shipment-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search shipment, order, or tracking', 'cetech-woocommerce-delivery-engine' ) . '" />';
		echo '<label class="screen-reader-text" for="cetech-de-shipment-status">' . esc_html__( 'Filter by status', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-shipment-status" name="status">';

		foreach ( ShipmentPresentation::status_filter_options() as $option ) {
			echo '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $status, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}

		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @return list<string>
	 */
	private function list_row_cells( ShipmentListRow $row ): array {
		$shipment  = $row->shipment;
		$reference = ShipmentPresentation::staff_reference( $shipment );
		$detail    = $this->detail_url( $shipment->id );
		$eta       = esc_html( ShipmentPresentation::list_eta_label( $shipment ) );

		if ( ShipmentPresentation::eta_original_and_current_differ( $shipment ) ) {
			$eta .= '<br /><span class="description">' . esc_html__( 'Current estimate', 'cetech-woocommerce-delivery-engine' ) . '</span>';
		}

		return [
			'<a href="' . esc_url( $detail ) . '"><strong>' . esc_html( $reference ) . '</strong></a>',
			$this->order_html( $row->order_number, $row->order_edit_url ),
			esc_html( $row->customer_label ),
			esc_html( ShipmentPresentation::delivery_option_label( $shipment ) ),
			esc_html( ShipmentPresentation::item_count_label( $row->item_count ) ),
			$this->status_html( $shipment ),
			$eta,
			esc_html( ShipmentPresentation::tracking_state_label( $shipment ) ),
			esc_html( ShipmentPresentation::timestamp( $shipment->updated_at ) ),
		];
	}

	private function status_html( Shipment $shipment ): string {
		$label = $shipment->status->label();

		return '<span class="' . esc_attr( ShipmentPresentation::status_badge_class( $shipment->status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	private function order_html( string $order_number, ?string $order_edit_url ): string {
		$label = sprintf(
			/* translators: %s: WooCommerce order number */
			__( 'Order %s', 'cetech-woocommerce-delivery-engine' ),
			$order_number
		);

		if ( null !== $order_edit_url && '' !== $order_edit_url ) {
			return '<a href="' . esc_url( $order_edit_url ) . '">' . esc_html( $label ) . '</a>';
		}

		return esc_html( $label );
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private function delivery_rows( Shipment $shipment ): array {
		$rows = [
			[ __( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::delivery_option_label( $shipment ) ) ],
		];

		if ( ShipmentPresentation::eta_original_and_current_differ( $shipment ) ) {
			$rows[] = [ __( 'Original estimate', 'cetech-woocommerce-delivery-engine' ), esc_html( (string) $shipment->eta_original ) ];
			$rows[] = [ __( 'Current estimate', 'cetech-woocommerce-delivery-engine' ), esc_html( (string) $shipment->eta_current ) ];
		} else {
			$rows[] = [ __( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::list_eta_label( $shipment ) ) ];
		}

		$rows[] = [ __( 'Customer delivery charge', 'cetech-woocommerce-delivery-engine' ), ShipmentPresentation::customer_delivery_charge( $shipment ) ];

		if ( '' !== $shipment->currency_code ) {
			$rows[] = [ __( 'Currency', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->currency_code ) ];
		}

		if ( null !== $shipment->public_note && '' !== $shipment->public_note && ! $this->can_edit_tracking() ) {
			$rows[] = [ __( 'Public note', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->public_note ) ];
		}

		if ( ShipmentPresentation::can_view_private_notes() && null !== $shipment->private_note && '' !== $shipment->private_note ) {
			$rows[] = [ __( 'Private note', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->private_note ) ];
		}

		return $rows;
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private function tracking_rows( Shipment $shipment ): array {
		if ( ! ShipmentPresentation::has_tracking( $shipment ) && ( null === $shipment->dispatch_at || '' === $shipment->dispatch_at ) ) {
			return [
				[ __( 'Tracking', 'cetech-woocommerce-delivery-engine' ), esc_html__( 'Not added', 'cetech-woocommerce-delivery-engine' ) ],
			];
		}

		$rows = [
			[ __( 'Tracking', 'cetech-woocommerce-delivery-engine' ), esc_html( ShipmentPresentation::tracking_state_label( $shipment ) ) ],
		];

		if ( null !== $shipment->tracking_carrier_display && '' !== $shipment->tracking_carrier_display ) {
			$rows[] = [ __( 'Carrier', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->tracking_carrier_display ) ];
		}

		if ( null !== $shipment->tracking_number && '' !== $shipment->tracking_number ) {
			$rows[] = [ __( 'Tracking number', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->tracking_number ) ];
		}

		if ( null !== $shipment->tracking_url && '' !== $shipment->tracking_url ) {
			$rows[] = [
				__( 'Tracking URL', 'cetech-woocommerce-delivery-engine' ),
				'<a href="' . esc_url( $shipment->tracking_url ) . '" rel="noopener noreferrer">' . esc_html( $shipment->tracking_url ) . '</a>',
			];
		}

		if ( null !== $shipment->dispatch_at && '' !== $shipment->dispatch_at ) {
			$rows[] = [ __( 'Dispatch date', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->dispatch_at ) ];
		}

		return $rows;
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private function operations_rows( Shipment $shipment ): array {
		$rows = [];

		if ( ShipmentPresentation::can_view_private_origins() && null !== $shipment->supplier_id ) {
			$rows[] = [
				__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
				esc_html(
					sprintf(
						/* translators: %d: stored historical supplier id */
						__( 'ID %d (historical name not recorded)', 'cetech-woocommerce-delivery-engine' ),
						$shipment->supplier_id
					)
				),
			];
		}

		if ( ShipmentPresentation::can_view_private_origins() && null !== $shipment->origin_id ) {
			$rows[] = [
				__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
				esc_html(
					sprintf(
						/* translators: %d: stored historical origin id */
						__( 'ID %d (historical name not recorded)', 'cetech-woocommerce-delivery-engine' ),
						$shipment->origin_id
					)
				),
			];
		}

		if ( ShipmentPresentation::can_view_private_costs() && null !== $shipment->internal_cost && '' !== $shipment->internal_cost ) {
			$rows[] = [ __( 'Internal cost', 'cetech-woocommerce-delivery-engine' ), esc_html( $shipment->internal_cost ) ];
		}

		return $rows;
	}

	private function render_items( ShipmentWorkspaceDetail $detail ): void {
		if ( [] === $detail->items ) {
			echo '<p>' . esc_html__( 'No items were recorded on this shipment.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}

		$rows = [];

		foreach ( $detail->items as $item ) {
			$facts = $detail->order_item_facts[ $item->order_item_id ] ?? [ 'name' => '', 'sku' => '', 'variation_id' => null ];
			$rows[] = [
				esc_html( $this->item_name( $item, $facts ) ),
				esc_html( $this->item_variation( $item, $facts ) ),
				esc_html( $facts['sku'] !== '' ? $facts['sku'] : '—' ),
				esc_html( (string) $item->quantity ),
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Variation', 'cetech-woocommerce-delivery-engine' ),
				__( 'SKU', 'cetech-woocommerce-delivery-engine' ),
				__( 'Quantity', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);
	}

	/**
	 * @param array{name: string, sku: string, variation_id: int|null} $facts
	 */
	private function item_name( ShipmentItem $item, array $facts ): string {
		if ( '' !== trim( $item->product_name_snapshot ) ) {
			return $item->product_name_snapshot;
		}

		if ( '' !== trim( $facts['name'] ) ) {
			return $facts['name'];
		}

		return __( 'Item unavailable', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array{name: string, sku: string, variation_id: int|null} $facts
	 */
	private function item_variation( ShipmentItem $item, array $facts ): string {
		$variation_id = $item->variation_id ?? $facts['variation_id'];

		if ( null === $variation_id || $variation_id <= 0 ) {
			return '—';
		}

		return (string) $variation_id;
	}

	private function render_history( ShipmentWorkspaceDetail $detail ): void {
		if ( [] === $detail->events ) {
			echo '<p>' . esc_html__( 'No shipment history has been recorded yet.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}

		$events = array_reverse( $detail->events );
		$rows   = [];

		foreach ( $events as $event ) {
			$note = $this->history_note_html( $event );

			$event_cell = esc_html( ShipmentPresentation::event_label( $event ) );
			$event_cell .= '<br /><span class="description">' . esc_html( ShipmentPresentation::history_actor_label( $event ) ) . '</span>';

			$rows[] = [
				esc_html( ShipmentPresentation::timestamp( $event->event_at ) ),
				$event_cell,
				'' !== $note ? $note : '—',
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'When', 'cetech-woocommerce-delivery-engine' ),
				__( 'Event', 'cetech-woocommerce-delivery-engine' ),
				__( 'Note', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);
	}

	/**
	 * @param list<array{0: string, 1: string}> $rows
	 */
	private function render_definition_table( array $rows ): void {
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<th scope="row">' . esc_html( $row[0] ) . '</th>';
			echo '<td>' . wp_kses( $row[1], $this->allowed_detail_html() ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_pagination( int $page, int $total_pages, string $search, string $status ): void {
		if ( $total_pages <= 1 || ! function_exists( 'paginate_links' ) ) {
			return;
		}

		$links = paginate_links(
			[
				'base'      => esc_url( add_query_arg( 'paged', '%#%', $this->list_url( $search, $status ) ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => __( '&laquo; Previous', 'cetech-woocommerce-delivery-engine' ),
				'next_text' => __( 'Next &raquo;', 'cetech-woocommerce-delivery-engine' ),
				'type'      => 'plain',
			]
		);

		if ( ! is_string( $links ) || '' === $links ) {
			return;
		}

		echo '<nav class="tablenav bottom cetech-de-shipment-pagination" aria-label="' . esc_attr__( 'Shipment list pagination', 'cetech-woocommerce-delivery-engine' ) . '">';
		echo '<div class="tablenav-pages">' . wp_kses( $links, $this->allowed_detail_html() ) . '</div>';
		echo '</nav>';
	}

	private function can_edit_tracking(): bool {
		return $this->tracking instanceof ShipmentTrackingService;
	}

	private function can_change_status(): bool {
		return $this->status instanceof ShipmentStatusService
			&& current_user_can( 'update_shipment_status' );
	}

	private function can_update_eta(): bool {
		return $this->eta instanceof ShipmentEtaService
			&& current_user_can( 'update_shipment_status' );
	}

	private function history_note_html( ShipmentEvent $event ): string {
		$parts = [];

		if ( ! ShipmentPresentation::is_correction_event( $event ) && null !== $event->public_note && '' !== $event->public_note ) {
			$parts[] = esc_html( $event->public_note );
		}

		if ( null !== $event->internal_note && '' !== $event->internal_note ) {
			$parts[] = esc_html( ShipmentPresentation::operational_reason_label( $event->internal_note ) );
		}

		return implode( '<br />', $parts );
	}

	private function render_status_controls( Shipment $shipment ): void {
		AdminPageLayout::open_section( __( 'Status', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p><strong>' . esc_html__( 'Current status', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ';
		echo $this->status_html( $shipment );
		echo '</p>';

		$targets = ShipmentStatusTransitionPolicy::normal_targets( $shipment->status );

		if ( [] !== $targets ) {
			echo '<h3>' . esc_html__( 'Available actions', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
			echo '<div class="cetech-de-shipment-actions">';

			foreach ( $targets as $target ) {
				$this->render_normal_status_form( $shipment, $target );
			}

			echo '</div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Delivered and cancelled shipments have no ordinary next step. Use Correct status only if the recorded status is wrong.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}

		$this->render_correction_form( $shipment );
		AdminPageLayout::close_section();
	}

	private function render_normal_status_form( Shipment $shipment, ShipmentStatus $target ): void {
		$needs_reason = ShipmentStatusTransitionPolicy::requires_reason( $shipment->status, $target, false );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-shipment-ops-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="shipment_id" value="' . esc_attr( (string) $shipment->id ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CHANGE_STATUS ) . '" />';
		echo '<input type="hidden" name="target_status" value="' . esc_attr( $target->value ) . '" />';
		AdminFormHelper::nonce_field( self::ACTION_CHANGE_STATUS );

		if ( $needs_reason ) {
			$reason_id = 'status_reason_' . $target->value;
			echo '<p>';
			echo '<label for="' . esc_attr( $reason_id ) . '">' . esc_html( $this->status_reason_label( $target ) );
			echo ' <span class="description">' . esc_html__( '(required)', 'cetech-woocommerce-delivery-engine' ) . '</span></label><br />';
			echo '<textarea class="large-text" id="' . esc_attr( $reason_id ) . '" name="status_reason" rows="3" required></textarea>';
			echo '</p>';
		}

		$destructive = ShipmentStatus::Cancelled === $target;
		$class       = $destructive ? 'button button-secondary cetech-de-delete-link' : 'button button-secondary';

		echo '<p class="submit">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $this->status_action_label( $shipment->status, $target ) ) . '</button>';
		echo '</p>';
		echo '</form>';
	}

	private function render_correction_form( Shipment $shipment ): void {
		echo '<div class="cetech-de-shipment-correction">';
		echo '<h3>' . esc_html__( 'Correct status', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Use this only to fix a status that was recorded incorrectly. This is not a normal workflow action. The reason stays internal and is not shown to the customer.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-shipment-ops-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="shipment_id" value="' . esc_attr( (string) $shipment->id ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CORRECT_STATUS ) . '" />';
		AdminFormHelper::nonce_field( self::ACTION_CORRECT_STATUS );

		echo '<p>';
		echo '<label for="cetech-de-correct-status">' . esc_html__( 'Corrected status', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<select id="cetech-de-correct-status" name="target_status" required>';

		foreach ( ShipmentStatus::cases() as $status ) {
			if ( $status === $shipment->status ) {
				continue;
			}

			echo '<option value="' . esc_attr( $status->value ) . '">' . esc_html( $status->label() ) . '</option>';
		}

		echo '</select></p>';
		echo '<p>';
		echo '<label for="cetech-de-correct-reason">' . esc_html__( 'Correction reason', 'cetech-woocommerce-delivery-engine' );
		echo ' <span class="description">' . esc_html__( '(required)', 'cetech-woocommerce-delivery-engine' ) . '</span></label><br />';
		echo '<textarea class="large-text" id="cetech-de-correct-reason" name="status_reason" rows="3" required></textarea>';
		echo '</p>';
		echo '<p class="submit">';
		echo '<button type="submit" class="button">' . esc_html__( 'Save correction', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</p>';
		echo '</form></div>';
	}

	private function render_eta_form( Shipment $shipment ): void {
		AdminPageLayout::open_section( __( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p><strong>' . esc_html__( 'Original estimate', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ';
		echo esc_html( '' !== trim( (string) $shipment->eta_original ) ? (string) $shipment->eta_original : '—' );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The original checkout estimate cannot be changed. Update only the current operational estimate. The reason stays internal and is not shown to the customer.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-shipment-ops-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="shipment_id" value="' . esc_attr( (string) $shipment->id ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_UPDATE_ETA ) . '" />';
		AdminFormHelper::nonce_field( self::ACTION_UPDATE_ETA );

		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::text_field(
			'eta_current',
			__( 'Current estimate', 'cetech-woocommerce-delivery-engine' ),
			(string) $shipment->eta_current,
			false,
			__( 'Human-readable estimate text, matching the original checkout estimate format. This is not recalculated from current product settings.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'eta_reason',
			__( 'Reason for updating the estimate', 'cetech-woocommerce-delivery-engine' ),
			'',
			3,
			__( 'Required when the current estimate actually changes. This is not the public shipment note.', 'cetech-woocommerce-delivery-engine' ),
			true
		);
		echo '</tbody></table>';
		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Update current estimate', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</p>';
		echo '</form>';
		AdminPageLayout::close_section();
	}

	private function status_action_label( ShipmentStatus $from, ShipmentStatus $to ): string {
		if ( ShipmentStatus::Delayed === $from ) {
			return match ( $to ) {
				ShipmentStatus::Processing => __( 'Resume as Processing', 'cetech-woocommerce-delivery-engine' ),
				ShipmentStatus::Dispatched => __( 'Resume as Dispatched', 'cetech-woocommerce-delivery-engine' ),
				ShipmentStatus::InTransit => __( 'Resume as In transit', 'cetech-woocommerce-delivery-engine' ),
				ShipmentStatus::Delivered => __( 'Mark as Delivered', 'cetech-woocommerce-delivery-engine' ),
				ShipmentStatus::Cancelled => __( 'Cancel shipment', 'cetech-woocommerce-delivery-engine' ),
				default => sprintf(
					/* translators: %s: shipment status label */
					__( 'Mark as %s', 'cetech-woocommerce-delivery-engine' ),
					$to->label()
				),
			};
		}

		return match ( $to ) {
			ShipmentStatus::Processing => __( 'Mark as Processing', 'cetech-woocommerce-delivery-engine' ),
			ShipmentStatus::Dispatched => __( 'Mark as Dispatched', 'cetech-woocommerce-delivery-engine' ),
			ShipmentStatus::InTransit => __( 'Mark as In transit', 'cetech-woocommerce-delivery-engine' ),
			ShipmentStatus::Delayed => __( 'Mark as Delayed / issue', 'cetech-woocommerce-delivery-engine' ),
			ShipmentStatus::Delivered => __( 'Mark as Delivered', 'cetech-woocommerce-delivery-engine' ),
			ShipmentStatus::Cancelled => __( 'Cancel shipment', 'cetech-woocommerce-delivery-engine' ),
			default => sprintf(
				/* translators: %s: shipment status label */
				__( 'Mark as %s', 'cetech-woocommerce-delivery-engine' ),
				$to->label()
			),
		};
	}

	private function status_reason_label( ShipmentStatus $target ): string {
		if ( ShipmentStatus::Delayed === $target ) {
			return __( 'Reason for delay or issue', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ShipmentStatus::Cancelled === $target ) {
			return __( 'Reason for cancelling this shipment', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Reason for this status change', 'cetech-woocommerce-delivery-engine' );
	}

	private function render_tracking_form( Shipment $shipment ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-shipment-tracking-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="shipment_id" value="' . esc_attr( (string) $shipment->id ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE_TRACKING ) . '" />';
		AdminFormHelper::nonce_field( self::ACTION_SAVE_TRACKING );

		AdminPageLayout::open_form_panel(
			__( 'Tracking details', 'cetech-woocommerce-delivery-engine' ),
			__( 'These details can be shown to the customer. Saving them does not change the shipment status.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'tracking_carrier',
			__( 'Carrier', 'cetech-woocommerce-delivery-engine' ),
			(string) $shipment->tracking_carrier_display,
			false,
			__( 'Public carrier name shown to the customer. Leave blank if no carrier has been recorded.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'tracking_number',
			__( 'Tracking number', 'cetech-woocommerce-delivery-engine' ),
			(string) $shipment->tracking_number,
			false,
			__( 'The identifier supplied by the carrier. This is not the shipment reference.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::url_field(
			'tracking_url',
			__( 'Tracking URL', 'cetech-woocommerce-delivery-engine' ),
			(string) $shipment->tracking_url,
			__( 'Full web address starting with http or https. Leave blank if there is no tracking page.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::date_field(
			'dispatch_date',
			__( 'Dispatch date', 'cetech-woocommerce-delivery-engine' ),
			ShipmentDispatchDate::to_staff_date( $shipment->dispatch_at ),
			__( 'The date the shipment left, if known. This does not mark the shipment as dispatched.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'public_note',
			__( 'Public shipment note', 'cetech-woocommerce-delivery-engine' ),
			(string) $shipment->public_note,
			4,
			__( 'Visible to the customer. Private operational notes stay in the Delivery section and are never shown to customers.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save tracking', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</p>';
		echo '</form>';
	}

	private function list_url( ?string $search = null, ?string $status = null ): string {
		$args = [ 'page' => self::SLUG ];

		if ( null !== $search && '' !== $search ) {
			$args['s'] = $search;
		}

		if ( null !== $status && '' !== $status ) {
			$args['status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function detail_url( int $shipment_id ): string {
		return add_query_arg(
			[
				'page'     => self::SLUG,
				'shipment' => $shipment_id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return array<string, array<string, bool|array<string, bool>>>
	 */
	private function allowed_detail_html(): array {
		return [
			'a'      => [
				'href'  => true,
				'rel'   => true,
				'class' => true,
			],
			'span'   => [
				'class'        => true,
				'aria-current' => true,
			],
			'strong' => [],
			'br'     => [],
			'nav'    => [
				'class'      => true,
				'aria-label' => true,
			],
		];
	}

	private function request_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
	}

	private function request_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';

		return $status;
	}

	private function request_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
	}
}
