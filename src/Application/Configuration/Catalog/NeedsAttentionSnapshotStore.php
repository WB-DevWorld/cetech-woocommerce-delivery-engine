<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Non-autoloaded options behind the Needs Attention catalog snapshot.
 *
 * All four options are derived cache and are removed on uninstall regardless
 * of the keep-data setting.
 */
final class NeedsAttentionSnapshotStore {

	public const OPTION_SNAPSHOT   = 'cetech_de_attention_catalog_snapshot';
	public const OPTION_GENERATION = 'cetech_de_attention_catalog_generation';
	public const OPTION_PROGRESS   = 'cetech_de_attention_catalog_refresh';
	public const OPTION_LOCK       = 'cetech_de_attention_catalog_refresh_lock';

	/**
	 * @return list<string>
	 */
	public static function option_names(): array {
		return [ self::OPTION_SNAPSHOT, self::OPTION_GENERATION, self::OPTION_PROGRESS, self::OPTION_LOCK ];
	}

	public function snapshot(): ?NeedsAttentionCatalogSnapshot {
		return NeedsAttentionCatalogSnapshot::from_stored( $this->read( self::OPTION_SNAPSHOT ) );
	}

	public function save_snapshot( NeedsAttentionCatalogSnapshot $snapshot ): void {
		$this->write( self::OPTION_SNAPSHOT, $snapshot->to_array() );
	}

	public function generation(): int {
		return (int) $this->read( self::OPTION_GENERATION, 0 );
	}

	/**
	 * The generation as stored right now, bypassing this request's object cache
	 * (a persistent cache keeps a per-request copy that another process's
	 * invalidation does not update). Used before a scan result is saved.
	 */
	public function stored_generation(): int {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) && ! str_contains( $wpdb::class, 'FakeWpdb' ) ) {
			$table = (string) $wpdb->options;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", self::OPTION_GENERATION ) );
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( self::OPTION_GENERATION, 'options' );
			}

			return null === $value ? 0 : (int) $value;
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_GENERATION, 'options' );
		}

		return $this->generation();
	}

	/**
	 * Move to a new generation. The value is random rather than incremented, so
	 * two concurrent invalidations can never write back the generation a running
	 * scan started from.
	 */
	public function new_generation(): int {
		$current = $this->generation();
		do {
			$next = random_int( 1, PHP_INT_MAX );
		} while ( $next === $current );

		$this->write( self::OPTION_GENERATION, $next );

		return $next;
	}

	/**
	 * @return array{generation: int, offset: int, count: int, started_at: int}|null
	 */
	public function progress(): ?array {
		$stored = $this->read( self::OPTION_PROGRESS );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		return [
			'generation' => (int) ( $stored['generation'] ?? 0 ),
			'offset'     => max( 0, (int) ( $stored['offset'] ?? 0 ) ),
			'count'      => max( 0, (int) ( $stored['count'] ?? 0 ) ),
			'started_at' => (int) ( $stored['started_at'] ?? 0 ),
		];
	}

	/**
	 * @param array{generation: int, offset: int, count: int, started_at: int} $progress
	 */
	public function save_progress( array $progress ): void {
		$this->write( self::OPTION_PROGRESS, $progress );
	}

	public function clear_progress(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_PROGRESS );
		}
	}

	/**
	 * Take the single-runner lock. A lock older than $ttl seconds is treated as
	 * abandoned (for example a PHP fatal mid-run) and replaced.
	 *
	 * Best effort, like the other option locks in this plugin: it stops a second
	 * cron runner from doing the same work. Correctness does not depend on it;
	 * the generation check before saving does.
	 */
	public function acquire_lock( int $now, int $ttl ): bool {
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}

		if ( add_option( self::OPTION_LOCK, (string) $now, '', false ) ) {
			return true;
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_LOCK, 'options' );
		}
		$held_since = (int) $this->read( self::OPTION_LOCK, 0 );
		if ( $held_since > 0 && $now - $held_since < $ttl ) {
			return false;
		}

		delete_option( self::OPTION_LOCK );

		return add_option( self::OPTION_LOCK, (string) $now, '', false );
	}

	public function release_lock(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_LOCK );
		}
	}

	public function delete_all(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}

		foreach ( self::option_names() as $option ) {
			delete_option( $option );
		}
	}

	private function read( string $option, mixed $default_value = null ): mixed {
		return function_exists( 'get_option' ) ? get_option( $option, $default_value ) : $default_value;
	}

	private function write( string $option, mixed $value ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( null === get_option( $option, null ) && function_exists( 'add_option' ) && add_option( $option, $value, '', false ) ) {
			return;
		}

		update_option( $option, $value, false );
	}
}
