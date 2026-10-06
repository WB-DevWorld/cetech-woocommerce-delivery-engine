<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Immutable family/scope identity with mutable version pointers and revision. */
final readonly class LogicalRule implements \JsonSerializable {
	private const FIELDS = [ 'id', 'site_id', 'family_guard_id', 'logical_uuid', 'scope_format', 'scope_json', 'scope_hash', 'revision', 'last_version_sequence', 'current_published_version_id', 'draft_version_id', 'scheduled_version_id', 'created_at', 'updated_at' ];
	private function __construct( public int $id, public int $site_id, public int $family_guard_id, public string $logical_uuid, public int $scope_format, public array $scope, public string $scope_json, public string $scope_hash, public int $revision, public int $last_version_sequence, public ?int $current_published_version_id, public ?int $draft_version_id, public ?int $scheduled_version_id, public RuleTime $created_at, public RuleTime $updated_at, public string $family_code, public string $policy_hash ) {}

	public static function from_row( array $row, RuleFamilyGuard $guard, RuleFamilyProfile $profile ): self {
		RuleRecordCodec::exact( $row, self::FIELDS );
		$id = RuleRecordCodec::integer( $row['id'] );
		$site = RuleRecordCodec::integer( $row['site_id'] );
		$family = RuleRecordCodec::integer( $row['family_guard_id'] );
		$uuid = RuleRecordCodec::uuid( $row['logical_uuid'] );
		$format = RuleRecordCodec::integer( $row['scope_format'] );
		$revision = RuleRecordCodec::integer( $row['revision'] );
		$sequence = RuleRecordCodec::integer( $row['last_version_sequence'], 0 );
		$hash = RuleRecordCodec::digest( $row['scope_hash'] );
		if ( ! is_string( $row['scope_json'] ) ) { throw new \InvalidArgumentException( 'Invalid rule scope JSON.' ); }
		$scope = $profile->scope_schema()->decode( $row['scope_json'], 4096 );
		$canonical = $profile->scope_schema()->encode( $scope, 4096 );
		$published = RuleRecordCodec::nullable_id( $row['current_published_version_id'] );
		$draft = RuleRecordCodec::nullable_id( $row['draft_version_id'] );
		$scheduled = RuleRecordCodec::nullable_id( $row['scheduled_version_id'] );
		$created = RuleRecordCodec::time( $row['created_at'] );
		$updated = RuleRecordCodec::time( $row['updated_at'] );
		if ( $site !== $guard->site_id || $family !== $guard->id || $guard->family_code !== $profile->family() || ! hash_equals( $guard->policy_hash, $profile->policy_hash() ) || $format !== $profile->version() || $canonical !== $row['scope_json'] || ! hash_equals( RuleContent::scope_hash( $profile, $scope ), $hash ) || ( null !== $draft && null !== $scheduled ) || $updated->compare( $created ) < 0 || $created->compare( $guard->created_at ) < 0 || $updated->compare( $guard->updated_at ) > 0 ) {
			throw new \InvalidArgumentException( 'Invalid logical rule identity or scope.' );
		}
		return new self( $id, $site, $family, $uuid, $format, $scope, $canonical, $hash, $revision, $sequence, $published, $draft, $scheduled, $created, $updated, $guard->family_code, $guard->policy_hash );
	}

	public function scope(): array { return $this->scope; }
	public function row(): array {
		return [ 'id' => $this->id, 'site_id' => $this->site_id, 'family_guard_id' => $this->family_guard_id, 'logical_uuid' => $this->logical_uuid, 'scope_format' => $this->scope_format, 'scope_json' => $this->scope_json, 'scope_hash' => $this->scope_hash, 'revision' => $this->revision, 'last_version_sequence' => $this->last_version_sequence, 'current_published_version_id' => $this->current_published_version_id, 'draft_version_id' => $this->draft_version_id, 'scheduled_version_id' => $this->scheduled_version_id, 'created_at' => $this->created_at->sql(), 'updated_at' => $this->updated_at->sql() ];
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Logical rule requires an explicit authorized projection.' ); }
}
