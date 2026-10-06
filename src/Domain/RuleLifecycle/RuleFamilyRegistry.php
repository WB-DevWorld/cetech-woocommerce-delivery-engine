<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Explicit finite registration. Production defaults to no active rule families. */
final class RuleFamilyRegistry {
	private array $entries = [];

	/** @param list<RuleFamilyProfile> $profiles */
	public function __construct( array $profiles = [] ) {
		if ( ! array_is_list( $profiles ) || count( $profiles ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid rule family registry.' );
		}
		foreach ( $profiles as $profile ) {
			if ( ! $profile instanceof RuleFamilyProfile ) {
				throw new \InvalidArgumentException( 'Invalid rule family profile.' );
			}
			$declaration = self::declaration( $profile );
			if ( isset( $this->entries[ $profile->family() ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate rule family.' );
			}
			$this->entries[ $profile->family() ] = [ $profile, $declaration ];
		}
	}

	public function get( string $family ): RuleFamilyProfile {
		if ( ! isset( $this->entries[ $family ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported rule family.' );
		}
		[ $profile, $original ] = $this->entries[ $family ];
		if ( ! hash_equals( $original, self::declaration( $profile ) ) ) {
			throw new \InvalidArgumentException( 'Rule family declaration changed.' );
		}
		return $profile;
	}

	/** @return list<RuleFamilyProfile> */
	public function profiles(): array {
		$out = [];
		foreach ( array_keys( $this->entries ) as $family ) { $out[] = $this->get( $family ); }
		return $out;
	}

	public static function declaration( RuleFamilyProfile $profile ): string {
		RuleRecordCodec::code( $profile->family() );
		RuleRecordCodec::code( $profile->unavailable_reason() );
		RuleRecordCodec::digest( $profile->policy_hash() );
		if ( 1 !== $profile->version() ) {
			throw new \InvalidArgumentException( 'Unsupported rule family format.' );
		}
		$rank = self::precedence( $profile );
		return hash( 'sha256', json_encode( [ $profile->family(), $profile->version(), $profile->policy_hash(), $profile->scope_schema()->fingerprint(), $profile->payload_schema()->fingerprint(), $profile->subject_schema()->fingerprint(), $rank, $profile->equal_rank_policy(), $profile->unavailable_reason() ], JSON_THROW_ON_ERROR ) );
	}

	public static function precedence( RuleFamilyProfile $profile ): array {
		$value = $profile->precedence();
		if ( ! array_is_list( $value ) || [] === $value || count( $value ) > 3 || ! in_array( $profile->equal_rank_policy(), [ 'refuse', 'tie_break' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid rule precedence declaration.' );
		}
		$out = [];
		$seen = [];
		foreach ( $value as $rank ) {
			if ( ! is_array( $rank ) || count( $rank ) !== 2 || ! isset( $rank['field'], $rank['direction'] ) || ! in_array( $rank['field'], [ 'specificity', 'priority', 'logical_uuid' ], true ) || ! in_array( $rank['direction'], [ 'asc', 'desc' ], true ) || isset( $seen[ $rank['field'] ] ) ) {
				throw new \InvalidArgumentException( 'Invalid rule precedence declaration.' );
			}
			$seen[ $rank['field'] ] = true;
			$out[] = [ 'field' => $rank['field'], 'direction' => $rank['direction'] ];
		}
		if ( ( 'tie_break' === $profile->equal_rank_policy() ) !== isset( $seen['logical_uuid'] ) || ( isset( $seen['logical_uuid'] ) && 'logical_uuid' !== $out[ count( $out ) - 1 ]['field'] ) ) {
			throw new \InvalidArgumentException( 'Rule tie-break is not explicitly declared.' );
		}
		return $out;
	}
}
