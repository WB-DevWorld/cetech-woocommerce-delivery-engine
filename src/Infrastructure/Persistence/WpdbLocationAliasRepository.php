<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;

final class WpdbLocationAliasRepository implements LocationAliasRepositoryInterface {

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations
	) {
	}

	public function find_exact( string $country_code, string $normalized_alias, ?int $parent_id = null ): ?CanonicalLocation {
		$country_code      = strtoupper( trim( $country_code ) );
		$normalized_alias  = trim( $normalized_alias );
		if ( '' === $country_code || '' === $normalized_alias ) {
			return null;
		}

		global $wpdb;
		$aliases   = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$locations = TableNames::for( GeographySchema::LOCATIONS_SUFFIX );
		$sql       = "SELECT loc.* FROM `{$aliases}` als INNER JOIN `{$locations}` loc ON loc.id = als.location_id
			WHERE als.normalized_alias = %s AND als.status = %s AND loc.country_code = %s AND loc.status = %s";
		$args      = [ $normalized_alias, RecordStatus::Active->value, $country_code, RecordStatus::Active->value ];
		if ( null !== $parent_id && $parent_id > 0 ) {
			$sql   .= ' AND (loc.parent_location_id = %d OR loc.ancestry_path LIKE %s)';
			$args[] = $parent_id;
			$parent = $this->locations->find_by_id( $parent_id );
			$path   = $parent instanceof CanonicalLocation && '' !== $parent->ancestry_path
				? $parent->ancestry_path
				: '/' . $parent_id . '/';
			$args[] = $wpdb->esc_like( $path ) . '%';
		}
		$sql .= ' LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	public function list_for_location( int $location_id ): array {
		if ( $location_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$sql   = "SELECT alias FROM `{$table}` WHERE location_id = %d AND status = %s ORDER BY is_preferred DESC, alias ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $location_id, RecordStatus::Active->value ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
	}

	public function add_alias( int $location_id, string $alias, string $normalized_alias, string $language_code = '', string $alias_type = 'alternate', bool $preferred = false ): void {
		if ( $location_id <= 0 || '' === $normalized_alias ) {
			return;
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::ALIASES_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (location_id, alias, normalized_alias, language_code, alias_type, is_preferred, status, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE alias = VALUES(alias), updated_at = VALUES(updated_at), status = VALUES(status)",
				$location_id,
				$alias,
				$normalized_alias,
				$language_code,
				$alias_type,
				$preferred ? 1 : 0,
				RecordStatus::Active->value,
				$now,
				$now
			)
		);
	}
}
