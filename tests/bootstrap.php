<?php

declare(strict_types=1);

/**
 * Minimal stubs for Stage 2 unit tests that do not boot WordPress.
 */

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param mixed $value
	 */
	function update_option( string $option, $value, bool|string $autoload = true ): bool {
		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default_value
	 *
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['cetech_de_test_options'][ $option ] ?? $default_value;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
