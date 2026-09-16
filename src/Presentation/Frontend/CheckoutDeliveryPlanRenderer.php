<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

/**
 * Compact checkout confirmation of per-item delivery choices.
 *
 * Presentation only. Does not edit addresses or rates.
 */
final class CheckoutDeliveryPlanRenderer {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'render' ], 6 );
		add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render' ], 6 );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			CartCustomerContextEditorRenderer::STYLE_HANDLE,
			$base . 'assets/frontend/cart-customer-context-editor.css',
			[],
			$version
		);
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function render(): void {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		if ( ! empty( $GLOBALS['cetech_de_checkout_plan_rendered'] ) ) {
			return;
		}

		$plan = CustomerStorefrontCopy::delivery_plan( WC()->cart->get_cart() );
		if ( [] === $plan ) {
			return;
		}

		$GLOBALS['cetech_de_checkout_plan_rendered'] = true;

		echo '<section class="cetech-de-delivery-plan" aria-labelledby="cetech-de-delivery-plan-title">';
		echo '<h2 id="cetech-de-delivery-plan-title" class="cetech-de-delivery-plan__title">'
			. esc_html( CustomerStorefrontCopy::your_deliveries() )
			. '</h2>';

		foreach ( $plan as $group ) {
			echo '<div class="cetech-de-delivery-plan__group">';
			echo '<h3 class="cetech-de-delivery-plan__heading">' . esc_html( (string) ( $group['heading'] ?? '' ) ) . '</h3>';
			foreach ( $group['lines'] as $line ) {
				echo '<p class="cetech-de-delivery-plan__line">';
				if ( '' !== (string) ( $line['product'] ?? '' ) ) {
					echo '<span class="cetech-de-delivery-plan__product">' . esc_html( (string) $line['product'] ) . '</span> ';
				}
				echo '<span class="cetech-de-delivery-plan__offer">' . esc_html( (string) ( $line['estimate'] ?? $line['offer'] ?? '' ) ) . '</span>';
				echo '</p>';
			}
			echo '</div>';
		}

		echo '</section>';
	}
}
