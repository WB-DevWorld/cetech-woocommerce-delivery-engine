<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Order;

/**
 * Customer-safe read-only delivery summary on order view surfaces (thank-you / My Account).
 *
 * Stage 13F compact contract: delivery option + estimate (+ pickup extras when present).
 * Does not repeat WooCommerce shipping charges or internal fulfilment/method labels.
 */
final class CustomerOrderDeliverySummaryRenderer {

	public const STYLE_HANDLE = 'cetech-de-customer-order-delivery-summary';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CustomerOrderDeliverySummaryBuilder $summary_builder,
		private ?CustomerShipmentQuery $shipment_query = null
	) {
	}

	public function register(): void {
		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 15, 1 );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_account_page' ) && ! function_exists( 'is_order_received_page' ) ) {
			return;
		}

		$on_customer_order_surface = ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_view_order_page' ) && is_view_order_page() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( ! $on_customer_order_surface ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.3';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'assets/frontend/customer-order-delivery-summary.css',
			[],
			$version
		);
	}

	public function render( WC_Order $order ): void {
		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order || $order->get_id() <= 0 ) {
			return;
		}

		$summary = $this->summary_builder->build( $order );

		if ( null === $summary ) {
			return;
		}

		$covered = $this->covered_delivery_item_ids( $order );
		$this->render_summary( $summary, $covered );
	}

	/**
	 * @param array<int, true> $covered_item_ids
	 */
	private function render_summary( CustomerOrderDeliverySummary $summary, array $covered_item_ids = [] ): void {
		$lines = [];

		foreach ( $summary->lines as $line ) {
			if ( $this->is_store_pickup_line( $line ) ) {
				$lines[] = $line;
				continue;
			}

			if ( $line->order_item_id > 0 && isset( $covered_item_ids[ $line->order_item_id ] ) ) {
				continue;
			}

			$lines[] = $line;
		}

		if ( [] === $lines ) {
			return;
		}

		echo '<section class="cetech-de-order-delivery-summary woocommerce-order-delivery-summary">';
		echo '<h2 class="woocommerce-order-delivery-summary__title">';
		echo esc_html__( 'Delivery details', 'cetech-woocommerce-delivery-engine' );
		echo '</h2>';

		$multi = count( $lines ) > 1;

		foreach ( $lines as $line ) {
			$this->render_line_block( $line, $multi );
		}

		echo '</section>';
	}

	private function render_line_block( CustomerOrderDeliveryLineSummary $line, bool $show_product ): void {
		if ( $show_product ) {
			echo '<p class="cetech-de-order-delivery-summary__product">' . esc_html( $line->product_name ) . '</p>';
		}

		$rows = $this->compact_rows( $line );

		if ( [] === $rows ) {
			return;
		}

		echo '<ul class="cetech-de-order-delivery-summary__compact">';

		foreach ( $rows as $row ) {
			echo '<li><span class="label">' . esc_html( $row['key'] ) . ':</span> ';
			echo esc_html( $row['value'] ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * @return list<array{key: string, value: string}>
	 */
	private function compact_rows( CustomerOrderDeliveryLineSummary $line ): array {
		$choice_slug = $this->infer_choice_slug( $line->fulfilment_choice_label );

		return DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_choice_label'     => $line->fulfilment_choice_label,
				'delivery_offer_public_label' => $line->delivery_option_label,
				'estimate_text'               => $line->estimate_text,
				'pickup_location_label'       => $line->pickup_location_label,
				'pickup_address'              => $line->pickup_address,
				'pickup_instructions'         => $line->pickup_instructions,
			],
			$choice_slug
		);
	}

	private function infer_choice_slug( string $choice_label ): ?string {
		$normalized = strtolower( trim( $choice_label ) );

		if ( str_contains( $normalized, 'pickup' ) ) {
			return 'store_pickup';
		}

		if ( str_contains( $normalized, 'delivery' ) ) {
			return 'delivery';
		}

		return null;
	}

	private function is_store_pickup_line( CustomerOrderDeliveryLineSummary $line ): bool {
		return 'store_pickup' === $this->infer_choice_slug( $line->fulfilment_choice_label );
	}

	/**
	 * @return array<int, true>
	 */
	private function covered_delivery_item_ids( WC_Order $order ): array {
		if ( ! $this->shipment_query instanceof CustomerShipmentQuery ) {
			return [];
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_shipment_records' ) ) {
			return [];
		}

		if ( ! function_exists( 'is_view_order_page' ) || ! is_view_order_page() ) {
			return [];
		}

		return $this->shipment_query->covered_order_item_ids( (int) $order->get_id() );
	}
}
