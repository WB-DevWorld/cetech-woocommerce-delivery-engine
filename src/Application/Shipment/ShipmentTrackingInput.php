<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Staff-submitted tracking fields. Not trusted until ShipmentTrackingService validates them.
 */
final class ShipmentTrackingInput {

	public function __construct(
		public readonly string $carrier,
		public readonly string $tracking_number,
		public readonly string $tracking_url,
		public readonly string $dispatch_date,
		public readonly string $public_note
	) {
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function from_post( array $post ): self {
		return new self(
			self::unslash_string( $post['tracking_carrier'] ?? '' ),
			self::unslash_string( $post['tracking_number'] ?? '' ),
			self::unslash_string( $post['tracking_url'] ?? '' ),
			self::unslash_string( $post['dispatch_date'] ?? '' ),
			self::unslash_string( $post['public_note'] ?? '' )
		);
	}

	private static function unslash_string( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';

		if ( function_exists( 'wp_unslash' ) ) {
			$value = (string) wp_unslash( $value );
		}

		return $value;
	}
}
