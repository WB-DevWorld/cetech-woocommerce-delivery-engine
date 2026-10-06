<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;

final class WpdbDestinationRuleRepository extends AbstractWpdbRepository implements DestinationRuleRepositoryInterface {

	protected function table_suffix(): string {
		return 'destination_rules';
	}

	public function listByZoneId( int $zone_id ): array {
		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE zone_id = %d ORDER BY priority ASC, id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $zone_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	public function deleteByZoneId( int $zone_id ): bool {
		global $wpdb;

		$table = $this->table_name();
		$sql   = "DELETE FROM `{$table}` WHERE zone_id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $zone_id ) );

		return false !== $result;
	}

	public function replaceForZone( int $zone_id, array $rules ): bool {
		global $wpdb;

		$this->throw_if_transaction_unresolved();
		$joined = $this->transaction_is_open();
		if ( ! $joined && ! $this->open_owned_transaction() ) {
			return false;
		}

		self::probe_transaction( 'after_transaction_open' );

		if ( ! $this->deleteByZoneId( $zone_id ) ) {
			$this->remember_transaction_cause( trim( (string) $wpdb->last_error ) );
			$this->rollback_owned_transaction();

			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rules as $rule ) {
			if ( ! $this->insert_rule_row( $zone_id, $rule, $now ) ) {
				$this->remember_transaction_cause( trim( (string) $wpdb->last_error ) );
				$this->rollback_owned_transaction();

				return false;
			}
		}

		if ( $joined ) {
			return true;
		}
		if ( ! $this->commit_owned_transaction() ) {
			$this->rollback_owned_transaction();

			return false;
		}

		return true;
	}

	public function count_all(): int {
		return parent::count_all();
	}

	public function list( int $limit = 500 ): array {
		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` ORDER BY zone_id ASC, id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, max( 1, min( 500, $limit ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function insert_rule_row( int $zone_id, array $rule, string $now ): bool {
		global $wpdb;

		$table = $this->table_name();
		$row   = [
			'zone_id'    => $zone_id,
			'rule_type'  => (string) ( $rule['rule_type'] ?? '' ),
			'rule_value' => (string) ( $rule['rule_value'] ?? '' ),
			'match_mode' => (string) ( $rule['match_mode'] ?? 'exact' ),
			'priority'   => (int) ( $rule['priority'] ?? 100 ),
			'created_at' => $now,
			'updated_at' => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			$row,
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id > 0;
	}
}
