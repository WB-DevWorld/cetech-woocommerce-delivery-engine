<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

/** Deliberately excludes state rows, actor, request token, addresses and quotes. */
final readonly class EmergencyAdmissionResult implements \JsonSerializable {
	public function __construct(
		public bool $allowed,
		public string $code,
		public EmergencyOwnership $ownership,
		public ?int $revision = null
	) {
		if ( ! in_array( $code, [ 'allowed', 'unmanaged', 'already_paid', 'checkout_suspended', 'control_unavailable', 'stale_control_revision', 'checkout_revalidation_required', 'unsupported_activation_policy' ], true )
			|| ( null !== $revision && $revision < 1 )
			|| $allowed !== in_array( $code, [ 'allowed', 'unmanaged', 'already_paid' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid checkout admission result.' );
		}
	}

	public function jsonSerialize(): never {
		throw new \LogicException( 'Use the declared emergency-control projection.' );
	}
}
