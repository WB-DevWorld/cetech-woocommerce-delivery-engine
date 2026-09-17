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
 *
 * Hierarchy is provider-neutral:
 * country → ADM1 → ADM2 → ADM3 → ADM4 → locality
 * as far as the source data provides. Localities attach to the deepest
 * resolvable administrative ancestor. PCLI/PCL* rows update the existing
 * country node and never create a duplicate country child.
 *
 * Provider rename/move/coordinate updates rewrite provider-derived metadata
 * on the existing canonical location and keep the internal ID. Former names
 * are stored as aliases. Provider rows that disappear from a later dataset
 * are not deleted or deactivated automatically: Delivery Areas that reference
 * the canonical ID keep working until a merchant reviews them. Deprecation is
 * a later explicit review action, not an implicit import side-effect.
 */
final class GeoNamesPackImporter {

	public const SOURCE_URL_PATTERN = 'https://download.geonames.org/export/dump/%s.zip';

	public const LICENSE_NAME = 'CC BY 4.0';

	public const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';

	public const ATTRIBUTION = 'This product uses GeoNames gazetteer data (https://www.geonames.org/) licensed under CC BY 4.0.';

	public const MAX_SCAN_PER_TICK = 2500;

	public const MAX_SECONDS_PER_TICK = 4.0;

