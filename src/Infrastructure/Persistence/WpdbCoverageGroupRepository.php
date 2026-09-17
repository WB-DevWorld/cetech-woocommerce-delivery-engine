<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Coverage\CoverageMember;
use CetechDeliveryEngine\Domain\Coverage\CoveragePostcode;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

final class WpdbCoverageGroupRepository implements CoverageGroupRepositoryInterface {

	public function list_by_zone( int $zone_id ): array {
		$map = $this->list_by_zone_ids( [ $zone_id ] );

		return $map[ $zone_id ] ?? [];
	}

	public function list_by_zone_ids( array $zone_ids ): array {
		$zone_ids = array_values( array_unique( array_filter( array_map( 'intval', $zone_ids ) ) ) );
		$out      = [];
		foreach ( $zone_ids as $id ) {
			$out[ $id ] = [];
		}
		if ( [] === $zone_ids ) {
			return $out;
		}

		global $wpdb;
		$groups_table = TableNames::for( CoverageSchema::GROUPS_SUFFIX );
		$in           = implode( ',', array_fill( 0, count( $zone_ids ), '%d' ) );
		$sql          = "SELECT * FROM `{$groups_table}` WHERE zone_id IN ({$in}) ORDER BY sort_order ASC, id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$zone_ids ), ARRAY_A );
		$ids  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$ids[] = (int) ( $row['id'] ?? 0 );
		}

