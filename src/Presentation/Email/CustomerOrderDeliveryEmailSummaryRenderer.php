<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Email;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryPackageSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Email;
use WC_Order;

/**
 * Customer-safe read-only delivery summary in WooCommerce customer order emails.
 */
final class CustomerOrderDeliveryEmailSummaryRenderer {

	public const EMAIL_SUMMARY_FLAG = 'enable_customer_email_delivery_summary';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CustomerOrderDeliverySummaryBuilder $summary_builder
	) {
	}

	public function register(): void {
		if ( ! $this->feature_flags->is_enabled( self::EMAIL_SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		add_action( 'woocommerce_email_after_order_table', [ $this, 'render' ], 15, 4 );
	}

	/**
	 * @param WC_Order              $order
	 * @param bool                  $sent_to_admin
	 * @param bool                  $plain_text
	 * @param WC_Email|false|null   $email
	 */
	public function render( WC_Order $order, bool $sent_to_admin, bool $plain_text, $email = false ): void {
		if ( ! $this->feature_flags->is_enabled( self::EMAIL_SUMMARY_FLAG ) ) {
			return;
		}

		if ( $sent_to_admin || ! $this->is_customer_email_context( $email ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order || $order->get_id() <= 0 ) {
			return;
		}

		$summary = $this->summary_builder->build( $order );

		if ( null === $summary ) {
			return;
		}

		if ( $plain_text ) {
			echo $this->render_plain_text_summary( $summary );

			return;
		}

		echo $this->render_html_summary( $summary );
	}

	/**
	 * @param WC_Email|false|null $email
	 */
	private function is_customer_email_context( $email ): bool {
		if ( $email instanceof WC_Email && method_exists( $email, 'is_customer_email' ) ) {
			return (bool) $email->is_customer_email();
		}

		return true;
	}

	private function render_html_summary( CustomerOrderDeliverySummary $summary ): string {
		$output = '<div style="margin-bottom:40px;">';
		$output .= '<h2>' . esc_html__( 'Delivery details', 'cetech-woocommerce-delivery-engine' ) . '</h2>';

		if ( [] !== $summary->lines ) {
			$output .= '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;" border="1">';
			$output .= '<thead><tr>';
			$output .= '<th scope="col" style="text-align:left;">' . esc_html__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			$output .= '<th scope="col" style="text-align:left;">' . esc_html__( 'Delivery option', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			$output .= '</tr></thead><tbody>';

			foreach ( $summary->lines as $line ) {
				$output .= '<tr>';
				$output .= '<td>' . esc_html( $line->product_name );
				$output .= $this->render_html_line_details( $line );
				$output .= '</td>';
				$output .= '<td>' . esc_html( $line->delivery_option_label ) . '</td>';
				$output .= '</tr>';
			}

			$output .= '</tbody></table>';
		}

		if ( null !== $summary->package ) {
			$output .= $this->render_html_package_block( $summary->package );
		}

		$output .= '</div>';

		return $output;
	}

	private function render_html_line_details( CustomerOrderDeliveryLineSummary $line ): string {
		$rows = $this->collect_line_detail_rows( $line );

		if ( [] === $rows ) {
			return '';
		}

		$output = '<ul style="margin:8px 0 0;padding-left:18px;">';

		foreach ( $rows as $row ) {
			$output .= '<li><strong>' . esc_html( $row['label'] ) . '</strong> ' . esc_html( $row['value'] ) . '</li>';
		}

		$output .= '</ul>';

		return $output;
	}

	private function render_html_package_block( CustomerOrderDeliveryPackageSummary $package ): string {
		$output = '<h3 style="margin-top:24px;">' . esc_html__( 'Shipping summary', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		$output .= '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;" border="1"><tbody>';
		$output .= '<tr><th scope="row" style="text-align:left;">' . esc_html( DeliveryPresentationLabels::shipping_method() ) . '</th>';
		$output .= '<td>' . esc_html( $package->shipping_method_label ) . '</td></tr>';

		if ( null !== $package->package_amount_display && '' !== trim( $package->package_amount_display ) ) {
			$output .= '<tr><th scope="row" style="text-align:left;">' . esc_html( DeliveryPresentationLabels::delivery_charge() ) . '</th>';
			$output .= '<td>' . esc_html( $package->package_amount_display ) . '</td></tr>';
		}

		$output .= '</tbody></table>';

		return $output;
	}

	private function render_plain_text_summary( CustomerOrderDeliverySummary $summary ): string {
		$lines   = [];
		$lines[] = __( 'Delivery details', 'cetech-woocommerce-delivery-engine' );
		$lines[] = str_repeat( '-', 40 );

		foreach ( $summary->lines as $line ) {
			$lines[] = $line->product_name;
			$lines[] = DeliveryPresentationLabels::delivery_option() . ': ' . $line->delivery_option_label;

			foreach ( $this->collect_line_detail_rows( $line ) as $row ) {
				$lines[] = $row['label'] . ' ' . $row['value'];
			}

			$lines[] = '';
		}

		if ( null !== $summary->package ) {
			$lines[] = __( 'Shipping summary', 'cetech-woocommerce-delivery-engine' );
			$lines[] = DeliveryPresentationLabels::shipping_method() . ': ' . $summary->package->shipping_method_label;

			if ( null !== $summary->package->package_amount_display && '' !== trim( $summary->package->package_amount_display ) ) {
				$lines[] = DeliveryPresentationLabels::delivery_charge() . ': ' . $summary->package->package_amount_display;
			}
		}

		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * @return list<array{label: string, value: string}>
	 */
	private function collect_line_detail_rows( CustomerOrderDeliveryLineSummary $line ): array {
		$rows        = [];
		$choice_slug = $this->infer_choice_slug( $line->fulfilment_choice_label );

		if ( '' !== $line->fulfilment_availability_label ) {
			$rows[] = [
				'label' => DeliveryPresentationLabels::fulfilment() . ':',
				'value' => $line->fulfilment_availability_label,
			];
		}

		if ( '' !== $line->fulfilment_choice_label ) {
			$rows[] = [
				'label' => DeliveryPresentationLabels::method_label_for_choice( $choice_slug ) . ':',
				'value' => $line->fulfilment_choice_label,
			];
		}

		if ( null !== $line->estimate_text && '' !== trim( $line->estimate_text ) ) {
			$rows[] = [
				'label' => DeliveryPresentationLabels::estimate_label_for_choice( $choice_slug ) . ':',
				'value' => DeliveryPresentationLabels::strip_estimated_prefix( $line->estimate_text ),
			];
		}

		if ( null !== $line->quoted_amount_display && '' !== trim( $line->quoted_amount_display ) ) {
			$rows[] = [
				'label' => DeliveryPresentationLabels::delivery_charge() . ':',
				'value' => $line->quoted_amount_display,
			];
		}

		return $rows;
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
}
