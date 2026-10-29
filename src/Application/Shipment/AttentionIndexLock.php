<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Mutual exclusion for the compact Needs Attention option indexes (COR-033).
 *
 * The indexes are read-modify-write lists stored in one option each, so two
 * writers (two orders cancelled at once, a refund hook racing a status change)
 * could overwrite each other's entries. The lock is an option row created with
 * add_option(), which is atomic on the option_name unique key, held for the
 * duration of one index mutation and released afterwards. A lock older than
 * TTL_SECONDS is taken over so a crashed writer cannot block the index forever.
 * The index is re-read from the database after the lock is taken, never from a
 * value cached before another writer committed.
 */
final class AttentionIndexLock {

	public const LOCK_SUFFIX = '_lock';

	public const TTL_SECONDS = 30;

	public static int $max_attempts = 40;

	public static int $wait_micros = 25000;

	private function __construct() {
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 *
	 * @throws AttentionIndexBusyException When the lock could not be taken within the attempts.
	 */
	public static function run( string $option, callable $work ): mixed {
		$lock = $option . self::LOCK_SUFFIX;

		if ( ! self::acquire( $lock ) ) {
			throw new AttentionIndexBusyException( $option );
		}

		try {
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option, 'options' );
			}

			return $work();
		} finally {
			delete_option( $lock );
		}
	}

	private static function acquire( string $lock ): bool {
		$attempts = max( 1, self::$max_attempts );

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			if ( add_option( $lock, (string) time(), '', false ) ) {
				return true;
			}

			if ( self::is_stale( $lock ) ) {
				delete_option( $lock );
				continue;
			}

			if ( $attempt + 1 < $attempts && self::$wait_micros > 0 ) {
				usleep( self::$wait_micros );
			}
		}

		return false;
	}

	private static function is_stale( string $lock ): bool {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $lock, 'options' );
		}

		$taken_at = (int) get_option( $lock, 0 );

		return $taken_at > 0 && ( time() - $taken_at ) > self::TTL_SECONDS;
	}
}