		$members   = $this->load_members( $ids );
		$postcodes = $this->load_postcodes( $ids );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$group_id = (int) ( $row['id'] ?? 0 );
			$zone_id  = (int) ( $row['zone_id'] ?? 0 );
			$out[ $zone_id ][] = CoverageGroup::fromRow(
				$row,
				$members[ $group_id ] ?? [],
				$postcodes[ $group_id ] ?? []
			);
		}

		return $out;
	}

	public function find_by_id( int $id ): ?CoverageGroup {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = TableNames::for( CoverageSchema::GROUPS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$members   = $this->load_members( [ $id ] );
		$postcodes = $this->load_postcodes( [ $id ] );

		return CoverageGroup::fromRow( $row, $members[ $id ] ?? [], $postcodes[ $id ] ?? [] );
	}

	public function save_group( array $payload ): CoverageGroup {
		global $wpdb;
		$table = TableNames::for( CoverageSchema::GROUPS_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$id    = (int) ( $payload['id'] ?? 0 );
		$legacy = isset( $payload['legacy_migration'] ) && is_array( $payload['legacy_migration'] ) ? $payload['legacy_migration'] : [];
		$encoded = wp_json_encode( $legacy );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}

		$row = [
			'zone_id'               => (int) ( $payload['zone_id'] ?? 0 ),
			'root_location_id'      => (int) ( $payload['root_location_id'] ?? 0 ),
			'coverage_mode'         => (string) ( $payload['coverage_mode'] ?? 'entire_area' ),
			'sort_order'            => (int) ( $payload['sort_order'] ?? 100 ),
			'status'                => (string) ( $payload['status'] ?? RecordStatus::Active->value ),
			'review_required'       => ! empty( $payload['review_required'] ) ? 1 : 0,
			'legacy_migration_json' => $encoded,
			'updated_at'            => $now,
		];

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, [ 'id' => $id ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}

		if ( isset( $payload['members'] ) && is_array( $payload['members'] ) ) {
			$this->replace_members( $id, $payload['members'] );
		}
		if ( isset( $payload['postcodes'] ) && is_array( $payload['postcodes'] ) ) {
			$this->replace_postcodes( $id, $payload['postcodes'] );
		}

		return $this->find_by_id( $id ) ?? CoverageGroup::fromRow( $row + [ 'id' => $id ] );
	}

	public function delete_group( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}

		global $wpdb;
		$this->replace_members( $id, [] );
		$this->replace_postcodes( $id, [] );
		$table = TableNames::for( CoverageSchema::GROUPS_SUFFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	public function delete_by_zone( int $zone_id ): void {
		foreach ( $this->list_by_zone( $zone_id ) as $group ) {
			$this->delete_group( $group->id );
		}
	}

	public function replace_members( int $group_id, array $members ): void {
		global $wpdb;
		$table = TableNames::for( CoverageSchema::MEMBERS_SUFFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'coverage_group_id' => $group_id ], [ '%d' ] );
		$now = gmdate( 'Y-m-d H:i:s' );
		$seen = [];
		foreach ( $members as $member ) {
			$location_id = (int) ( $member['location_id'] ?? 0 );
			$membership  = (string) ( $member['membership'] ?? CoverageMembership::Include->value );
			$key         = $location_id . ':' . $membership;
			if ( $location_id <= 0 || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				[
					'coverage_group_id' => $group_id,
					'location_id'       => $location_id,
					'membership'        => $membership,
					'created_at'        => $now,
				]
			);
		}
	}

	public function replace_postcodes( int $group_id, array $postcodes ): void {
		global $wpdb;
		$table = TableNames::for( CoverageSchema::POSTCODES_SUFFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, [ 'coverage_group_id' => $group_id ], [ '%d' ] );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$seen = [];
		foreach ( $postcodes as $postcode ) {
			$value = strtoupper( trim( (string) ( $postcode['postcode_value'] ?? '' ) ) );
			$mode  = (string) ( $postcode['match_mode'] ?? 'exact' );
			$key   = $value . ':' . $mode;
			if ( '' === $value || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				[
					'coverage_group_id' => $group_id,
					'postcode_value'    => $value,
					'match_mode'        => $mode,
					'priority'          => (int) ( $postcode['priority'] ?? 100 ),
					'status'            => (string) ( $postcode['status'] ?? RecordStatus::Active->value ),
					'created_at'        => $now,
					'updated_at'        => $now,
				]
			);
		}
	}

	public function replace_for_zone( int $zone_id, array $groups ): array {
		$this->delete_by_zone( $zone_id );
		$saved = [];
		foreach ( $groups as $index => $payload ) {
			$payload['zone_id']    = $zone_id;
			$payload['sort_order'] = (int) ( $payload['sort_order'] ?? ( ( $index + 1 ) * 10 ) );
			$saved[]               = $this->save_group( $payload );
		}

		return $saved;
	}

	public function count_review_required(): int {
		global $wpdb;
		$table = TableNames::for( CoverageSchema::GROUPS_SUFFIX );
		$sql   = "SELECT COUNT(*) FROM `{$table}` WHERE review_required = 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param list<int> $group_ids
	 *
	 * @return array<int, list<CoverageMember>>
	 */
	private function load_members( array $group_ids ): array {
		$group_ids = array_values( array_filter( $group_ids ) );
		$out       = [];
		foreach ( $group_ids as $id ) {
			$out[ $id ] = [];
		}
		if ( [] === $group_ids ) {
			return $out;
		}

		global $wpdb;
		$table = TableNames::for( CoverageSchema::MEMBERS_SUFFIX );
		$in    = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
		$sql   = "SELECT * FROM `{$table}` WHERE coverage_group_id IN ({$in}) ORDER BY id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$group_ids ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$member = CoverageMember::fromRow( $row );
			$out[ $member->coverage_group_id ][] = $member;
		}

		return $out;
	}

	/**
	 * @param list<int> $group_ids
	 *
	 * @return array<int, list<CoveragePostcode>>
	 */
	private function load_postcodes( array $group_ids ): array {
		$group_ids = array_values( array_filter( $group_ids ) );
		$out       = [];
		foreach ( $group_ids as $id ) {
			$out[ $id ] = [];
		}
		if ( [] === $group_ids ) {
			return $out;
		}

		global $wpdb;
		$table = TableNames::for( CoverageSchema::POSTCODES_SUFFIX );
		$in    = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
		$sql   = "SELECT * FROM `{$table}` WHERE coverage_group_id IN ({$in}) ORDER BY priority ASC, id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$group_ids ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$postcode = CoveragePostcode::fromRow( $row );
			$out[ $postcode->coverage_group_id ][] = $postcode;
		}

		return $out;
	}
}
