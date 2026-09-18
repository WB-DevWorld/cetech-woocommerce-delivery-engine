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
		if ( BulkToolsPage::SLUG === $page ) {
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
			}
			if ( wp_style_is( 'select2', 'registered' ) ) {
				wp_enqueue_style( 'select2' );
			}
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}

			$bulk_deps = [ 'jquery' ];
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				$bulk_deps[] = 'selectWoo';
			}

			wp_enqueue_script(
				'cetech-de-bulk-tools',
				CETECH_DE_URL . 'assets/admin/bulk-tools.js',
				$bulk_deps,
				$version,
				true
			);
			wp_localize_script(
				'cetech-de-bulk-tools',
				'cetechDeBulk',
				[
					'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
					'action'              => BulkJobProgressEndpoint::ACTION,
					'nonce'               => wp_create_nonce( BulkJobProgressEndpoint::ACTION ),
					'searchProductsNonce' => wp_create_nonce( 'search-products' ),
				]
			);
		}

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

		if ( DestinationZonesPage::SLUG === $page || LocationPacksPage::SLUG === $page ) {
			wp_localize_script(
				self::HANDLE,
				'cetechDeGeography',
				[
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'searchAction' => \CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint::SEARCH_ACTION,
					'packAction'   => \CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint::PACK_ACTION,
					'searchNonce'  => wp_create_nonce( \CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint::SEARCH_ACTION ),
					'packNonce'    => wp_create_nonce( \CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint::PACK_ACTION ),
					'i18n'         => [
						'search'     => __( 'Search…', 'cetech-woocommerce-delivery-engine' ),
						'included'   => __( 'localities included', 'cetech-woocommerce-delivery-engine' ),
						'selectAll'  => __( 'Select all', 'cetech-woocommerce-delivery-engine' ),
						'clear'      => __( 'Clear', 'cetech-woocommerce-delivery-engine' ),
					],
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
