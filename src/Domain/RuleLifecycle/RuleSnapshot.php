<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Coherent captured generation. A partial capture can never select a winner. */
final readonly class RuleSnapshot implements \JsonSerializable {
	public RuleFamilyProfile $profile;
	public ?RuleFamilyGuard $guard;
	public array $logicals;
	public array $versions;
	public bool $complete;
	public bool $history_complete;

	public function __construct( RuleFamilyProfile $profile, ?RuleFamilyGuard $guard, array $logicals, array $versions, bool $complete = true, bool $history_complete = false ) {
		RuleFamilyRegistry::declaration( $profile );
		if ( ! array_is_list( $logicals ) || ! array_is_list( $versions ) || count( $logicals ) > 1000 || count( $versions ) > 2000 || ( null === $guard && ( [] !== $logicals || [] !== $versions ) ) || ( null !== $guard && ( $guard->family_code !== $profile->family() || ! hash_equals( $guard->policy_hash, $profile->policy_hash() ) ) ) ) {
			throw new \InvalidArgumentException( 'Invalid rule snapshot boundary.' );
		}
		$logical_map = $version_map = $uuids = $sequences = $maximum = [];
		foreach ( $logicals as $logical ) {
			if ( ! $logical instanceof LogicalRule || null === $guard || $logical->site_id !== $guard->site_id || $logical->family_guard_id !== $guard->id || $logical->family_code !== $guard->family_code || ! hash_equals( $logical->policy_hash, $guard->policy_hash ) || isset( $logical_map[ $logical->id ] ) || isset( $uuids[ 'logical:' . $logical->logical_uuid ] ) ) {
				throw new \InvalidArgumentException( 'Invalid logical rule snapshot identity.' );
			}
			$logical_map[ $logical->id ] = $logical;
			$uuids[ 'logical:' . $logical->logical_uuid ] = true;
		}
		$active_count = $retired_count = 0;
		foreach ( $versions as $version ) {
			if ( ! $version instanceof RuleVersion || $version->hypothetical || ! isset( $logical_map[ $version->logical_rule_id ] ) || $version->site_id !== $guard?->site_id || isset( $version_map[ $version->id ] ) || isset( $uuids[ 'version:' . $version->version_uuid ] ) || isset( $sequences[ $version->logical_rule_id . ':' . $version->version_sequence ] ) ) {
				throw new \InvalidArgumentException( 'Invalid rule version snapshot identity.' );
			}
			$version_map[ $version->id ] = $version;
			$uuids[ 'version:' . $version->version_uuid ] = true;
			$sequences[ $version->logical_rule_id . ':' . $version->version_sequence ] = true;
			$maximum[ $version->logical_rule_id ] = max( $maximum[ $version->logical_rule_id ] ?? 0, $version->version_sequence );
			if ( RuleState::Retired === $version->state ) { ++$retired_count; } else { ++$active_count; }
		}
		if ( $active_count > 1000 || $retired_count > 1000 ) { throw new \InvalidArgumentException( 'Rule snapshot exceeds its candidate or evidence budget.' ); }
		foreach ( $logical_map as $logical ) {
			if ( $complete && $history_complete && ( $maximum[ $logical->id ] ?? 0 ) !== $logical->last_version_sequence ) {
				throw new \InvalidArgumentException( 'Rule sequence head does not match stored history.' );
			}
			foreach ( [ 'draft_version_id' => RuleState::Draft, 'scheduled_version_id' => RuleState::Scheduled, 'current_published_version_id' => RuleState::Published ] as $field => $state ) {
				$id = $logical->{$field};
				if ( null === $id ) { continue; }
				if ( ! isset( $version_map[ $id ] ) ) {
					if ( $complete ) { throw new \InvalidArgumentException( 'Rule version pointer is missing.' ); }
					continue;
				}
				if ( $version_map[ $id ]->logical_rule_id !== $logical->id || $version_map[ $id ]->state !== $state ) {
					throw new \InvalidArgumentException( 'Rule version pointer contradicts its state.' );
				}
			}
		}
		foreach ( $version_map as $version ) {
			$logical = $logical_map[ $version->logical_rule_id ];
			$field = match ( $version->state ) { RuleState::Draft => 'draft_version_id', RuleState::Scheduled => 'scheduled_version_id', RuleState::Published => 'current_published_version_id', RuleState::Retired => null };
			if ( null !== $field && $logical->{$field} !== $version->id || ( RuleState::Retired === $version->state && in_array( $version->id, [ $logical->draft_version_id, $logical->scheduled_version_id, $logical->current_published_version_id ], true ) ) ) {
				throw new \InvalidArgumentException( 'Orphan or contradictory active rule version.' );
			}
			if ( null === $version->supersedes_version_id ) { continue; }
			$prior = $version_map[ $version->supersedes_version_id ] ?? null;
			if ( null === $prior ) {
				if ( $complete && ( $history_complete || RuleState::Retired !== $version->state ) ) { throw new \InvalidArgumentException( 'Rule predecessor is missing.' ); }
				continue;
			}
			if ( $prior->logical_rule_id !== $logical->id || $prior->site_id !== $version->site_id || $prior->version_sequence >= $version->version_sequence || null === $prior->published_at || ( null !== $version->scheduled_predecessor_row_revision && $version->scheduled_predecessor_row_revision > $prior->row_revision ) || ( null !== $version->published_at && ( RuleState::Retired !== $prior->state || null === $prior->retired_at || ! $prior->retired_at->equals( $version->published_at ) ) ) ) {
				throw new \InvalidArgumentException( 'Invalid rule supersession history.' );
			}
		}
		$this->profile = $profile;
		$this->guard = $guard;
		$this->logicals = array_values( $logical_map );
		$this->versions = array_values( $version_map );
		$this->complete = $complete;
		$this->history_complete = $history_complete;
	}

	public static function from_rows( RuleFamilyProfile $profile, ?array $guard_row, array $logical_rows, array $version_rows, bool $complete = true, bool $history_complete = false ): self {
		if ( count( $logical_rows ) > 1000 || count( $version_rows ) > 2000 ) { throw new \InvalidArgumentException( 'Rule snapshot exceeds its row budget.' ); }
		$guard = null === $guard_row ? null : RuleFamilyGuard::from_row( $guard_row, $profile );
		if ( null === $guard ) { return new self( $profile, null, $logical_rows, $version_rows, $complete, $history_complete ); }
		$logicals = $map = $versions = [];
		foreach ( $logical_rows as $row ) {
			if ( ! is_array( $row ) ) { throw new \InvalidArgumentException( 'Invalid logical rule row.' ); }
			$logical = LogicalRule::from_row( $row, $guard, $profile );
			$logicals[] = $logical;
			$map[ $logical->id ] = $logical;
		}
		foreach ( $version_rows as $row ) {
			if ( ! is_array( $row ) ) { throw new \InvalidArgumentException( 'Invalid rule version row.' ); }
			$id = RuleRecordCodec::integer( $row['logical_rule_id'] ?? null );
			if ( ! isset( $map[ $id ] ) ) { throw new \InvalidArgumentException( 'Missing rule version logical identity.' ); }
			$versions[] = RuleVersion::from_row( $row, $map[ $id ], $profile );
		}
		return new self( $profile, $guard, $logicals, $versions, $complete, $history_complete );
	}

	/** Full migration/history verification, distinct from bounded current evaluation. */
	public static function from_history_rows( RuleFamilyProfile $profile, ?array $guard_row, array $logical_rows, array $version_rows ): self {
		return self::from_rows( $profile, $guard_row, $logical_rows, $version_rows, true, true );
	}

	/** @return list<RuleCandidate> */
	public function candidates(): array {
		$map = [];
		foreach ( $this->logicals as $logical ) { $map[ $logical->id ] = $logical; }
		$versions = $this->history_complete ? $this->versions : array_values( array_filter( $this->versions, static fn( RuleVersion $version ): bool => RuleState::Retired !== $version->state ) );
		return array_map( static fn( RuleVersion $version ): RuleCandidate => new RuleCandidate( $map[ $version->logical_rule_id ], $version ), $versions );
	}

	public function candidate_digest(): string {
		$logical_facts = $version_facts = [];
		foreach ( $this->logicals as $logical ) { $logical_facts[ $logical->id ] = [ $logical->logical_uuid, $logical->revision, $logical->scope_hash, $logical->current_published_version_id, $logical->draft_version_id, $logical->scheduled_version_id, $logical->last_version_sequence ]; }
		foreach ( $this->versions as $version ) { $version_facts[ $version->id ] = [ $version->version_uuid, $version->row_revision, $version->content_hash, $version->state->value, $version->published_at?->sql(), $version->retired_at?->sql() ]; }
		ksort( $logical_facts, SORT_NUMERIC ); ksort( $version_facts, SORT_NUMERIC );
		return hash( 'sha256', 'cetech-rule-candidates-v1:' . json_encode( [ $this->guard?->site_id, $this->profile->family(), $this->profile->policy_hash(), $this->guard?->revision, $this->complete, $this->history_complete, $logical_facts, $version_facts ], JSON_THROW_ON_ERROR ) );
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule snapshot requires an explicit authorized projection.' ); }
}
