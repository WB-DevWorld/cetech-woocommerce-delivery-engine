<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryPackageSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Order;

/**
 * Customer-safe read-only delivery summary on order view surfaces (thank-you / My Account).
 */
final class CustomerOrderDeliverySummaryRenderer {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CustomerOrderDeliverySummaryBuilder $summary_builder
	) {
	}

	public function register(): void {
		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 15, 1 );
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

		$this->render_summary( $summary );
	}

	private function render_summary( CustomerOrderDeliverySummary $summary ): void {
		echo '<section class="cetech-de-order-delivery-summary woocommerce-order-delivery-summary">';
		echo '<h2 class="woocommerce-order-delivery-summary__title">';
		echo esc_html__( 'Delivery details', 'cetech-woocommerce-delivery-engine' );
		echo '</h2>';

		if ( [] !== $summary->lines ) {
			echo '<table class="woocommerce-table woocommerce-table--order-delivery shop_table order_details">';
			echo '<thead><tr>';
			echo '<th class="product-name">' . esc_html__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th class="delivery-option">' . esc_html__( 'Delivery option', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $summary->lines as $line ) {
				$this->render_line_row( $line );
			}

			echo '</tbody></table>';
		}

		if ( null !== $summary->package ) {
			$this->render_package_block( $summary->package );
		}

		echo '</section>';
	}

	private function render_line_row( CustomerOrderDeliveryLineSummary $line ): void {
		echo '<tr class="order_delivery_item">';
		echo '<td class="product-name" data-title="' . esc_attr__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '">';
		echo esc_html( $line->product_name );
		echo $this->render_line_details_list( $line );
		echo '</td>';
		echo '<td class="delivery-option" data-title="' . esc_attr__( 'Delivery option', 'cetech-woocommerce-delivery-engine' ) . '">';
		echo esc_html( $line->delivery_option_label );
		echo '</td>';
		echo '</tr>';
	}

	private function render_line_details_list( CustomerOrderDeliveryLineSummary $line ): string {
		$items = [];

		if ( '' !== $line->fulfilment_availability_label ) {
			$items[] = sprintf(
				'<li><span class="label">%1$s</span> %2$s</li>',
				esc_html( DeliveryPresentationLabels::fulfilment() . ':' ),
				esc_html( $line->fulfilment_availability_label )
			);
		}

		if ( '' !== $line->fulfilment_choice_label ) {
			$choice_slug = $this->infer_choice_slug( $line->fulfilment_choice_label );
			$items[]     = sprintf(
				'<li><span class="label">%1$s</span> %2$s</li>',
				esc_html( DeliveryPresentationLabels::method_label_for_choice( $choice_slug ) . ':' ),
				esc_html( $line->fulfilment_choice_label )
			);
		}

		if ( null !== $line->estimate_text && '' !== trim( $line->estimate_text ) ) {
			$choice_slug = $this->infer_choice_slug( $line->fulfilment_choice_label );
			$items[]     = sprintf(
				'<li><span class="label">%1$s</span> %2$s</li>',
				esc_html( DeliveryPresentationLabels::estimate_label_for_choice( $choice_slug ) . ':' ),
				esc_html( DeliveryPresentationLabels::strip_estimated_prefix( $line->estimate_text ) )
			);
		}

		if ( null !== $line->quoted_amount_display && '' !== trim( $line->quoted_amount_display ) ) {
			$items[] = sprintf(
				'<li><span class="label">%1$s</span> %2$s</li>',
				esc_html( DeliveryPresentationLabels::delivery_charge() . ':' ),
				esc_html( $line->quoted_amount_display )
			);
		}

		if ( [] === $items ) {
			return '';
		}

		return '<ul class="cetech-de-order-delivery-summary__details">' . implode( '', $items ) . '</ul>';
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

	private function render_package_block( CustomerOrderDeliveryPackageSummary $package ): void {
		echo '<div class="cetech-de-order-delivery-summary__package">';
		echo '<h3>' . esc_html__( 'Shipping summary', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<table class="woocommerce-table woocommerce-table--order-delivery-package shop_table">';
		echo '<tbody>';

		echo '<tr><th scope="row">' . esc_html( DeliveryPresentationLabels::shipping_method() ) . '</th>';
		echo '<td>' . esc_html( $package->shipping_method_label ) . '</td></tr>';

		if ( null !== $package->package_amount_display && '' !== trim( $package->package_amount_display ) ) {
			echo '<tr><th scope="row">' . esc_html( DeliveryPresentationLabels::delivery_charge() ) . '</th>';
			echo '<td>' . esc_html( $package->package_amount_display ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
