<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\PolicyAssignment;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentMap, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyContent, RefundPolicyFamily};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent, ReturnPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleFamilyProfile, RuleSnapshot, RuleStartMode, RuleTime};

/** Pure codec-validated fixture data; no SQL, WordPress, native grant or admission. */
final class PolicyAssignmentDomainFixture {
	public const CREATED = '2026-10-24 10:00:00.000000';
	public const START = '2026-10-24 11:00:00.000000';
	public const NOW = '2026-10-24 12:00:00.000000';
	public static function uuid( int $id ): string { return sprintf( '00000000-0000-4000-8000-%012x', $id ); }
	public static function slot( int $id = 101 ): array { return [ 'mode' => 'assigned', 'policy_uuid' => self::uuid( $id ) ]; }
	public static function state( string $type = 'product', int $id = 33, int $parent = 0, int $row = 50, int $revision = 1, ?PolicyAssignmentMap $returns = null, ?PolicyAssignmentMap $refunds = null ): PolicyAssignmentState {
		$c = PolicyAssignmentState::make_coordinate( 1, $type, $id, $parent );
		$bytes = PolicyAssignmentState::record_json( $c, self::uuid( 900 ), $revision,
			$returns ?? PolicyAssignmentMap::empty( 1, 'return_policy_v1' ),
			$refunds ?? PolicyAssignmentMap::empty( 1, 'refund_policy_v1' ), 9, 'Fixture original reason', self::NOW );
		return PolicyAssignmentState::from_physical( 1, $type, $id, $parent, $row, $bytes );
	}
	public static function payload( ?PolicyAssignmentState $opened = null, int $offer = 0 ): array {
		$opened ??= PolicyAssignmentState::absent( 1, 'product', 33 );
		return [ 'format' => 1, 'coordinate' => $opened->coordinate(), 'offer_id' => $offer,
			'opened' => [ 'row_id' => $opened->row_id(), 'bytes' => $opened->original_bytes() ],
			'instructions' => [ 'return_policy_v1' => [ 'mode' => 'disabled' ], 'refund_policy_v1' => [ 'mode' => 'inherit' ] ],
			'reviewed' => [ 'return_policy_v1' => null, 'refund_policy_v1' => null ],
			'actor_user_id' => 9, 'reason' => 'Fixture assignment reason', 'generation' => $opened->generation() ?? self::uuid( 901 ),
		];
	}
	public static function identity( array $data, string $token = 'original' ): OperationIdentity {
		return new OperationIdentity( $data['coordinate']['site_id'], PolicyAssignmentCommand::AUTHORITY, 'opaque:staff', PolicyAssignmentCommand::OPERATION, 1,
			PolicyAssignmentCommand::make_target( $data['coordinate']['scope_type'], $data['coordinate']['scope_id'], $data['offer_id'] ), $token );
	}
	public static function command( array $data, string $token = 'original' ): PolicyAssignmentCommand {
		return PolicyAssignmentCommand::from_array( self::identity( $data, $token ), $data );
	}
	public static function witness( int $logical = 101, int $version = 1 ): array {
		return [ 'policy_uuid' => self::uuid( $logical ), 'version_uuid' => self::uuid( $version ),
			'logical_revision' => 10, 'version_revision' => 2, 'content_hash' => str_repeat( 'a', 64 ), 'guard_revision' => 10 ];
	}
	public static function profile( string $family ): RuleFamilyProfile {
		$deny = static fn(): bool => false;
		return match ( $family ) {
			'return_policy_v1' => new ReturnPolicyFamily( $deny, $deny, $deny ),
			'refund_policy_v1' => new RefundPolicyFamily( $deny, $deny, $deny ),
		};
	}
	/** Closed row shapes validated by the existing C01 factories. */
	public static function rows( string $family = 'return_policy_v1', string $state = 'published', array $version_changes = [], array $logical_changes = [], array $content_changes = [] ): array {
		$profile = self::profile( $family ); $scope = [ 'policy_uuid' => self::uuid( 101 ) ];
		$content = [ 'name' => 'PRIVATE supplied policy', 'customer_title' => 'Supplied title', 'summary' => "Supplied café.\nSecond line.", 'full_text' => "Literal <em>text</em>.\r\n\tEnd.", 'exceptions' => '' ];
		$content = array_replace( $content, $content_changes );
		$payload = 'return_policy_v1' === $family ? ReturnPolicyContent::from_array( $content )->to_rule_payload() : RefundPolicyContent::from_array( $content )->to_rule_payload();
		$logical = array_replace( [ 'id' => 1, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => self::uuid( 101 ),
			'scope_format' => 1, 'scope_json' => $profile->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $profile, $scope ),
			'revision' => 10, 'last_version_sequence' => 1, 'current_published_version_id' => 'published' === $state ? 1 : null,
			'draft_version_id' => 'draft' === $state ? 1 : null, 'scheduled_version_id' => 'scheduled' === $state ? 1 : null,
			'created_at' => self::CREATED, 'updated_at' => self::NOW ], $logical_changes );
		$guard = [ 'id' => 1, 'site_id' => $logical['site_id'], 'family_code' => $family, 'family_format' => 1, 'policy_hash' => $profile->policy_hash(), 'revision' => 10, 'created_at' => self::CREATED, 'updated_at' => self::NOW ];
		$version = array_replace( [
			'id' => 1, 'site_id' => $logical['site_id'], 'logical_rule_id' => 1, 'version_uuid' => self::uuid( 1 ), 'version_sequence' => 1,
			'row_revision' => 'draft' === $state ? 1 : ( 'retired' === $state ? 3 : 2 ), 'state' => $state, 'payload_format' => 1,
			'payload_json' => $profile->payload_schema()->encode( $payload ), 'content_hash' => '', 'priority' => 0,
			'start_mode' => 'scheduled' === $state ? 'at' : 'immediate', 'effective_from' => 'draft' === $state ? null : self::START,
			'effective_until' => null, 'author_user_id' => 9, 'change_reason' => 'PRIVATE supplied source reason',
			'supersedes_version_id' => null, 'scheduled_revision' => 'scheduled' === $state ? 2 : null,
			'scheduled_logical_revision' => 'scheduled' === $state ? 2 : null, 'scheduled_predecessor_row_revision' => null,
			'sealed_at' => 'draft' === $state ? null : ( 'scheduled' === $state ? '2026-10-24 10:30:00.000000' : self::START ),
			'scheduled_at' => 'scheduled' === $state ? '2026-10-24 10:30:00.000000' : null,
			'published_at' => in_array( $state, [ 'published', 'retired' ], true ) ? self::START : null,
			'retired_at' => 'retired' === $state ? '2026-10-24 11:30:00.000000' : null,
			'created_at' => self::CREATED, 'updated_at' => self::NOW,
		], $version_changes );
		$version['content_hash'] = RuleContent::hash( $profile, $profile->payload_schema()->decode( $version['payload_json'] ), RuleStartMode::from( $version['start_mode'] ),
			null === $version['effective_from'] ? null : RuleTime::parse( $version['effective_from'] ), null === $version['effective_until'] ? null : RuleTime::parse( $version['effective_until'] ), $version['priority'], $version['supersedes_version_id'] );
		return compact( 'profile', 'guard', 'logical', 'version' );
	}
	public static function snapshot( string $family = 'return_policy_v1', string $state = 'published', array $version_changes = [], array $logical_changes = [], array $content_changes = [], bool $complete = true ): RuleSnapshot {
		$r = self::rows( $family, $state, $version_changes, $logical_changes, $content_changes );
		return RuleSnapshot::from_rows( $r['profile'], $r['guard'], [ $r['logical'] ], [ $r['version'] ], $complete );
	}
}
