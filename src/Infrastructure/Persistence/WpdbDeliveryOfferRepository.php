<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Configuration\CatalogReadinessSignal;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;

final class WpdbDeliveryOfferRepository extends AbstractWpdbRepository implements DeliveryOfferRepositoryInterface {

	protected function table_suffix(): string {
		return 'delivery_offers';
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
			'internal_code'          => (string) ( $data['internal_code'] ?? '' ),
			'internal_name'          => (string) ( $data['internal_name'] ?? '' ),
			'public_label'           => (string) ( $data['public_label'] ?? '' ),
			'route'                  => (string) ( $data['route'] ?? '' ),
			'service_level'          => (string) ( $data['service_level'] ?? '' ),
			'carrier_visibility'     => (string) ( $data['carrier_visibility'] ?? 'assigned_by_store' ),
			'carrier_name'           => $this->nullable_string( $data['carrier_name'] ?? null, 255 ),
			'public_description'     => $this->nullable_string( $data['public_description'] ?? null ),
			'default_processing_min' => $this->nullable_int( $data['default_processing_min'] ?? null ),
			'default_processing_max' => $this->nullable_int( $data['default_processing_max'] ?? null ),
			'default_transit_min'    => $this->nullable_int( $data['default_transit_min'] ?? null ),
			'default_transit_max'    => $this->nullable_int( $data['default_transit_max'] ?? null ),
			'default_final_mile_min' => $this->nullable_int( $data['default_final_mile_min'] ?? null ),
			'default_final_mile_max' => $this->nullable_int( $data['default_final_mile_max'] ?? null ),
			'display_priority'       => (int) ( $data['display_priority'] ?? 100 ),
			'status'                 => (string) ( $data['status'] ?? 'active' ),
			'updated_at'             => $now,
		];

		$formats = [
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s',
		];

		if ( $id > 0 ) {
			if ( ! $this->update_row( $id, $row, $formats ) ) {
				return 0;
			}

			CatalogReadinessSignal::changed( 'delivery_offer' );

			return $id;
		}

		$insert_row = array_merge(
			$row,
			[
				'tax_class'      => '',
				'price_basis'    => 'manual',
				'duration_unit'  => 'business_days',
				'created_at'     => $now,
			]
		);

		$insert_formats = array_merge(
			$formats,
			[ '%s', '%s', '%s', '%s' ]
		);

		$insert_id = $this->insert_row( $insert_row, $insert_formats );
		if ( $insert_id > 0 ) {
			CatalogReadinessSignal::changed( 'delivery_offer' );
		}

		return $insert_id > 0 ? $insert_id : 0;
	}

	public function list( array $criteria = [] ): array {
		return $this->fetch_list( $criteria, (int) ( $criteria['limit'] ?? 500 ) );
	}

	public function softDelete( int $id ): bool {
		return $this->signal_if( $this->mark_inactive( $id ) );
	}

	public function hardDelete( int $id ): bool {
		return $this->signal_if( $this->delete_row_by_id( $id ) );
	}

	private function signal_if( bool $changed ): bool {
		if ( $changed ) {
			CatalogReadinessSignal::changed( 'delivery_offer' );
		}

		return $changed;
	}

	public function count_all(): int {
		return parent::count_all();
	}

	private function nullable_string( mixed $value, ?int $max_length = null ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		if ( '' === $string ) {
			return null;
		}

		if ( null !== $max_length && strlen( $string ) > $max_length ) {
			return substr( $string, 0, $max_length );
		}

		return $string;
	}

	private function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return max( 0, (int) $value );
	}
}
