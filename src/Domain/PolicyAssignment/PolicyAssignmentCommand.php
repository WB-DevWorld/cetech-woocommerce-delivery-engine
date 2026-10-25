<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\PolicyAssignment;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationJson};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleRecordCodec, RuleTime};

/** Exact original two-slot operation; it neither parses principals nor grants authority. */
final readonly class PolicyAssignmentCommand implements OperationCommand, \JsonSerializable {
	public const OPERATION = 'policy.assignment.set';
	public const VERSION = 1;
	public const AUTHORITY = 'policy_assignment.configuration';

	private function __construct(
		public OperationIdentity $identity, private array $data, private PolicyAssignmentState $original,
		private CanonicalIntent $canonical
	) {}

	public static function from_array( OperationIdentity $identity, array $data ): self {
		OperationJson::exact_fields( (object) $data, [ 'format', 'coordinate', 'offer_id', 'opened', 'instructions', 'reviewed', 'actor_user_id', 'reason', 'generation' ] );
		$coordinate = self::object( $data['coordinate'] );
		OperationJson::exact_fields( (object) $coordinate, [ 'site_id', 'scope_type', 'scope_id', 'parent_id' ] );
		foreach ( [ 'site_id', 'scope_id', 'parent_id' ] as $key ) { if ( ! is_int( $coordinate[$key] ) ) { self::invalid(); } }
		if ( ! is_string( $coordinate['scope_type'] ) || ! is_int( $data['offer_id'] ) || $data['offer_id'] < 0
			|| 1 !== $data['format'] || ! is_int( $data['actor_user_id'] ) || $data['actor_user_id'] < 1
			|| ! is_string( $data['generation'] ) || ! is_string( $data['reason'] ) || '' === trim( $data['reason'] )
			|| strlen( $data['reason'] ) > 1000 || 1 !== preg_match( '//u', $data['reason'] )
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $data['reason'] ) ) { self::invalid(); }
		$coordinate = PolicyAssignmentState::make_coordinate( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'] );
		if ( self::OPERATION !== $identity->operation || self::VERSION !== $identity->operation_version || $identity->site_id !== $coordinate['site_id']
			|| $identity->target_key !== self::make_target( $coordinate['scope_type'], $coordinate['scope_id'], $data['offer_id'] ) ) { self::invalid(); }
		RuleRecordCodec::uuid( $data['generation'] );
		$opened = self::object( $data['opened'] );
		OperationJson::exact_fields( (object) $opened, [ 'row_id', 'bytes' ] );
		if ( ! is_int( $opened['row_id'] ) || $opened['row_id'] < 0 || ! is_string( $opened['bytes'] )
			|| ( 0 === $opened['row_id'] ) !== ( '' === $opened['bytes'] ) ) { self::invalid(); }
		$original = 0 === $opened['row_id']
			? PolicyAssignmentState::absent( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'] )
			: PolicyAssignmentState::from_physical( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'], $opened['row_id'], $opened['bytes'] );
		if ( null !== $original->generation() && $data['generation'] !== $original->generation() ) { self::invalid(); }
		$instructions = self::object( $data['instructions'] ); $reviewed = self::object( $data['reviewed'] );
		OperationJson::exact_fields( (object) $instructions, PolicyAssignmentMap::FAMILIES );
		OperationJson::exact_fields( (object) $reviewed, PolicyAssignmentMap::FAMILIES );
		$checked_instructions = $checked_reviewed = [];
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			$slot = self::object( $instructions[$family] );
			$slot = PolicyAssignmentMap::empty( $coordinate['site_id'], $family )->with_instruction( 0, $slot )->facts()['default'];
			$checked_instructions[$family] = $slot;
			if ( 'assigned' !== $slot['mode'] ) {
				if ( null !== $reviewed[$family] ) { self::invalid(); }
				$checked_reviewed[$family] = null;
				continue;
			}
			$witness = self::object( $reviewed[$family] );
			OperationJson::exact_fields( (object) $witness, [ 'policy_uuid', 'version_uuid', 'logical_revision', 'version_revision', 'content_hash', 'guard_revision' ] );
			if ( RuleRecordCodec::uuid( $witness['policy_uuid'] ) !== $slot['policy_uuid'] ) { self::invalid(); }
			RuleRecordCodec::uuid( $witness['version_uuid'] ); RuleRecordCodec::digest( $witness['content_hash'] );
			foreach ( [ 'logical_revision', 'version_revision', 'guard_revision' ] as $key ) { if ( ! is_int( $witness[$key] ) || $witness[$key] < 1 ) { self::invalid(); } }
			$checked_reviewed[$family] = [
				'policy_uuid' => $witness['policy_uuid'], 'version_uuid' => $witness['version_uuid'],
				'logical_revision' => $witness['logical_revision'], 'version_revision' => $witness['version_revision'],
				'content_hash' => $witness['content_hash'], 'guard_revision' => $witness['guard_revision'],
			];
		}
		$payload = [
			'format' => 1, 'coordinate' => $coordinate, 'offer_id' => $data['offer_id'],
			'opened' => [ 'row_id' => $opened['row_id'], 'bytes' => $opened['bytes'] ],
			'instructions' => $checked_instructions, 'reviewed' => $checked_reviewed,
			'actor_user_id' => $data['actor_user_id'], 'reason' => $data['reason'], 'generation' => $data['generation'],
		];
		// Validate the complete escaped original, not only the independently bounded maps.
		OperationJson::encode( (object) $payload );
		$canonical = CanonicalIntent::from_command( $identity, [ 'coordinate' => $coordinate, 'offer_id' => $data['offer_id'] ],
			[ 'opened' => $payload['opened'] ], $payload );
		return new self( $identity, $payload, $original, $canonical );
	}

	public static function make_target( string $type, int $id, int $offer ): string {
		if ( ! in_array( $type, [ 'global', 'product', 'variation' ], true ) || $offer < 0
			|| ( 'global' === $type && 0 !== $id ) || ( 'global' !== $type && $id < 1 ) ) { self::invalid(); }
		return 'policy-assignment:' . $type . ':' . $id . ':offer:' . $offer;
	}
	public function opened(): PolicyAssignmentState { return $this->original; }
	public function coordinate(): array { return $this->data['coordinate']; }
	public function offer_id(): int { return $this->data['offer_id']; }
	public function instructions(): array { return $this->data['instructions']; }
	public function reviewed(): array { return $this->data['reviewed']; }
	public function actor_user_id(): int { return $this->data['actor_user_id']; }
	public function reason(): string { return $this->data['reason']; }
	public function generation(): string { return $this->data['generation']; }
	public function intent(): CanonicalIntent { return $this->canonical; }
	/** Explicit private recovery export of the exact validated original. */
	public function payload(): array { return $this->data; }

	public function no_change(): bool {
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			$before = $this->original->map( $family );
			if ( $before->with_instruction( $this->offer_id(), $this->data['instructions'][$family] )->json() !== $before->json() ) { return false; }
		}
		return true;
	}

	/** Pure proposal only. The store supplies the acknowledged physical identity. */
	public function desired_bytes( string $SQLtime ): string {
		$at = RuleTime::parse( $SQLtime );
		if ( null !== $this->original->changed_at() && $at->compare( $this->original->changed_at() ) < 0 ) { self::invalid(); }
		if ( $this->no_change() ) { return $this->original->original_bytes(); }
		if ( PHP_INT_MAX === $this->original->revision() ) { self::invalid(); }
		return PolicyAssignmentState::record_json( $this->coordinate(), $this->generation(), $this->original->revision() + 1,
			$this->original->map( 'return_policy_v1' )->with_instruction( $this->offer_id(), $this->data['instructions']['return_policy_v1'] ),
			$this->original->map( 'refund_policy_v1' )->with_instruction( $this->offer_id(), $this->data['instructions']['refund_policy_v1'] ),
			$this->actor_user_id(), $this->reason(), $SQLtime );
	}

	public function desired( string $SQLtime, int $row_id ): PolicyAssignmentState {
		$bytes = $this->desired_bytes( $SQLtime );
		if ( $this->no_change() ) {
			if ( $row_id !== $this->original->row_id() ) { self::invalid(); }
			return $this->original;
		}
		if ( $row_id < 1 || ( 0 !== $this->original->row_id() && $row_id !== $this->original->row_id() ) ) { self::invalid(); }
		$c = $this->coordinate();
		return PolicyAssignmentState::from_physical( $c['site_id'], $c['scope_type'], $c['scope_id'], $c['parent_id'], $row_id, $bytes );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Policy assignment commands require an explicit private projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Policy assignment commands require an explicit private projection.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Policy assignment commands require a validated original.' ); }
	public function __debugInfo(): array { return [ 'private_policy_assignment_command' => 'redacted' ]; }
	private static function object( mixed $value ): array {
		if ( $value instanceof \stdClass && \stdClass::class === get_class( $value ) ) { return get_object_vars( $value ); }
		if ( ! is_array( $value ) ) { self::invalid(); }
		return $value;
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid policy assignment command.' ); }
}
