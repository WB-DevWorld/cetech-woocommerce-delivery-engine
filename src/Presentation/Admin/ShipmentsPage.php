<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipment\ShipmentListRow;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceDetail;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;

/**
 * WordPress-native staff Shipments list and read-only detail workspace.
 */
final class ShipmentsPage {

	public const SLUG = 'cetech-delivery-engine-shipments';

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentWorkspaceQuery $query
	) {
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
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Shipment', 'cetech-woocommerce-delivery-engine' ),
			__( 'Read-only shipment details from the historical paid-order record.', 'cetech-woocommerce-delivery-engine' ),
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

		AdminPageLayout::open_section( __( 'Items', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_items( $detail );
		AdminPageLayout::close_section();

		AdminPageLayout::open_section( __( 'Tracking', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_definition_table( $this->tracking_rows( $shipment ) );
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

		if ( null !== $shipment->public_note && '' !== $shipment->public_note ) {
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
			$note = '';

			if ( null !== $event->public_note && '' !== $event->public_note ) {
				$note = esc_html( $event->public_note );
			}

			if ( ShipmentPresentation::can_view_private_notes() && null !== $event->internal_note && '' !== $event->internal_note ) {
				$private = esc_html( $event->internal_note );
				$note    = '' === $note ? $private : $note . '<br /><span class="description">' . $private . '</span>';
			}

			$rows[] = [
				esc_html( ShipmentPresentation::timestamp( $event->event_at ) ),
				esc_html( ShipmentPresentation::event_label( $event ) ),
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
