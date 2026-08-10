<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardAmountFormatter;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * Rate card persistence for admin configuration only.
 *
 * Must not be read by customer-facing templates, cart/checkout flows,
 * WooCommerce shipping methods, REST/Store API responses, or emails.
 */
final class WpdbRateCardRepository extends AbstractWpdbRepository implements RateCardRepositoryInterface {

	protected function table_suffix(): string {
		return 'rate_cards';
	}

	public function findById( int $id ): ?array {
		return $this->fetch_row_by_id( $id );
	}

	public function findByCode( string $code ): ?array {
		return $this->fetch_row_by_code( $code );
	}

	public function save( array $data ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = isset( $data['id'] ) ? (int) $data['id'] : 0;

		$row = [
			'internal_code'        => (string) ( $data['internal_code'] ?? '' ),
			'delivery_offer_id'    => (int) ( $data['delivery_offer_id'] ?? 0 ),
			'destination_zone_id'  => (int) ( $data['destination_zone_id'] ?? 0 ),
			'logistics_profile_id' => $this->nullable_positive_int( $data['logistics_profile_id'] ?? null ),
			'supplier_id'          => $this->nullable_positive_int( $data['supplier_id'] ?? null ),
			'origin_id'            => $this->nullable_positive_int( $data['origin_id'] ?? null ),
			'charge_type'          => (string) ( $data['charge_type'] ?? '' ),
			'base_amount'          => RateCardAmountFormatter::format( $this->require_base_amount( $data ) ),
			'base_currency'        => strtoupper( trim( (string) ( $data['base_currency'] ?? '' ) ) ),
			'priority'             => (int) ( $data['priority'] ?? 100 ),
			'effective_from'       => $this->nullable_datetime( $data['effective_from'] ?? null ),
			'effective_to'         => $this->nullable_datetime( $data['effective_to'] ?? null ),
			'status'               => (string) ( $data['status'] ?? 'active' ),
			'updated_at'           => $now,
		];

		$formats = [ '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%f', '%s', '%d', '%s', '%s', '%s', '%s' ];

		if ( $id > 0 ) {
			if ( ! $this->update_row( $id, $row, $formats ) ) {
				return 0;
			}

			return $id;
		}

		$row['created_at'] = $now;
		$insert_formats    = array_merge( $formats, [ '%s' ] );

		$insert_id = $this->insert_row( $row, $insert_formats );

		return $insert_id > 0 ? $insert_id : 0;
	}

	public function list( array $criteria = [] ): array {
		return $this->fetch_list( $criteria, (int) ( $criteria['limit'] ?? 500 ) );
	}

	public function softDelete( int $id ): bool {
		return $this->mark_inactive( $id );
	}

	public function hardDelete( int $id ): bool {
		return $this->delete_row_by_id( $id );
	}

	public function count_all(): int {
		return parent::count_all();
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		return $this->count_where( 'delivery_offer_id', $delivery_offer_id );
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		return $this->count_where( 'destination_zone_id', $destination_zone_id );
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		global $wpdb;

		if ( $rate_card_id <= 0 ) {
			return 0;
		}

		$patterns = [
			'%"rate_card_id":' . $rate_card_id . ',%',
			'%"rate_card_id":' . $rate_card_id . '}',
			'%"rate_card_id": ' . $rate_card_id . ',%',
			'%"rate_card_id": ' . $rate_card_id . '}',
		];

		$total = 0;

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
					OrderDeliverySnapshot::META_LINE_SNAPSHOT,
					$pattern
				)
			);

			$total = max( $total, $count );
		}

		return $total;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		return $this->count_where( 'logistics_profile_id', $logistics_profile_id );
	}

	public function listActiveForQuoteMatch(
		int $delivery_offer_id,
		int $destination_zone_id,
		string $currency_code
	): array {
		global $wpdb;

		$table         = $this->table_name();
		$currency_code = strtoupper( trim( $currency_code ) );
		$sql           = "SELECT * FROM `{$table}` WHERE status = %s AND delivery_offer_id = %d AND destination_zone_id = %d AND base_currency = %s ORDER BY priority ASC, id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				RecordStatus::Active->value,
				$delivery_offer_id,
				$destination_zone_id,
				$currency_code
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}

	private function nullable_datetime( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		if ( '' === $string ) {
			return null;
		}

		$timestamp = strtotime( $string );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function require_base_amount( array $data ): mixed {
		if ( ! array_key_exists( 'base_amount', $data ) ) {
			throw new \InvalidArgumentException( 'Rate card base_amount is required and must be numeric.' );
		}

		return $data['base_amount'];
	}
}
