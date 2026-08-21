<?php
/**
 * Minimal dbDelta stand-in for lifecycle qualification (not WordPress core).
 */

declare(strict_types=1);

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * @param string|list<string> $queries
	 * @return array<string, string>
	 */
	function dbDelta( $queries = '', bool $execute = true ): array {
		unset( $execute );
		global $wpdb;

		if ( ! is_array( $queries ) ) {
			$queries = [ (string) $queries ];
		}

		foreach ( $queries as $sql ) {
			if ( preg_match( '/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-z0-9_]+)`?/i', (string) $sql, $matches ) ) {
				if ( is_object( $wpdb ) && method_exists( $wpdb, 'register_table' ) ) {
					$wpdb->register_table( $matches[1] );
				}
			}
		}

		return [];
	}
}
