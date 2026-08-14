<?php

declare(strict_types=1);

/**
 * WordPress / WooCommerce stubs for Stage 13B owner UI fixture rendering.
 * Not a live WordPress runtime. Used only to call actual admin page classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'CETECH_DE_VERSION' ) ) {
	define( 'CETECH_DE_VERSION', '1.0.0-rc.2' );
}

if ( ! defined( 'CETECH_DE_PATH' ) ) {
	define( 'CETECH_DE_PATH', dirname( __DIR__, 4 ) . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'CETECH_DE_URL' ) ) {
	define( 'CETECH_DE_URL', 'https://example.test/wp-content/plugins/cetech-woocommerce-delivery-engine/' );
}

$GLOBALS['cetech_de_test_options']    = $GLOBALS['cetech_de_test_options'] ?? [];
$GLOBALS['cetech_de_test_transients'] = $GLOBALS['cetech_de_test_transients'] ?? [];
$GLOBALS['cetech_de_test_wc_products'] = $GLOBALS['cetech_de_test_wc_products'] ?? [];
$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new class() {
	public string $prefix = 'wp_';

	public function prepare( string $query, ...$args ): string {
		return (string) ( $args[0] ?? $query );
	}

	public function get_var( string $query ): string {
		return $query;
	}
};

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['cetech_de_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = true ): bool {
		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $option, $GLOBALS['cetech_de_test_options'] ) ) {
			return false;
		}

		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		return $GLOBALS['cetech_de_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		$GLOBALS['cetech_de_test_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['cetech_de_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		$key = strtolower( (string) $key );

		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ): string {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ): string {
		return str_replace( [ '\\', "'", "\n", "\r" ], [ '\\\\', "\\'", '', '' ], (string) $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ): string {
		return (string) $data;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $data, $allowed_html = [] ): string {
		return (string) $data;
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( string $url, $action = -1, string $name = '_wpnonce' ): string {
		return add_query_arg( $name, wp_create_nonce( (string) $action ), $url );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, "/\\" ) . '/';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '' ): string {
		return 'https://example.test/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = null ) {
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = is_string( $value ) ? $value : ( is_string( $url ) ? $url : admin_url( 'admin.php' ) );
		} else {
			$args = [ (string) $key => $value ];
			$url  = is_string( $url ) ? $url : admin_url( 'admin.php' );
		}

		$parts = parse_url( $url );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		foreach ( $args as $name => $arg_value ) {
			if ( null === $arg_value || false === $arg_value ) {
				unset( $query[ $name ] );
			} else {
				$query[ $name ] = $arg_value;
			}
		}

		$scheme = ( $parts['scheme'] ?? 'https' ) . '://';
		$host   = $parts['host'] ?? 'example.test';
		$path   = $parts['path'] ?? '/wp-admin/admin.php';

		return $scheme . $host . $path . ( [] === $query ? '' : '?' . http_build_query( $query ) );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'fixture-nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, string $action = '' ): bool {
		return is_string( $nonce ) && '' !== $nonce;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		if ( $echo ) {
			echo $html;
		}

		return $html;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $echo = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current = true, bool $echo = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, bool $echo = true ): string {
		$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $echo ) {
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true, $other_attributes = null ): void {
		$class = 'button';
		if ( 'primary' === $type || 'primary large' === $type ) {
			$class .= ' button-primary';
		} elseif ( 'secondary' === $type ) {
			$class .= ' button-secondary';
		}
		$button = '<input type="submit" name="' . esc_attr( $name ) . '" class="' . esc_attr( $class ) . '" value="' . esc_attr( $text ) . '" />';
		echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ): never {
		throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '6.7.2' : 'Fixture Store';
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = null, $theme_root = null ): object {
		return new class() {
			public function get_template(): string {
				return 'twentytwentyfour';
			}

			public function get_stylesheet(): string {
				return 'twentytwentyfour';
			}

			public function get( string $header ): string {
				return 'Twenty Twenty-Four';
			}

			public function __toString(): string {
				return 'Twenty Twenty-Four';
			}
		};
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'GHS';
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): object {
		return new class() {
			public object $countries;

			public function __construct() {
				$this->countries = new class() {
					public function get_countries(): array {
						return [ 'GH' => 'Ghana' ];
					}
				};
			}
		};
	}
}

if ( ! class_exists( 'WooCommerce', false ) ) {
	class WooCommerce {
	}
}

if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
	class WC_Shipping_Method {
		public string $id = '';

		public int $instance_id = 0;

		public string $title = '';

		public string $method_title = '';

		public string $method_description = '';

		public string $tax_status = '';

		/** @var list<string> */
		public array $supports = [];

		/** @var array<string, mixed> */
		public array $instance_form_fields = [];

		public function init_form_fields(): void {
		}

		public function init_settings(): void {
		}

		public function get_option( string $key, $default_value = '' ) {
			return $default_value;
		}

		public function process_admin_options(): void {
		}
	}
}

if ( ! class_exists( 'WP_Post', false ) ) {
	class WP_Post {
		public int $ID = 0;

		public int $post_parent = 0;

		public string $post_title = '';
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

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
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

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', $deps = [], $ver = false, $args = false ): void {
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', $deps = [], $ver = false, $media = 'all' ): void {
	}
}

require_once dirname( __DIR__, 4 ) . '/tests/stubs/woocommerce-product-stub.php';
