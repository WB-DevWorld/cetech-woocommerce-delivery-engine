<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Mandatory quote semantics stay separate from optional C05 extension references. */
final class DeliveryQuoteSnapshotReader {
	public function read( array $decoded, bool $marker_exists = false, mixed $marker = null ): DeliveryQuoteSnapshotReadResult {
		$present = array_key_exists( DeliveryQuoteSnapshotEnvelope::MEMBER, $decoded );
		if ( ! $present && ! $marker_exists ) { return new DeliveryQuoteSnapshotReadResult( 'not_recorded' ); }
		$outer = $decoded['snapshot_version'] ?? null;
		$promise = DeliveryQuoteSnapshotEnvelope::PROMISE_OUTER_VERSION === $outer;
		if ( $promise && ( ! $marker_exists || ( 2 !== $marker && '2' !== $marker ) ) ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
		if ( $marker_exists && ( $promise ? ( 2 !== $marker && '2' !== $marker ) : ( 1 !== $marker && '1' !== $marker ) ) ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
		if ( ! $present ) { return new DeliveryQuoteSnapshotReadResult( 'missing' ); }
		$packet = $decoded[DeliveryQuoteSnapshotEnvelope::MEMBER];
		if ( $packet instanceof \stdClass && \stdClass::class === get_class( $packet ) ) {
			if ( isset( $packet->format ) && is_int( $packet->format ) && ( $promise ? 2 : 1 ) !== $packet->format ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
			if ( $promise && 2 !== ( $packet->format ?? null ) ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
			try { return new DeliveryQuoteSnapshotReadResult( 'recorded', DeliveryQuoteSnapshotEnvelope::from_json( json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 18 ) ) ); }
			catch ( \Throwable ) { return new DeliveryQuoteSnapshotReadResult( 'malformed' ); }
		}
		if ( ! is_array( $packet ) || array_is_list( $packet ) ) { return new DeliveryQuoteSnapshotReadResult( 'malformed' ); }
		if ( isset( $packet['format'] ) && is_int( $packet['format'] ) && ( $promise ? 2 : 1 ) !== $packet['format'] ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
		if ( $promise && 2 !== ( $packet['format'] ?? null ) ) { return new DeliveryQuoteSnapshotReadResult( 'unsupported' ); }
		try { return new DeliveryQuoteSnapshotReadResult( 'recorded', DeliveryQuoteSnapshotEnvelope::from_array( $packet ) ); }
		catch ( \Throwable ) { return new DeliveryQuoteSnapshotReadResult( 'malformed' ); }
	}
}
