<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;

final class WpdbCanonicalLocationRepository extends AbstractWpdbRepository implements CanonicalLocationRepositoryInterface {

	protected function table_suffix(): string {
		return GeographySchema::LOCATIONS_SUFFIX;
	}

	public function find_by_id( int $id ): ?CanonicalLocation {
		if ( $id <= 0 ) {
			return null;
		}

		$row = $this->fetch_row_by_id( $id );

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function find_by_key( string $location_key ): ?CanonicalLocation {
		$location_key = trim( $location_key );
		if ( '' === $location_key ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE location_key = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $location_key ), ARRAY_A );

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function find_by_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( [] === $ids ) {
			return [];
		}

		global $wpdb;
		$table  = $this->table_name();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql    = "SELECT * FROM `{$table}` WHERE id IN ({$in})";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$location = CanonicalLocation::fromRow( $row );
			$out[ $location->id ] = $location;
		}

		return array_values( $out );
	}

	public function find_country( string $country_code ): ?CanonicalLocation {
		$country_code = strtoupper( trim( $country_code ) );
		if ( 2 !== strlen( $country_code ) ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND location_type = %s AND parent_location_id IS NULL LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, $country_code, GeographyLocationType::Country->value ),
			ARRAY_A
		);

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null ): ?CanonicalLocation {
		$country_code    = strtoupper( trim( $country_code ) );
		$normalized_name = GeographyNameNormalizer::normalize( $normalized_name );
		if ( '' === $country_code || '' === $normalized_name ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [ $country_code, $normalized_name, RecordStatus::Active->value ];
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND normalized_name = %s AND status = %s";
		if ( null === $parent_id || 0 === $parent_id ) {
			$sql .= ' AND parent_location_id IS NULL';
		} else {
			$sql   .= ' AND parent_location_id = %d';
			$args[] = $parent_id;
		}
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$sql .= ' LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function list_children( int $parent_id, ?GeographyLocationType $type = null, int $limit = 50, int $offset = 0 ): array {
		if ( $parent_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$table  = $this->table_name();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$args   = [ $parent_id, RecordStatus::Active->value ];
		$sql    = "SELECT * FROM `{$table}` WHERE parent_location_id = %d AND status = %s";
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$sql   .= ' ORDER BY canonical_name ASC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = CanonicalLocation::fromRow( $row );
		}

		return $out;
	}

	public function count_children( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [ $parent_id, RecordStatus::Active->value ];
		$sql   = "SELECT COUNT(*) FROM `{$table}` WHERE parent_location_id = %d AND status = %s";
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$search = GeographyNameNormalizer::normalize( $search );
		if ( '' !== $search ) {
			$sql   .= ' AND normalized_name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

		return (int) $count;
	}

	public function search_localities( string $country_code, ?int $parent_id, string $query, int $limit = 25, int $offset = 0 ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$query        = GeographyNameNormalizer::normalize( $query );
		$limit        = max( 1, min( 50, $limit ) );
		$offset       = max( 0, $offset );
		if ( '' === $country_code ) {
			return [];
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [ $country_code, GeographyLocationType::Locality->value, RecordStatus::Active->value ];
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND location_type = %s AND status = %s";
		if ( null !== $parent_id && $parent_id > 0 ) {
			$path   = '%/' . $parent_id . '/%';
			$sql   .= ' AND (parent_location_id = %d OR ancestry_path LIKE %s)';
			$args[] = $parent_id;
			$args[] = $path;
		}
		if ( '' !== $query ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$sql   .= ' AND (normalized_name LIKE %s OR ascii_name LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		$sql   .= ' ORDER BY canonical_name ASC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = CanonicalLocation::fromRow( $row );
		}

		return $out;
	}

	public function list_by_country( string $country_code, ?GeographyLocationType $type = null, int $limit = 500 ): array {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code ) {
			return [];
		}

		global $wpdb;
		$table = $this->table_name();
		$limit = max( 1, min( 2000, $limit ) );
		$args  = [ $country_code, RecordStatus::Active->value ];
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND status = %s";
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$sql   .= ' ORDER BY location_type ASC, canonical_name ASC LIMIT %d';
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = CanonicalLocation::fromRow( $row );
		}

		return $out;
	}

	public function save( CanonicalLocation $location ): CanonicalLocation {
		global $wpdb;

		$table = $this->table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$row   = [
			'location_key'        => '' !== $location->location_key ? $location->location_key : GeographyNameNormalizer::new_location_key(),
			'country_code'        => $location->country_code,
			'parent_location_id'  => $location->parent_location_id,
			'location_type'       => $location->location_type->value,
			'administrative_level'=> $location->administrative_level,
			'canonical_name'      => $location->canonical_name,
			'normalized_name'     => $location->normalized_name,
			'ascii_name'          => $location->ascii_name,
			'latitude'            => $location->latitude,
			'longitude'           => $location->longitude,
			'status'              => $location->status->value,
			'ancestry_path'       => $location->ancestry_path,
			'updated_at'          => $now,
		];

		if ( $location->id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, [ 'id' => $location->id ] );
			$saved = $this->find_by_id( $location->id );

			return $saved ?? $location;
		}

		$row['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row );
		$id    = (int) $wpdb->insert_id;
		$saved = $this->find_by_id( $id );
		if ( $saved instanceof CanonicalLocation && '' === $saved->ancestry_path ) {
			$path = $this->compute_path( $saved );
			$this->update_ancestry_path( $saved->id, $path );
			$saved = $this->find_by_id( $id ) ?? $saved;
		}

		return $saved ?? $location;
	}

	public function update_ancestry_path( int $id, string $path ): void {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[
				'ancestry_path' => $path,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
	}

	private function compute_path( CanonicalLocation $location ): string {
		if ( null === $location->parent_location_id ) {
			return LocationAncestry::append_path( '', $location->id );
		}

		$parent = $this->find_by_id( $location->parent_location_id );
		$parent_path = $parent instanceof CanonicalLocation ? $parent->ancestry_path : '';
		if ( '' === $parent_path && $parent instanceof CanonicalLocation ) {
			$parent_path = LocationAncestry::append_path( '', $parent->id );
		}

		return LocationAncestry::append_path( $parent_path, $location->id );
	}
}
