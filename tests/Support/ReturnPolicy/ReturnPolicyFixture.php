<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\ReturnPolicy;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\{LogicalRule, RuleCandidate, RuleContent, RuleFamilyGuard, RuleFamilyProfile, RuleMatch, RuleSchema, RuleStartMode, RuleTime, RuleVersion};

/** Codec-validated C01 read fixtures; neither a registered family nor accepted write history. */
final class ReturnPolicyFixture {
	public const CREATED = '2026-10-23 09:00:00.000000';
	public const START = '2026-10-23 10:00:00.000000';
	public const UPDATED = '2026-10-23 12:00:00.000000';

	public static function content( array $changes = [] ): ReturnPolicyContent {
		return ReturnPolicyContent::from_array( array_replace( [ 'name' => 'Fixture return policy', 'customer_title' => 'Fixture returns', 'summary' => 'Test-only return information.', 'full_text' => 'Fixture return terms for source-level tests.', 'exceptions' => 'Fixture exception wording.' ], $changes ) );
	}

	/** A local declaration only: every authorization remains refused. */
	public static function profile( string $family = ReturnPolicyContent::FAMILY, int $format = ReturnPolicyContent::FORMAT ): RuleFamilyProfile {
		return new readonly class( $family, $format ) implements RuleFamilyProfile {
			public function __construct( private string $code, private int $format ) {}
			public function family(): string { return $this->code; }
			public function version(): int { return $this->format; }
			public function policy_hash(): string { return hash( 'sha256', 'test-only-return-policy:' . $this->code . ':' . $this->format . ':' . $this->payload_schema()->fingerprint() ); }
			public function scope_schema(): RuleSchema { return new RuleSchema( [ 'product_id' => 'positive_int' ] ); }
			public function subject_schema(): RuleSchema { return $this->scope_schema(); }
			public function payload_schema(): RuleSchema { return ReturnPolicyContent::payload_schema(); }
			public function authorize( OperationIdentity $identity, array $scope ): bool { return false; }
			public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool { return false; }
			public function match( array $scope, array $subject ): RuleMatch { return new RuleMatch( $this->scope_schema()->validate( $scope ) === $this->subject_schema()->validate( $subject ), 1 ); }
			public function overlaps( array $left_scope, array $right_scope ): ?bool { return $this->scope_schema()->validate( $left_scope ) === $this->scope_schema()->validate( $right_scope ); }
			public function precedence(): array { return [ [ 'field' => 'specificity', 'direction' => 'desc' ], [ 'field' => 'priority', 'direction' => 'asc' ] ]; }
			public function equal_rank_policy(): string { return 'refuse'; }
			public function unavailable_reason(): string { return 'rule_unavailable'; }
		};
	}

	/** @return array{guard:array,logical:array,version:array} Real row shapes for ordinary C01 validation. */
	public static function rows( string $state = 'published', array $content_changes = [], array $version_changes = [], array $logical_changes = [], ?RuleFamilyProfile $profile = null ): array {
		$profile ??= self::profile();
		$times = match ( $state ) {
			'draft' => [ 'start_mode' => 'immediate', 'effective_from' => null, 'sealed_at' => null, 'scheduled_at' => null, 'published_at' => null, 'retired_at' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null ],
			'scheduled' => [ 'start_mode' => 'at', 'effective_from' => self::START, 'sealed_at' => '2026-10-23 09:30:00.000000', 'scheduled_at' => '2026-10-23 09:30:00.000000', 'published_at' => null, 'retired_at' => null, 'scheduled_revision' => 2, 'scheduled_logical_revision' => 2 ],
			'published', 'retired' => [ 'start_mode' => 'immediate', 'effective_from' => self::START, 'sealed_at' => self::START, 'scheduled_at' => null, 'published_at' => self::START, 'retired_at' => 'retired' === $state ? '2026-10-23 11:00:00.000000' : null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null ],
			default => throw new \InvalidArgumentException( 'Unsupported return policy fixture state.' ),
		};
		$scope = [ 'product_id' => 33 ];
		$logical = array_replace( [ 'id' => 1, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => self::uuid( 101 ), 'scope_format' => $profile->version(), 'scope_json' => $profile->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $profile, $scope ), 'revision' => 10, 'last_version_sequence' => 1, 'current_published_version_id' => 'published' === $state ? 1 : null, 'draft_version_id' => 'draft' === $state ? 1 : null, 'scheduled_version_id' => 'scheduled' === $state ? 1 : null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ], $logical_changes );
		$guard = [ 'id' => $logical['family_guard_id'], 'site_id' => $logical['site_id'], 'family_code' => $profile->family(), 'family_format' => $profile->version(), 'policy_hash' => $profile->policy_hash(), 'revision' => 10, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ];
		$version = array_replace( [ 'id' => 1, 'site_id' => $logical['site_id'], 'logical_rule_id' => $logical['id'], 'version_uuid' => self::uuid( 1 ), 'version_sequence' => 1, 'row_revision' => 'draft' === $state ? 1 : ( 'retired' === $state ? 3 : 2 ), 'state' => $state, 'payload_format' => $profile->version(), 'payload_json' => $profile->payload_schema()->encode( self::content( $content_changes )->to_rule_payload() ), 'content_hash' => '', 'priority' => 100, 'effective_until' => null, 'author_user_id' => 9, 'change_reason' => 'Fixture return policy', 'supersedes_version_id' => null, 'scheduled_predecessor_row_revision' => null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ], $times, $version_changes );
		$version['content_hash'] = RuleContent::hash( $profile, $profile->payload_schema()->decode( $version['payload_json'] ), RuleStartMode::from( $version['start_mode'] ), null === $version['effective_from'] ? null : RuleTime::parse( $version['effective_from'] ), null === $version['effective_until'] ? null : RuleTime::parse( $version['effective_until'] ), $version['priority'], $version['supersedes_version_id'] );
		return compact( 'guard', 'logical', 'version' );
	}

	public static function candidate( string $state = 'published', array $content_changes = [], array $version_changes = [], array $logical_changes = [], ?RuleFamilyProfile $profile = null ): RuleCandidate {
		$profile ??= self::profile();
		$rows = self::rows( $state, $content_changes, $version_changes, $logical_changes, $profile );
		$guard = RuleFamilyGuard::from_row( $rows['guard'], $profile );
		$logical = LogicalRule::from_row( $rows['logical'], $guard, $profile );
		return new RuleCandidate( $logical, RuleVersion::from_row( $rows['version'], $logical, $profile ) );
	}

	private static function uuid( int $id ): string { return '00000000-0000-4000-8000-' . str_pad( (string) $id, 12, '0', STR_PAD_LEFT ); }
}
