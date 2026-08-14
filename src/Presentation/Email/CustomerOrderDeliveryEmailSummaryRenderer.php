<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Email;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Email;
use WC_Order;

/**
 * Customer-safe read-only delivery summary in WooCommerce customer order emails.
 *
 * Stage 13F compact contract mirrors thank-you / My Account presentation.
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

		if ( null === $summary || [] === $summary->lines ) {
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

		$multi = count( $summary->lines ) > 1;

		foreach ( $summary->lines as $line ) {
			if ( $multi ) {
				$output .= '<p style="margin:12px 0 4px;font-weight:600;">' . esc_html( $line->product_name ) . '</p>';
			}

			$rows = $this->compact_rows( $line );

			if ( [] === $rows ) {
				continue;
			}

			$output .= '<ul style="margin:0 0 12px;padding-left:18px;">';

			foreach ( $rows as $row ) {
				$output .= '<li><strong>' . esc_html( $row['key'] ) . ':</strong> ' . esc_html( $row['value'] ) . '</li>';
			}

			$output .= '</ul>';
		}

		$output .= '</div>';

		return $output;
	}

	private function render_plain_text_summary( CustomerOrderDeliverySummary $summary ): string {
		$lines   = [];
		$lines[] = __( 'Delivery details', 'cetech-woocommerce-delivery-engine' );
		$lines[] = str_repeat( '-', 40 );

		$multi = count( $summary->lines ) > 1;

		foreach ( $summary->lines as $line ) {
			if ( $multi ) {
				$lines[] = $line->product_name;
			}

			foreach ( $this->compact_rows( $line ) as $row ) {
				$lines[] = $row['key'] . ': ' . $row['value'];
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
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
}
