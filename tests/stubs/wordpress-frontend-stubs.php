<?php

declare(strict_types=1);

/**
 * WordPress frontend stubs for VariableDeliverySelectorAssets unit tests.
 */

if ( ! function_exists( 'is_product' ) ) {
	function is_product(): bool {
		return (bool) ( $GLOBALS['cetech_de_test_is_product'] ?? false );
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		return (int) ( $GLOBALS['cetech_de_test_the_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * @param mixed $deps
	 */
	function wp_enqueue_script( string $handle, string $src = '', $deps = [], $ver = false, $args = false ): void {
		$GLOBALS['cetech_de_test_enqueued_scripts'][] = $handle;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * @param mixed $deps
	 */
	function wp_enqueue_style( string $handle, string $src = '', $deps = [], $ver = false, $media = 'all' ): void {
		$GLOBALS['cetech_de_test_enqueued_styles'][] = $handle;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * @param mixed $data
	 */
	function wp_localize_script( string $handle, string $object_name, $data ): bool {
		return true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'test-nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		return new class() {
			public function get_template(): string {
				return (string) ( $GLOBALS['cetech_de_test_theme_template'] ?? 'default' );
			}

			public function get( string $header = '' ) {
				return '';
			}

			public function get_stylesheet(): string {
				return $this->get_template();
			}

			public function parent() {
				return false;
			}
		};
	}
}

if ( ! class_exists( 'WooCommerce', false ) && '1' !== getenv( 'CETECH_DE_DISABLE_WC_STUB' ) ) {
	class WooCommerce {
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( string $message, string $notice_type = 'success' ): void {
		$GLOBALS['cetech_de_test_notices'][] = [
			'message' => $message,
			'type'    => $notice_type,
		];
	}
}
