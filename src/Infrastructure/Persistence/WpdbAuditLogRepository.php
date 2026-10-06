<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;

final class WpdbAuditLogRepository extends AbstractWpdbRepository implements AuditLogRepositoryInterface {

	protected function table_suffix(): string {
		return 'audit_log';
	}

	public function findById( int $id ): ?array {
		return $this->fetch_row_by_id( $id );
	}

	public function append( array $data ): int {
		$action      = trim( (string) ( $data['action'] ?? '' ) );
		$entity_type = trim( (string) ( $data['entity_type'] ?? '' ) );

		if ( '' === $action ) {
			throw new \InvalidArgumentException( 'Audit log action must not be empty.' );
		}

		if ( '' === $entity_type ) {
			throw new \InvalidArgumentException( 'Audit log entity_type must not be empty.' );
		}

		global $wpdb;

		$table = $this->table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$row = [
			'actor_user_id'  => $this->nullable_positive_int( $data['actor_user_id'] ?? null ),
			'action'         => $action,
			'entity_type'    => $entity_type,
			'entity_id'      => $this->nullable_positive_int( $data['entity_id'] ?? null ),
			'previous_value' => $this->nullable_audit_value( $data['previous_value'] ?? null ),
			'new_value'      => $this->nullable_audit_value( $data['new_value'] ?? null ),
			'site_context'   => $this->nullable_string( $data['site_context'] ?? null, 255 ),
			'created_at'     => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			$row,
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	public function findByRequestToken( string $request_token ): ?array {
		$request_token = trim( $request_token );
		if ( '' === $request_token ) {
			return null;
		}

		global $wpdb;

		$table = $this->table_name();
		$sql   = "SELECT * FROM `{$table}` WHERE JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.request_token')) = %s ORDER BY id DESC LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $request_token ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	public function list( array $criteria = [] ): array {
		global $wpdb;

		$table = $this->table_name();
		$where = '1=1';
		$args  = [];

		if ( isset( $criteria['entity_type'] ) ) {
			$where .= ' AND entity_type = %s';
			$args[] = (string) $criteria['entity_type'];
		}

		if ( isset( $criteria['entity_id'] ) ) {
			$where .= ' AND entity_id = %d';
			$args[] = (int) $criteria['entity_id'];
		}

		$limit  = max( 1, min( 500, (int) ( $criteria['limit'] ?? 100 ) ) );
		$sql    = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d";
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		if ( $int <= 0 ) {
			throw new \InvalidArgumentException( 'Audit log ID fields must be positive integers when provided.' );
		}

		return $int;
	}

	private function nullable_string( mixed $value, int $max_length ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		if ( '' === $string ) {
			return null;
		}

		if ( strlen( $string ) > $max_length ) {
			$string = substr( $string, 0, $max_length );
		}

		return $string;
	}

	private function nullable_audit_value( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$value = wp_json_encode( $this->redact_sensitive_audit_fields( $value ) );
		}

		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	private function redact_sensitive_audit_fields( array $payload ): array {
		$blocked_keys = [
			'supplier',
			'origin',
			'supplier_id',
			'origin_id',
			'internal_cost',
			'payment_token',
			'card_number',
			'password',
		];

		foreach ( $blocked_keys as $key ) {
			unset( $payload[ $key ] );
		}

		return $payload;
	}
}
