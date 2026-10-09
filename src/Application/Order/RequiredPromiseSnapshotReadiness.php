<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Compiled required-promise readers only. This grants no writer, seal or payment authority. */
final class RequiredPromiseSnapshotReadiness {
	public static function supports( mixed $outer, mixed $envelope, mixed $profile, mixed $packet ): bool {
		return '3' === $outer && 2 === $envelope && 1 === $packet && DeliveryQuoteSnapshotReadiness::supports( $envelope, $profile );
	}
	public static function contract(): array {
		return [ 'component' => 'required_promise_snapshot_v1', 'outer_format' => '3', 'envelope_format' => 2, 'profile' => DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE, 'packet_format' => 1, 'marker' => DeliveryQuoteSnapshotEnvelope::META_FORMAT, 'marker_value' => '2', 'reader_only' => true, 'supports_required' => true, 'optional_c05_version' => 1 ];
	}
}
