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

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['cetech_de_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * @return object|null
	 */
	function get_role( string $role ) {
		return $GLOBALS['cetech_de_test_roles'][ $role ] ?? null;
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles(): object {
		if ( ! isset( $GLOBALS['cetech_de_test_wp_roles'] ) ) {
			$GLOBALS['cetech_de_test_wp_roles'] = new class() {
				/** @var array<string, array<string, mixed>> */
				public array $roles = [];
			};
		}

		return $GLOBALS['cetech_de_test_wp_roles'];
	}
}

if ( ! function_exists( 'translate_user_role' ) ) {
	function translate_user_role( string $name ): string {
		return $name;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string ) ?? $string;
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string ) ?? $string;
		}

		return trim( $string );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		unset( $args );

		return (bool) ( $GLOBALS['cetech_de_test_caps'][ $capability ] ?? $GLOBALS['cetech_de_test_caps']['*'] ?? false );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) ( $GLOBALS['cetech_de_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $maybeint
	 */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
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

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['cetech_de_test_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['cetech_de_test_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
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

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return $nonce === 'test-nonce-' . $action;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['cetech_de_test_actions'][ $hook ][] = [
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		];

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', mixed $deprecated = '', bool|string $autoload = 'yes' ): bool {
		unset( $deprecated, $autoload );

		if ( array_key_exists( $option, $GLOBALS['cetech_de_test_options'] ?? [] ) ) {
			return false;
		}

		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( mixed $number, mixed $dp = false, bool $trim_zeros = false ): string {
		unset( $trim_zeros );
		$decimals = is_numeric( $dp ) ? (int) $dp : 4;

		return number_format( (float) $number, $decimals, '.', '' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( mixed ...$args ): string {
		if ( [] === $args ) {
			return 'https://example.test/wp-admin/admin.php';
		}

		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = isset( $args[1] ) ? (string) $args[1] : 'https://example.test/wp-admin/admin.php';
		} elseif ( isset( $args[2] ) ) {
			$params = [ (string) $args[0] => $args[1] ];
			$url    = (string) $args[2];
		} else {
			$params = [ (string) $args[0] => $args[1] ?? '' ];
			$url    = 'https://example.test/wp-admin/admin.php';
		}

		$query = [];

		foreach ( $params as $key => $value ) {
			$query[ (string) $key ] = (string) $value;
		}

		$separator = str_contains( $url, '?' ) ? '&' : '?';

		return $url . $separator . http_build_query( $query );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( mixed $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		unset( $status, $x_redirect_by );
		$GLOBALS['cetech_de_test_redirects'][] = $location;

		throw new RuntimeException( 'cetech_de_test_redirect' );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) ( $GLOBALS['cetech_de_test_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * @param array<string, mixed> $allowed_html
	 */
	function wp_kses( mixed $content, $allowed_html, $allowed_protocols = [] ): string {
		unset( $allowed_html, $allowed_protocols );

		return (string) $content;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';

		if ( $display ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( mixed $message = '', mixed $title = '', mixed $args = [] ): void {
		unset( $title, $args );
		$GLOBALS['cetech_de_test_wp_die'] = $message;

		throw new RuntimeException( 'wp_die' );
	}
}

if ( ! function_exists( 'paginate_links' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function paginate_links( $args = [] ): string {
		$total   = (int) ( $args['total'] ?? 1 );
		$current = (int) ( $args['current'] ?? 1 );

		if ( $total <= 1 ) {
			return '';
		}

		return '<span class="cetech-de-test-pagination">page ' . $current . ' of ' . $total . '</span>';
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( mixed ...$args ): string {
		$GLOBALS['cetech_de_test_menus'][] = $args;

		return (string) ( $args[3] ?? 'cetech-delivery-engine' );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page(
		string $parent_slug,
		string $page_title,
		string $menu_title,
		string $capability,
		string $menu_slug,
		mixed $callback = ''
	): string {
		$GLOBALS['cetech_de_test_submenus'][] = [
			'parent'     => $parent_slug,
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
		];

		return $menu_slug;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function wc_price( mixed $price, $args = [] ): string {
		$currency  = isset( $args['currency'] ) ? (string) $args['currency'] : '';
		$formatted = number_format( (float) $price, 2, '.', '' );

		return ( '' !== $currency ? $currency . ' ' : '' ) . $formatted;
	}
}

require_once __DIR__ . '/stubs/woocommerce-product-stub.php';

require_once __DIR__ . '/stubs/woocommerce-order-stub.php';

require_once __DIR__ . '/stubs/wordpress-frontend-stubs.php';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
