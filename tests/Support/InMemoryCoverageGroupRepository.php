<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Coverage\CoverageMember;
use CetechDeliveryEngine\Domain\Coverage\CoveragePostcode;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

final class InMemoryCoverageGroupRepository implements CoverageGroupRepositoryInterface {

	/** @var array<int, CoverageGroup> */
	private array $groups = [];

	private int $next_group = 1;
	private int $next_member = 1;
	private int $next_postcode = 1;

	public function list_by_zone( int $zone_id ): array {
		$out = [];
		foreach ( $this->groups as $group ) {
			if ( $group->zone_id === $zone_id ) {
				$out[] = $group;
			}
		}

		return $out;
	}

	public function list_by_zone_ids( array $zone_ids ): array {
		$map = [];
		foreach ( $zone_ids as $id ) {
			$map[ (int) $id ] = $this->list_by_zone( (int) $id );
		}

		return $map;
	}

	public function find_by_id( int $id ): ?CoverageGroup {
		return $this->groups[ $id ] ?? null;
	}

	public function save_group( array $payload ): CoverageGroup {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( $id <= 0 ) {
			$id = $this->next_group++;
		} elseif ( $id >= $this->next_group ) {
			$this->next_group = $id + 1;
		}

		$members = [];
		foreach ( $payload['members'] ?? [] as $member ) {
			$members[] = new CoverageMember(
				$this->next_member++,
				$id,
				(int) $member['location_id'],
				CoverageMembership::tryFrom( (string) $member['membership'] ) ?? CoverageMembership::Include
			);
		}

		$postcodes = [];
		foreach ( $payload['postcodes'] ?? [] as $postcode ) {
			$postcodes[] = CoveragePostcode::fromRow(
				$postcode + [
					'id'                => $this->next_postcode++,
					'coverage_group_id' => $id,
				]
			);
		}

		$group = new CoverageGroup(
			$id,
			(int) ( $payload['zone_id'] ?? 0 ),
			(int) ( $payload['root_location_id'] ?? 0 ),
			CoverageMode::tryFrom( (string) ( $payload['coverage_mode'] ?? '' ) ) ?? CoverageMode::EntireArea,
			(int) ( $payload['sort_order'] ?? 100 ),
			RecordStatus::tryFrom( (string) ( $payload['status'] ?? RecordStatus::Active->value ) ) ?? RecordStatus::Active,
			! empty( $payload['review_required'] ),
			is_array( $payload['legacy_migration'] ?? null ) ? $payload['legacy_migration'] : [],
			$members,
			$postcodes
		);
		$this->groups[ $id ] = $group;

		return $group;
	}

	public function delete_group( int $id ): void {
		unset( $this->groups[ $id ] );
	}

	public function delete_by_zone( int $zone_id ): void {
		foreach ( $this->list_by_zone( $zone_id ) as $group ) {
			unset( $this->groups[ $group->id ] );
		}
	}

	public function replace_members( int $group_id, array $members ): void {
		$group = $this->groups[ $group_id ] ?? null;
		if ( $group instanceof CoverageGroup ) {
			$this->save_group( $this->payload_from_group( $group, $members, null ) );
		}
	}

	public function replace_postcodes( int $group_id, array $postcodes ): void {
		$group = $this->groups[ $group_id ] ?? null;
		if ( $group instanceof CoverageGroup ) {
			$this->save_group( $this->payload_from_group( $group, null, $postcodes ) );
		}
	}

	public function replace_for_zone( int $zone_id, array $groups ): array {
		$this->delete_by_zone( $zone_id );
		$saved = [];
		foreach ( $groups as $payload ) {
			$payload['zone_id'] = $zone_id;
			$saved[]            = $this->save_group( $payload );
		}

		return $saved;
	}

	public function count_review_required(): int {
		$count = 0;
		foreach ( $this->groups as $group ) {
			if ( $group->review_required ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<array{location_id:int,membership:string}>|null $members
	 * @param list<array<string, mixed>>|null $postcodes
	 *
	 * @return array<string, mixed>
	 */
	private function payload_from_group( CoverageGroup $group, ?array $members, ?array $postcodes ): array {
		if ( null === $members ) {
			$members = [];
			foreach ( $group->members as $member ) {
				$members[] = [
					'location_id' => $member->location_id,
					'membership'  => $member->membership->value,
				];
			}
		}
		if ( null === $postcodes ) {
			$postcodes = [];
			foreach ( $group->postcodes as $postcode ) {
				$postcodes[] = [
					'postcode_value' => $postcode->postcode_value,
					'match_mode'     => $postcode->match_mode->value,
					'priority'       => $postcode->priority,
					'status'         => $postcode->status->value,
				];
			}
		}

		return [
			'id'               => $group->id,
			'zone_id'          => $group->zone_id,
			'root_location_id' => $group->root_location_id,
			'coverage_mode'    => $group->mode->value,
			'sort_order'       => $group->sort_order,
			'status'           => $group->status->value,
			'review_required'  => $group->review_required,
			'legacy_migration' => $group->legacy_migration,
			'members'          => $members,
			'postcodes'        => $postcodes,
		];
	}
}
