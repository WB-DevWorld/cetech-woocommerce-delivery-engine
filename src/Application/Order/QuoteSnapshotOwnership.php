<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Shared loaded/physical ownership census. Unknown promise-era bytes cannot become legacy absence. */
final class QuoteSnapshotOwnership {
	public static function version_owned( mixed $version ): bool { return ! in_array( $version, [ 1, 2, '1', '2' ], true ); }
	public static function from_raw( mixed $raw ): bool {
		if ( ! is_string( $raw ) || '' === $raw ) { return false; }
		if ( strlen( $raw ) > 4 * 1024 * 1024 ) { return true; }
		try {
			$decoded = json_decode( $raw, true, OrderDeliverySnapshotJson::MAX_DEPTH, JSON_THROW_ON_ERROR );
			if ( is_array( $decoded ) && ( array_key_exists( DeliveryQuoteSnapshotEnvelope::MEMBER, $decoded ) || array_key_exists( 'snapshot_version', $decoded ) && self::version_owned( $decoded['snapshot_version'] ) ) ) { return true; }
		} catch ( \Throwable ) {}
		$names = preg_replace_callback( '/\\\\u00([0-7][0-9a-fA-F])/', static fn( array $match ): string => chr( hexdec( $match[1] ) ), $raw );
		if ( null === $names || str_contains( $names, DeliveryQuoteSnapshotEnvelope::MEMBER ) ) { return true; }
		// Malformed or duplicate future-version declarations remain protected,
		// including ASCII escaped names/values that a stale loaded object missed.
		$count = preg_match_all( '/"snapshot_version"\s*:\s*/', $names, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $count || $count > 1 ) { return true; }
		if ( 1 === $count ) {
			$match = $matches[0][0]; $value = substr( $names, $match[1] + strlen( $match[0] ) );
			return 1 !== preg_match( '/\A(?:"[12]"|[12])(?=[,}\s])/', $value );
		}
		return false;
	}
}
