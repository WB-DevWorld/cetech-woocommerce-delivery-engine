<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\RecordStatus;

/**
 * Shared helpers for wpdb-backed configuration repositories.
 */
abstract class AbstractWpdbRepository {

	protected const SAVE_NOT_IMPLEMENTED_MESSAGE = 'Repository save() is not implemented until Phase 2B CRUD.';

	abstract protected function table_suffix(): string;

	protected function throw_save_not_implemented(): never {
		throw new \BadMethodCallException( self::SAVE_NOT_IMPLEMENTED_MESSAGE );
	}

	protected function table_name(): string {
		return TableNames::for( $this->table_suffix() );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function fetch_row_by_id( int $id ): ?array {
		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function fetch_row_by_code( string $code ): ?array {
		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE internal_code = %s LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $code ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function fetch_list( array $criteria = [], int $limit = 100 ): array {
		global $wpdb;

		$table = $this->table_name();
		$where = '1=1';
		$args  = [];

		if ( isset( $criteria['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $criteria['status'];
		}

		$sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id ASC LIMIT %d";
		$args[] = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Keyset page for complete iteration. $limit is the page size only — it does not
	 * cap the total matching set. Admin list() remains a separate bounded screen page.
	 *
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array {
		global $wpdb;

		$table = $this->table_name();
		$where = 'id > %d';
		$args  = [ max( 0, $after_id ) ];

		if ( isset( $criteria['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $criteria['status'];
		}

		$sql    = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id ASC LIMIT %d";
		$args[] = max( 1, min( 250, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	protected function mark_inactive( int $id ): bool {
		$existing = $this->fetch_row_by_id( $id );

		if ( null === $existing ) {
			return false;
		}

		if ( RecordStatus::Inactive->value === (string) ( $existing['status'] ?? '' ) ) {
			return true;
		}

		return $this->set_status( $id, RecordStatus::Inactive->value );
	}

	protected function set_status( int $id, string $status ): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->update(
			$table,
			[
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return false;
		}

		if ( 0 === $updated ) {
			$row = $this->fetch_row_by_id( $id );

			return null !== $row && (string) ( $row['status'] ?? '' ) === $status;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $formats
	 */
	protected function insert_row( array $row, array $formats ): int {
		global $wpdb;

		[ $row, $formats ] = $this->without_nulls( $row, $formats );
		$table             = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $row, $formats );

		if ( false === $inserted ) {
			return 0;
		}

		$insert_id = (int) $wpdb->insert_id;

		return $insert_id > 0 ? $insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $formats
	 */
	protected function update_row( int $id, array $row, array $formats ): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->update(
			$table,
			$row,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		if ( false === $updated ) {
			return false;
		}

		if ( 0 === $updated ) {
			return null !== $this->fetch_row_by_id( $id );
		}

		return true;
	}

	public function count_all(): int {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	protected function delete_row_by_id( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		return false !== $deleted && $deleted > 0;
	}

	protected function count_where( string $column, int $value ): int {
		global $wpdb;

		if ( $value <= 0 ) {
			return 0;
		}

		$table = $this->table_name();
		$sql   = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $value ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $formats
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	private function without_nulls( array $row, array $formats ): array {
		$clean = [];
		$fmt   = [];
		$index = 0;
		foreach ( $row as $key => $value ) {
			if ( null !== $value ) {
				$clean[ $key ] = $value;
				$fmt[]         = $formats[ $index ] ?? '%s';
			}
			++$index;
		}

		return [ $clean, $fmt ];
	}
}
