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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$key = strtolower( $key );

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data
	 */
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, "/\\" );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
