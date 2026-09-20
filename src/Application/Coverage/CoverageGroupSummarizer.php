<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Coverage;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;

/**
 * Customer/admin-safe Delivery Area coverage summaries from schema-6 groups.
 */
final class CoverageGroupSummarizer {

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations
	) {
	}

	/**
	 * @param list<CoverageGroup> $groups
	 */
	public function summarize( array $groups ): string {
		$usable = array_values(
			array_filter(
				$groups,
				static fn ( CoverageGroup $group ): bool => $group->isUsable()
			)
		);
		if ( [] === $usable ) {
			return '';
		}
		$parts = [];
		foreach ( $usable as $group ) {
			$parts[] = $this->summarize_group( $group );
		}
		$parts = array_values( array_filter( $parts ) );
		if ( [] === $parts ) {
			return '';
		}
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		return sprintf( '%d coverage groups: %s', count( $parts ), implode( ' OR ', $parts ) );
	}

	private function summarize_group( CoverageGroup $group ): string {
		$root = $this->locations->find_by_id( $group->root_location_id );
		$name = $root ? $root->canonical_name : 'Coverage';
		if ( CoverageMode::EntireArea === $group->mode ) {
			return sprintf( '%s — entire area', $name );
		}
		if ( CoverageMode::EntireExcept === $group->mode ) {
			$count = count( $group->members_of( CoverageMembership::Exclude ) );

			return sprintf( '%s — entire area except %d location%s', $name, $count, 1 === $count ? '' : 's' );
		}

		$includes = $group->members_of( CoverageMembership::Include );
		$count    = count( $includes );
		$shown    = [];
		foreach ( array_slice( $includes, 0, 3 ) as $member ) {
			$location = $this->locations->find_by_id( $member->location_id );
			if ( $location ) {
				$shown[] = $location->canonical_name;
			}
		}
		if ( $count <= 3 && [] !== $shown ) {
			return sprintf( '%s — %s', $name, implode( ', ', $shown ) );
		}
		if ( [] !== $shown && $count > 3 ) {
			return sprintf( '%s — %s +%d', $name, implode( ', ', $shown ), $count - count( $shown ) );
		}

		return sprintf( '%s — %d selected location%s', $name, $count, 1 === $count ? '' : 's' );
	}
}
