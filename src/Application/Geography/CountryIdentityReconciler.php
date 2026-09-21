<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

/**
 * Idempotent in-place repair of canonical country roots corrupted by competing
 * GeoNames PCL* rows. Preserves id, location_key, country_code, generation,
 * ancestry and foreign-key relationships. Schema stays 6.
 */
final class CountryIdentityReconciler {

	public const OPTION_KEY = 'cetech_de_country_identity_repair';

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private LocationAliasRepositoryInterface $aliases,
		private ProviderMappingRepositoryInterface $mappings,
		private GeographyPackRepositoryInterface $packs,
		private WooCommerceGeographyBootstrap $woo,
		private GeoNamesGazetteerParser $parser = new GeoNamesGazetteerParser()
	) {
	}

	/**
	 * One-shot per plugin identity. Safe on storefront and admin.
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_repair(): array {
		$version = defined( 'CETECH_DE_VERSION' ) ? (string) CETECH_DE_VERSION : '';
		if ( function_exists( 'get_option' ) && '' !== $version && (string) get_option( self::OPTION_KEY, '' ) === $version ) {
			return [
				'skipped' => true,
				'results' => [],
			];
		}

		$results = $this->repair_all();
		if ( function_exists( 'update_option' ) && '' !== $version ) {
			update_option( self::OPTION_KEY, $version, false );
		}

		return [
			'skipped' => false,
			'results' => $results,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function repair_all(): array {
		$out = [];
		foreach ( $this->locations->list_country_roots() as $country ) {
			$out[] = $this->repair_one( $country );
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function repair_country_code( string $country_code ): array {
		$country = $this->locations->find_country( $country_code, true );
		if ( ! $country instanceof CanonicalLocation ) {
			return [
				'country_code' => strtoupper( trim( $country_code ) ),
				'changed'      => false,
				'reason'       => 'missing',
			];
		}

		return $this->repair_one( $country );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function repair_one( CanonicalLocation $country ): array {
		$mapping_rows = $this->mappings->list_mappings_for_location( $country->id );
		$invalid      = [];
		$candidates   = [];
		foreach ( $mapping_rows as $row ) {
			if ( GeographyProvider::GeoNames->value !== (string) ( $row['provider'] ?? '' ) ) {
				continue;
			}
			$feature = (string) ( $row['feature_code'] ?? '' );
			$rank    = $this->parser->country_identity_rank( $feature );
			if ( $rank <= 0 ) {
				$invalid[] = $row;
				continue;
			}
			$row['_rank'] = $rank;
			$candidates[] = $row;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$rank = ( (int) ( $b['_rank'] ?? 0 ) ) <=> ( (int) ( $a['_rank'] ?? 0 ) );
				if ( 0 !== $rank ) {
					return $rank;
				}
				$aid = (int) ( $a['external_id'] ?? 0 );
				$bid = (int) ( $b['external_id'] ?? 0 );
				if ( $aid > 0 && $bid > 0 && $aid !== $bid ) {
					return $aid <=> $bid;
				}

				return strcmp( (string) ( $a['external_id'] ?? '' ), (string) ( $b['external_id'] ?? '' ) );
			}
		);

		$winner    = $candidates[0] ?? null;
		$detached  = [];
		foreach ( $invalid as $row ) {
			$this->detach_geonames_mapping( $country, $row );
			$detached[] = (string) ( $row['external_id'] ?? '' );
		}
		foreach ( $candidates as $index => $row ) {
			if ( 0 === $index ) {
				continue;
			}
			$this->detach_geonames_mapping( $country, $row );
			$detached[] = (string) ( $row['external_id'] ?? '' );
		}

		$target_name = $this->authoritative_name( $country );
		$target_norm = GeographyNameNormalizer::normalize( $target_name );
		$target_ascii = GeographyNameNormalizer::fold_ascii( $target_name );

		$coords  = [ $country->latitude, $country->longitude ];
		$tainted = $invalid !== [];
		if ( is_array( $winner ) ) {
			$from_source = $this->identity_coordinates( $country, $winner );
			if ( is_array( $from_source ) ) {
				$coords  = $from_source;
				$tainted = false;
			}
		}
		if ( $tainted ) {
			$coords = [ null, null ];
		}

		$name_changed  = $country->canonical_name !== $target_name
			|| $country->normalized_name !== $target_norm
			|| $country->ascii_name !== $target_ascii;
		$coord_changed = ! $this->same_coord( $country->latitude, $coords[0] )
			|| ! $this->same_coord( $country->longitude, $coords[1] );

		if ( $name_changed || $coord_changed ) {
			$this->locations->save(
				new CanonicalLocation(
					$country->id,
					$country->location_key,
					$country->country_code,
					$country->parent_location_id,
					$country->location_type,
					$country->administrative_level,
					$target_name,
					$target_norm,
					$target_ascii,
					$coords[0],
					$coords[1],
					$country->status,
					$country->ancestry_path,
					$country->generation,
					$country->draft_json,
					$country->generation_token,
					$country->draft_generation_token,
					$country->prepared_ancestry_path,
					$country->prepared_generation_token,
					$country->prepared_hierarchy_root_id
				)
			);
		}

		return [
			'country_code'       => $country->country_code,
			'id'                 => $country->id,
			'location_key'       => $country->location_key,
			'generation'         => $country->generation,
			'changed'            => $name_changed || $coord_changed || $detached !== [],
			'canonical_name'     => $target_name,
			'normalized_name'    => $target_norm,
			'detached_mappings'  => array_values( array_filter( $detached ) ),
		];
	}

	private function authoritative_name( CanonicalLocation $country ): string {
		$wc = trim( $this->woo->country_label( $country->country_code ) );
		if ( '' !== $wc && strtoupper( $wc ) !== $country->country_code ) {
			return $wc;
		}

		$canonical_norm = GeographyNameNormalizer::normalize( $country->canonical_name );
		if ( $canonical_norm !== $country->normalized_name ) {
			foreach ( $this->aliases->list_for_location( $country->id ) as $alias ) {
				if ( GeographyNameNormalizer::normalize( $alias ) === $country->normalized_name ) {
					return $alias;
				}
			}
		}

		return $country->canonical_name;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function detach_geonames_mapping( CanonicalLocation $country, array $row ): void {
		$external = trim( (string) ( $row['external_id'] ?? '' ) );
		if ( '' !== $external ) {
			$this->mappings->delete_mapping( GeographyProvider::GeoNames, $external );
		}
		$meta = $this->mapping_metadata( $row );
		$ascii = trim( (string) ( $meta['ascii_name'] ?? '' ) );
		if ( '' !== $ascii ) {
			$this->aliases->delete_normalized_alias( $country->id, GeographyNameNormalizer::normalize( $ascii ) );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapping_metadata( array $row ): array {
		if ( is_array( $row['metadata'] ?? null ) ) {
			return $row['metadata'];
		}
		$raw = $row['provider_metadata_json'] ?? '';
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );

			return is_array( $decoded ) ? $decoded : [];
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $winner
	 * @return array{0:?float,1:?float}|null
	 */
	private function identity_coordinates( CanonicalLocation $country, array $winner ): ?array {
		$pack = $this->packs->find_by_country_provider( $country->country_code, GeographyProvider::GeoNames );
		if ( ! $pack instanceof \CetechDeliveryEngine\Domain\Geography\GeographyPack ) {
			return null;
		}
		$path = (string) $pack->source_reference;
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		$want   = (string) ( $winner['external_id'] ?? '' );
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$parts = explode( "\t", $line );
			if ( ( $parts[0] ?? '' ) !== $want || count( $parts ) < 6 ) {
				continue;
			}
			fclose( $handle );
			$lat = is_numeric( $parts[4] ) ? (float) $parts[4] : null;
			$lon = is_numeric( $parts[5] ) ? (float) $parts[5] : null;

			return [ $lat, $lon ];
		}
		fclose( $handle );

		return null;
	}

	private function same_coord( ?float $left, ?float $right ): bool {
		if ( null === $left && null === $right ) {
			return true;
		}
		if ( null === $left || null === $right ) {
			return false;
		}

		return abs( $left - $right ) < 0.000001;
	}
}
