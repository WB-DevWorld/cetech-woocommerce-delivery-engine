<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Validated detached version. No setter can change published or scheduled bytes. */
final readonly class RuleVersion implements \JsonSerializable {
	private const FIELDS = [ 'id', 'site_id', 'logical_rule_id', 'version_uuid', 'version_sequence', 'row_revision', 'state', 'payload_format', 'payload_json', 'content_hash', 'priority', 'start_mode', 'effective_from', 'effective_until', 'author_user_id', 'change_reason', 'supersedes_version_id', 'scheduled_revision', 'scheduled_logical_revision', 'scheduled_predecessor_row_revision', 'sealed_at', 'scheduled_at', 'published_at', 'retired_at', 'created_at', 'updated_at' ];
	private function __construct( public int $id, public int $site_id, public int $logical_rule_id, public string $version_uuid, public int $version_sequence, public int $row_revision, public RuleState $state, public int $payload_format, public array $payload, public string $payload_json, public string $content_hash, public int $priority, public RuleStartMode $start_mode, public ?RuleTime $effective_from, public ?RuleTime $effective_until, public int $author_user_id, public string $change_reason, public ?int $supersedes_version_id, public ?int $scheduled_revision, public ?int $scheduled_logical_revision, public ?int $scheduled_predecessor_row_revision, public ?RuleTime $sealed_at, public ?RuleTime $scheduled_at, public ?RuleTime $published_at, public ?RuleTime $retired_at, public RuleTime $created_at, public RuleTime $updated_at, public bool $hypothetical ) {}

	public static function from_row( array $row, LogicalRule $logical, RuleFamilyProfile $profile ): self {
		return self::hydrate( $row, $logical, $profile, false );
	}

	private static function hydrate( array $row, LogicalRule $logical, RuleFamilyProfile $profile, bool $hypothetical ): self {
		RuleRecordCodec::exact( $row, self::FIELDS );
		$id = RuleRecordCodec::integer( $row['id'] );
		$site = RuleRecordCodec::integer( $row['site_id'] );
		$logical_id = RuleRecordCodec::integer( $row['logical_rule_id'] );
		$uuid = RuleRecordCodec::uuid( $row['version_uuid'] );
		$sequence = RuleRecordCodec::integer( $row['version_sequence'] );
		$revision = RuleRecordCodec::integer( $row['row_revision'] );
		$format = RuleRecordCodec::integer( $row['payload_format'] );
		$author = RuleRecordCodec::integer( $row['author_user_id'] );
		$priority = RuleRecordCodec::priority( $row['priority'] );
		$hash = RuleRecordCodec::digest( $row['content_hash'] );
		$reason = RuleRecordCodec::reason( $row['change_reason'] );
		$predecessor = RuleRecordCodec::nullable_id( $row['supersedes_version_id'] );
		$scheduled_revision = RuleRecordCodec::nullable_id( $row['scheduled_revision'] );
		$scheduled_logical_revision = RuleRecordCodec::nullable_id( $row['scheduled_logical_revision'] );
		$scheduled_predecessor_revision = RuleRecordCodec::nullable_id( $row['scheduled_predecessor_row_revision'] );
		if ( ! is_string( $row['state'] ) || ! is_string( $row['start_mode'] ) || null === ( $state = RuleState::tryFrom( $row['state'] ) ) || null === ( $start = RuleStartMode::tryFrom( $row['start_mode'] ) ) || ! is_string( $row['payload_json'] ) ) {
			throw new \InvalidArgumentException( 'Invalid rule version state or content.' );
		}
		$payload = $profile->payload_schema()->decode( $row['payload_json'], 16384 );
		$canonical = $profile->payload_schema()->encode( $payload, 16384 );
		$from = RuleRecordCodec::nullable_time( $row['effective_from'] );
		$until = RuleRecordCodec::nullable_time( $row['effective_until'] );
		$sealed = RuleRecordCodec::nullable_time( $row['sealed_at'] );
		$scheduled = RuleRecordCodec::nullable_time( $row['scheduled_at'] );
		$published = RuleRecordCodec::nullable_time( $row['published_at'] );
		$retired = RuleRecordCodec::nullable_time( $row['retired_at'] );
		$created = RuleRecordCodec::time( $row['created_at'] );
		$updated = RuleRecordCodec::time( $row['updated_at'] );
		if ( $site !== $logical->site_id || $logical_id !== $logical->id || $logical->family_code !== $profile->family() || ! hash_equals( $logical->policy_hash, $profile->policy_hash() ) || $format !== $profile->version() || ( ! $hypothetical && $sequence > $logical->last_version_sequence ) || $id === $predecessor || $canonical !== $row['payload_json'] || ! hash_equals( RuleContent::hash( $profile, $payload, $start, $from, $until, $priority, $predecessor ), $hash ) || $updated->compare( $created ) < 0 || $created->compare( $logical->created_at ) < 0 || ( ! $hypothetical && $updated->compare( $logical->updated_at ) > 0 ) ) {
			throw new \InvalidArgumentException( 'Invalid rule version identity or digest.' );
		}
		if ( RuleStartMode::At === $start && null === $from || ( null !== $from && null !== $until && $until->compare( $from ) <= 0 ) ) {
			throw new \InvalidArgumentException( 'Invalid rule authored interval.' );
		}
		foreach ( [ $sealed, $scheduled, $published, $retired ] as $instant ) {
			if ( null !== $instant && ( $instant->compare( $created ) < 0 || ( ! $hypothetical && $instant->compare( $updated ) > 0 ) ) ) {
				throw new \InvalidArgumentException( 'Invalid rule lifecycle chronology.' );
			}
		}
		if ( null !== $scheduled && ( null === $sealed || RuleStartMode::At !== $start || null === $from || $from->compare( $scheduled ) <= 0 || $sealed->compare( $scheduled ) > 0 ) ) {
			throw new \InvalidArgumentException( 'Invalid scheduled rule facts.' );
		}
		if ( null === $scheduled ? ( null !== $scheduled_revision || null !== $scheduled_logical_revision || null !== $scheduled_predecessor_revision ) : ( null === $scheduled_revision || null === $scheduled_logical_revision || $scheduled_revision > $revision || $scheduled_logical_revision > $logical->revision || ( null === $predecessor ) !== ( null === $scheduled_predecessor_revision ) ) ) {
			throw new \InvalidArgumentException( 'Invalid original scheduling revision facts.' );
		}
		if ( null !== $published && ( null === $sealed || null === $from || $published->compare( $sealed ) < 0 || $published->compare( $from ) < 0 || ( null !== $until && $published->compare( $until ) >= 0 ) || ( RuleStartMode::Immediate === $start && ! $published->equals( $from ) ) ) ) {
			throw new \InvalidArgumentException( 'Invalid published rule facts.' );
		}
		if ( null !== $retired && ( ( null !== $sealed && $retired->compare( $sealed ) < 0 ) || ( null !== $published && $retired->compare( $published ) < 0 ) || ( null !== $scheduled && $retired->compare( $scheduled ) < 0 ) ) ) {
			throw new \InvalidArgumentException( 'Invalid retired rule facts.' );
		}
		$valid_state = match ( $state ) {
			RuleState::Draft => null === $sealed && null === $scheduled && null === $published && null === $retired && ( RuleStartMode::At === $start || null === $from ),
			RuleState::Scheduled => null !== $sealed && null !== $scheduled && null === $published && null === $retired,
			RuleState::Published => null !== $sealed && null !== $published && null === $retired,
			RuleState::Retired => null !== $retired && ( null !== $sealed || ( null === $scheduled && null === $published ) ),
		};
		if ( ! $valid_state ) { throw new \InvalidArgumentException( 'Contradictory rule lifecycle state.' ); }
		return new self( $id, $site, $logical_id, $uuid, $sequence, $revision, $state, $format, $payload, $canonical, $hash, $priority, $start, $from, $until, $author, $reason, $predecessor, $scheduled_revision, $scheduled_logical_revision, $scheduled_predecessor_revision, $sealed, $scheduled, $published, $retired, $created, $updated, $hypothetical );
	}

	/** Explicit preview overlay; never a persisted acceptance or a mutation helper. */
	public static function hypothetical( array $row, LogicalRule $logical, RuleFamilyProfile $profile ): self { return self::hydrate( $row, $logical, $profile, true ); }
	public function payload(): array { return $this->payload; }

	public function eligible_at( RuleTime $at ): bool {
		return RuleState::Published === $this->state && null !== $this->published_at && null !== $this->effective_from && $at->compare( $this->published_at ) >= 0 && $at->compare( $this->effective_from ) >= 0 && ( null === $this->effective_until || $at->compare( $this->effective_until ) < 0 ) && ( null === $this->retired_at || $at->compare( $this->retired_at ) < 0 );
	}

	/** Planned windows are used for conflicts, not proof of scheduled activation. */
	public function authored_interval( ?RuleTime $immediate_at = null ): ?RuleInterval {
		$from = $this->effective_from ?? $immediate_at;
		return null === $from ? null : new RuleInterval( $from, $this->effective_until );
	}

	public function row(): array {
		return [ 'id' => $this->id, 'site_id' => $this->site_id, 'logical_rule_id' => $this->logical_rule_id, 'version_uuid' => $this->version_uuid, 'version_sequence' => $this->version_sequence, 'row_revision' => $this->row_revision, 'state' => $this->state->value, 'payload_format' => $this->payload_format, 'payload_json' => $this->payload_json, 'content_hash' => $this->content_hash, 'priority' => $this->priority, 'start_mode' => $this->start_mode->value, 'effective_from' => $this->effective_from?->sql(), 'effective_until' => $this->effective_until?->sql(), 'author_user_id' => $this->author_user_id, 'change_reason' => $this->change_reason, 'supersedes_version_id' => $this->supersedes_version_id, 'scheduled_revision' => $this->scheduled_revision, 'scheduled_logical_revision' => $this->scheduled_logical_revision, 'scheduled_predecessor_row_revision' => $this->scheduled_predecessor_row_revision, 'sealed_at' => $this->sealed_at?->sql(), 'scheduled_at' => $this->scheduled_at?->sql(), 'published_at' => $this->published_at?->sql(), 'retired_at' => $this->retired_at?->sql(), 'created_at' => $this->created_at->sql(), 'updated_at' => $this->updated_at->sql() ];
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule version requires an explicit authorized projection.' ); }
}
