<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

/**
 * Canonical operational readiness for Needs Attention and Delivery Preview.
 *
 * Ready means the effective configuration can offer valid delivery.
 * Needs Attention means it cannot — with the same human-readable reason
 * used on both screens.
 */
final class OperationalReadiness {

	public const STATUS_READY = 'Ready';

	public const STATUS_NEEDS_ATTENTION = 'Needs Attention';

	public function __construct(
		public readonly bool $is_ready,
		public readonly string $status_label,
		public readonly ?string $reason
	) {
	}

	public static function ready(): self {
		return new self( true, self::STATUS_READY, null );
	}

	public static function needs_attention( string $reason ): self {
		return new self( false, self::STATUS_NEEDS_ATTENTION, $reason );
	}
}