	/** @var list<string> */
	public const PHASES = [ 'admin1', 'admin2', 'admin3', 'admin4', 'locality' ];

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
				'progress'         => $this->empty_progress(),
			]
		);
	}

	/**
	 * Reset import state for a new dataset. Canonical IDs/mappings are preserved.
	 */
	public function begin_dataset( GeographyPack $pack, string $source_path, string $checksum, string $dataset_version ): GeographyPack {
		$progress = $this->empty_progress();
		$active   = (int) ( $pack->progress['active_generation'] ?? 0 );
		$target   = $active + 1;
		if ( $target < 1 ) {
			$target = 1;
		}
		$progress['dataset_checksum']    = $checksum;
		$progress['active_generation']   = $active;
		$progress['target_generation']   = $target;
		$progress['target_token']        = bin2hex( random_bytes( 8 ) );
		$progress['target_checksum']     = $checksum;
		$progress['update_dataset']      = [
			'checksum'         => $checksum,
			'dataset_version'  => $dataset_version,
			'source_reference' => $source_path,
		];
		if ( isset( $pack->progress['last_successful'] ) && is_array( $pack->progress['last_successful'] ) ) {
			$progress['last_successful'] = $pack->progress['last_successful'];
		} elseif ( $pack->has_usable_dataset() ) {
			$progress['last_successful'] = [
				'checksum'         => $pack->active_checksum(),
				'dataset_version'  => $pack->active_dataset_version(),
				'source_reference' => $pack->source_reference,
				'installed_at'     => $pack->installed_at,
			];
		}

		return $this->packs->save(
			[
				'id'               => $pack->id,
				'country_code'     => $pack->country_code,
				'provider'         => $pack->provider->value,
				'dataset_name'     => $pack->dataset_name !== '' ? $pack->dataset_name : 'gazetteer',
				'dataset_version'  => $dataset_version,
				'source_url'       => $pack->source_url !== '' ? $pack->source_url : sprintf( self::SOURCE_URL_PATTERN, $pack->country_code ),
				'source_reference' => $source_path,
				'checksum'         => $checksum,
				'license_name'     => $pack->license_name !== '' ? $pack->license_name : self::LICENSE_NAME,
				'license_url'      => $pack->license_url !== '' ? $pack->license_url : self::LICENSE_URL,
				'attribution_text' => $pack->attribution_text !== '' ? $pack->attribution_text : self::ATTRIBUTION,
				'status'           => GeographyPackStatus::Importing->value,
				'import_cursor'    => '0',
				'progress'         => $progress,
				'last_error'       => '',
				'installed_at'     => $pack->installed_at,
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
		$target    = (int) ( $progress['target_generation'] ?? 0 );
		if ( $target <= 0 ) {
			$target = max( 1, (int) ( $progress['active_generation'] ?? 0 ) + 1 );
			$progress['target_generation'] = $target;
			if ( '' === (string) ( $progress['target_token'] ?? '' ) ) {
				$progress['target_token'] = bin2hex( random_bytes( 8 ) );
			}
		}
		$last      = $cursor;
		$phase     = (string) ( $progress['phase'] ?? 'admin1' );
		if ( ! in_array( $phase, self::PHASES, true ) ) {
			$phase = 'admin' === $phase ? 'admin1' : ( 'locality' === $phase ? 'locality' : 'admin1' );
		}

		$eof     = false;
		$scanned = 0;
		foreach ( $this->parser->iterate_file( $file_path, $cursor, $batch_size, self::MAX_SCAN_PER_TICK, self::MAX_SECONDS_PER_TICK ) as $row ) {
			if ( ! empty( $row['_batch_end'] ) ) {
				$last    = (int) ( $row['_file_offset'] ?? $last );
				$eof     = ! empty( $row['_eof'] );
				$scanned = (int) ( $row['_scanned'] ?? $scanned );
				continue;
			}
			$last = (int) ( $row['_file_offset'] ?? $last );
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
			if ( ! empty( $row['is_country'] ) ) {
				$did = $this->upsert_row( $pack, $country, $row, $target );
				if ( $did ) {
					++$imported;
				} else {
					++$skipped;
				}
				++$processed;
				continue;
			}
			if ( ! $this->row_matches_phase( $row, $phase ) ) {
				++$skipped;
				++$processed;
				continue;
			}

			$did = $this->upsert_row( $pack, $country, $row, $target );
			if ( $did ) {
				++$imported;
			} else {
				++$skipped;
			}
			++$processed;
		}

		$complete = false;
		if ( $eof ) {
			$idx = array_search( $phase, self::PHASES, true );
			if ( is_int( $idx ) && $idx < count( self::PHASES ) - 1 ) {
				$phase = self::PHASES[ $idx + 1 ];
				$last  = 0;
				$status = GeographyPackStatus::Importing;
			} else {
				$this->locations->promote_generation( $target );
				$status   = GeographyPackStatus::Ready;
				$complete = true;
			}
		} else {
			$status = GeographyPackStatus::Importing;
		}

		$progress = [
			'processed'          => $processed,
			'imported'           => $imported,
			'skipped'            => $skipped,
			'total'              => (int) ( $progress['total'] ?? 0 ),
			'phase'              => $phase,
			'scanned'            => $scanned,
			'target_generation'  => $target,
			'target_token'       => (string) ( $progress['target_token'] ?? '' ),
			'target_checksum'    => (string) ( $progress['target_checksum'] ?? $pack->checksum ),
			'dataset_checksum'   => (string) ( $progress['dataset_checksum'] ?? $pack->checksum ),
			'active_generation'  => $complete ? $target : (int) ( $progress['active_generation'] ?? 0 ),
		];
		$this->packs->update_progress(
			$pack->id,
			$status,
			(string) $last,
			$progress,
			'',
			$complete ? gmdate( 'Y-m-d H:i:s' ) : null
		);

		return [
			'status'    => $status->value,
			'processed' => $processed,
			'imported'  => $imported,
			'skipped'   => $skipped,
			'cursor'    => $last,
			'phase'     => $phase,
			'complete'  => $complete,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function row_matches_phase( array $row, string $phase ): bool {
		$level = isset( $row['admin_level'] ) ? (int) $row['admin_level'] : 0;
		return match ( $phase ) {
			'admin1' => ! empty( $row['is_admin'] ) && 1 === $level,
			'admin2' => ! empty( $row['is_admin'] ) && 2 === $level,
			'admin3' => ! empty( $row['is_admin'] ) && 3 === $level,
			'admin4' => ! empty( $row['is_admin'] ) && 4 === $level,
			'locality' => ! empty( $row['is_locality'] ),
			default => false,
		};
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function upsert_row( GeographyPack $pack, CanonicalLocation $country, array $row, int $target ): bool {
		$external = (string) ( $row['geoname_id'] ?? '' );
		if ( '' === $external ) {
			return false;
		}

		if ( ! empty( $row['is_country'] ) ) {
			return $this->map_country_feature( $pack, $country, $row, $target );
		}

		$name = (string) ( $row['name'] ?? '' );
		if ( '' === $name ) {
			return false;
		}

		$parent = $this->resolve_parent( $country, $row );
		$type   = ! empty( $row['is_admin'] ) ? GeographyLocationType::Administrative : GeographyLocationType::Locality;
		$level  = isset( $row['admin_level'] ) ? (int) $row['admin_level'] : null;
		if ( GeographyLocationType::Administrative === $type && ( $level ?? 0 ) <= 0 ) {
			$level = 1;
		}

		$existing_id = $this->mappings->find_location_id( GeographyProvider::GeoNames, $external );
		if ( null !== $existing_id ) {
			$existing = $this->locations->find_by_id( $existing_id );
			if ( $existing instanceof CanonicalLocation && ( $existing->isActive() || $existing->generation === $target ) ) {
				$this->apply_provider_update( $existing, $parent, $row, $target );
				if ( ! $existing->isActive() || $existing->generation === $target ) {
					$this->store_aliases( $existing->id, $row );
				}
				$this->store_mappings( $existing->id, $pack, $row, $level );
				return true;
			}
		}

		$matched = $this->reconcile_canonical( $country, $parent, $name, $type, $level, $row, $target );
		if ( $matched instanceof CanonicalLocation ) {
			$this->apply_provider_update( $matched, $parent, $row, $target );
			$this->store_mappings( $matched->id, $pack, $row, $level );
			if ( ! $matched->isActive() || $matched->generation === $target ) {
				$this->store_aliases( $matched->id, $row );
			}
			return true;
		}

		if ( GeographyLocationType::Administrative === $type && $this->has_ambiguous_administrative_core( $parent, $name, $level ) ) {
			return false;
		}

		$saved = $this->locations->save(
			new CanonicalLocation(
				0,
				GeographyNameNormalizer::new_location_key(),
				$country->country_code,
				$parent->id,
				$type,
				$level,
				$name,
				GeographyNameNormalizer::normalize( $name ),
				GeographyNameNormalizer::normalize( (string) ( $row['ascii_name'] ?? $name ) ),
				isset( $row['latitude'] ) ? (float) $row['latitude'] : null,
				isset( $row['longitude'] ) ? (float) $row['longitude'] : null,
				RecordStatus::Inactive,
				LocationAncestry::append_path( $parent->ancestry_path, 0 ),
				$target
			)
		);
		$this->locations->update_ancestry_path(
			$saved->id,
			LocationAncestry::append_path( $parent->ancestry_path, $saved->id )
		);
		$this->store_mappings( $saved->id, $pack, $row, $level );
		$this->store_aliases( $saved->id, $row );

		return true;
	}

	/**
	 * GeoNames PCLI/PCL* maps onto the existing canonical country. Never Ghana→Ghana.
	 *
	 * @param array<string, mixed> $row
	 */
	private function map_country_feature( GeographyPack $pack, CanonicalLocation $country, array $row, int $target = 0 ): bool {
		$this->apply_provider_update( $country, null, $row, $target, false );
		$this->mappings->upsert(
			$country->id,
			GeographyProvider::GeoNames,
			(string) $row['geoname_id'],
			$pack->id,
			(string) ( $row['modified'] ?? $pack->dataset_version ),
			'',
			(string) ( $row['feature_class'] ?? 'A' ),
			(string) ( $row['feature_code'] ?? 'PCLI' ),
			[ 'ascii_name' => (string) ( $row['ascii_name'] ?? '' ) ]
		);
		$this->store_aliases( $country->id, $row );

		return true;
	}

	/**
	 * Preserve canonical identity. Update name/parent/coordinates from provider data.
	 *
	 * @param array<string, mixed> $row
	 */
	private function apply_provider_update( CanonicalLocation $existing, ?CanonicalLocation $parent, array $row, int $target = 0, bool $may_reparent = true ): void {
		$name  = trim( (string) ( $row['name'] ?? $existing->canonical_name ) );
		$ascii = trim( (string) ( $row['ascii_name'] ?? $existing->ascii_name ) );
		if ( '' === $name ) {
			$name = $existing->canonical_name;
		}

		$new_parent = $existing->parent_location_id;
		if ( $may_reparent && $parent instanceof CanonicalLocation && $parent->id !== $existing->id ) {
			$new_parent = $parent->id;
		}

		if ( $existing->isActive() && $existing->generation !== $target ) {
			$encoded = function_exists( 'wp_json_encode' )
				? wp_json_encode(
					[
						'generation'         => $target,
						'canonical_name'     => $name,
						'ascii_name'         => '' !== $ascii ? GeographyNameNormalizer::normalize( $ascii ) : $existing->ascii_name,
						'parent_location_id' => $new_parent,
						'latitude'           => isset( $row['latitude'] ) ? (float) $row['latitude'] : $existing->latitude,
						'longitude'          => isset( $row['longitude'] ) ? (float) $row['longitude'] : $existing->longitude,
					]
				)
				: json_encode(
					[
						'generation'         => $target,
						'canonical_name'     => $name,
						'ascii_name'         => '' !== $ascii ? GeographyNameNormalizer::normalize( $ascii ) : $existing->ascii_name,
						'parent_location_id' => $new_parent,
						'latitude'           => isset( $row['latitude'] ) ? (float) $row['latitude'] : $existing->latitude,
						'longitude'          => isset( $row['longitude'] ) ? (float) $row['longitude'] : $existing->longitude,
					]
				);
			$this->locations->save(
				new CanonicalLocation(
					$existing->id,
					$existing->location_key,
					$existing->country_code,
					$existing->parent_location_id,
					$existing->location_type,
					$existing->administrative_level,
					$existing->canonical_name,
					$existing->normalized_name,
					$existing->ascii_name,
					$existing->latitude,
					$existing->longitude,
					$existing->status,
					$existing->ancestry_path,
					$existing->generation,
					is_string( $encoded ) ? $encoded : ''
				)
			);

			return;
		}

		if ( $name !== $existing->canonical_name ) {
			$this->aliases->add_alias(
				$existing->id,
				$existing->canonical_name,
				$existing->normalized_name,
				'',
				'former_name',
				false
			);
		}

		$old_path = $existing->ancestry_path;
		$updated  = new CanonicalLocation(
			$existing->id,
			$existing->location_key,
			$existing->country_code,
			$new_parent,
			$existing->location_type,
			$existing->administrative_level,
			$name,
			GeographyNameNormalizer::normalize( $name ),
			'' !== $ascii ? GeographyNameNormalizer::normalize( $ascii ) : $existing->ascii_name,
			isset( $row['latitude'] ) ? (float) $row['latitude'] : $existing->latitude,
			isset( $row['longitude'] ) ? (float) $row['longitude'] : $existing->longitude,
			$existing->status,
			$existing->ancestry_path,
			$existing->generation > 0 ? $existing->generation : $target,
			''
		);
		$this->locations->save( $updated );

		if ( $may_reparent && $parent instanceof CanonicalLocation && $new_parent === $parent->id ) {
			$new_path = LocationAncestry::append_path( $parent->ancestry_path, $existing->id );
			$this->locations->update_ancestry_path( $existing->id, $new_path );
			if ( '' !== $old_path && $old_path !== $new_path ) {
				$this->locations->rebuild_descendant_ancestry( $existing->id, $old_path, $new_path );
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function reconcile_canonical(
		CanonicalLocation $country,
		CanonicalLocation $parent,
		string $name,
		GeographyLocationType $type,
		?int $level,
		array $row,
		int $target
	): ?CanonicalLocation {
		$normalized = GeographyNameNormalizer::normalize( $name );
		$by_name    = $this->locations->find_exact_child( $country->country_code, $parent->id, $normalized, $type, $target );
		if ( $by_name instanceof CanonicalLocation ) {
			return $by_name;
		}

		$ascii = GeographyNameNormalizer::normalize( (string) ( $row['ascii_name'] ?? '' ) );
		if ( '' !== $ascii && $ascii !== $normalized ) {
			$by_ascii = $this->locations->find_exact_child( $country->country_code, $parent->id, $ascii, $type, $target );
			if ( $by_ascii instanceof CanonicalLocation ) {
				return $by_ascii;
			}
		}

		$by_alias = $this->aliases->find_exact( $country->country_code, $normalized, $parent->id );
		if ( $by_alias instanceof CanonicalLocation && $by_alias->location_type === $type ) {
			return $by_alias;
		}
		if ( '' !== $ascii ) {
			$by_alias = $this->aliases->find_exact( $country->country_code, $ascii, $parent->id );
			if ( $by_alias instanceof CanonicalLocation && $by_alias->location_type === $type ) {
				return $by_alias;
			}
		}

		if ( GeographyLocationType::Administrative === $type ) {
			return $this->locations->find_unique_administrative_core( $country->country_code, $parent->id, $name, $level );
		}

		return null;
	}

	private function has_ambiguous_administrative_core( CanonicalLocation $parent, string $name, ?int $level ): bool {
		$core = GeographyNameNormalizer::administrative_core( $name );
		if ( '' === $core ) {
			return false;
		}
		$matches = 0;
		foreach ( $this->locations->list_children( $parent->id, GeographyLocationType::Administrative, 250, 0 ) as $child ) {
			if ( null !== $level && $level > 0 && $child->administrative_level !== $level ) {
				continue;
			}
			if ( GeographyNameNormalizer::administrative_core( $child->canonical_name ) === $core
				|| GeographyNameNormalizer::administrative_core( $child->ascii_name ) === $core
				|| GeographyNameNormalizer::administrative_core( $child->normalized_name ) === $core ) {
				++$matches;
			}
		}

		return $matches > 1;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function store_mappings( int $location_id, GeographyPack $pack, array $row, ?int $level ): void {
		$external = (string) ( $row['geoname_id'] ?? '' );
		$this->mappings->upsert(
			$location_id,
			GeographyProvider::GeoNames,
			$external,
			$pack->id,
			(string) ( $row['modified'] ?? $pack->dataset_version ),
			(string) ( $row['admin1'] ?? '' ),
			(string) ( $row['feature_class'] ?? '' ),
			(string) ( $row['feature_code'] ?? '' ),
			[ 'ascii_name' => (string) ( $row['ascii_name'] ?? '' ) ]
		);

		$code_key = $this->admin_code_key( $pack->country_code, $row, $level ?? 0 );
		if ( '' !== $code_key ) {
			$this->mappings->upsert(
				$location_id,
				GeographyProvider::GeoNames,
				$code_key,
				$pack->id,
				(string) ( $row['modified'] ?? $pack->dataset_version ),
				(string) ( $row['admin1'] ?? '' ),
				'A',
				(string) ( $row['feature_code'] ?? '' )
			);
		}
	}

	/**
	 * Deepest resolvable canonical administrative ancestor for this row.
	 *
	 * @param array<string, mixed> $row
	 */
	private function resolve_parent( CanonicalLocation $country, array $row ): CanonicalLocation {
		$own_level = isset( $row['admin_level'] ) ? (int) $row['admin_level'] : 0;
		if ( ! empty( $row['is_locality'] ) ) {
			$own_level = 5;
		}

		foreach ( [ 4, 3, 2, 1 ] as $level ) {
			if ( $own_level > 0 && $level >= $own_level ) {
				continue;
			}
			$key = $this->admin_code_key( $country->country_code, $row, $level );
			if ( '' === $key ) {
				continue;
			}
			$mapped = $this->mappings->find_location_id( GeographyProvider::GeoNames, $key );
			if ( null === $mapped ) {
				continue;
			}
			$location = $this->locations->find_by_id( $mapped );
			if ( $location instanceof CanonicalLocation ) {
				return $location;
			}
		}

		$admin1 = trim( (string) ( $row['admin1'] ?? '' ) );
		if ( '' !== $admin1 ) {
			foreach ( $this->locations->list_children( $country->id, GeographyLocationType::Administrative, 250, 0 ) as $admin ) {
				if ( $admin->normalized_name === GeographyNameNormalizer::normalize( $admin1 ) ) {
					return $admin;
				}
			}
		}

		return $country;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function admin_code_key( string $country_code, array $row, int $level ): string {
		if ( $level < 1 ) {
			return '';
		}
		$parts = [ $country_code ];
		for ( $i = 1; $i <= $level; $i++ ) {
			$field = 'admin' . $i;
			$value = trim( (string) ( $row[ $field ] ?? '' ) );
			if ( '' === $value ) {
				return '';
			}
			$parts[] = $value;
		}

		return implode( '.', $parts );
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

	/**
	 * @return array<string, mixed>
	 */
	private function empty_progress(): array {
		return [
			'processed' => 0,
			'imported'  => 0,
			'skipped'   => 0,
			'total'     => 0,
			'phase'     => 'admin1',
		];
	}
}
