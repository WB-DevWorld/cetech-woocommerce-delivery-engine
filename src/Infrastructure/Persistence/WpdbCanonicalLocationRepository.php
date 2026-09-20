<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
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

	public function find_country( string $country_code, bool $include_inactive = false ): ?CanonicalLocation {
		$country_code = strtoupper( trim( $country_code ) );
		if ( 2 !== strlen( $country_code ) ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [ $country_code, GeographyLocationType::Country->value ];
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND location_type = %s AND parent_location_id IS NULL";
		if ( ! $include_inactive ) {
			$sql   .= ' AND status = %s';
			$args[] = RecordStatus::Active->value;
		}
		$sql .= ' ORDER BY status ASC, id ASC LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, ...$args ),
			ARRAY_A
		);

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null, ?int $include_generation = null, string $include_token = '' ): ?CanonicalLocation {
		$country_code    = strtoupper( trim( $country_code ) );
		$normalized_name = GeographyNameNormalizer::normalize( $normalized_name );
		if ( '' === $country_code || '' === $normalized_name ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [ $country_code, $normalized_name, RecordStatus::Active->value ];
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND normalized_name = %s AND (status = %s";
		if ( '' !== $include_token ) {
			$sql   .= ' OR (status = %s AND generation_token = %s)';
			$args[] = RecordStatus::Inactive->value;
			$args[] = $include_token;
		} elseif ( null !== $include_generation && $include_generation > 0 ) {
			$sql   .= ' OR (status = %s AND generation = %d)';
			$args[] = RecordStatus::Inactive->value;
			$args[] = $include_generation;
		}
		$sql .= ')';
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

	public function find_unique_exact_descendant( string $country_code, int $ancestor_id, string $normalized_name, ?GeographyLocationType $type = null ): ?CanonicalLocation {
		$country_code    = strtoupper( trim( $country_code ) );
		$normalized_name = GeographyNameNormalizer::normalize( $normalized_name );
		if ( '' === $country_code || '' === $normalized_name || $ancestor_id <= 0 ) {
			return null;
		}
		$ancestor = $this->find_by_id( $ancestor_id );
		if ( ! $ancestor instanceof CanonicalLocation || '' === $ancestor->ancestry_path ) {
			return null;
		}

		global $wpdb;
		$table = $this->table_name();
		$args  = [
			$country_code,
			RecordStatus::Active->value,
			$normalized_name,
			$normalized_name,
			$normalized_name,
			$wpdb->esc_like( $ancestor->ancestry_path ) . '%',
			$ancestor_id,
		];
		$sql = "SELECT * FROM `{$table}` WHERE country_code = %s AND status = %s AND (normalized_name = %s OR ascii_name = %s OR LOWER(ascii_name) = %s) AND ancestry_path LIKE %s AND id != %d";
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$sql .= ' LIMIT 2';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}

		return CanonicalLocation::fromRow( $rows[0] );
	}

	public function list_children( int $parent_id, ?GeographyLocationType $type = null, int $limit = 50, int $offset = 0 ): array {
		if ( $parent_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$table  = $this->table_name();
		$limit  = max( 1, min( 250, $limit ) );
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
			$args[] = $wpdb->esc_like( $search ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

		return (int) $count;
	}

	public function count_descendants( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int {
		if ( $parent_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = $this->table_name();
		$path  = $this->descendant_prefix( $parent_id );
		$args  = [ $parent_id, $path, RecordStatus::Active->value ];
		$sql   = "SELECT COUNT(*) FROM `{$table}` WHERE (parent_location_id = %d OR ancestry_path LIKE %s) AND status = %s AND id != %d";
		$args[] = $parent_id;
		if ( $type instanceof GeographyLocationType ) {
			$sql   .= ' AND location_type = %s';
			$args[] = $type->value;
		}
		$search = GeographyNameNormalizer::normalize( $search );
		if ( '' !== $search ) {
			$like    = $wpdb->esc_like( $search ) . '%';
			$aliases = TableNames::for( GeographySchema::ALIASES_SUFFIX );
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.generation_token = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
			$args[]  = '';
			$args[]  = $like;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

		return (int) $count;
	}

	public function count_localities( string $country_code, ?int $parent_id, string $query = '' ): int {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code ) {
			return 0;
		}
		if ( null !== $parent_id && $parent_id > 0 ) {
			return $this->count_descendants( $parent_id, GeographyLocationType::Locality, $query );
		}

		global $wpdb;
		$table  = $this->table_name();
		$search = GeographyNameNormalizer::normalize( $query );
		$args   = [ $country_code, GeographyLocationType::Locality->value, RecordStatus::Active->value ];
		$sql    = "SELECT COUNT(*) FROM `{$table}` WHERE country_code = %s AND location_type = %s AND status = %s";
		if ( '' !== $search ) {
			$like    = $wpdb->esc_like( $search ) . '%';
			$aliases = TableNames::for( GeographySchema::ALIASES_SUFFIX );
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.generation_token = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
			$args[]  = '';
			$args[]  = $like;
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
			$path   = $this->descendant_prefix( $parent_id );
			$sql   .= ' AND id != %d AND (parent_location_id = %d OR ancestry_path LIKE %s)';
			$args[] = $parent_id;
			$args[] = $parent_id;
			$args[] = $path;
		}
		if ( '' !== $query ) {
			$like    = $wpdb->esc_like( $query ) . '%';
			$aliases = TableNames::for( GeographySchema::ALIASES_SUFFIX );
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.generation_token = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
			$args[]  = '';
			$args[]  = $like;
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
			'generation'             => $location->generation,
			'generation_token'       => $location->generation_token,
			'draft_generation_token' => $location->draft_generation_token,
			'draft_json'             => $location->draft_json,
			'updated_at'             => $now,
		];

		if ( $location->id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => $location->id ] );
			if ( false === $result ) {
				throw new \RuntimeException( 'Failed to update canonical location ' . $location->id . '.' );
			}
			$saved = $this->find_by_id( $location->id );

			return $saved ?? $location;
		}

		$row['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to insert canonical location.' );
		}
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
		$result = $wpdb->update(
			$table,
			[
				'ancestry_path' => $path,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to update ancestry path for location ' . $id . '.' );
		}
	}

	public function rebuild_descendant_ancestry( int $root_id, string $old_path, string $new_path, int $limit = 2000 ): int {
		if ( $root_id <= 0 || '' === $old_path || $old_path === $new_path ) {
			return 0;
		}

		$batch  = max( 1, $limit );
		$total  = 0;
		$safety = 0;
		do {
			$updated = $this->rebuild_descendant_batch( $root_id, $old_path, $new_path, $batch );
			$total  += $updated;
			++$safety;
		} while ( $updated > 0 && $safety < 100000 );

		return $total;
	}

	public function prepare_generation( string $generation_token, int $limit = 200, int $after_id = 0, array $hierarchy = [] ): array {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			throw new \RuntimeException( 'Promotion requires a generation token.' );
		}

		$batch      = max( 1, $limit );
		$after      = max( 0, $after_id );
		$root_cursor = max( 0, (int) ( $hierarchy['hierarchy_root_cursor'] ?? 0 ) );
		$desc_cursor = max( 0, (int) ( $hierarchy['hierarchy_descendant_cursor'] ?? 0 ) );
		$root_id     = max( 0, (int) ( $hierarchy['hierarchy_root_id'] ?? 0 ) );
		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE draft_generation_token = %s AND id > %d ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$draft_rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token, $after, $batch ), ARRAY_A );
		$processed  = 0;
		$last_id    = $after;
		$draft_rows = is_array( $draft_rows ) ? $draft_rows : [];
		foreach ( $draft_rows as $row ) {
			$location = CanonicalLocation::fromRow( $row );
			$last_id  = max( $last_id, $location->id );
			$draft    = '' !== $location->draft_json ? json_decode( $location->draft_json, true ) : null;
			if ( is_array( $draft ) ) {
				$this->stage_draft_side_effects( $location, $draft, $generation_token );
				$this->stage_prepared_hierarchy( $location, $draft, $generation_token );
			} elseif ( RecordStatus::Inactive === $location->status && '' === $location->ancestry_path ) {
				$path = $this->compute_path( $location );
				if ( '' !== $path ) {
					$this->write_prepared_ancestry( $location->id, $path, $generation_token );
				}
			}
			++$processed;
		}

		$drafts_exhausted = count( $draft_rows ) < $batch;
		$hierarchy_done   = false;
		$remaining        = $batch - $processed;
		if ( $remaining > 0 ) {
			$staged      = $this->stage_prepared_descendants( $generation_token, $remaining, $root_cursor, $desc_cursor, $root_id );
			$processed  += $staged['processed'];
			$root_cursor = $staged['hierarchy_root_cursor'];
			$desc_cursor = $staged['hierarchy_descendant_cursor'];
			$root_id     = $staged['hierarchy_root_id'];
			$hierarchy_done = $staged['done'];
		}

		return [
			'processed'                    => $processed,
			'last_id'                      => $last_id,
			'done'                         => $drafts_exhausted && $hierarchy_done,
			'hierarchy_root_cursor'        => $root_cursor,
			'hierarchy_descendant_cursor'  => $desc_cursor,
			'hierarchy_root_id'            => $root_id,
		];
	}

	public function finalize_generation( string $generation_token, ?callable $finalize = null ): int {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			throw new \RuntimeException( 'Promotion requires a generation token.' );
		}

		global $wpdb;
		$table     = $this->table_name();
		$mappings  = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$aliases   = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now       = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( $this->has_unfinished_hierarchy_preparation( $generation_token )
				|| $this->prepared_hierarchy_is_inconsistent( $generation_token ) ) {
				throw new GeographyPackIncompletePreparationException(
					'Hierarchy preparation is incomplete for token ' . $generation_token . '.'
				);
			}
			if ( is_callable( $finalize ) ) {
				$finalize();
			}
			$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE draft_generation_token = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$draft_count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $generation_token ) );
			if ( $draft_count > 0 ) {
				$this->apply_drafts_set_based( $generation_token, $now );
			}
			$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE prepared_generation_token = %s AND prepared_ancestry_path != ''";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prepared_count = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $generation_token ) );
			if ( $prepared_count > 0 ) {
				$this->apply_prepared_ancestry_set_based( $generation_token, $now );
			}
			$sql = "UPDATE `{$table}` SET status = %s, updated_at = %s WHERE generation_token = %s AND status = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$activated = $wpdb->query(
				$wpdb->prepare( $sql, RecordStatus::Active->value, $now, $generation_token, RecordStatus::Inactive->value )
			);
			if ( false === $activated ) {
				throw new \RuntimeException( 'Failed to activate staged locations for token ' . $generation_token . '.' );
			}
			$this->promote_staged_mappings_set_based( $mappings, $generation_token, $now );
			$this->promote_staged_aliases_set_based( $aliases, $generation_token, $now );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );

			return (int) $activated;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	public function promote_generation( string $generation_token, ?callable $finalize = null ): int {
		$after     = 0;
		$guard     = 0;
		$hierarchy = [];
		do {
			$prepared  = $this->prepare_generation( $generation_token, 200, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			++$guard;
		} while ( ! $prepared['done'] && $guard < 100000 );

		return $this->finalize_generation( $generation_token, $finalize );
	}

	public function abandon_generation( string $generation_token ): void {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			return;
		}

		global $wpdb;
		$locations = $this->table_name();
		$mappings  = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$aliases   = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now       = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			$sql = "DELETE FROM `{$locations}` WHERE generation_token = %s AND status = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( $sql, $generation_token, RecordStatus::Inactive->value ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to abandon staged locations for token ' . $generation_token . '.' );
			}

			$sql = "UPDATE `{$locations}` SET `draft_json` = NULL, `draft_generation_token` = %s, `prepared_ancestry_path` = %s, `prepared_generation_token` = %s, `prepared_hierarchy_root_id` = %d, `updated_at` = %s WHERE (`draft_generation_token` = %s OR `prepared_generation_token` = %s)";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cleared = $wpdb->query(
				$wpdb->prepare( $sql, '', '', '', 0, $now, $generation_token, $generation_token )
			);
			if ( false === $cleared ) {
				throw new \RuntimeException( 'Failed to clear staged drafts for token ' . $generation_token . '.' );
			}

			$sql = "DELETE FROM `{$mappings}` WHERE `generation_token` = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( $sql, $generation_token ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to abandon staged mappings for token ' . $generation_token . '.' );
			}

			$sql = "DELETE FROM `{$aliases}` WHERE `generation_token` = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( $sql, $generation_token ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to abandon staged aliases for token ' . $generation_token . '.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	public function find_unique_administrative_core( string $country_code, int $parent_id, string $name, ?int $level = null ): ?CanonicalLocation {
		$core = GeographyNameNormalizer::administrative_core( $name );
		if ( '' === $core || $parent_id <= 0 ) {
			return null;
		}

		$matches = [];
		$offset  = 0;
		do {
			$page = $this->list_children( $parent_id, GeographyLocationType::Administrative, 250, $offset );
			foreach ( $page as $child ) {
				if ( null !== $level && $level > 0 && $child->administrative_level !== $level ) {
					continue;
				}
				if ( GeographyNameNormalizer::administrative_core( $child->canonical_name ) === $core
					|| GeographyNameNormalizer::administrative_core( $child->ascii_name ) === $core
					|| GeographyNameNormalizer::administrative_core( $child->normalized_name ) === $core ) {
					$matches[ $child->id ] = $child;
				}
			}
			$offset += count( $page );
		} while ( 250 === count( $page ) );

		return 1 === count( $matches ) ? array_values( $matches )[0] : null;
	}

	public function display_breadcrumb( CanonicalLocation $location ): string {
		$ids = array_values(
			array_filter(
				array_map( 'intval', explode( '/', trim( $location->ancestry_path, '/' ) ) )
			)
		);
		$names = [];
		foreach ( array_reverse( $ids ) as $id ) {
			if ( $id === $location->id ) {
				continue;
			}
			$node = $this->find_by_id( $id );
			if ( ! $node instanceof CanonicalLocation || $node->isCountry() ) {
				continue;
			}
			$names[] = $node->canonical_name;
		}

		return implode( ', ', $names );
	}

	/**
	 * @param array<string, mixed> $draft
	 */
	private function stage_draft_side_effects( CanonicalLocation $existing, array $draft, string $generation_token ): void {
		$former = trim( (string) ( $draft['former_name'] ?? '' ) );
		if ( '' !== $former ) {
			$this->write_staged_alias( $existing->id, $former, GeographyNameNormalizer::normalize( $former ), 'former_name', $generation_token );
		}
		foreach ( $draft['aliases'] ?? [] as $alias_row ) {
			if ( ! is_array( $alias_row ) ) {
				continue;
			}
			$alias = trim( (string) ( $alias_row['alias'] ?? '' ) );
			$norm  = trim( (string) ( $alias_row['normalized'] ?? GeographyNameNormalizer::normalize( $alias ) ) );
			if ( '' === $alias || '' === $norm ) {
				continue;
			}
			$this->write_staged_alias( $existing->id, $alias, $norm, (string) ( $alias_row['type'] ?? 'alternate' ), $generation_token );
		}
		foreach ( $draft['mappings'] ?? [] as $mapping ) {
			if ( is_array( $mapping ) ) {
				$this->write_staged_mapping( $existing->id, $mapping, $generation_token );
			}
		}
	}

	/**
	 * @param array<string, mixed> $draft
	 */
	private function stage_prepared_hierarchy( CanonicalLocation $location, array $draft, string $generation_token ): void {
		$parent_id = isset( $draft['parent_location_id'] ) ? (int) $draft['parent_location_id'] : (int) ( $location->parent_location_id ?? 0 );
		$path      = $this->future_ancestry_path( $location, $parent_id > 0 ? $parent_id : null, $generation_token );
		$live_parent = (int) ( $location->parent_location_id ?? 0 );
		$owner_id    = ( $parent_id !== $live_parent ) ? $location->id : 0;
		if ( '' !== $path ) {
			$this->write_prepared_ancestry( $location->id, $path, $generation_token, $owner_id );
		}
	}

	private function future_ancestry_path( CanonicalLocation $location, ?int $parent_id, string $generation_token ): string {
		return $this->future_graph_path( $location, $generation_token );
	}

	/**
	 * Resolve the complete future-parent graph for this generation.
	 * Independent of row order and of whether a moving parent has already been prepared.
	 *
	 * @param array<int, true> $stack
	 */
	private function future_graph_path( CanonicalLocation $location, string $generation_token, array $stack = [] ): string {
		if ( isset( $stack[ $location->id ] ) ) {
			throw new \RuntimeException( 'Cyclic or invalid future-parent graph.' );
		}
		$stack[ $location->id ] = true;
		$parent_id              = $this->future_parent_id( $location, $generation_token );
		if ( $parent_id <= 0 ) {
			return LocationAncestry::append_path( '', $location->id );
		}
		$parent = $this->find_by_id( $parent_id );
		if ( ! $parent instanceof CanonicalLocation ) {
			throw new \RuntimeException( 'Cyclic or invalid future-parent graph.' );
		}
		if ( $this->draft_changes_parent( $parent ) && $parent->draft_generation_token === $generation_token ) {
			$parent_path = $this->future_graph_path( $parent, $generation_token, $stack );
		} else {
			$parent_path = $parent->ancestry_path;
		}

		return LocationAncestry::append_path( $parent_path, $location->id );
	}

	private function future_parent_id( CanonicalLocation $location, string $generation_token ): int {
		if ( $location->draft_generation_token === $generation_token && '' !== $location->draft_json ) {
			$draft = json_decode( $location->draft_json, true );
			if ( is_array( $draft ) && array_key_exists( 'parent_location_id', $draft ) ) {
				return (int) $draft['parent_location_id'];
			}
		}

		return (int) ( $location->parent_location_id ?? 0 );
	}

	private function write_prepared_ancestry( int $id, string $path, string $generation_token, int $hierarchy_root_id = 0 ): void {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			[
				'prepared_ancestry_path'     => $path,
				'prepared_generation_token'  => $generation_token,
				'prepared_hierarchy_root_id' => max( 0, $hierarchy_root_id ),
				'updated_at'                 => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to stage prepared ancestry for location ' . $id . '.' );
		}
	}

	/**
	 * @return array{processed: int, done: bool, hierarchy_root_cursor: int, hierarchy_descendant_cursor: int, hierarchy_root_id: int}
	 */
	private function stage_prepared_descendants(
		string $generation_token,
		int $limit,
		int $root_cursor,
		int $desc_cursor,
		int $current_root_id
	): array {
		$remaining = max( 1, $limit );
		$updated   = 0;
		while ( $remaining > 0 ) {
			$root = null;
			if ( $current_root_id > 0 ) {
				$root = $this->find_by_id( $current_root_id );
				if ( ! $root instanceof CanonicalLocation || ! $this->requires_descendant_hierarchy_preparation( $root, $generation_token ) ) {
					$root_cursor     = max( $root_cursor, $current_root_id );
					$current_root_id = 0;
					$desc_cursor     = 0;
					$root            = null;
				}
			}
			if ( ! $root instanceof CanonicalLocation ) {
				$found = $this->find_next_hierarchy_root( $generation_token, $root_cursor );
				if ( $found['exhausted'] ) {
					return $this->hierarchy_stage_result( $updated, true, $root_cursor, 0, 0 );
				}
				if ( ! $found['root'] instanceof CanonicalLocation ) {
					return $this->hierarchy_stage_result( $updated, false, max( $root_cursor, $found['scanned_until'] ), $desc_cursor, 0 );
				}
				$root            = $found['root'];
				$current_root_id = $root->id;
				$desc_cursor     = 0;
			}

			$batch = $this->prepare_root_descendants( $root, $generation_token, $desc_cursor, $remaining );
			$updated   += $batch['count'];
			$remaining -= max( 1, $batch['scanned'] );
			if ( ! $batch['more'] ) {
				$root_cursor     = $root->id;
				$current_root_id = 0;
				$desc_cursor     = 0;
				continue;
			}
			$desc_cursor = $batch['last_id'];
			if ( $remaining <= 0 ) {
				break;
			}
		}

		$done = 0 === $current_root_id && ! $this->has_unfinished_hierarchy_preparation( $generation_token );

		return $this->hierarchy_stage_result( $updated, $done, $root_cursor, $desc_cursor, $current_root_id );
	}

	private function has_unprocessed_generation_drafts( string $generation_token ): bool {
		global $wpdb;
		$table = $this->table_name();
		$sql   = "SELECT id FROM `{$table}` WHERE draft_generation_token = %s AND prepared_generation_token != %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( $sql, $generation_token, $generation_token ) );

		return null !== $id && false !== $id && '' !== (string) $id && 0 !== (int) $id;
	}

	/**
	 * @return array{root: ?CanonicalLocation, scanned_until: int, exhausted: bool}
	 */
	private function find_next_hierarchy_root( string $generation_token, int $after_id ): array {
		$scanned = max( 0, $after_id );
		for ( $i = 0; $i < 50; $i++ ) {
			$candidate = $this->next_prepared_moving_root( $generation_token, $scanned );
			if ( ! $candidate instanceof CanonicalLocation ) {
				return [
					'root'          => null,
					'scanned_until' => $scanned,
					'exhausted'     => true,
				];
			}
			$scanned = $candidate->id;
			if ( $this->root_has_unprepared_descendants( $candidate, $generation_token ) ) {
				return [
					'root'          => $candidate,
					'scanned_until' => $candidate->id,
					'exhausted'     => false,
				];
			}
		}

		return [
			'root'          => null,
			'scanned_until' => $scanned,
			'exhausted'     => false,
		];
	}

	private function next_prepared_moving_root( string $generation_token, int $after_id ): ?CanonicalLocation {
		$page = $this->list_prepared_moving_roots_page( $generation_token, $after_id, 1 );

		return $page[0] ?? null;
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	private function list_prepared_moving_roots_page( string $generation_token, int $after_id, int $limit ): array {
		global $wpdb;
		$table = $this->table_name();
		$limit = max( 1, min( 100, $limit ) );
		$sql   = "SELECT * FROM `{$table}` WHERE prepared_generation_token = %s AND prepared_hierarchy_root_id = id AND id > %d ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $generation_token, max( 0, $after_id ), $limit ),
			ARRAY_A
		);
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = CanonicalLocation::fromRow( $row );
		}

		return $out;
	}

	/**
	 * @return array{count: int, last_id: int, scanned: int, more: bool}
	 */
	private function prepare_root_descendants( CanonicalLocation $root, string $generation_token, int $desc_cursor, int $limit ): array {
		global $wpdb;
		$table = $this->table_name();
		$like  = $wpdb->esc_like( $root->ancestry_path ) . '%';
		$start = strlen( $root->ancestry_path ) + 1;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$sql   = "UPDATE `{$table}` SET prepared_ancestry_path = CONCAT(%s, SUBSTRING(ancestry_path, %d)), prepared_generation_token = %s, prepared_hierarchy_root_id = %d, updated_at = %s WHERE ancestry_path LIKE %s AND id != %d AND id > %d AND (prepared_hierarchy_root_id = %d OR prepared_generation_token != %s) ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$batch = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$root->prepared_ancestry_path,
				$start,
				$generation_token,
				$root->id,
				$now,
				$like,
				$root->id,
				max( 0, $desc_cursor ),
				0,
				$generation_token,
				max( 1, $limit )
			)
		);
		if ( false === $batch ) {
			throw new \RuntimeException( 'Failed to stage prepared descendant ancestry for token ' . $generation_token . '.' );
		}
		$count = (int) $batch;
		if ( $count <= 0 ) {
			return [
				'count'   => 0,
				'last_id' => $desc_cursor,
				'scanned' => 0,
				'more'    => false,
			];
		}

		$high_sql = "SELECT * FROM `{$table}` WHERE prepared_generation_token = %s AND prepared_hierarchy_root_id = %d AND ancestry_path LIKE %s AND id != %d AND id > %d ORDER BY id DESC LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$high = $wpdb->get_row(
			$wpdb->prepare( $high_sql, $generation_token, $root->id, $like, $root->id, max( 0, $desc_cursor ) ),
			ARRAY_A
		);

		return [
			'count'   => $count,
			'last_id' => is_array( $high ) ? max( $desc_cursor, (int) ( $high['id'] ?? 0 ) ) : $desc_cursor,
			'scanned' => $count,
			'more'    => true,
		];
	}

	private function requires_descendant_hierarchy_preparation( CanonicalLocation $location, string $generation_token ): bool {
		if ( $location->prepared_generation_token !== $generation_token ) {
			return false;
		}
		if ( '' === $location->prepared_ancestry_path || '' === $location->ancestry_path ) {
			return false;
		}

		return $location->prepared_ancestry_path !== $location->ancestry_path;
	}

	private function has_unfinished_hierarchy_preparation( string $generation_token ): bool {
		if ( $this->has_unprocessed_generation_drafts( $generation_token ) ) {
			return true;
		}
		$after = 0;
		while ( true ) {
			$page = $this->list_prepared_moving_roots_page( $generation_token, $after, 50 );
			if ( [] === $page ) {
				return false;
			}
			foreach ( $page as $root ) {
				$after = $root->id;
				if ( $this->root_has_unprepared_descendants( $root, $generation_token ) ) {
					return true;
				}
			}
		}
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	private function list_hierarchy_changing_roots( string $generation_token ): array {
		$roots = [];
		$after = 0;
		while ( true ) {
			$page = $this->list_prepared_moving_roots_page( $generation_token, $after, 100 );
			if ( [] === $page ) {
				break;
			}
			foreach ( $page as $root ) {
				$roots[] = $root;
				$after   = $root->id;
			}
		}

		return $roots;
	}

	private function next_hierarchy_changing_draft( string $generation_token, int $after_id ): ?CanonicalLocation {
		global $wpdb;
		$table = $this->table_name();
		while ( true ) {
			$sql = "SELECT * FROM `{$table}` WHERE draft_generation_token = %s AND id > %d ORDER BY id ASC LIMIT 200";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token, max( 0, $after_id ) ), ARRAY_A );
			if ( ! is_array( $rows ) || [] === $rows ) {
				return null;
			}
			foreach ( $rows as $row ) {
				$location = CanonicalLocation::fromRow( $row );
				if ( $this->draft_changes_parent( $location ) ) {
					return $location;
				}
				$after_id = $location->id;
			}
		}
	}

	private function draft_changes_parent( CanonicalLocation $location ): bool {
		if ( '' === $location->draft_json ) {
			return false;
		}
		$draft = json_decode( $location->draft_json, true );
		if ( ! is_array( $draft ) || ! array_key_exists( 'parent_location_id', $draft ) ) {
			return false;
		}

		return (int) $draft['parent_location_id'] !== (int) ( $location->parent_location_id ?? 0 );
	}

	private function planned_ancestry_path( CanonicalLocation $location, string $generation_token ): string {
		return $this->future_graph_path( $location, $generation_token );
	}

	private function root_has_unprepared_descendants( CanonicalLocation $root, string $generation_token ): bool {
		if ( '' === $root->ancestry_path ) {
			return false;
		}
		$nested = $this->nested_moving_roots( $root, $generation_token );
		$after  = 0;
		while ( true ) {
			$batch = $this->list_live_descendants_page( $root, $after, 200 );
			if ( [] === $batch ) {
				return false;
			}
			foreach ( $batch as $child ) {
				$after = $child->id;
				if ( $this->owned_by_nested_root( $child, $nested ) ) {
					continue;
				}
				$suffix   = substr( $child->ancestry_path, strlen( $root->ancestry_path ) );
				$expected = $root->prepared_ancestry_path . $suffix;
				if ( $child->prepared_generation_token !== $generation_token
					|| $child->prepared_hierarchy_root_id !== $root->id
					|| $child->prepared_ancestry_path !== $expected ) {
					return true;
				}
			}
		}
	}

	private function prepared_hierarchy_is_inconsistent( string $generation_token ): bool {
		$roots = $this->list_hierarchy_changing_roots( $generation_token );
		$after = 0;
		while ( true ) {
			global $wpdb;
			$table = $this->table_name();
			$sql   = "SELECT * FROM `{$table}` WHERE prepared_generation_token = %s AND id > %d ORDER BY id ASC LIMIT 200";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token, $after ), ARRAY_A );
			if ( ! is_array( $rows ) || [] === $rows ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$location = CanonicalLocation::fromRow( $row );
				$after    = $location->id;
				$owner    = $this->nearest_moving_root( $location, $roots );
				$expected_owner = $owner instanceof CanonicalLocation ? $owner->id : 0;
				$expected_path  = $this->expected_prepared_path( $location, $owner, $generation_token );
				if ( $location->prepared_hierarchy_root_id !== $expected_owner || $location->prepared_ancestry_path !== $expected_path ) {
					return true;
				}
			}
		}
	}

	/**
	 * @param list<CanonicalLocation> $roots
	 */
	private function nearest_moving_root( CanonicalLocation $location, array $roots ): ?CanonicalLocation {
		$best     = null;
		$best_len = -1;
		foreach ( $roots as $root ) {
			if ( $root->id === $location->id ) {
				return $root;
			}
			if ( '' === $root->ancestry_path || ! str_starts_with( $location->ancestry_path, $root->ancestry_path ) ) {
				continue;
			}
			$len = strlen( $root->ancestry_path );
			if ( $len > $best_len ) {
				$best     = $root;
				$best_len = $len;
			}
		}

		return $best;
	}

	private function expected_prepared_path( CanonicalLocation $location, ?CanonicalLocation $owner, string $generation_token ): string {
		if ( $owner instanceof CanonicalLocation && $owner->id === $location->id ) {
			return $this->planned_ancestry_path( $location, $generation_token );
		}
		if ( $owner instanceof CanonicalLocation && '' !== $owner->ancestry_path ) {
			$owner_future = $this->planned_ancestry_path( $owner, $generation_token );

			return $owner_future . substr( $location->ancestry_path, strlen( $owner->ancestry_path ) );
		}

		return $this->planned_ancestry_path( $location, $generation_token );
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	private function nested_moving_roots( CanonicalLocation $root, string $generation_token ): array {
		if ( '' === $root->ancestry_path ) {
			return [];
		}

		global $wpdb;
		$table  = $this->table_name();
		$like   = $wpdb->esc_like( $root->ancestry_path ) . '%';
		$nested = [];
		$after  = 0;
		while ( true ) {
			$sql = "SELECT * FROM `{$table}` WHERE prepared_generation_token = %s AND prepared_hierarchy_root_id = id AND id != %d AND ancestry_path LIKE %s AND id > %d ORDER BY id ASC LIMIT 100";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $generation_token, $root->id, $like, $after ),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || [] === $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$candidate = CanonicalLocation::fromRow( $row );
				$after     = $candidate->id;
				if ( '' === $candidate->ancestry_path ) {
					continue;
				}
				$nested[] = $candidate;
			}
		}

		return $nested;
	}

	/**
	 * @param list<CanonicalLocation> $nested
	 */
	private function owned_by_nested_root( CanonicalLocation $child, array $nested ): bool {
		foreach ( $nested as $root ) {
			if ( $child->id === $root->id ) {
				return true;
			}
			if ( '' !== $root->ancestry_path && str_starts_with( $child->ancestry_path, $root->ancestry_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	private function list_live_descendants_page( CanonicalLocation $root, int $after_id, int $limit ): array {
		global $wpdb;
		$table = $this->table_name();
		$like  = $wpdb->esc_like( $root->ancestry_path ) . '%';
		$sql   = "SELECT * FROM `{$table}` WHERE ancestry_path LIKE %s AND id != %d AND id > %d ORDER BY id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $like, $root->id, max( 0, $after_id ), max( 1, $limit ) ),
			ARRAY_A
		);
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = CanonicalLocation::fromRow( $row );
		}

		return $out;
	}

	/**
	 * @return array{processed: int, done: bool, hierarchy_root_cursor: int, hierarchy_descendant_cursor: int, hierarchy_root_id: int}
	 */
	private function hierarchy_stage_result( int $processed, bool $done, int $root_cursor, int $desc_cursor, int $root_id ): array {
		return [
			'processed'                   => $processed,
			'done'                        => $done,
			'hierarchy_root_cursor'       => $root_cursor,
			'hierarchy_descendant_cursor' => $desc_cursor,
			'hierarchy_root_id'           => $root_id,
		];
	}

	private function apply_prepared_ancestry_set_based( string $generation_token, string $now ): void {
		global $wpdb;
		$table = $this->table_name();
		$sql   = "UPDATE `{$table}` SET ancestry_path = prepared_ancestry_path, prepared_ancestry_path = '', prepared_generation_token = '', prepared_hierarchy_root_id = 0, updated_at = %s WHERE prepared_generation_token = %s AND prepared_ancestry_path != ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $now, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to apply prepared ancestry paths for token ' . $generation_token . '.' );
		}
	}

	private function apply_drafts_set_based( string $generation_token, string $now ): void {
		global $wpdb;
		$table = $this->table_name();
		$sql   = "UPDATE `{$table}` SET
			canonical_name = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.canonical_name')), canonical_name),
			ascii_name = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.ascii_name')), ascii_name),
			normalized_name = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.normalized_name')), normalized_name),
			latitude = CASE
				WHEN JSON_EXTRACT(draft_json, '$.latitude') IS NULL THEN latitude
				WHEN JSON_TYPE(JSON_EXTRACT(draft_json, '$.latitude')) = 'NULL' THEN NULL
				ELSE JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.latitude'))
			END,
			longitude = CASE
				WHEN JSON_EXTRACT(draft_json, '$.longitude') IS NULL THEN longitude
				WHEN JSON_TYPE(JSON_EXTRACT(draft_json, '$.longitude')) = 'NULL' THEN NULL
				ELSE JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.longitude'))
			END,
			parent_location_id = CASE
				WHEN JSON_EXTRACT(draft_json, '$.parent_location_id') IS NULL THEN parent_location_id
				WHEN JSON_TYPE(JSON_EXTRACT(draft_json, '$.parent_location_id')) = 'NULL' THEN NULL
				ELSE JSON_UNQUOTE(JSON_EXTRACT(draft_json, '$.parent_location_id'))
			END,
			draft_json = NULL,
			draft_generation_token = '',
			updated_at = %s
			WHERE draft_generation_token = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $now, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to apply staged drafts for token ' . $generation_token . '.' );
		}
	}

	private function promote_staged_mappings_set_based( string $table, string $generation_token, string $now ): void {
		global $wpdb;
		$update = "UPDATE `{$table}` live INNER JOIN `{$table}` staged
			ON live.`provider` = staged.`provider`
			AND live.`external_id` = staged.`external_id`
			AND live.`generation_token` = ''
			AND staged.`generation_token` = %s
			SET live.`location_id` = staged.`location_id`,
				live.`pack_id` = staged.`pack_id`,
				live.`dataset_version` = staged.`dataset_version`,
				live.`provider_parent_reference` = staged.`provider_parent_reference`,
				live.`feature_class` = staged.`feature_class`,
				live.`feature_code` = staged.`feature_code`,
				live.`provider_metadata_json` = staged.`provider_metadata_json`,
				live.`updated_at` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $update, $generation_token, $now ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to merge staged mappings for token ' . $generation_token . '.' );
		}

		$insert = "INSERT INTO `{$table}` (`location_id`, `provider`, `external_id`, `pack_id`, `dataset_version`, `provider_parent_reference`, `feature_class`, `feature_code`, `provider_metadata_json`, `generation_token`, `created_at`, `updated_at`)
			SELECT staged.`location_id`, staged.`provider`, staged.`external_id`, staged.`pack_id`, staged.`dataset_version`, staged.`provider_parent_reference`, staged.`feature_class`, staged.`feature_code`, staged.`provider_metadata_json`, '', staged.`created_at`, %s
			FROM `{$table}` staged
			WHERE staged.`generation_token` = %s
			AND NOT EXISTS (
				SELECT 1 FROM `{$table}` live
				WHERE live.`provider` = staged.`provider`
				AND live.`external_id` = staged.`external_id`
				AND live.`generation_token` = ''
			)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $insert, $now, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to insert live mappings for token ' . $generation_token . '.' );
		}

		$sql = "DELETE FROM `{$table}` WHERE `generation_token` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to delete staged mappings for token ' . $generation_token . '.' );
		}
	}

	private function promote_staged_aliases_set_based( string $table, string $generation_token, string $now ): void {
		global $wpdb;
		$update = "UPDATE `{$table}` live INNER JOIN `{$table}` staged
			ON live.`location_id` = staged.`location_id`
			AND live.`normalized_alias` = staged.`normalized_alias`
			AND live.`generation_token` = ''
			AND staged.`generation_token` = %s
			SET live.`alias` = staged.`alias`,
				live.`alias_type` = staged.`alias_type`,
				live.`status` = %s,
				live.`updated_at` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $update, $generation_token, RecordStatus::Active->value, $now ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to merge staged aliases for token ' . $generation_token . '.' );
		}

		$insert = "INSERT INTO `{$table}` (`location_id`, `alias`, `normalized_alias`, `language_code`, `alias_type`, `is_preferred`, `status`, `generation_token`, `created_at`, `updated_at`)
			SELECT staged.`location_id`, staged.`alias`, staged.`normalized_alias`, staged.`language_code`, staged.`alias_type`, staged.`is_preferred`, %s, '', staged.`created_at`, %s
			FROM `{$table}` staged
			WHERE staged.`generation_token` = %s
			AND NOT EXISTS (
				SELECT 1 FROM `{$table}` live
				WHERE live.`location_id` = staged.`location_id`
				AND live.`normalized_alias` = staged.`normalized_alias`
				AND live.`generation_token` = ''
			)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $insert, RecordStatus::Active->value, $now, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to insert live aliases for token ' . $generation_token . '.' );
		}

		$sql = "DELETE FROM `{$table}` WHERE `generation_token` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $generation_token ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to delete staged aliases for token ' . $generation_token . '.' );
		}
	}

	private function write_staged_alias( int $location_id, string $alias, string $normalized, string $type, string $generation_token ): void {
		global $wpdb;
		$table = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$sql   = "SELECT * FROM `{$table}` WHERE location_id = %d AND normalized_alias = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $location_id, $normalized, $generation_token ), ARRAY_A );
		$row      = [
			'location_id'      => $location_id,
			'alias'            => $alias,
			'normalized_alias' => $normalized,
			'language_code'    => '',
			'alias_type'       => $type,
			'is_preferred'     => 0,
			'status'           => RecordStatus::Inactive->value,
			'generation_token' => $generation_token,
			'updated_at'       => $now,
		];
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to write staged location alias.' );
		}
	}

	/**
	 * @param array<string, mixed> $mapping
	 */
	private function write_staged_mapping( int $location_id, array $mapping, string $generation_token ): void {
		$provider = (string) ( $mapping['provider'] ?? GeographyProvider::GeoNames->value );
		$external = trim( (string) ( $mapping['external_id'] ?? '' ) );
		if ( '' === $external ) {
			return;
		}
		global $wpdb;
		$table   = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( is_array( $mapping['metadata'] ?? null ) ? $mapping['metadata'] : [] ) : json_encode( is_array( $mapping['metadata'] ?? null ) ? $mapping['metadata'] : [] );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}
		$pack_id = isset( $mapping['pack_id'] ) && '' !== (string) $mapping['pack_id'] ? (int) $mapping['pack_id'] : null;
		$sql     = "SELECT * FROM `{$table}` WHERE provider = %s AND external_id = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $provider, $external, $generation_token ), ARRAY_A );
		$row      = [
			'location_id'               => $location_id,
			'provider'                  => $provider,
			'external_id'               => $external,
			'pack_id'                   => $pack_id,
			'dataset_version'           => (string) ( $mapping['dataset_version'] ?? '' ),
			'provider_parent_reference' => (string) ( $mapping['provider_parent_reference'] ?? '' ),
			'feature_class'             => (string) ( $mapping['feature_class'] ?? '' ),
			'feature_code'              => (string) ( $mapping['feature_code'] ?? '' ),
			'provider_metadata_json'    => $encoded,
			'generation_token'          => $generation_token,
			'updated_at'                => $now,
		];
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to write staged provider mapping.' );
		}
	}

	/**
	 * @param array<string, mixed> $draft
	 */
	private function apply_draft( CanonicalLocation $existing, array $draft ): void {
		$name   = trim( (string) ( $draft['canonical_name'] ?? $existing->canonical_name ) );
		$parent = isset( $draft['parent_location_id'] ) ? (int) $draft['parent_location_id'] : $existing->parent_location_id;
		$old    = $existing->ancestry_path;
		$this->save(
			new CanonicalLocation(
				$existing->id,
				$existing->location_key,
				$existing->country_code,
				( $parent ?? 0 ) > 0 ? $parent : null,
				$existing->location_type,
				$existing->administrative_level,
				'' !== $name ? $name : $existing->canonical_name,
				GeographyNameNormalizer::normalize( '' !== $name ? $name : $existing->canonical_name ),
				(string) ( $draft['ascii_name'] ?? $existing->ascii_name ),
				isset( $draft['latitude'] ) ? (float) $draft['latitude'] : $existing->latitude,
				isset( $draft['longitude'] ) ? (float) $draft['longitude'] : $existing->longitude,
				$existing->status,
				$existing->ancestry_path,
				$existing->generation,
				'',
				$existing->generation_token,
				''
			)
		);
		$former = trim( (string) ( $draft['former_name'] ?? '' ) );
		if ( '' !== $former ) {
			$this->write_live_alias( $existing->id, $former, GeographyNameNormalizer::normalize( $former ), 'former_name' );
		}
		foreach ( $draft['aliases'] ?? [] as $alias_row ) {
			if ( ! is_array( $alias_row ) ) {
				continue;
			}
			$alias = trim( (string) ( $alias_row['alias'] ?? '' ) );
			$norm  = trim( (string) ( $alias_row['normalized'] ?? GeographyNameNormalizer::normalize( $alias ) ) );
			if ( '' === $alias || '' === $norm ) {
				continue;
			}
			$this->write_live_alias( $existing->id, $alias, $norm, (string) ( $alias_row['type'] ?? 'alternate' ) );
		}
		foreach ( $draft['mappings'] ?? [] as $mapping ) {
			if ( is_array( $mapping ) ) {
				$this->write_live_mapping( $existing->id, $mapping );
			}
		}
		if ( ( $parent ?? 0 ) > 0 && $parent !== $existing->parent_location_id ) {
			$parent_loc = $this->find_by_id( (int) $parent );
			if ( $parent_loc instanceof CanonicalLocation ) {
				$new_path = LocationAncestry::append_path( $parent_loc->ancestry_path, $existing->id );
				$this->update_ancestry_path( $existing->id, $new_path );
				if ( '' !== $old && $old !== $new_path ) {
					$this->rebuild_descendant_ancestry( $existing->id, $old, $new_path );
				}
			}
		}
	}

	private function activate_remaining_staged_mappings( string $generation_token ): void {
		global $wpdb;
		$mappings = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$sql      = "SELECT * FROM `{$mappings}` WHERE generation_token = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$location_id = (int) ( $row['location_id'] ?? 0 );
			if ( $location_id <= 0 ) {
				continue;
			}
			$this->write_live_mapping(
				$location_id,
				[
					'provider'                  => (string) ( $row['provider'] ?? '' ),
					'external_id'               => (string) ( $row['external_id'] ?? '' ),
					'pack_id'                   => $row['pack_id'] ?? null,
					'dataset_version'           => (string) ( $row['dataset_version'] ?? '' ),
					'provider_parent_reference' => (string) ( $row['provider_parent_reference'] ?? '' ),
					'feature_class'             => (string) ( $row['feature_class'] ?? '' ),
					'feature_code'              => (string) ( $row['feature_code'] ?? '' ),
					'metadata'                  => json_decode( (string) ( $row['provider_metadata_json'] ?? '{}' ), true ),
				]
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $mappings, [ 'id' => (int) ( $row['id'] ?? 0 ) ] );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to delete staged mapping ' . (int) ( $row['id'] ?? 0 ) . '.' );
			}
		}
	}

	private function activate_staged_side_effects( CanonicalLocation $location, string $generation_token ): void {
		global $wpdb;
		$mappings = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$sql      = "SELECT * FROM `{$mappings}` WHERE generation_token = %s AND location_id = %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token, $location->id ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$this->write_live_mapping(
				$location->id,
				[
					'provider'                   => (string) ( $row['provider'] ?? '' ),
					'external_id'                => (string) ( $row['external_id'] ?? '' ),
					'pack_id'                    => $row['pack_id'] ?? null,
					'dataset_version'            => (string) ( $row['dataset_version'] ?? '' ),
					'provider_parent_reference'  => (string) ( $row['provider_parent_reference'] ?? '' ),
					'feature_class'              => (string) ( $row['feature_class'] ?? '' ),
					'feature_code'               => (string) ( $row['feature_code'] ?? '' ),
					'metadata'                   => json_decode( (string) ( $row['provider_metadata_json'] ?? '{}' ), true ),
				]
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $mappings, [ 'id' => (int) ( $row['id'] ?? 0 ) ] );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to delete staged mapping ' . (int) ( $row['id'] ?? 0 ) . '.' );
			}
		}
	}

	private function activate_remaining_staged_aliases( string $generation_token ): void {
		global $wpdb;
		$aliases = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$sql     = "SELECT * FROM `{$aliases}` WHERE generation_token = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $generation_token ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$location_id = (int) ( $row['location_id'] ?? 0 );
			$alias       = (string) ( $row['alias'] ?? '' );
			$normalized  = (string) ( $row['normalized_alias'] ?? '' );
			if ( $location_id <= 0 || '' === $alias || '' === $normalized ) {
				continue;
			}
			$this->write_live_alias( $location_id, $alias, $normalized, (string) ( $row['alias_type'] ?? 'alternate' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $aliases, [ 'id' => (int) ( $row['id'] ?? 0 ) ] );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Failed to delete staged alias ' . (int) ( $row['id'] ?? 0 ) . '.' );
			}
		}
	}

	private function write_live_alias( int $location_id, string $alias, string $normalized, string $type ): void {
		global $wpdb;
		$table = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$sql   = "SELECT * FROM `{$table}` WHERE location_id = %d AND normalized_alias = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $location_id, $normalized, '' ), ARRAY_A );
		$row      = [
			'location_id'      => $location_id,
			'alias'            => $alias,
			'normalized_alias' => $normalized,
			'language_code'    => '',
			'alias_type'       => $type,
			'is_preferred'     => 0,
			'status'           => RecordStatus::Active->value,
			'generation_token' => '',
			'updated_at'       => $now,
		];
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to write location alias.' );
		}
	}

	/**
	 * @param array<string, mixed> $mapping
	 */
	private function write_live_mapping( int $location_id, array $mapping ): void {
		$provider = (string) ( $mapping['provider'] ?? GeographyProvider::GeoNames->value );
		$external = trim( (string) ( $mapping['external_id'] ?? '' ) );
		if ( '' === $external ) {
			return;
		}
		global $wpdb;
		$table   = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( is_array( $mapping['metadata'] ?? null ) ? $mapping['metadata'] : [] ) : json_encode( is_array( $mapping['metadata'] ?? null ) ? $mapping['metadata'] : [] );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}
		$pack_id = isset( $mapping['pack_id'] ) && '' !== (string) $mapping['pack_id'] ? (int) $mapping['pack_id'] : null;
		$sql     = "SELECT * FROM `{$table}` WHERE provider = %s AND external_id = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $provider, $external, '' ), ARRAY_A );
		$row      = [
			'location_id'               => $location_id,
			'provider'                  => $provider,
			'external_id'               => $external,
			'pack_id'                   => $pack_id,
			'dataset_version'           => (string) ( $mapping['dataset_version'] ?? '' ),
			'provider_parent_reference' => (string) ( $mapping['provider_parent_reference'] ?? '' ),
			'feature_class'             => (string) ( $mapping['feature_class'] ?? '' ),
			'feature_code'              => (string) ( $mapping['feature_code'] ?? '' ),
			'provider_metadata_json'    => $encoded,
			'generation_token'          => '',
			'updated_at'                => $now,
		];
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to write provider mapping.' );
		}
	}

	private function rebuild_descendant_batch( int $root_id, string $old_path, string $new_path, int $limit ): int {
		global $wpdb;
		$table = $this->table_name();
		$like  = $wpdb->esc_like( $old_path ) . '%';
		$sql   = "SELECT id, ancestry_path FROM `{$table}` WHERE id != %d AND ancestry_path LIKE %s LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $root_id, $like, $limit ), ARRAY_A );
		$updated = 0;
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$path = (string) ( $row['ancestry_path'] ?? '' );
			if ( ! str_starts_with( $path, $old_path ) ) {
				continue;
			}
			$suffix = substr( $path, strlen( $old_path ) );
			$this->update_ancestry_path( (int) ( $row['id'] ?? 0 ), $new_path . $suffix );
			++$updated;
		}

		return $updated;
	}

	private function descendant_prefix( int $parent_id ): string {
		global $wpdb;
		$parent = $this->find_by_id( $parent_id );
		$path   = LocationAncestry::descendant_like_prefix( $parent instanceof CanonicalLocation ? $parent->ancestry_path : '', $parent_id );

		return $wpdb->esc_like( $path ) . '%';
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
