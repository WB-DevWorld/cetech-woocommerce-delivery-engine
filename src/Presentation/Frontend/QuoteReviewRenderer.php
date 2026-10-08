<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteResult;

/** Customer view consumes only the core's explicit shopper projection. */
final class QuoteReviewRenderer {

	public static function message( string $code ): string {
		return match ( $code ) {
			'no_quote' => __( 'Refresh delivery to review the current price.', 'cetech-woocommerce-delivery-engine' ),
			'review_required' => __( 'Review the delivery price, then confirm it.', 'cetech-woocommerce-delivery-engine' ),
			'confirmed' => __( 'Delivery price confirmed for these delivery details.', 'cetech-woocommerce-delivery-engine' ),
			'expired' => __( 'This delivery quote has expired. Refresh delivery and review the new price.', 'cetech-woocommerce-delivery-engine' ),
			'changed' => __( 'Your delivery details changed. Review delivery again.', 'cetech-woocommerce-delivery-engine' ),
			'unconfirmed' => __( 'We could not confirm this quote. Retry the same request.', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Delivery quoting is temporarily unavailable. Try again.', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	public function table_row( CartQuoteResult $result ): string {
		$facts = $result->shopper_facts();
		$json = json_encode( $facts, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$html = '<div class="cetech-de-quote-review" data-quote-review-transport="classic" data-quote-review-facts="' . esc_attr( $json ) . '">';
		$html .= '<p class="cetech-de-quote-review-message" role="status" aria-live="polite">' . esc_html( self::message( $facts['message_code'] ) ) . '</p>';
		if ( is_array( $facts['quote'] ) ) {
			$html .= '<ul class="cetech-de-quote-review-money">';
			foreach ( $facts['quote']['money'] as $component ) {
				$money = $component['display_total'] ?? $component['total'];
				$html .= '<li>' . esc_html( $component['customer_label'] ) . ': <strong>' . esc_html( $money['currency'] . ' ' . $money['amount'] ) . '</strong></li>';
			}
			$html .= '</ul><p class="cetech-de-quote-review-expiry">' . esc_html__( 'Delivery price is held until', 'cetech-woocommerce-delivery-engine' )
				. ' <time datetime="' . esc_attr( $facts['quote']['expires_at'] ) . '">' . esc_html( $facts['quote']['expires_at'] ) . '</time>. '
				. esc_html__( 'Delivery details and price rules must stay unchanged.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		$html .= '<div class="cetech-de-quote-review-actions">';
		foreach ( [ 'refresh' => 'Refresh delivery', 'confirm' => 'Confirm delivery price', 'retry' => 'Retry same request' ] as $action => $label ) {
			if ( $facts['can_' . $action] ) { $html .= '<button type="button" data-quote-review-action="' . esc_attr( $action ) . '">' . esc_html__( $label, 'cetech-woocommerce-delivery-engine' ) . '</button>'; }
		}
		$html .= '</div></div>';
		return '<tr class="cetech-de-quote-review-row"><th>' . esc_html__( 'Delivery price', 'cetech-woocommerce-delivery-engine' ) . '</th><td>' . $html . '</td></tr>';
	}

	public function unavailable_table_row(): string {
		return '<tr class="cetech-de-quote-review-row"><th>' . esc_html__( 'Delivery price', 'cetech-woocommerce-delivery-engine' ) . '</th><td><p role="status">'
			. esc_html( self::message( 'unavailable' ) ) . '</p></td></tr>';
	}
}
