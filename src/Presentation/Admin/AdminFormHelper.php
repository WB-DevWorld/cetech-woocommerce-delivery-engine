<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Shared admin form helpers.
 */
final class AdminFormHelper {

	public static function nonce_field( string $action ): void {
		wp_nonce_field( $action, 'cetech_de_nonce' );
	}

	public static function nonce_field_html( string $action ): string {
		return '<input type="hidden" name="cetech_de_nonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}

	public static function verify_nonce( string $action ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_nonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( (string) $_POST['cetech_de_nonce'] ) ),
			$action
		);
	}

	public static function text_field(
		string $name,
		string $label,
		string $value = '',
		bool $required = false,
		string $description = ''
	): void {
		echo '<tr><th scope="row">';
		echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="description">' . esc_html__( '(required)', 'cetech-woocommerce-delivery-engine' ) . '</span>';
		}
		echo '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" %3$s />',
			esc_attr( $name ),
			esc_attr( $value ),
			$required ? 'required' : ''
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function textarea_field(
		string $name,
		string $label,
		string $value = '',
		int $rows = 4,
		string $description = ''
	): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<textarea class="large-text" id="%1$s" name="%1$s" rows="%2$d">%3$s</textarea>',
			esc_attr( $name ),
			$rows,
			esc_textarea( $value )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function date_field(
		string $name,
		string $label,
		string $value = '',
		string $description = ''
	): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="date" class="cetech-de-date-input" id="%1$s" name="%1$s" value="%2$s" />',
			esc_attr( $name ),
			esc_attr( $value )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function url_field(
		string $name,
		string $label,
		string $value = '',
		string $description = ''
	): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="url" class="regular-text" id="%1$s" name="%1$s" value="%2$s" inputmode="url" autocomplete="off" />',
			esc_attr( $name ),
			esc_attr( $value )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function number_field(
		string $name,
		string $label,
		?int $value = null,
		int $min = 0,
		string $description = ''
	): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="number" class="small-text" id="%1$s" name="%1$s" value="%2$s" min="%3$d" step="1" />',
			esc_attr( $name ),
			null === $value ? '' : esc_attr( (string) $value ),
			$min
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string, string> $options
	 */
	public static function select_field(
		string $name,
		string $label,
		array $options,
		string $selected = '',
		string $description = '',
		bool $required = false
	): void {
		echo '<tr><th scope="row">';
		echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="description">' . esc_html__( '(required)', 'cetech-woocommerce-delivery-engine' ) . '</span>';
		}
		echo '</label></th><td>';

		$select_attrs = 'class="cetech-de-select"';
		if ( $required ) {
			$select_attrs .= ' required="required" aria-required="true"';
		}

		printf( '<select id="%1$s" name="%1$s" %2$s>', esc_attr( $name ), $select_attrs );
		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $option_label )
			);
		}
		echo '</select>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string, string> $options
	 * @param list<string>          $selected
	 */
	public static function checkbox_group_field(
		string $name,
		string $label,
		array $options,
		array $selected = [],
		string $description = ''
	): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		foreach ( $options as $value => $option_label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( in_array( $value, $selected, true ), true, false ),
				esc_html( $option_label )
			);
		}
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function sanitize_code( string $code ): string {
		$code = strtolower( trim( $code ) );

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $code );
	}

	public static function is_valid_code( string $code ): bool {
		return (bool) preg_match( '/^[a-z0-9_-]+$/', $code );
	}

	/**
	 * Generate a unique reference code from a display name.
	 *
	 * @param callable(string): bool $exists
	 */
	public static function generate_code_from_name( string $name, callable $exists, string $fallback = 'item' ): string {
		$base = strtolower( trim( $name ) );
		$base = (string) preg_replace( '/[\s_]+/', '-', $base );
		$base = self::sanitize_code( $base );
		if ( '' === $base ) {
			$base = $fallback;
		}
		if ( strlen( $base ) > 40 ) {
			$base = substr( $base, 0, 40 );
		}

		$code  = $base;
		$index = 2;
		while ( $exists( $code ) ) {
			$suffix = '-' . $index;
			$code   = substr( $base, 0, max( 1, 48 - strlen( $suffix ) ) ) . $suffix;
			++$index;
			if ( $index > 99 ) {
				$code = $base . '-' . wp_generate_password( 4, false, false );
				break;
			}
		}

		return $code;
	}

	public static function checkbox_field(
		string $name,
		string $label,
		bool $checked = false,
		string $description = ''
	): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		printf(
			'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html__( 'Yes', 'cetech-woocommerce-delivery-engine' )
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function verify_get_nonce( string $action ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['cetech_de_nonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( (string) $_GET['cetech_de_nonce'] ) ),
			$action
		);
	}
}
