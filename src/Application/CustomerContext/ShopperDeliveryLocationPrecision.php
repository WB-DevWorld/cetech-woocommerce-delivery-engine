<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Coverage\CoverageMember;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\CustomerContext\ShopperLocationPrecision;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

/**
 * Bounded precision policy over active Delivery Areas and usable Coverage Groups.
 *
 * Uses configured roots/members and canonical ancestry only. Does not walk
 * national geography tables or Location Pack descendants.
 */
final class ShopperDeliveryLocationPrecision {

	public function __construct(
		private CanonicalLocationResolver $resolver,
		private DestinationZoneRepositoryInterface $zones,
		private CoverageGroupRepositoryInterface $groups,
		private CanonicalLocationRepositoryInterface $locations
	) {
	}

	public function evaluate( ?MatchingLocation $matching ): ShopperLocationPrecision {
		if ( ! $matching instanceof MatchingLocation || ! $matching->isPresent() ) {
			return ShopperLocationPrecision::sufficient( ShopperLocationPrecision::REASON_SUFFICIENT );
		}

		$country = $matching->country_identity;
		if ( ! $this->resolver->country_has_usable_pack( $country ) ) {
			return ShopperLocationPrecision::sufficient( ShopperLocationPrecision::REASON_LEGACY_NO_PACK );
		}

		$resolved = $this->resolver->resolve_from_matching( $matching, CanonicalResolutionContext::ShopperSelector );
		$current  = $resolved->location;
		if ( ! $current instanceof CanonicalLocation || ! $current->isActive() ) {
			$level = '' !== trim( $matching->state_identity ) || '' !== trim( $matching->state )
				? ShopperLocationPrecision::LEVEL_LOCALITY
				: ShopperLocationPrecision::LEVEL_REGION;

			return $this->insufficient( $level, ShopperLocationPrecision::REASON_CANONICAL_UNRESOLVED );
		}

		$needed_region   = false;
		$needed_locality = false;
		$reason          = ShopperLocationPrecision::REASON_NARROWER_COVERAGE;

		foreach ( $this->usable_groups() as $group ) {
			$trigger = $this->group_precision_trigger( $group, $current );
			if ( null === $trigger ) {
				continue;
			}

			$reason = $trigger['reason'];
			if ( ShopperLocationPrecision::LEVEL_REGION === $trigger['level'] ) {
				$needed_region = true;
			} else {
				$needed_locality = true;
			}
		}

		if ( $current->isCountry() && $needed_region ) {
			return $this->insufficient( ShopperLocationPrecision::LEVEL_REGION, $reason );
		}

		if ( $needed_locality || ( $current->isAdministrative() && $needed_region ) ) {
			return $this->insufficient( ShopperLocationPrecision::LEVEL_LOCALITY, $reason );
		}

		if ( $needed_region ) {
			return $this->insufficient( ShopperLocationPrecision::LEVEL_REGION, $reason );
		}

		return ShopperLocationPrecision::sufficient();
	}

	/**
	 * @return list<CoverageGroup>
	 */
	private function usable_groups(): array {
		$zone_ids = $this->active_zone_ids();
		if ( [] === $zone_ids ) {
			return [];
		}

		$usable = [];
		foreach ( $this->groups->list_by_zone_ids( $zone_ids ) as $zone_groups ) {
			foreach ( $zone_groups as $group ) {
				if ( $group instanceof CoverageGroup && $group->isUsable() ) {
					$usable[] = $group;
				}
			}
		}

		return $usable;
	}

	/**
	 * @return list<int>
	 */
	private function active_zone_ids(): array {
		$ids   = [];
		$after = 0;
		do {
			$page = $this->zones->page_after(
				$after,
				100,
				[ 'status' => RecordStatus::Active->value ]
			);
			if ( [] === $page ) {
				break;
			}
			foreach ( $page as $zone ) {
				$id    = (int) ( $zone['id'] ?? 0 );
				$after = max( $after, $id );
				if ( $id <= 0 ) {
					continue;
				}
				if ( RecordStatus::Active->value !== (string) ( $zone['status'] ?? '' ) ) {
					continue;
				}
				$ids[] = $id;
			}
		} while ( [] !== $page );

		return $ids;
	}

