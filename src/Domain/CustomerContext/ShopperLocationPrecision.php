<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Server-authoritative answer: is this shopper destination precise enough
 * for a FINAL Delivery quote?
 */
final class ShopperLocationPrecision {

	public const LEVEL_REGION = 'region';

	public const LEVEL_LOCALITY = 'locality';

	public const LEVEL_POSTCODE = 'postcode';

	public const REASON_LEGACY_NO_PACK = 'legacy_no_usable_pack';

	public const REASON_SUFFICIENT = 'sufficient';

	public const REASON_NARROWER_COVERAGE = 'narrower_active_coverage';

	public const REASON_SELECTED_DESCENDANT = 'selected_descendants_nested_member';

	public const REASON_ENTIRE_EXCEPT = 'entire_except_ambiguous';

	public const REASON_CANONICAL_UNRESOLVED = 'canonical_unresolved';

	public const MESSAGE_KEY_REGION = 'need_precision.region';

	public const MESSAGE_KEY_LOCALITY = 'need_precision.locality';

	public function __construct(
		public readonly bool $sufficient,
		public readonly ?string $required_level,
		public readonly string $reason,
		public readonly string $message_key,
		public readonly string $public_message
	) {
	}

	public static function sufficient( string $reason = self::REASON_SUFFICIENT ): self {
		return new self( true, null, $reason, '', '' );
	}

	public static function insufficient( string $level, string $reason, string $message_key, string $public_message ): self {
		return new self( false, $level, $reason, $message_key, $public_message );
	}

	/**
	 * @return array{sufficient: bool, required_level: string|null, reason: string, message_key: string}
	 */
	public function toArray(): array {
		return [
			'sufficient'     => $this->sufficient,
			'required_level' => $this->required_level,
			'reason'         => $this->reason,
			'message_key'    => $this->message_key,
		];
	}
}
