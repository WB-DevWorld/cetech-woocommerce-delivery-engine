<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

/**
 * Batched, resumable, idempotent GeoNames country gazetteer importer.
 */
final class GeoNamesPackImporter {

	public const SOURCE_URL_PATTERN = 'https://download.geonames.org/export/dump/%s.zip';

	public const LICENSE_NAME = 'CC BY 4.0';

	public const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';

	public const ATTRIBUTION = 'This product uses GeoNames gazetteer data (https://www.geonames.org/) licensed under CC BY 4.0.';

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private LocationAliasRepositoryInterface $aliases,
		private ProviderMappingRepositoryInterface $mappings,
		private GeographyPackRepositoryInterface $packs,
		private WooCommerceGeographyBootstrap $woo_bootstrap,
		private GeoNamesGazetteerParser $parser = new GeoNamesGazetteerParser()
	) {
	}

	public function ensure_pack( string $country_code, string $source_path = '' ): GeographyPack {
		$country_code = strtoupper( trim( $country_code ) );
		$existing     = $this->packs->find_by_country_provider( $country_code, GeographyProvider::GeoNames );
		if ( $existing instanceof GeographyPack ) {
			return $existing;
		}

		return $this->packs->save(
			[
				'country_code'     => $country_code,
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'dataset_version'  => '',
				'source_url'       => sprintf( self::SOURCE_URL_PATTERN, $country_code ),
				'source_reference' => $source_path,
				'license_name'     => self::LICENSE_NAME,
				'license_url'      => self::LICENSE_URL,
				'attribution_text' => self::ATTRIBUTION,
				'status'           => GeographyPackStatus::Pending->value,
				'progress'         => [
					'processed' => 0,
					'imported'  => 0,
					'skipped'   => 0,
					'total'     => 0,
				],
			]
		);
	}

	/**
	 * Process one bounded batch from a local gazetteer text file.
	 *
	 * @return array<string, mixed>
	 */
	public function import_batch( GeographyPack $pack, string $file_path, int $batch_size = 100 ): array {
		$batch_size = max( 1, min( 250, $batch_size ) );
		$this->woo_bootstrap->bootstrap_country( $pack->country_code );
		$country = $this->locations->find_country( $pack->country_code );
		if ( ! $country instanceof CanonicalLocation ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				$pack->import_cursor,
				$pack->progress,
				'Country node could not be bootstrapped.'
			);

			return [
				'status' => GeographyPackStatus::Failed->value,
				'error'  => 'country_missing',
			];
		}

		$cursor    = (int) $pack->import_cursor;
		$progress  = $pack->progress;
		$processed = (int) ( $progress['processed'] ?? 0 );
		$imported  = (int) ( $progress['imported'] ?? 0 );
		$skipped   = (int) ( $progress['skipped'] ?? 0 );
		$last      = $cursor;
		$seen      = 0;
		$phase     = (string) ( $progress['phase'] ?? 'admin' );
		if ( ! in_array( $phase, [ 'admin', 'locality' ], true ) ) {
			$phase = 'admin';
		}

		foreach ( $this->parser->iterate_file( $file_path, $cursor, $batch_size ) as $row ) {
			$last = (int) ( $row['_file_offset'] ?? $last );
			++$seen;
			if ( ! empty( $row['_skip'] ) ) {
				++$skipped;
				++$processed;
				continue;
			}
			if ( (string) ( $row['country_code'] ?? '' ) !== $pack->country_code ) {
				++$skipped;
				++$processed;
				continue;
			}
			$is_admin = ! empty( $row['is_admin'] );
			if ( 'admin' === $phase && ! $is_admin ) {
				++$skipped;
				++$processed;
				continue;
			}
			if ( 'locality' === $phase && $is_admin ) {
				++$skipped;
				++$processed;
				continue;
			}

			$did = $this->upsert_row( $pack, $country, $row );
			if ( $did ) {
				++$imported;
			} else {
				++$skipped;
			}
			++$processed;
		}

		$eof = $seen < $batch_size;
		if ( $eof && 'admin' === $phase ) {
			$phase   = 'locality';
			$last    = 0;
			$eof     = false;
			$status  = GeographyPackStatus::Importing;
		} else {
			$status = $eof ? GeographyPackStatus::Ready : GeographyPackStatus::Importing;
		}
		$progress = [
			'processed' => $processed,
			'imported'  => $imported,
			'skipped'   => $skipped,
			'total'     => (int) ( $progress['total'] ?? 0 ),
			'phase'     => $phase,
		];
		$this->packs->update_progress(
			$pack->id,
			$status,
			(string) $last,
			$progress,
			'',
			$eof ? gmdate( 'Y-m-d H:i:s' ) : null
		);

		return [
			'status'    => $status->value,
			'processed' => $processed,
			'imported'  => $imported,
			'skipped'   => $skipped,
			'cursor'    => $last,
			'complete'  => $eof,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function upsert_row( GeographyPack $pack, CanonicalLocation $country, array $row ): bool {
		$external = (string) ( $row['geoname_id'] ?? '' );
		if ( '' === $external ) {
			return false;
		}

		$existing_id = $this->mappings->find_location_id( GeographyProvider::GeoNames, $external );
		$parent      = $this->resolve_parent( $country, $row );
		$name        = (string) ( $row['name'] ?? '' );
		if ( '' === $name ) {
			return false;
		}

		$type  = ! empty( $row['is_admin'] ) ? GeographyLocationType::Administrative : GeographyLocationType::Locality;
		$level = isset( $row['admin_level'] ) ? (int) $row['admin_level'] : null;
		if ( GeographyLocationType::Administrative === $type && ( $level ?? 0 ) <= 0 ) {
			$level = 1;
		}

		if ( null !== $existing_id ) {
			$existing = $this->locations->find_by_id( $existing_id );
			if ( $existing instanceof CanonicalLocation ) {
				$this->store_aliases( $existing->id, $row );
				$this->mappings->upsert(
					$existing->id,
					GeographyProvider::GeoNames,
					$external,
					$pack->id,
					(string) ( $row['modified'] ?? '' ),
					(string) ( $row['admin1'] ?? '' ),
					(string) ( $row['feature_class'] ?? '' ),
					(string) ( $row['feature_code'] ?? '' ),
					[ 'ascii_name' => (string) ( $row['ascii_name'] ?? '' ) ]
				);

				return true;
			}
		}

		$by_name = $this->locations->find_exact_child(
			$country->country_code,
			$parent?->id,
			GeographyNameNormalizer::normalize( $name ),
			$type
		);
		if ( $by_name instanceof CanonicalLocation ) {
			$this->mappings->upsert(
				$by_name->id,
				GeographyProvider::GeoNames,
				$external,
				$pack->id,
				(string) ( $row['modified'] ?? '' ),
				(string) ( $row['admin1'] ?? '' ),
				(string) ( $row['feature_class'] ?? '' ),
				(string) ( $row['feature_code'] ?? '' )
			);
			$this->store_aliases( $by_name->id, $row );

			return true;
		}

		$saved = $this->locations->save(
			new CanonicalLocation(
				0,
				GeographyNameNormalizer::new_location_key(),
				$country->country_code,
				$parent?->id,
				$type,
				$level,
				$name,
				GeographyNameNormalizer::normalize( $name ),
				GeographyNameNormalizer::normalize( (string) ( $row['ascii_name'] ?? $name ) ),
				isset( $row['latitude'] ) ? (float) $row['latitude'] : null,
				isset( $row['longitude'] ) ? (float) $row['longitude'] : null,
				RecordStatus::Active,
				LocationAncestry::append_path( $parent?->ancestry_path ?? $country->ancestry_path, 0 )
			)
		);
		$this->locations->update_ancestry_path(
			$saved->id,
			LocationAncestry::append_path( $parent?->ancestry_path ?? $country->ancestry_path, $saved->id )
		);
		$this->mappings->upsert(
			$saved->id,
			GeographyProvider::GeoNames,
			$external,
			$pack->id,
			(string) ( $row['modified'] ?? '' ),
			(string) ( $row['admin1'] ?? '' ),
			(string) ( $row['feature_class'] ?? '' ),
			(string) ( $row['feature_code'] ?? '' )
		);
		if ( 'ADM1' === (string) ( $row['feature_code'] ?? '' ) && '' !== trim( (string) ( $row['admin1'] ?? '' ) ) ) {
			$this->mappings->upsert(
				$saved->id,
				GeographyProvider::GeoNames,
				$country->country_code . '.' . trim( (string) $row['admin1'] ),
				$pack->id,
				(string) ( $row['modified'] ?? '' ),
				(string) ( $row['admin1'] ?? '' ),
				'A',
				'ADM1'
			);
		}
		$this->store_aliases( $saved->id, $row );

		return true;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function resolve_parent( CanonicalLocation $country, array $row ): CanonicalLocation {
		$admin1 = trim( (string) ( $row['admin1'] ?? '' ) );
		if ( '' === $admin1 ) {
			return $country;
		}

		$geonames_admin_key = $country->country_code . '.' . $admin1;
		$mapped             = $this->mappings->find_location_id( GeographyProvider::GeoNames, $geonames_admin_key );
		if ( null !== $mapped ) {
			$location = $this->locations->find_by_id( $mapped );

			return $location instanceof CanonicalLocation ? $location : $country;
		}

		$mapped = $this->mappings->find_location_id( GeographyProvider::GeoNames, 'ADM1:' . $country->country_code . ':' . $admin1 );
		if ( null !== $mapped ) {
			$location = $this->locations->find_by_id( $mapped );

			return $location instanceof CanonicalLocation ? $location : $country;
		}

		foreach ( $this->locations->list_children( $country->id, GeographyLocationType::Administrative, 200, 0 ) as $admin ) {
			if ( $admin->normalized_name === GeographyNameNormalizer::normalize( $admin1 ) ) {
				return $admin;
			}
		}

		return $country;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function store_aliases( int $location_id, array $row ): void {
		$ascii = trim( (string) ( $row['ascii_name'] ?? '' ) );
		$name  = trim( (string) ( $row['name'] ?? '' ) );
		if ( '' !== $ascii && GeographyNameNormalizer::normalize( $ascii ) !== GeographyNameNormalizer::normalize( $name ) ) {
			$this->aliases->add_alias( $location_id, $ascii, GeographyNameNormalizer::normalize( $ascii ), 'und', 'ascii', false );
		}
		foreach ( $row['alternates'] ?? [] as $alias ) {
			$alias = trim( (string) $alias );
			if ( '' === $alias ) {
				continue;
			}
			$normalized = GeographyNameNormalizer::normalize( $alias );
			if ( '' === $normalized || $normalized === GeographyNameNormalizer::normalize( $name ) ) {
				continue;
			}
			$this->aliases->add_alias( $location_id, $alias, $normalized, '', 'alternate', false );
		}
	}
}
