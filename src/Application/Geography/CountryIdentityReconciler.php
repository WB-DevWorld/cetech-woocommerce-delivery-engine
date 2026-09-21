<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WordPressOptionCasStore;

/**
 * Idempotent in-place repair of canonical country roots corrupted by competing
 * GeoNames PCL* rows. Preserves id, location_key, country_code, generation,
 * ancestry and foreign-key relationships. Schema stays 6.
 *
 * Historical kickoff is gated by REPAIR_REVISION, not plugin version.
 * Post-promotion repair_country_code() is independent of that gate.
 */
final class CountryIdentityReconciler {

	public const REPAIR_REVISION = 1;

	public const REPAIR_TOKEN = 'geo-country-identity-v1';

	public const OPTION_KEY = 'cetech_de_country_identity_repair_revision';

	public const LOCK_OPTION_KEY = 'cetech_de_country_identity_repair_lock';

	public const LOCK_TTL_SECONDS = 60;

	public int $source_scan_count = 0;

	/** @var (\Closure(self):void)|null Test seam for concurrency and failure simulation. */
	public ?\Closure $before_repair_all = null;

	/**
	 * Invoked after a stale lock is observed and before CAS takeover.
	 *
	 * @var (\Closure(mixed):void)|null
	 */
	public ?\Closure $after_observe_lock = null;

	/**
	 * Invoked after each country in a leased repair_all() pass.
	 *
	 * @var (\Closure(self, CanonicalLocation):void)|null
	 */
	public ?\Closure $after_repair_one = null;

	/** @var (\Closure(self):void)|null Test seam: after first revision check, before lease acquire. */
	public ?\Closure $before_lock_acquire = null;

	/** @var (\Closure(self):void)|null Test seam: after lease is owned, before post-acquire revision recheck. */
	public ?\Closure $after_lock_acquire = null;

	public WordPressOptionCasStore $cas;

	private string $lock_owner = '';

	/** @var array<string, mixed> */
	private array $held_lease = [];

