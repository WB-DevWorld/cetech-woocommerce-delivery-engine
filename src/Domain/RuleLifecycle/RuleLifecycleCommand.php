<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Original validated envelope. No accepted execution time can replace this intent. */
final readonly class RuleLifecycleCommand implements OperationCommand {
	public const OPERATIONS = [ 'rule.draft.create', 'rule.draft.edit', 'rule.schedule', 'rule.publish', 'rule.retire', 'rule.activate' ];
	private CanonicalIntent $canonical;
	public string $scope_hash;
	public string $content_hash;
	private function __construct(
		public OperationIdentity $identity,
		public RuleFamilyProfile $family,
		public string $logical_uuid,
		public string $version_uuid,
		public array $scope,
		public array $payload,
		public RuleStartMode $start_mode,
		public ?RuleTime $effective_from,
		public ?RuleTime $effective_until,
		public int $priority,
		public int $author_user_id,
		public string $change_reason,
		public ?string $predecessor_uuid,
		public array $preconditions
	) {
		$this->scope_hash = hash( 'sha256', 'cetech-rule-scope-v1:' . $family->scope_schema()->encode( $scope, 4096 ) );
		$this->content_hash = RuleContent::hash( $family, $payload, $start_mode, $effective_from, $effective_until, $priority, 0 === $preconditions['predecessor_id'] ? null : $preconditions['predecessor_id'] );
		if ( $identity->target_key !== self::make_target_key( $family->family(), $this->scope_hash, $logical_uuid ) ) {
			self::invalid();
		}
		$this->canonical = CanonicalIntent::from_command( $identity,
			[ 'logical_uuid' => $logical_uuid, 'version_uuid' => $version_uuid, 'scope_hash' => $this->scope_hash ],
			$preconditions,
			[ 'family' => $family->family(), 'policy_hash' => $family->policy_hash(), 'scope' => $scope, 'payload' => $payload,
				'start_mode' => $start_mode->value, 'effective_from' => $effective_from?->sql(), 'effective_until' => $effective_until?->sql(),
				'priority' => $priority, 'author_user_id' => $author_user_id, 'change_reason' => $change_reason,
				'predecessor_uuid' => $predecessor_uuid, 'content_hash' => $this->content_hash ] );
	}

	public static function from_payload( OperationIdentity $identity, mixed $input, RuleFamilyRegistry $families ): self {
		$keys = [ 'family', 'logical_uuid', 'version_uuid', 'scope', 'payload', 'start_mode', 'effective_from', 'effective_until', 'priority', 'author_user_id', 'change_reason', 'predecessor_uuid', 'preconditions' ];
		if ( ! is_array( $input ) || count( $input ) !== count( $keys ) || array_diff( $keys, array_keys( $input ) )
			|| ! in_array( $identity->operation, self::OPERATIONS, true ) || 1 !== $identity->operation_version
			|| ! is_string( $input['family'] ) || ! RequestContext::is_valid_identifier( $input['logical_uuid'] ) || ! RequestContext::is_valid_identifier( $input['version_uuid'] )
			|| ! is_array( $input['scope'] ) || ! is_array( $input['payload'] ) || ! is_string( $input['start_mode'] )
			|| ! is_int( $input['priority'] ) || $input['priority'] < -2147483648 || $input['priority'] > 2147483647
			|| ! is_int( $input['author_user_id'] ) || $input['author_user_id'] < 1
			|| ! is_string( $input['change_reason'] ) || '' === trim( $input['change_reason'] ) || strlen( $input['change_reason'] ) > 512
			|| 1 !== preg_match( '//u', $input['change_reason'] ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $input['change_reason'] ) ) { self::invalid(); }
		$family = $families->get( $input['family'] );
		$scope = $family->scope_schema()->decode( $family->scope_schema()->encode( $input['scope'], 4096 ), 4096 );
		$payload = $family->payload_schema()->decode( $family->payload_schema()->encode( $input['payload'], 16384 ), 16384 );
		$mode = RuleStartMode::tryFrom( $input['start_mode'] );
		if ( null === $mode ) { self::invalid(); }
		$from = self::time( $input['effective_from'] );
		$until = self::time( $input['effective_until'] );
		if ( ( RuleStartMode::Immediate === $mode && null !== $from && 'rule.retire' !== $identity->operation ) || ( RuleStartMode::At === $mode && null === $from )
			|| ( null !== $from && null !== $until && $until->compare( $from ) <= 0 ) ) { self::invalid(); }
		$p = $input['preconditions'];
		$pk = [ 'family_revision', 'logical_id', 'logical_revision', 'version_id', 'version_revision', 'predecessor_id', 'predecessor_revision' ];
		if ( ! is_array( $p ) || count( $p ) !== count( $pk ) || array_diff( $pk, array_keys( $p ) ) ) { self::invalid(); }
		$preconditions = [];
		foreach ( $pk as $key ) {
			if ( ! is_int( $p[$key] ) || $p[$key] < 0 || PHP_INT_MAX === $p[$key] ) { self::invalid(); }
			$preconditions[$key] = $p[$key];
		}
		foreach ( [ 'logical', 'version', 'predecessor' ] as $name ) {
			if ( ( 0 === $p[$name . '_id'] ) !== ( 0 === $p[$name . '_revision'] ) ) { self::invalid(); }
		}
		$predecessor = $input['predecessor_uuid'];
		if ( ( null === $predecessor ) !== ( 0 === $p['predecessor_id'] ) || ( null !== $predecessor && ! RequestContext::is_valid_identifier( $predecessor ) ) ) { self::invalid(); }
		if ( 'rule.activate' === $identity->operation ) {
			if ( 0 !== $p['family_revision'] || 0 === $p['logical_id'] || 0 === $p['version_id'] || RuleStartMode::At !== $mode ) { self::invalid(); }
		} elseif ( 'rule.draft.create' === $identity->operation ) {
			if ( 0 !== $p['version_id'] || ( 0 === $p['logical_id'] && null !== $predecessor ) ) { self::invalid(); }
		} elseif ( 0 === $p['logical_id'] || 0 === $p['version_id'] ) { self::invalid(); }
		return new self( $identity, $family, $input['logical_uuid'], $input['version_uuid'], $scope, $payload, $mode, $from, $until, $input['priority'], $input['author_user_id'], (string) $input['change_reason'], $predecessor, $preconditions );
	}

	public static function make_target_key( string $family, string $scope_hash, string $logical_uuid ): string {
		return 'family:' . $family . ':scope:' . $scope_hash . ':rule:' . $logical_uuid;
	}
	/** Recover sealed original preconditions, including after the current rows changed. */
	public static function activation_payload( RuleFamilyProfile $family, LogicalRule $logical, RuleVersion $version, ?RuleVersion $predecessor ): array {
		if ( null === $version->scheduled_revision || null === $version->scheduled_logical_revision || null === $version->scheduled_at || $version->logical_rule_id !== $logical->id || ( $predecessor?->id ) !== $version->supersedes_version_id || ( null !== $predecessor && $predecessor->logical_rule_id !== $logical->id ) ) { self::invalid(); }
		return [ 'family' => $family->family(), 'logical_uuid' => $logical->logical_uuid, 'version_uuid' => $version->version_uuid,
			'scope' => $logical->scope, 'payload' => $version->payload, 'start_mode' => $version->start_mode->value,
			'effective_from' => $version->effective_from?->sql(), 'effective_until' => $version->effective_until?->sql(),
			'priority' => $version->priority, 'author_user_id' => $version->author_user_id, 'change_reason' => $version->change_reason, 'predecessor_uuid' => $predecessor?->version_uuid,
			'preconditions' => [ 'family_revision' => 0, 'logical_id' => $logical->id, 'logical_revision' => $version->scheduled_logical_revision,
				'version_id' => $version->id, 'version_revision' => $version->scheduled_revision, 'predecessor_id' => $predecessor?->id ?? 0, 'predecessor_revision' => $version->scheduled_predecessor_row_revision ?? 0 ] ];
	}
	public static function activation_identity( int $site_id, RuleFamilyProfile $family, LogicalRule $logical, RuleVersion $version ): OperationIdentity {
		if ( null === $version->scheduled_revision || $site_id !== $logical->site_id || $version->site_id !== $site_id || $version->logical_rule_id !== $logical->id ) { self::invalid(); }
		return new OperationIdentity( $site_id, 'rule.lifecycle.activation.v1', 'rule-activation:' . $version->version_uuid, 'rule.activate', 1,
			self::make_target_key( $family->family(), $logical->scope_hash, $logical->logical_uuid ),
			hash( 'sha256', 'cetech-rule-activation-v1:' . $version->version_uuid . ':' . $version->scheduled_revision ) );
	}
	public function intent(): CanonicalIntent { return $this->canonical; }
	private static function time( mixed $value ): ?RuleTime { if ( null === $value ) { return null; } if ( ! is_string( $value ) ) { self::invalid(); } return RuleTime::parse( $value ); }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid rule lifecycle command.' ); }
}
