<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Compiled reader support only; not storage, writer activation, or placement readiness. */
final class DeliveryQuoteSnapshotReadiness {
	public static function supports( mixed $format, mixed $profile ): bool {
		if ( DeliveryQuoteSnapshotEnvelope::PROMISE_FORMAT === $format ) { return is_array( $profile ) && count( $profile ) === 2 && ( $profile['code'] ?? null ) === 'service_promise_v1' && ( $profile['version'] ?? null ) === 1; }
		return 1 === $format && is_array( $profile ) && count( $profile ) === 2 && ( $profile['code'] ?? null ) === 'legacy_fixed_base_v1' && ( $profile['version'] ?? null ) === 1;
	}
	public static function contract(): array { return [ 'format' => DeliveryQuoteSnapshotEnvelope::FORMAT, 'profile' => DeliveryQuoteSnapshotEnvelope::PROFILE, 'marker' => DeliveryQuoteSnapshotEnvelope::META_FORMAT, 'reader_only' => true ]; }
	public static function promise_contract(): array { return RequiredPromiseSnapshotReadiness::contract(); }
}