	private bool $lease_lost = false;

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private LocationAliasRepositoryInterface $aliases,
		private ProviderMappingRepositoryInterface $mappings,
		private GeographyPackRepositoryInterface $packs,
		private WooCommerceGeographyBootstrap $woo,
		private GeoNamesGazetteerParser $parser = new GeoNamesGazetteerParser(),
		private ?\Closure $now = null,
		?WordPressOptionCasStore $cas = null
	) {
		$this->cas = $cas ?? new WordPressOptionCasStore();
	}

	/**
	 * One-shot historical repair keyed to REPAIR_REVISION. Safe on storefront
	 * and admin. Sequence: revision check → lease acquire → revision recheck
	 * under the owned lease → repair_all → persist revision → release lease.
	 * Concurrent callers skip rather than duplicate repair_all().
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_repair( ?int $revision = null ): array {
		$revision = $revision ?? self::REPAIR_REVISION;
		if ( $revision < 1 ) {
			$revision = self::REPAIR_REVISION;
		}

		if ( $this->stored_revision() >= $revision ) {
			return [
				'skipped' => true,
				'reason'  => 'revision_complete',
				'results' => [],
			];
		}

		$acquired = false;
		try {
			if ( $this->before_lock_acquire instanceof \Closure ) {
				( $this->before_lock_acquire )( $this );
			}
			if ( ! $this->try_acquire_lock( $revision ) ) {
				return [
					'skipped' => true,
					'reason'  => 'locked',
					'results' => [],
				];
			}
			$acquired = true;
			if ( $this->after_lock_acquire instanceof \Closure ) {
				( $this->after_lock_acquire )( $this );
			}
			if ( $this->stored_revision() >= $revision ) {
				return [
					'skipped' => true,
					'reason'  => 'revision_complete',
					'results' => [],
				];
			}

			$results = $this->repair_all();
			if ( $this->lease_lost ) {
				return [
					'skipped' => true,
					'reason'  => 'lease_lost',
					'results' => $results,
				];
			}
			$this->persist_revision( $revision );

			return [
				'skipped' => false,
				'reason'  => 'repaired',
				'results' => $results,
			];
		} finally {
			if ( $acquired ) {
				$this->release_lock();
			}
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function repair_all(): array {
		if ( $this->before_repair_all instanceof \Closure ) {
			( $this->before_repair_all )( $this );
		}
		$out = [];
		foreach ( $this->locations->list_country_roots() as $country ) {
			if ( $this->lease_active() && ! $this->renew_lease() ) {
				$this->lease_lost = true;
				break;
			}
			$out[] = $this->repair_one( $country );
			if ( $this->after_repair_one instanceof \Closure ) {
				( $this->after_repair_one )( $this, $country );
			}
			if ( $this->lease_active() && ! $this->renew_lease() ) {
				$this->lease_lost = true;
				break;
			}
		}

		return $out;
	}

	/**
	 * Per-country enforcement after a successful pack promotion. Not gated by
	 * the historical repair revision.
	 *
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
		$classified   = $this->classify_geonames_mappings( $mapping_rows );
		$target_name  = $this->authoritative_name( $country );
		$target_norm  = GeographyNameNormalizer::normalize( $target_name );
		$target_ascii = GeographyNameNormalizer::fold_ascii( $target_name );
		$name_mismatch = $country->canonical_name !== $target_name
			|| $country->normalized_name !== $target_norm
			|| $country->ascii_name !== $target_ascii;

		if ( ! $this->needs_repair( $classified, $name_mismatch ) ) {
			return [
				'country_code'      => $country->country_code,
				'id'                => $country->id,
				'location_key'      => $country->location_key,
				'generation'        => $country->generation,
				'changed'           => false,
				'reason'            => 'clean',
				'canonical_name'    => $country->canonical_name,
				'normalized_name'   => $country->normalized_name,
				'detached_mappings' => [],
				'source_scanned'    => false,
			];
		}

		$winner   = $classified['candidates'][0] ?? null;
		$detached = [];
		foreach ( $classified['invalid'] as $row ) {
			$this->detach_geonames_mapping( $country, $row );
			$detached[] = (string) ( $row['external_id'] ?? '' );
		}
		foreach ( $classified['candidates'] as $index => $row ) {
			if ( 0 === $index ) {
				continue;
			}
			$this->detach_geonames_mapping( $country, $row );
			$detached[] = (string) ( $row['external_id'] ?? '' );
		}

		$coords       = [ $country->latitude, $country->longitude ];
		$tainted      = $classified['invalid'] !== [];
		$scanned      = false;
		if ( is_array( $winner ) ) {
			$from_source = $this->identity_coordinates( $country, $winner );
			$scanned     = true === ( $from_source['scanned'] ?? false );
			if ( is_array( $from_source['coords'] ?? null ) ) {
				$coords  = $from_source['coords'];
				$tainted = false;
			}
		}
		if ( $tainted ) {
			$coords = [ null, null ];
		}

		$coord_changed = ! $this->same_coord( $country->latitude, $coords[0] )
			|| ! $this->same_coord( $country->longitude, $coords[1] );

		if ( $name_mismatch || $coord_changed ) {
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
			'country_code'      => $country->country_code,
			'id'                => $country->id,
			'location_key'      => $country->location_key,
			'generation'        => $country->generation,
			'changed'           => $name_mismatch || $coord_changed || $detached !== [],
			'reason'            => 'repaired',
			'canonical_name'    => $target_name,
			'normalized_name'   => $target_norm,
			'detached_mappings' => array_values( array_filter( $detached ) ),
			'source_scanned'    => $scanned,
		];
	}

	/**
	 * Live identity is repaired even while a pack is Importing/Pending.
	 * Staged generation_token rows are never listed or deleted here.
	 * Pack cursor/status/generation are never written.
	 *
	 * @param list<array<string, mixed>> $mapping_rows
	 * @return array{invalid:list<array<string, mixed>>, candidates:list<array<string, mixed>>}
	 */
	private function classify_geonames_mappings( array $mapping_rows ): array {
		$invalid    = [];
		$candidates = [];
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
			$row['_rank']   = $rank;
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

		return [
			'invalid'    => $invalid,
			'candidates' => $candidates,
		];
	}

	/**
	 * @param array{invalid:list<array<string, mixed>>, candidates:list<array<string, mixed>>} $classified
	 */
	private function needs_repair( array $classified, bool $name_mismatch ): bool {
		if ( $name_mismatch ) {
			return true;
		}
		if ( $classified['invalid'] !== [] ) {
			return true;
		}

		return count( $classified['candidates'] ) > 1;
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
		$meta  = $this->mapping_metadata( $row );
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
	 * Opens the pack gazetteer only for a root already known to need repair.
	 *
	 * @param array<string, mixed> $winner
	 * @return array{coords:array{0:?float,1:?float}|null, scanned:bool}
	 */
	private function identity_coordinates( CanonicalLocation $country, array $winner ): array {
		$pack = $this->packs->find_by_country_provider( $country->country_code, GeographyProvider::GeoNames );
		if ( ! $pack instanceof GeographyPack ) {
			return [
				'coords'  => null,
				'scanned' => false,
			];
		}
		$path = (string) $pack->source_reference;
		if ( '' === $path || ! is_readable( $path ) ) {
			return [
				'coords'  => null,
				'scanned' => false,
			];
		}
		++$this->source_scan_count;
		$want   = (string) ( $winner['external_id'] ?? '' );
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return [
				'coords'  => null,
				'scanned' => true,
			];
		}
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$parts = explode( "\t", $line );
			if ( ( $parts[0] ?? '' ) !== $want || count( $parts ) < 6 ) {
				continue;
			}
			fclose( $handle );
			$lat = is_numeric( $parts[4] ) ? (float) $parts[4] : null;
			$lon = is_numeric( $parts[5] ) ? (float) $parts[5] : null;

			return [
				'coords'  => [ $lat, $lon ],
				'scanned' => true,
			];
		}
		fclose( $handle );

		return [
			'coords'  => null,
			'scanned' => true,
		];
	}

	private function stored_revision(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}
		$raw = get_option( self::OPTION_KEY, 0 );

		return $this->parse_revision( $raw );
	}

	private function persist_revision( int $revision ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		update_option( self::OPTION_KEY, $revision, false );
	}

	/**
	 * @param mixed $raw
	 */
	private function parse_revision( mixed $raw ): int {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return max( 0, (int) $raw );
		}
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( is_numeric( $trimmed ) ) {
				return max( 0, (int) $trimmed );
			}
		}

		return 0;
	}

	private function try_acquire_lock( int $revision ): bool {
		$owner = uniqid( 'cir-', true );
		$lease = $this->lease_payload( $owner, $revision );
		if ( $this->cas->add( self::LOCK_OPTION_KEY, $lease ) ) {
			$this->hold_lease( $lease );

			return true;
		}

		$current = $this->cas->get( self::LOCK_OPTION_KEY, false );
		if ( ! $this->lock_is_expired( $current ) ) {
			return false;
		}
		if ( $this->after_observe_lock instanceof \Closure ) {
			( $this->after_observe_lock )( $current );
		}
		if ( ! $this->cas->compare_and_swap( self::LOCK_OPTION_KEY, $current, $lease ) ) {
			return false;
		}
		$this->hold_lease( $lease );

		return true;
	}

	private function renew_lease(): bool {
		if ( ! $this->lease_active() ) {
			return false;
		}
		$next = $this->lease_payload(
			$this->lock_owner,
			(int) ( $this->held_lease['revision'] ?? self::REPAIR_REVISION ),
			(int) ( $this->held_lease['acquired_at'] ?? $this->now() )
		);
		if ( ! $this->cas->compare_and_swap( self::LOCK_OPTION_KEY, $this->held_lease, $next ) ) {
			$this->lock_owner  = '';
			$this->held_lease  = [];
			$this->lease_lost  = true;

			return false;
		}
		$this->held_lease = $next;

		return true;
	}

	private function release_lock(): void {
		if ( '' === $this->lock_owner || [] === $this->held_lease ) {
			$this->lock_owner = '';
			$this->held_lease = [];

			return;
		}
		$this->cas->compare_and_delete( self::LOCK_OPTION_KEY, $this->held_lease );
		$this->lock_owner = '';
		$this->held_lease = [];
	}

	private function lease_active(): bool {
		return '' !== $this->lock_owner && [] !== $this->held_lease && ! $this->lease_lost;
	}

	/**
	 * @param array<string, mixed> $lease
	 */
	private function hold_lease( array $lease ): void {
		$this->lock_owner = (string) ( $lease['owner'] ?? '' );
		$this->held_lease = $lease;
		$this->lease_lost = false;
	}

	/**
	 * @return array{owner:string,expires_at:int,acquired_at:int,revision:int}
	 */
	private function lease_payload( string $owner, int $revision, ?int $acquired_at = null ): array {
		$now = $this->now();

		return [
			'owner'       => $owner,
			'expires_at'  => $now + self::LOCK_TTL_SECONDS,
			'acquired_at' => $acquired_at ?? $now,
			'revision'    => $revision,
		];
	}

	/**
	 * Malformed/legacy lock values expire immediately so they cannot block
	 * forever. Takeover still uses CAS against the exact observed value.
	 *
	 * @param mixed $current
	 */
	private function lock_is_expired( mixed $current ): bool {
		if ( ! is_array( $current ) ) {
			return true;
		}
		$owner = trim( (string) ( $current['owner'] ?? '' ) );
		if ( '' === $owner ) {
			return true;
		}
		$expires = (int) ( $current['expires_at'] ?? 0 );
		if ( $expires <= 0 ) {
			return true;
		}

		return $expires < $this->now();
	}

	private function now(): int {
		if ( $this->now instanceof \Closure ) {
			return (int) ( $this->now )();
		}

		return time();
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
