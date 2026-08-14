<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Loads WordPress-native Delivery Engine admin CSS/JS only on relevant screens.
 */
final class AdminUxAssets {

	public const HANDLE = 'cetech-de-admin-ux';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_relevant_screen( $hook_suffix ) ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.3';

		wp_enqueue_style(
			self::HANDLE,
			CETECH_DE_URL . 'assets/admin/delivery-engine-admin.css',
			[ 'dashicons' ],
			$version
		);

		wp_enqueue_script(
			self::HANDLE,
			CETECH_DE_URL . 'assets/admin/delivery-engine-admin.js',
			[],
			$version,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( EffectiveConfigurationPreviewPage::SLUG === $page ) {
			wp_localize_script(
				self::HANDLE,
				'cetechDePreview',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => PreviewVariationsEndpoint::ACTION,
					'nonce'   => wp_create_nonce( PreviewVariationsEndpoint::ACTION ),
				]
			);
		}
	}

	private function is_relevant_screen( string $hook_suffix ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( str_starts_with( $page, 'cetech-delivery-engine' ) ) {
			return true;
		}

		$product_hooks = [
			'post.php',
			'post-new.php',
			'woocommerce_page_wc-orders',
		];

		if ( in_array( $hook_suffix, $product_hooks, true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( null !== $screen && in_array( $screen->id, [ 'product', 'product_page_product_attributes', 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
				return true;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';
			if ( 'product' === $post_type ) {
				return true;
			}
		}

		return false;
	}
}
