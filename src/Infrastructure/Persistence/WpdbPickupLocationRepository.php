<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Configuration\CatalogReadinessSignal;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

final class WpdbPickupLocationRepository extends AbstractWpdbRepository implements PickupLocationRepositoryInterface {

	protected function table_suffix(): string {
		return 'pickup_locations';
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
			'internal_code'              => (string) ( $data['internal_code'] ?? '' ),
			'location_name'              => (string) ( $data['location_name'] ?? '' ),
			'public_address'             => $this->nullable_string( $data['public_address'] ?? null ),
			'public_opening_hours'       => $this->nullable_string( $data['public_opening_hours'] ?? null ),
			'public_pickup_instructions' => $this->nullable_string( $data['public_pickup_instructions'] ?? null ),
			'contact_phone'              => $this->nullable_string( $data['contact_phone'] ?? null, 64 ),
			'contact_email'              => $this->nullable_string( $data['contact_email'] ?? null, 255 ),
			'readiness_estimate'         => $this->nullable_string( $data['readiness_estimate'] ?? null, 255 ),
			'status'                     => (string) ( $data['status'] ?? 'active' ),
			'updated_at'                 => $now,
		];

		$formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( $id > 0 ) {
			if ( ! $this->update_row( $id, $row, $formats ) ) {
				return 0;
			}

			CatalogReadinessSignal::changed( 'pickup_location' );

			return $id;
		}

		$row['created_at'] = $now;
		$insert_formats    = array_merge( $formats, [ '%s' ] );

		$insert_id = $this->insert_row( $row, $insert_formats );
		if ( $insert_id > 0 ) {
			CatalogReadinessSignal::changed( 'pickup_location' );
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
			CatalogReadinessSignal::changed( 'pickup_location' );
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
}
