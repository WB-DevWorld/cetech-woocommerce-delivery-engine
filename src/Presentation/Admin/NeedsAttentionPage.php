<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\OperationalState;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;

/**
 * Products whose effective delivery configuration is incomplete, plus
 * genuine paid-order shipment creation failures.
 */
final class NeedsAttentionPage {

	public const SLUG = 'cetech-delivery-engine-needs-attention';

	public const ACTION_RETRY_SHIPMENT = 'cetech_de_retry_shipment_creation';

	public function __construct(
		private readonly NeedsAttentionQuery $query,
		private readonly AdminActionHandler $action_handler,
		private readonly OperationalStateService $operational_state,
		private readonly ShipmentCreationIssueQuery $shipment_issues,
		private readonly ShipmentService $shipment_service,
		private readonly FeatureFlags $flags
	) {
	}

	public function handle_actions(): void {
		if ( ! $this->action_handler->verify_post(
			self::ACTION_RETRY_SHIPMENT,
			self::ACTION_RETRY_SHIPMENT,
			'manage_shipments',
			self::SLUG
		) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AdminActionHandler::verify_post().
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( (string) $_POST['order_id'] ) ) : 0;
		$order    = ( $order_id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			$this->action_handler->notices()->flash_error(
				__( 'That order could not be found, so shipment creation was not retried.', 'cetech-woocommerce-delivery-engine' )
			);
			$this->action_handler->redirect( self::SLUG );
		}

		$result = $this->shipment_service->create_for_paid_order( $order, ShipmentEventSource::Retry );

		if ( $result->is_success() ) {
			$this->action_handler->notices()->flash_success(
				__( 'Delivery shipments were created or confirmed for that paid order.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_error(
				__( 'Delivery shipments could still not be created for that paid order. The order and payment were left unchanged.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		$this->action_handler->notices()->render_notices();

		$items    = $this->query->list( 200 );
		$shipments = $this->shipment_issues->list( 50 );
		$op       = $this->operational_state->current();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ),
			__( 'An operational to-do list for products that are missing a usable delivery setup, and paid orders whose delivery shipments could not be created.', 'cetech-woocommerce-delivery-engine' )
		);

		if ( $op->customers_still_use_previous_rules() && ! $op->sitewide_setup_complete ) {
			AdminPageLayout::render_status_banner(
				[
					'tone'  => $op->overview_tone,
					'title' => $op->overview_title,
					'text'  => $op->overview_text,
				]
			);
		}

		$this->render_shipment_issues( $shipments );

		if ( [] === $items && [] === $shipments ) {
			AdminPageLayout::render_empty_state(
				__( 'Nothing needs attention', 'cetech-woocommerce-delivery-engine' ),
				__( 'Every listed product currently has a usable delivery setup, and no paid-order shipment creation problems are waiting.', 'cetech-woocommerce-delivery-engine' )
			);
			AdminPageLayout::close_page();
			return;
		}

		if ( [] === $items ) {
			AdminPageLayout::close_page();
			return;
		}

		$rows = [];
		foreach ( $items as $item ) {
			$edit_url = add_query_arg(
				[
					'page'       => ScopedConfigurationPage::SLUG,
					'scope_type' => ConfigurationScopeType::Product->value,
					'scope_id'   => $item['id'],
					'slice_key'  => ConfigurationScope::DEFAULT_SLICE_KEY,
				],
				admin_url( 'admin.php' )
			);

			$problem = esc_html( $item['reason'] );
			if ( $op->customers_still_use_previous_rules() ) {
				$problem .= '<br /><span class="description">' . esc_html__( 'Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', 'cetech-woocommerce-delivery-engine' ) . '</span>';
			} elseif ( OperationalState::CUSTOMER_RUNTIME_ECR === $op->customer_runtime ) {
				$problem .= '<br /><span class="description">' . esc_html__( 'Customers cannot currently use delivery for this product.', 'cetech-woocommerce-delivery-engine' ) . '</span>';
			}

			$rows[] = [
				'<a href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['label'] ) . '</strong></a>',
				$problem,
				'<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Fix Now', 'cetech-woocommerce-delivery-engine' ) . '</a>',
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Problem', 'cetech-woocommerce-delivery-engine' ),
				__( 'Action', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_page();
	}

	/**
	 * @param list<array{order_id: int, order_number: string, url: string, reason: string, attempted_at: ?string, error_code: string}> $shipments
	 */
	private function render_shipment_issues( array $shipments ): void {
		if ( [] === $shipments ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Paid order shipment problems', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'These paid orders still need delivery shipments. Product setup problems are listed separately below.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

		$rows = [];

		foreach ( $shipments as $issue ) {
			$attempted = '';

			if ( is_string( $issue['attempted_at'] ) && '' !== $issue['attempted_at'] ) {
				$attempted = '<br /><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: timestamp */
						__( 'Tried at %s.', 'cetech-woocommerce-delivery-engine' ),
						$issue['attempted_at']
					)
				) . '</span>';
			}

			$action = '';

			if ( current_user_can( 'manage_shipments' ) ) {
				$action  = '<form method="post" action="">';
				$action .= AdminFormHelper::nonce_field_html( self::ACTION_RETRY_SHIPMENT );
				$action .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_RETRY_SHIPMENT ) . '" />';
				$action .= '<input type="hidden" name="order_id" value="' . esc_attr( (string) $issue['order_id'] ) . '" />';
				$action .= '<button type="submit" class="button">' . esc_html__( 'Retry shipment creation', 'cetech-woocommerce-delivery-engine' ) . '</button>';
				$action .= '</form>';
			}

			if ( $this->flags->is_enabled( 'enable_shipment_records' ) && current_user_can( 'manage_shipments' ) ) {
				$view = add_query_arg(
					[
						'page' => ShipmentsPage::SLUG,
						's'    => (string) $issue['order_id'],
					],
					admin_url( 'admin.php' )
				);
				$action .= ( '' !== $action ? '<br />' : '' ) . '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View shipments', 'cetech-woocommerce-delivery-engine' ) . '</a>';
			}

			$rows[] = [
				'<a href="' . esc_url( $issue['url'] ) . '"><strong>' . esc_html(
					sprintf(
						/* translators: %s: order number */
						__( 'Order %s', 'cetech-woocommerce-delivery-engine' ),
						$issue['order_number']
					)
				) . '</strong></a>',
				esc_html( $issue['reason'] ) . $attempted,
				$action,
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Order', 'cetech-woocommerce-delivery-engine' ),
				__( 'Problem', 'cetech-woocommerce-delivery-engine' ),
				__( 'Action', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);
	}
}
