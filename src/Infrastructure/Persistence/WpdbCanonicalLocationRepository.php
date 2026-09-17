<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
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
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
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
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
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
			$sql    .= " AND (normalized_name LIKE %s OR ascii_name LIKE %s OR EXISTS (SELECT 1 FROM `{$aliases}` als WHERE als.location_id = `{$table}`.id AND als.status = %s AND als.normalized_alias LIKE %s))";
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = RecordStatus::Active->value;
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
			'generation'          => $location->generation,
			'generation_token'    => $location->generation_token,
			'draft_json'          => $location->draft_json,
			'updated_at'          => $now,
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
		$wpdb->update(
			$table,
			[
				'ancestry_path' => $path,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
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

	public function promote_generation( string $generation_token ): int {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			throw new \RuntimeException( 'Promotion requires a generation token.' );
		}

		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			$sql = "SELECT * FROM `{$table}` WHERE status = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$active_rows = $wpdb->get_results( $wpdb->prepare( $sql, RecordStatus::Active->value ), ARRAY_A );
			foreach ( is_array( $active_rows ) ? $active_rows : [] as $row ) {
				$location = CanonicalLocation::fromRow( $row );
				$draft    = '' !== $location->draft_json ? json_decode( $location->draft_json, true ) : null;
				if ( ! is_array( $draft ) || (string) ( $draft['generation_token'] ?? '' ) !== $generation_token ) {
					continue;
				}
				$this->apply_draft( $location, $draft );
			}

			$sql = "SELECT * FROM `{$table}` WHERE generation_token = %s AND status = %s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$inactive_rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $generation_token, RecordStatus::Inactive->value ),
				ARRAY_A
			);
			$activated = 0;
			foreach ( is_array( $inactive_rows ) ? $inactive_rows : [] as $row ) {
				$location = CanonicalLocation::fromRow( $row );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$table,
					[
						'status'     => RecordStatus::Active->value,
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					],
					[ 'id' => $location->id ]
				);
				if ( false === $result ) {
					throw new \RuntimeException( 'Failed to activate staged location ' . $location->id . '.' );
				}
				$this->activate_staged_side_effects( $location, $generation_token );
				++$activated;
			}
			$this->activate_remaining_staged_mappings( $generation_token );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );

			return $activated;
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
		foreach ( $this->list_children( $parent_id, GeographyLocationType::Administrative, 250, 0 ) as $child ) {
			if ( null !== $level && $level > 0 && $child->administrative_level !== $level ) {
				continue;
			}
			if ( GeographyNameNormalizer::administrative_core( $child->canonical_name ) === $core
				|| GeographyNameNormalizer::administrative_core( $child->ascii_name ) === $core
				|| GeographyNameNormalizer::administrative_core( $child->normalized_name ) === $core ) {
				$matches[ $child->id ] = $child;
			}
		}

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
				$existing->generation_token
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
			$wpdb->delete( $mappings, [ 'id' => (int) ( $row['id'] ?? 0 ) ] );
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
			$wpdb->delete( $mappings, [ 'id' => (int) ( $row['id'] ?? 0 ) ] );
		}
	}

	private function write_live_alias( int $location_id, string $alias, string $normalized, string $type ): void {
		global $wpdb;
		$table = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (location_id, alias, normalized_alias, language_code, alias_type, is_preferred, status, generation_token, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE alias = VALUES(alias), updated_at = VALUES(updated_at), status = VALUES(status), generation_token = VALUES(generation_token)",
				$location_id,
				$alias,
				$normalized,
				'',
				$type,
				0,
				RecordStatus::Active->value,
				'',
				$now,
				$now
			)
		);
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (location_id, provider, external_id, pack_id, dataset_version, provider_parent_reference, feature_class, feature_code, provider_metadata_json, generation_token, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE location_id = VALUES(location_id), pack_id = VALUES(pack_id), dataset_version = VALUES(dataset_version), provider_parent_reference = VALUES(provider_parent_reference), feature_class = VALUES(feature_class), feature_code = VALUES(feature_code), provider_metadata_json = VALUES(provider_metadata_json), updated_at = VALUES(updated_at)",
				$location_id,
				$provider,
				$external,
				$pack_id,
				(string) ( $mapping['dataset_version'] ?? '' ),
				(string) ( $mapping['provider_parent_reference'] ?? '' ),
				(string) ( $mapping['feature_class'] ?? '' ),
				(string) ( $mapping['feature_code'] ?? '' ),
				$encoded,
				'',
				$now,
				$now
			)
		);
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
