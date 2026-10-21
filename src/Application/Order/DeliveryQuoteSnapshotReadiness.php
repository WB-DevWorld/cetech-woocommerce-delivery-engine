<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Compiled reader support only; not storage, writer activation, or placement readiness. */
final class DeliveryQuoteSnapshotReadiness {
	public static function supports( mixed $format, mixed $profile ): bool {
		if ( DeliveryQuoteSnapshotEnvelope::FIXED_PROMOTION_FORMAT === $format ) { return is_array( $profile ) && count( $profile ) === 2 && ( $profile['code'] ?? null ) === 'fixed_promotion_native_v1' && ( $profile['version'] ?? null ) === 1; }
		if ( DeliveryQuoteSnapshotEnvelope::PROMISE_FORMAT === $format ) { return is_array( $profile ) && count( $profile ) === 2 && ( $profile['code'] ?? null ) === 'service_promise_v1' && ( $profile['version'] ?? null ) === 1; }
		return 1 === $format && is_array( $profile ) && count( $profile ) === 2 && ( $profile['code'] ?? null ) === 'legacy_fixed_base_v1' && ( $profile['version'] ?? null ) === 1;
	}
	public static function contract(): array { return [ 'format' => DeliveryQuoteSnapshotEnvelope::FORMAT, 'profile' => DeliveryQuoteSnapshotEnvelope::PROFILE, 'marker' => DeliveryQuoteSnapshotEnvelope::META_FORMAT, 'reader_only' => true ]; }
	public static function promise_contract(): array { return RequiredPromiseSnapshotReadiness::contract(); }
	public static function fixed_promotion_contract(): array { return [ 'format' => DeliveryQuoteSnapshotEnvelope::FIXED_PROMOTION_FORMAT, 'profile' => DeliveryQuoteSnapshotEnvelope::FIXED_PROMOTION_PROFILE, 'outer_version' => DeliveryQuoteSnapshotEnvelope::FIXED_PROMOTION_OUTER_VERSION, 'marker' => DeliveryQuoteSnapshotEnvelope::META_FORMAT, 'marker_value' => '3', 'reader_only' => true, 'writer_enabled' => false ]; }
}
