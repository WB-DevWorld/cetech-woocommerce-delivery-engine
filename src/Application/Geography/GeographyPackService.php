<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;

/**
 * Admin-facing pack lifecycle. Large imports are batched and resumable.
 */
final class GeographyPackService {

	public const HOOK = 'cetech_de_geography_pack_tick';

	public const GROUP = 'cetech-delivery-engine-geography';

	public function __construct(
		private GeographyPackRepositoryInterface $packs,
		private GeoNamesPackImporter $importer,
		private WooCommerceGeographyBootstrap $woo_bootstrap
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list_public(): array {
		$rows = [];
		foreach ( $this->packs->list_all() as $pack ) {
			$rows[] = $pack->publicAdminRow();
		}

		return $rows;
	}

	public function install( string $country_code, string $file_path = '' ): GeographyPack {
		$country_code = strtoupper( trim( $country_code ) );
		$this->woo_bootstrap->bootstrap_country( $country_code );
		$pack = $this->importer->ensure_pack( $country_code, $file_path );
		if ( GeographyPackStatus::Ready === $pack->status && '' === $file_path ) {
			return $pack;
		}

		$this->packs->update_progress(
			$pack->id,
			GeographyPackStatus::Importing,
			$pack->import_cursor,
			$pack->progress,
			''
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				[
					'pack_id'      => $pack->id,
					'source_path'  => $file_path,
				],
				self::GROUP
			);
		}

		return $this->packs->find_by_id( $pack->id ) ?? $pack;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function tick( int $pack_id, string $file_path, int $batch_size = 100 ): array {
		$pack = $this->packs->find_by_id( $pack_id );
		if ( ! $pack instanceof GeographyPack ) {
			return [ 'status' => 'missing' ];
		}

		if ( '' === $file_path ) {
			$file_path = (string) $pack->source_reference;
		}

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				$pack->import_cursor,
				$pack->progress,
				'Gazetteer file is not readable. Upload or extract the country pack locally.'
			);

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'file_unreadable' ];
		}

		$result = $this->importer->import_batch( $pack, $file_path, $batch_size );
		$status = (string) ( $result['status'] ?? '' );
		if ( GeographyPackStatus::Importing->value === $status && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				[
					'pack_id'     => $pack_id,
					'source_path' => $file_path,
				],
				self::GROUP
			);
		}

		return $result;
	}

	public function find( int $id ): ?GeographyPack {
		return $this->packs->find_by_id( $id );
	}

	public static function geonames_url( string $country_code ): string {
		return sprintf( GeoNamesPackImporter::SOURCE_URL_PATTERN, strtoupper( $country_code ) );
	}
}
