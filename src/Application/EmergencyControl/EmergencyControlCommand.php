<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** The original administrative envelope; retry metadata never changes intent. */
final readonly class EmergencyControlCommand implements OperationCommand, \JsonSerializable {
	public const OPERATION = 'checkout.emergency.transition';
	public const AUTHORITY = 'checkout.emergency.admin.v1';
	public const TARGET = 'checkout:global';
	private function __construct( public OperationIdentity $identity, public int $actor_user_id, public EmergencyControlState $opened, public string $desired_state, public string $reason_code ) {}
	public static function identity( int $site, int $actor, string $token ): OperationIdentity {
		if ( $actor < 1 ) { throw new \InvalidArgumentException( 'Invalid emergency-control actor.' ); }
		return new OperationIdentity( $site, self::AUTHORITY, 'wp-user:' . $actor, self::OPERATION, 1, self::TARGET, $token );
	}
	public static function actor( OperationIdentity $identity ): int {
		if ( self::AUTHORITY !== $identity->authority || self::OPERATION !== $identity->operation || 1 !== $identity->operation_version || self::TARGET !== $identity->target_key || 1 !== preg_match( '/\Awp-user:([1-9][0-9]*)\z/D', $identity->principal, $match ) || strlen( $match[1] ) > strlen( (string) PHP_INT_MAX ) || strlen( $match[1] ) === strlen( (string) PHP_INT_MAX ) && strcmp( $match[1], (string) PHP_INT_MAX ) > 0 ) { throw new \InvalidArgumentException( 'Invalid emergency-control identity.' ); }
		return (int) $match[1];
	}
	public static function payload( EmergencyControlState $opened, string $desired, string $reason ): array { return [ 'desired_state' => $desired, 'reason_code' => $reason, ...$opened->opened_preconditions() ]; }
	public static function from_payload( OperationIdentity $identity, mixed $payload ): self {
		$actor = self::actor( $identity );
		if ( ! is_array( $payload ) || 5 !== count( $payload ) || [] !== array_diff( [ 'desired_state', 'reason_code', 'opened_row_id', 'opened_revision', 'opened_bytes' ], array_keys( $payload ) ) || ! is_string( $payload['desired_state'] ) || ! is_string( $payload['reason_code'] ) || ! EmergencyControlState::valid_reason( $payload['desired_state'], $payload['reason_code'] ) || ! is_int( $payload['opened_row_id'] ) || $payload['opened_row_id'] < 0 || ! is_int( $payload['opened_revision'] ) || $payload['opened_revision'] < 1 || ! is_string( $payload['opened_bytes'] ) ) { throw new \InvalidArgumentException( 'Invalid emergency-control command.' ); }
		$opened = 0 === $payload['opened_row_id'] ? EmergencyControlState::absent( $identity->site_id ) : EmergencyControlState::from_physical( $identity->site_id, $payload['opened_row_id'], $payload['opened_bytes'] );
		if ( $opened->revision !== $payload['opened_revision'] || $opened->original_bytes() !== $payload['opened_bytes'] ) { throw new \InvalidArgumentException( 'Invalid emergency-control precondition.' ); }
		return new self( $identity, $actor, $opened, $payload['desired_state'], $payload['reason_code'] );
	}
	public function intent(): CanonicalIntent { return CanonicalIntent::from_command( $this->identity, [ 'site_id' => $this->identity->site_id, 'row_id' => $this->opened->row_id ], $this->opened->opened_preconditions(), [ 'desired_state' => $this->desired_state, 'reason_code' => $this->reason_code ] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Emergency-control command envelopes are private.' ); }
}
