<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\PolicyAssignment;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleRecordCodec, RuleTime};

/** Exact physical original plus independent maps; absence is a separate frame. */
final readonly class PolicyAssignmentState implements \JsonSerializable {
	private function __construct(
		private array $target, private int $physical_id, private int $row_revision, private ?string $row_generation,
		private string $bytes, private PolicyAssignmentMap $returns, private PolicyAssignmentMap $refunds, private ?RuleTime $row_time
	) {}

	public static function make_coordinate( int $site, string $scope_type, int $scope_id, int $parent_id = 0 ): array {
		if ( $site < 1 || ! in_array( $scope_type, [ 'global', 'product', 'variation' ], true )
			|| ( 'global' === $scope_type && ( 0 !== $scope_id || 0 !== $parent_id ) )
			|| ( 'product' === $scope_type && ( $scope_id < 1 || 0 !== $parent_id ) )
			|| ( 'variation' === $scope_type && ( $scope_id < 1 || $parent_id < 1 || $scope_id === $parent_id ) ) ) {
			self::invalid();
		}
		return [ 'site_id' => $site, 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'parent_id' => $parent_id ];
	}

	public static function absent( int $site, string $type, int $id, int $parent = 0 ): self {
		return new self( self::make_coordinate( $site, $type, $id, $parent ), 0, 0, null, '',
			PolicyAssignmentMap::empty( $site, 'return_policy_v1' ), PolicyAssignmentMap::empty( $site, 'refund_policy_v1' ), null );
	}

	public static function from_physical( int $site, string $type, int $id, int $parent, int $row_id, string $bytes ): self {
		$coordinate = self::make_coordinate( $site, $type, $id, $parent );
		if ( $row_id < 1 ) { self::invalid(); }
		$data = get_object_vars( OperationJson::decode( $bytes ) );
		OperationJson::exact_fields( (object) $data, [ 'format', 'site_id', 'scope_type', 'scope_id', 'parent_id', 'generation', 'revision', 'return_map', 'refund_map', 'actor_user_id', 'reason', 'changed_at' ] );
		if ( 1 !== $data['format'] ) { self::invalid(); }
		foreach ( $coordinate as $key => $value ) { if ( $data[$key] !== $value ) { self::invalid(); } }
		foreach ( [ 'generation', 'return_map', 'refund_map', 'reason', 'changed_at' ] as $key ) { if ( ! is_string( $data[$key] ) ) { self::invalid(); } }
		if ( ! is_int( $data['revision'] ) || ! is_int( $data['actor_user_id'] ) ) { self::invalid(); }
		$returns = PolicyAssignmentMap::from_json( $site, 'return_policy_v1', $data['return_map'] );
		$refunds = PolicyAssignmentMap::from_json( $site, 'refund_policy_v1', $data['refund_map'] );
		$canonical = self::record_json( $coordinate, $data['generation'], $data['revision'], $returns, $refunds, $data['actor_user_id'], $data['reason'], $data['changed_at'] );
		if ( $bytes !== $canonical ) { self::invalid(); }
		return new self( $coordinate, $row_id, $data['revision'], $data['generation'], $bytes, $returns, $refunds, RuleTime::parse( $data['changed_at'] ) );
	}

	public static function record_json( array $coordinate, string $generation, int $revision, PolicyAssignmentMap $return, PolicyAssignmentMap $refund, int $actor, string $reason, string $changed_at ): string {
		OperationJson::exact_fields( (object) $coordinate, [ 'site_id', 'scope_type', 'scope_id', 'parent_id' ] );
		if ( ! is_int( $coordinate['site_id'] ) || ! is_string( $coordinate['scope_type'] ) || ! is_int( $coordinate['scope_id'] ) || ! is_int( $coordinate['parent_id'] ) ) { self::invalid(); }
		$coordinate = self::make_coordinate( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'] );
		RuleRecordCodec::uuid( $generation ); RuleTime::parse( $changed_at );
		if ( $revision < 1 || $actor < 1 || '' === trim( $reason ) || strlen( $reason ) > 1000 || 1 !== preg_match( '//u', $reason )
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $reason )
			|| $return->site_id() !== $coordinate['site_id'] || $refund->site_id() !== $coordinate['site_id']
			|| 'return_policy_v1' !== $return->family() || 'refund_policy_v1' !== $refund->family() ) { self::invalid(); }
		$bytes = OperationJson::encode( (object) [
			'format' => 1, ...$coordinate, 'generation' => $generation, 'revision' => $revision,
			'return_map' => $return->json(), 'refund_map' => $refund->json(), 'actor_user_id' => $actor,
			'reason' => $reason, 'changed_at' => $changed_at,
		] );
		OperationJson::encode( (object) [ 'state' => $bytes ] );
		return $bytes;
	}

	public function coordinate(): array { return $this->target; }
	public function row_id(): int { return $this->physical_id; }
	public function revision(): int { return $this->row_revision; }
	public function generation(): ?string { return $this->row_generation; }
	public function changed_at(): ?RuleTime { return $this->row_time; }
	public function original_bytes(): string { return $this->bytes; }
	public function map( string $family ): PolicyAssignmentMap {
		return match ( $family ) {
			'return_policy_v1' => $this->returns, 'refund_policy_v1' => $this->refunds,
			default => throw new \InvalidArgumentException( 'Unsupported policy family.' ),
		};
	}
	public function digest(): string {
		return hash( 'sha256', 'cetech-policy-assignment-state-v1:' . json_encode( [
			$this->target, $this->physical_id, $this->row_revision, $this->row_generation, hash( 'sha256', $this->bytes ),
			$this->returns->digest(), $this->refunds->digest(),
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Policy assignment state requires an explicit private projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Policy assignment state requires an explicit private projection.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Policy assignment state requires validated physical bytes.' ); }
	public function __debugInfo(): array { return [ 'private_policy_assignment_state' => 'redacted' ]; }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid policy assignment state.' ); }
}
