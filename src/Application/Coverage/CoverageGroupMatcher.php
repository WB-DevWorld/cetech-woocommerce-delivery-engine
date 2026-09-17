<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Coverage;

use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Coverage\CoverageMatchDiagnostic;
use CetechDeliveryEngine\Domain\Coverage\CoverageMember;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Geography\ResolvedDestination;

/**
 * Coverage-group matcher.
 *
 * Different hierarchy levels inside a group = AND (implied by ancestry under root).
 * Multiple members at the same level = OR.
 * Multiple coverage groups = OR.
 * Exclusion wins over inherited inclusion inside a group.
 */
final class CoverageGroupMatcher {

	public function __construct(
		private CoverageGroupRepositoryInterface $groups,
		private CanonicalLocationRepositoryInterface $locations
	) {
	}

	/**
	 * @return array{matched: bool, specificity: int, diagnostic: CoverageMatchDiagnostic}
	 */
	public function match_zone( int $zone_id, ResolvedDestination $destination ): array {
		$groups = $this->groups->list_by_zone( $zone_id );
		$usable = array_values(
			array_filter(
				$groups,
				static fn ( CoverageGroup $group ): bool => $group->isUsable()
			)
		);

		if ( [] === $usable ) {
			return [
				'matched'     => false,
				'specificity' => DestinationZoneMatcher::SPECIFICITY_FALLBACK,
				'diagnostic'  => new CoverageMatchDiagnostic(
					false,
					$zone_id,
					null,
					'',
					'',
					DestinationZoneMatcher::SPECIFICITY_FALLBACK,
					'no_usable_coverage_group'
				),
			];
		}

		$best = null;
		foreach ( $usable as $group ) {
			$result = $this->match_group( $group, $destination );
			if ( $result['matched'] && ( null === $best || $result['specificity'] > $best['specificity'] ) ) {
				$best = $result;
			}
		}

		if ( null !== $best ) {
			return $best;
		}

		return [
			'matched'     => false,
			'specificity' => DestinationZoneMatcher::SPECIFICITY_FALLBACK,
			'diagnostic'  => new CoverageMatchDiagnostic(
				false,
				$zone_id,
				null,
				'',
				'no_group_matched',
				DestinationZoneMatcher::SPECIFICITY_FALLBACK,
				''
			),
		];
	}

	/**
	 * @return array{matched: bool, specificity: int, diagnostic: CoverageMatchDiagnostic}
	 */
	public function match_group( CoverageGroup $group, ResolvedDestination $destination ): array {
		$root = $this->locations->find_by_id( $group->root_location_id );
		if ( ! $root instanceof CanonicalLocation || ! $root->isActive() ) {
			return $this->miss( $group, 'root_missing' );
		}

		$location = $destination->location;
		if ( ! $location instanceof CanonicalLocation ) {
			return $this->miss( $group, 'destination_not_canonical' );
		}

		if ( $location->country_code !== $root->country_code ) {
			return $this->miss( $group, 'country_mismatch' );
		}

		if ( ! LocationAncestry::is_self_or_descendant( $location, $root ) ) {
			return $this->miss( $group, 'outside_root_ancestry' );
		}

		if ( ! $this->postcodes_match( $group, $destination->postcode ) ) {
			return $this->miss( $group, 'postcode_mismatch' );
		}

		$excluded = $this->matches_members( $location, $group->members_of( CoverageMembership::Exclude ) );
		if ( $excluded instanceof CanonicalLocation ) {
			$specificity = $this->specificity_from_constraint( $excluded, false );
			return [
				'matched'     => false,
				'specificity' => $specificity,
				'diagnostic'  => new CoverageMatchDiagnostic(
					false,
					$group->zone_id,
					$group->id,
					'',
					'excluded_descendant',
					$specificity,
					'',
					[ 'excluded_location_id' => $excluded->id ]
				),
			];
		}

		$included = true;
		$reason   = 'entire_area';
		$matched_constraint = $root;
		if ( CoverageMode::SelectedDescendants === $group->mode ) {
			$member = $this->matches_members( $location, $group->members_of( CoverageMembership::Include ) );
			if ( ! $member instanceof CanonicalLocation ) {
				return $this->miss( $group, 'not_selected_descendant' );
			}
			$reason            = 'selected_descendant';
			$matched_constraint = $member;
		} elseif ( CoverageMode::EntireExcept === $group->mode ) {
			$reason = 'entire_area_except';
		}

		$postcode_hit = [] !== $group->postcodes && $this->postcodes_match( $group, $destination->postcode );
		$specificity  = $this->specificity_from_constraint( $matched_constraint, $postcode_hit && [] !== $group->postcodes );

		return [
			'matched'     => $included,
			'specificity' => $specificity,
			'diagnostic'  => new CoverageMatchDiagnostic(
				true,
				$group->zone_id,
				$group->id,
				$reason,
				'',
				$specificity,
				'',
				[
					'root_location_id' => $root->id,
					'mode'             => $group->mode->value,
				]
			),
		];
	}

	/**
	 * @param list<CoverageMember> $members
	 */
	private function matches_members( CanonicalLocation $location, array $members ): ?CanonicalLocation {
		if ( [] === $members ) {
			return null;
		}

		$ids = [];
		foreach ( $members as $member ) {
			$ids[] = $member->location_id;
		}
		$loaded = $this->locations->find_by_ids( $ids );
		$best   = null;
		$best_rank = -1;
		foreach ( $loaded as $member_location ) {
			if ( ! LocationAncestry::is_self_or_descendant( $location, $member_location ) ) {
				continue;
			}
			$rank = $this->specificity_from_constraint( $member_location, false );
			if ( $rank > $best_rank ) {
				$best      = $member_location;
				$best_rank = $rank;
			}
		}

		return $best;
	}

	private function postcodes_match( CoverageGroup $group, string $postcode ): bool {
		$active = [];
		foreach ( $group->postcodes as $rule ) {
			if ( $rule->status->value === 'active' ) {
				$active[] = $rule;
			}
		}
		if ( [] === $active ) {
			return true;
		}

		foreach ( $active as $rule ) {
			if ( $rule->matches( $postcode ) ) {
				return true;
			}
		}

		return false;
	}

	private function specificity_from_constraint( CanonicalLocation $constraint, bool $postcode_matched ): int {
		if ( $postcode_matched ) {
			return DestinationZoneMatcher::SPECIFICITY_POSTCODE;
		}
		if ( $constraint->isLocality() ) {
			return DestinationZoneMatcher::SPECIFICITY_LOCALITY;
		}
		if ( $constraint->isAdministrative() ) {
			return match ( $constraint->administrative_level ?? 1 ) {
				4 => DestinationZoneMatcher::SPECIFICITY_ADM4,
				3 => DestinationZoneMatcher::SPECIFICITY_ADM3,
				2 => DestinationZoneMatcher::SPECIFICITY_ADM2,
				default => DestinationZoneMatcher::SPECIFICITY_ADM1,
			};
		}

		return DestinationZoneMatcher::SPECIFICITY_COUNTRY;
	}

	/**
	 * @return array{matched: bool, specificity: int, diagnostic: CoverageMatchDiagnostic}
	 */
	private function miss( CoverageGroup $group, string $reason ): array {
		return [
			'matched'     => false,
			'specificity' => DestinationZoneMatcher::SPECIFICITY_FALLBACK,
			'diagnostic'  => new CoverageMatchDiagnostic(
				false,
				$group->zone_id,
				$group->id,
				'',
				$reason,
				DestinationZoneMatcher::SPECIFICITY_FALLBACK,
				''
			),
		];
	}
}
