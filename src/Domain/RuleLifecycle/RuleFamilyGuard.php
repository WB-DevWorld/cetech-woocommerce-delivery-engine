<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Current locked family generation; identity and policy remain distinct. */
final readonly class RuleFamilyGuard implements \JsonSerializable {
	private const FIELDS = [ 'id', 'site_id', 'family_code', 'family_format', 'policy_hash', 'revision', 'created_at', 'updated_at' ];
	private function __construct( public int $id, public int $site_id, public string $family_code, public int $family_format, public string $policy_hash, public int $revision, public RuleTime $created_at, public RuleTime $updated_at ) {}

	public static function from_row( array $row, RuleFamilyProfile $profile ): self {
		RuleRecordCodec::exact( $row, self::FIELDS );
		RuleFamilyRegistry::declaration( $profile );
		$id = RuleRecordCodec::integer( $row['id'] );
		$site = RuleRecordCodec::integer( $row['site_id'] );
		$format = RuleRecordCodec::integer( $row['family_format'] );
		$revision = RuleRecordCodec::integer( $row['revision'] );
		$family = RuleRecordCodec::code( $row['family_code'] );
		$hash = RuleRecordCodec::digest( $row['policy_hash'] );
		$created = RuleRecordCodec::time( $row['created_at'] );
		$updated = RuleRecordCodec::time( $row['updated_at'] );
		if ( $family !== $profile->family() || $format !== $profile->version() || ! hash_equals( $profile->policy_hash(), $hash ) || $updated->compare( $created ) < 0 ) {
			throw new \InvalidArgumentException( 'Invalid rule family generation.' );
		}
		return new self( $id, $site, $family, $format, $hash, $revision, $created, $updated );
	}

	public function row(): array {
		return [ 'id' => $this->id, 'site_id' => $this->site_id, 'family_code' => $this->family_code, 'family_format' => $this->family_format, 'policy_hash' => $this->policy_hash, 'revision' => $this->revision, 'created_at' => $this->created_at->sql(), 'updated_at' => $this->updated_at->sql() ];
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule generation requires an explicit authorized projection.' ); }
}