	/**
	 * @return array{level: string, reason: string}|null
	 */
	private function group_precision_trigger( CoverageGroup $group, CanonicalLocation $current ): ?array {
		$ids = [ $group->root_location_id ];
		foreach ( $group->members as $member ) {
			if ( $member instanceof CoverageMember && $member->location_id > 0 ) {
				$ids[] = $member->location_id;
			}
		}

		$loaded = [];
		foreach ( $this->locations->find_by_ids( array_values( array_unique( $ids ) ) ) as $location ) {
			if ( $location->isActive() ) {
				$loaded[ $location->id ] = $location;
			}
		}

		$root = $loaded[ $group->root_location_id ] ?? null;
		if ( ! $root instanceof CanonicalLocation || $root->country_code !== $current->country_code ) {
			return null;
		}

		$root_is_current    = $root->id === $current->id;
		$root_is_descendant = $this->is_strict_descendant( $root, $current );
		$root_is_ancestor   = $this->is_strict_descendant( $current, $root );
		if ( ! $root_is_current && ! $root_is_descendant && ! $root_is_ancestor ) {
			return null;
		}

		if ( $root_is_descendant ) {
			return [
				'level'  => $this->next_level( $current, $root ),
				'reason' => ShopperLocationPrecision::REASON_NARROWER_COVERAGE,
			];
		}

		$includes = $this->member_locations( $group, CoverageMembership::Include, $loaded );
		$excludes = $this->member_locations( $group, CoverageMembership::Exclude, $loaded );

		if ( CoverageMode::SelectedDescendants === $group->mode ) {
			foreach ( $includes as $member ) {
				if ( $this->is_strict_descendant( $member, $current ) ) {
					return [
						'level'  => $this->next_level( $current, $member ),
						'reason' => ShopperLocationPrecision::REASON_SELECTED_DESCENDANT,
					];
				}
			}
		}

		if ( CoverageMode::EntireExcept === $group->mode || [] !== $excludes ) {
			foreach ( $excludes as $member ) {
				if ( $this->is_strict_descendant( $member, $current ) ) {
					return [
						'level'  => $this->next_level( $current, $member ),
						'reason' => ShopperLocationPrecision::REASON_ENTIRE_EXCEPT,
					];
				}
			}
		}

		return null;
	}

	/**
	 * @param array<int, CanonicalLocation> $loaded
	 *
	 * @return list<CanonicalLocation>
	 */
	private function member_locations( CoverageGroup $group, CoverageMembership $membership, array $loaded ): array {
		$out = [];
		foreach ( $group->members_of( $membership ) as $member ) {
			$location = $loaded[ $member->location_id ] ?? null;
			if ( $location instanceof CanonicalLocation ) {
				$out[] = $location;
			}
		}

		return $out;
	}

	private function next_level( CanonicalLocation $current, CanonicalLocation $narrower ): string {
		if ( $current->isCountry() ) {
			return $narrower->isLocality()
				? ShopperLocationPrecision::LEVEL_LOCALITY
				: ShopperLocationPrecision::LEVEL_REGION;
		}

		return ShopperLocationPrecision::LEVEL_LOCALITY;
	}

	private function is_strict_descendant( CanonicalLocation $candidate, CanonicalLocation $ancestor ): bool {
		return $candidate->id !== $ancestor->id
			&& LocationAncestry::is_self_or_descendant( $candidate, $ancestor );
	}

	private function insufficient( string $level, string $reason ): ShopperLocationPrecision {
		if ( ShopperLocationPrecision::LEVEL_LOCALITY === $level ) {
			return ShopperLocationPrecision::insufficient(
				ShopperLocationPrecision::LEVEL_LOCALITY,
				$reason,
				ShopperLocationPrecision::MESSAGE_KEY_LOCALITY,
				CustomerStorefrontCopy::select_city_town_for_exact_fee()
			);
		}

		return ShopperLocationPrecision::insufficient(
			ShopperLocationPrecision::LEVEL_REGION,
			$reason,
			ShopperLocationPrecision::MESSAGE_KEY_REGION,
			CustomerStorefrontCopy::select_region_for_exact_fee()
		);
	}
}
