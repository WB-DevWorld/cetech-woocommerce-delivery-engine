<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

/**
 * Atomic WordPress option insert / compare-and-swap / compare-and-delete.
 *
 * Used by the country-identity repair lease. Does not introduce schema 7.
 * Real $wpdb conditional UPDATE/DELETE is preferred; in-memory option stubs
 * keep the same expected-value contract for unit tests.
 */
final class WordPressOptionCasStore {

	/**
	 * @param mixed $value
	 */
	public function add( string $name, mixed $value ): bool {
		if ( $this->real_wpdb_available() ) {
			global $wpdb;
			$inserted = $wpdb->insert(
				(string) $wpdb->options,
				[
					'option_name'  => $name,
					'option_value' => $this->encode( $value ),
					'autoload'     => 'no',
				]
			);
			if ( false === $inserted || (int) $inserted < 1 ) {
				return false;
			}

			return $this->accept( $name, $value );
		}
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}
		$created = add_option( $name, $value, '', false );
		if ( $created ) {
			$this->remember( $name, $value );
			$this->invalidate_cache( $name );
		}

		return $created;
	}

	public function get( string $name, mixed $default = false ): mixed {
		if ( function_exists( 'get_option' ) ) {
			return get_option( $name, $default );
		}

		return $GLOBALS['cetech_de_test_options'][ $name ] ?? $default;
	}

	/**
	 * Replace the stored option only when the current encoded value still
	 * matches $expected. Zero-row success is accepted only when the row
	 * already equals $replacement (idempotent retry).
	 *
	 * @param mixed $expected
	 * @param mixed $replacement
	 */
	public function compare_and_swap( string $name, mixed $expected, mixed $replacement ): bool {
		if ( $this->real_wpdb_available() ) {
			global $wpdb;
			$updated = $wpdb->update(
				(string) $wpdb->options,
				[ 'option_value' => $this->encode( $replacement ) ],
				[
					'option_name'  => $name,
					'option_value' => $this->encode( $expected ),
				]
			);
			if ( false === $updated ) {
				return false;
			}
			if ( is_int( $updated ) && $updated > 0 ) {
				return $this->accept( $name, $replacement );
			}
			if ( 0 === (int) $updated && $this->persisted_equals( $name, $replacement ) ) {
				return $this->accept( $name, $replacement );
			}

			return false;
		}

		$current = $this->get( $name, null );
		if ( $this->encode( $current ) !== $this->encode( $expected ) ) {
			return false;
		}

		return $this->accept( $name, $replacement );
	}

	/**
	 * Delete the option only when the stored encoded value still matches
	 * $expected. A missing row is treated as success (already released).
	 *
	 * @param mixed $expected
	 */
	public function compare_and_delete( string $name, mixed $expected ): bool {
		if ( $this->real_wpdb_available() ) {
			global $wpdb;
			if ( ! $this->row_exists( $name ) ) {
				$this->forget( $name );
				$this->invalidate_cache( $name );

				return true;
			}
			$deleted = $wpdb->delete(
				(string) $wpdb->options,
				[
					'option_name'  => $name,
					'option_value' => $this->encode( $expected ),
				]
			);
			if ( false === $deleted ) {
				return false;
			}
			if ( is_int( $deleted ) && $deleted > 0 ) {
				$this->forget( $name );
				$this->invalidate_cache( $name );

				return true;
			}

			return ! $this->row_exists( $name );
		}

		if ( ! array_key_exists( $name, $GLOBALS['cetech_de_test_options'] ?? [] ) ) {
			$this->invalidate_cache( $name );

			return true;
		}
		if ( $this->encode( $this->get( $name, null ) ) !== $this->encode( $expected ) ) {
			return false;
		}
		$this->forget( $name );
		$this->invalidate_cache( $name );

		return true;
	}

	/**
	 * WordPress maybe_serialize-compatible encoding for option_value.
	 *
	 * @param mixed $value
	 */
	public function encode( mixed $value ): string {
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}

		return (string) $value;
	}

	private function accept( string $name, mixed $value ): bool {
		$this->remember( $name, $value );
		$this->invalidate_cache( $name );

		return true;
	}

	private function remember( string $name, mixed $value ): void {
		$GLOBALS['cetech_de_test_options'][ $name ] = $value;
	}

	private function forget( string $name ): void {
		unset( $GLOBALS['cetech_de_test_options'][ $name ] );
	}

	private function invalidate_cache( string $name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function persisted_equals( string $name, mixed $expected ): bool {
		$stored = $this->persisted_raw( $name );

		return is_string( $stored ) && $stored === $this->encode( $expected );
	}

	private function row_exists( string $name ): bool {
		return is_string( $this->persisted_raw( $name ) );
	}

	private function persisted_raw( string $name ): ?string {
		global $wpdb;
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->options );
		if ( ! is_string( $table ) || '' === $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a sanitized wpdb->options identifier.
		$sql = $wpdb->prepare(
			"SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1",
			$name
		);
		if ( ! is_string( $sql ) ) {
			return null;
		}
		$stored = $wpdb->get_var( $sql );

		return is_string( $stored ) ? $stored : null;
	}

	private function real_wpdb_available(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'update' ) || ! method_exists( $wpdb, 'delete' ) ) {
			return false;
		}

		return ! str_contains( $wpdb::class, 'FakeWpdb' );
	}
}
