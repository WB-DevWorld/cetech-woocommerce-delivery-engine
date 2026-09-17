<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;

/**
 * Admin-facing pack lifecycle. Large imports are batched and resumable.
 *
 * Explicit operations:
 * - install: first dataset for a country
 * - update: new dataset; resets cursor/progress; preserves canonical IDs
 * - retry: resume the current dataset from its persisted cursor
 */
final class GeographyPackService {

	public const HOOK = 'cetech_de_geography_pack_tick';

	public const DOWNLOAD_HOOK = 'cetech_de_geography_pack_download';

	public const GROUP = 'cetech-delivery-engine-geography';

	public const REVISION_OPTION = 'cetech_de_geography_revision';

	public const GEONAMES_URL_PATTERN = '#^https://download\.geonames\.org/export/dump/[A-Z]{2}\.zip$#';

	public const MAX_ARCHIVE_BYTES = 83886080;

	public const MAX_UNCOMPRESSED_BYTES = 262144000;

	public function __construct(
		private GeographyPackRepositoryInterface $packs,
		private GeoNamesPackImporter $importer,
		private WooCommerceGeographyBootstrap $woo_bootstrap,
		private GeoNamesPackPreflight $preflight = new GeoNamesPackPreflight()
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

		if ( '' === $file_path ) {
			$file_path = (string) $pack->source_reference;
		}

		if ( '' !== $file_path && is_readable( $file_path ) ) {
			$checksum = $this->checksum_of( $file_path );
			if ( GeographyPackStatus::Ready === $pack->status && $checksum === $pack->checksum && '' !== $pack->checksum ) {
				return $pack;
			}
			if ( GeographyPackStatus::Importing === $pack->status && $checksum === $pack->checksum && '' !== $pack->checksum ) {
				return $this->retry( $pack->id, $file_path );
			}

			return $this->update( $country_code, $file_path );
		}

		$this->enqueue_tick( $pack->id, $file_path, $pack->target_token() );

		return $this->packs->find_by_id( $pack->id ) ?? $pack;
	}

	public function update( string $country_code, string $file_path ): GeographyPack {
		$country_code = strtoupper( trim( $country_code ) );
		$this->woo_bootstrap->bootstrap_country( $country_code );
		$pack = $this->importer->ensure_pack( $country_code, $file_path );
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				'0',
				$pack->progress,
				'Gazetteer file is not readable.'
			);

			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		}

		$checksum = $this->checksum_of( $file_path );
		$preflight = $this->preflight->validate( $file_path, $country_code );
		if ( ! $preflight['ok'] ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				'0',
				$pack->progress,
				$this->preflight_error_message( (string) $preflight['error'] )
			);

			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		}

		$version  = $this->dataset_version( $file_path, $checksum );
		$locked   = $this->acquire_lifecycle_lock( $pack->id, 'update' );
		if ( ! $locked ) {
			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		}
		try {
			$pack   = $this->importer->begin_dataset( $pack, $file_path, $checksum, $version );
			$stored = $this->store_generation_file( $pack->country_code, $pack->target_token(), $checksum, $file_path );
			if ( '' === $stored ) {
				$this->packs->update_progress(
					$pack->id,
					GeographyPackStatus::Failed,
					'0',
					$pack->progress,
					'Could not create an immutable generation source file.'
				);

				return $this->packs->find_by_id( $pack->id ) ?? $pack;
			}
			if ( $stored !== $file_path ) {
				$pack = $this->packs->save(
					[
						'id'               => $pack->id,
						'source_reference' => $stored,
					]
				);
				$file_path = $stored;
			}
			$this->enqueue_tick( $pack->id, $file_path, $pack->target_token() );

			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		} finally {
			$this->release_lifecycle_lock( $pack->id );
		}
	}

	public function retry( int $pack_id, string $file_path = '' ): GeographyPack {
		$pack = $this->packs->find_by_id( $pack_id );
		if ( ! $pack instanceof GeographyPack ) {
			return new GeographyPack(
				0,
				'',
				GeographyProvider::GeoNames,
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				GeographyPackStatus::Failed,
				'0',
				[],
				'missing',
				null
			);
		}
		if ( '' === $file_path ) {
			$file_path = $pack->source_reference;
		}
		if ( '' !== $file_path && is_readable( $file_path ) && '' !== $pack->checksum ) {
			$checksum = $this->checksum_of( $file_path );
			if ( $checksum !== $pack->checksum ) {
				return $this->update( $pack->country_code, $file_path );
			}
		}

		if ( ! $this->acquire_lifecycle_lock( $pack->id, 'retry' ) ) {
			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		}
		try {
			$pack = $this->packs->find_by_id( $pack->id ) ?? $pack;
			if ( '' === $file_path ) {
				$file_path = $pack->source_reference;
			}
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Importing,
				$pack->import_cursor,
				$pack->progress,
				''
			);
			$this->enqueue_tick( $pack->id, $file_path, $pack->target_token() );

			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		} finally {
			$this->release_lifecycle_lock( $pack->id );
		}
	}

	/**
	 * Queue a background fetch of the official GeoNames country ZIP.
	 */
	public function queue_official_download( string $country_code ): GeographyPack {
		$country_code = strtoupper( trim( $country_code ) );
		if ( 2 !== strlen( $country_code ) || ! ctype_alpha( $country_code ) ) {
			throw new \InvalidArgumentException( 'Country code is invalid.' );
		}
		$this->woo_bootstrap->bootstrap_country( $country_code );
		$pack = $this->importer->ensure_pack( $country_code, '' );
		if ( ! $this->acquire_lifecycle_lock( $pack->id, 'begin' ) ) {
			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		}
		try {
			$pack = $this->packs->find_by_id( $pack->id ) ?? $pack;
			$url  = self::geonames_url( $country_code );
			if ( ! $this->is_allowed_geonames_url( $url, $country_code ) ) {
				$this->packs->update_progress(
					$pack->id,
					GeographyPackStatus::Failed,
					$pack->import_cursor,
					$pack->progress,
					'Official GeoNames URL is not allowed.'
				);

				return $this->packs->find_by_id( $pack->id ) ?? $pack;
			}

			$pack = $this->packs->save(
				[
					'id'               => $pack->id,
					'country_code'     => $pack->country_code,
					'provider'         => $pack->provider->value,
					'dataset_name'     => 'gazetteer',
					'dataset_version'  => $pack->dataset_version,
					'source_url'       => $url,
					'source_reference' => $pack->source_reference,
					'checksum'         => $pack->checksum,
					'license_name'     => GeoNamesPackImporter::LICENSE_NAME,
					'license_url'      => GeoNamesPackImporter::LICENSE_URL,
					'attribution_text' => GeoNamesPackImporter::ATTRIBUTION,
					'status'           => GeographyPackStatus::Pending->value,
					'import_cursor'    => '0',
					'progress'         => [
						'phase'              => 'download',
						'processed'          => 0,
						'imported'           => 0,
						'skipped'            => 0,
						'total'              => 0,
						'active_generation'  => (int) ( $pack->progress['active_generation'] ?? 0 ),
						'target_generation'  => max( 1, (int) ( $pack->progress['active_generation'] ?? 0 ), (int) ( $pack->progress['target_generation'] ?? 0 ) ) + 1,
						'target_token'       => strtolower( $pack->provider->value ) . ':' . $country_code . ':' . $pack->id . ':' . bin2hex( random_bytes( 8 ) ),
						'last_successful'    => $pack->last_successful(),
					],
					'last_error'       => '',
				]
			);

			$this->enqueue_download( $pack->id, $country_code, $pack->target_token() );

			return $this->packs->find_by_id( $pack->id ) ?? $pack;
		} finally {
			$this->release_lifecycle_lock( $pack->id );
		}
	}

	/**
	 * Background download + ZIP extract. Never runs as a single admin HTTP request.
	 *
	 * @return array<string, mixed>
	 */
	public function download_tick( int $pack_id, string $country_code, string $generation_token = '' ): array {
		$country_code = strtoupper( trim( $country_code ) );
		if ( ! $this->acquire_lifecycle_lock( $pack_id, 'download' ) ) {
			return [ 'status' => 'noop', 'reason' => 'locked' ];
		}
		try {
			$pack = $this->packs->find_by_id( $pack_id );
			if ( ! $pack instanceof GeographyPack ) {
				return [ 'status' => 'missing' ];
			}
			if ( '' === $generation_token ) {
				$generation_token = $pack->target_token();
			}
			if ( $this->is_stale_token( $pack, $generation_token ) ) {
				return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
			}

			$url = self::geonames_url( $country_code );
			if ( ! $this->is_allowed_geonames_url( $url, $country_code ) ) {
				$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'Blocked GeoNames URL.' );

				return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'ssrf_blocked' ];
			}

			$dir = $this->storage_dir();
			if ( '' === $dir ) {
				$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'Uploads directory is not writable.' );

				return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'storage' ];
			}

			$zip_path = $dir . '/' . $country_code . '.' . $this->safe_token_segment( $generation_token ) . '.zip';
			$fetched  = $this->safe_download( $url, $zip_path );
			if ( ! $fetched ) {
				$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'Official GeoNames download failed.' );

				return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'download_failed' ];
			}

			$txt = $this->extract_gazetteer_zip( $zip_path, $dir, $country_code, $generation_token );
			$this->safe_unlink( $zip_path );
			$fresh = $this->packs->find_by_id( $pack_id );
			if ( ! $fresh instanceof GeographyPack || $this->is_stale_token( $fresh, $generation_token ) ) {
				$this->safe_unlink( $txt );

				return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
			}
			if ( '' === $txt || ! is_readable( $txt ) ) {
				$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'The GeoNames archive did not contain a country gazetteer file.' );

				return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'extract_failed' ];
			}

			return $this->complete_official_source( $pack_id, $generation_token, $txt );
		} finally {
			$this->release_lifecycle_lock( $pack_id );
		}
	}

	/**
	 * Attach a downloaded gazetteer to the current target token. Never mints a newer target.
	 *
	 * @return array<string, mixed>
	 */
	public function complete_official_source( int $pack_id, string $generation_token, string $txt_path ): array {
		$pack = $this->packs->find_by_id( $pack_id );
		if ( ! $pack instanceof GeographyPack ) {
			return [ 'status' => 'missing' ];
		}
		if ( $this->is_stale_token( $pack, $generation_token ) ) {
			return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
		}
		if ( '' === $txt_path || ! is_readable( $txt_path ) ) {
			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'file_unreadable' ];
		}
		$preflight = $this->preflight->validate( $txt_path, $pack->country_code );
		if ( ! $preflight['ok'] ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				'0',
				$pack->progress,
				$this->preflight_error_message( (string) $preflight['error'] )
			);

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => (string) $preflight['error'] ];
		}
		$checksum = $this->checksum_of( $txt_path );
		$version  = $this->dataset_version( $txt_path, $checksum );
		$pack     = $this->importer->begin_dataset( $pack, $txt_path, $checksum, $version, $generation_token );
		if ( $pack->target_token() !== $generation_token ) {
			return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
		}
		$stored = $this->store_generation_file( $pack->country_code, $generation_token, $checksum, $txt_path );
		if ( '' === $stored ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				'0',
				$pack->progress,
				'Could not create an immutable generation source file.'
			);

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'generation_file' ];
		}
		$pack = $this->packs->save(
			[
				'id'               => $pack->id,
				'source_reference' => $stored,
			]
		);
		$this->enqueue_tick( $pack->id, $stored, $generation_token );

		return [ 'status' => $pack->status->value, 'pack_id' => $pack->id, 'source' => basename( $stored ) ];
	}

	/**
	 * Accept an uploaded gazetteer .txt or .zip into the controlled uploads directory.
	 */
	public function store_upload( string $country_code, string $tmp_path, string $original_name ): string {
		$country_code = strtoupper( trim( $country_code ) );
		$dir          = $this->storage_dir();
		if ( '' === $dir || ! is_readable( $tmp_path ) ) {
			return '';
		}

		$ext = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$uniq = bin2hex( random_bytes( 8 ) );
		if ( 'txt' === $ext ) {
			$dest = $dir . '/' . $country_code . '.' . $uniq . '.incoming.txt';
			if ( ! @copy( $tmp_path, $dest ) ) {
				return '';
			}

			return $dest;
		}

		if ( 'zip' === $ext ) {
			$zip_dest = $dir . '/' . $country_code . '.' . $uniq . '-upload.zip';
			if ( ! @copy( $tmp_path, $zip_dest ) ) {
				return '';
			}
			$txt = $this->extract_gazetteer_zip( $zip_dest, $dir, $country_code, $uniq );
			$this->safe_unlink( $zip_dest );

			return $txt;
		}

		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function tick( int $pack_id, string $file_path, int $batch_size = 100, string $generation_token = '' ): array {
		if ( ! $this->acquire_lifecycle_lock( $pack_id, 'tick' ) ) {
			return [ 'status' => 'noop', 'reason' => 'locked' ];
		}
		try {
			$pack = $this->packs->find_by_id( $pack_id );
			if ( ! $pack instanceof GeographyPack ) {
				return [ 'status' => 'missing' ];
			}
			if ( '' === $generation_token ) {
				$generation_token = $pack->target_token();
			}
			if ( $this->is_stale_token( $pack, $generation_token ) ) {
				return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
			}

			return $this->run_tick( $pack, $file_path, $batch_size, $generation_token );
		} finally {
			$this->release_lifecycle_lock( $pack_id );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_tick( GeographyPack $pack, string $file_path, int $batch_size, string $generation_token ): array {
		$pack_id = $pack->id;
		$fresh   = $this->packs->find_by_id( $pack_id );
		if ( ! $fresh instanceof GeographyPack || $this->is_stale_token( $fresh, $generation_token ) ) {
			return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
		}
		$pack = $fresh;
		if ( '' === $file_path ) {
			$file_path = (string) $pack->source_reference;
		}

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			$this->packs->update_progress(
				$pack->id,
				GeographyPackStatus::Failed,
				$pack->import_cursor,
				$pack->progress,
				'Gazetteer file is not readable. Upload a pack or fetch the official GeoNames country file.'
			);

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'file_unreadable' ];
		}

		if ( '' !== $pack->checksum ) {
			$checksum = $this->checksum_of( $file_path );
			if ( $checksum !== $pack->checksum ) {
				return [ 'status' => 'noop', 'reason' => 'checksum_mismatch' ];
			}
		}

		$result = $this->importer->import_batch( $pack, $file_path, $batch_size );
		$after  = $this->packs->find_by_id( $pack_id );
		if ( $after instanceof GeographyPack && $this->is_stale_token( $after, $generation_token ) && 'noop' !== ( $result['status'] ?? '' ) ) {
			return [ 'status' => 'noop', 'reason' => 'stale_generation' ];
		}
		$status = (string) ( $result['status'] ?? '' );
		if ( GeographyPackStatus::Importing->value === $status ) {
			$fresh = $this->packs->find_by_id( $pack_id );
			$this->enqueue_tick( $pack_id, $file_path, $fresh instanceof GeographyPack ? $fresh->target_token() : $pack->target_token() );
		}
		if ( GeographyPackStatus::Ready->value === $status ) {
			$this->bump_revision();
		}

		return $result;
	}

	public function find( int $id ): ?GeographyPack {
		return $this->packs->find_by_id( $id );
	}

	public static function geonames_url( string $country_code ): string {
		return sprintf( GeoNamesPackImporter::SOURCE_URL_PATTERN, strtoupper( $country_code ) );
	}

	public function is_allowed_geonames_url( string $url, string $country_code ): bool {
		$country_code = strtoupper( $country_code );
		$expected     = self::geonames_url( $country_code );

		return $url === $expected && (bool) preg_match( self::GEONAMES_URL_PATTERN, $url );
	}

	public function checksum_of( string $file_path ): string {
		$hash = hash_file( 'sha256', $file_path );

		return is_string( $hash ) ? $hash : '';
	}

	public function dataset_version( string $file_path, string $checksum ): string {
		$mtime = @filemtime( $file_path );
		$date  = is_int( $mtime ) ? gmdate( 'Y-m-d', $mtime ) : gmdate( 'Y-m-d' );

		return $date . '.' . substr( $checksum, 0, 12 );
	}

	private function enqueue_tick( int $pack_id, string $file_path, string $generation_token = '' ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$group = $this->pack_action_group( $pack_id );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, null, $group );
			}
			$args = [
				'pack_id'          => $pack_id,
				'source_path'      => $file_path,
				'generation_token' => $generation_token,
			];
			as_enqueue_async_action( self::HOOK, $args, $group, true );
		}
	}

	private function enqueue_download( int $pack_id, string $country_code, string $generation_token ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$group = $this->pack_action_group( $pack_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::DOWNLOAD_HOOK, null, $group );
			as_unschedule_all_actions( self::HOOK, null, $group );
		}
		as_enqueue_async_action(
			self::DOWNLOAD_HOOK,
			[
				'pack_id'          => $pack_id,
				'country_code'     => $country_code,
				'generation_token' => $generation_token,
			],
			$group,
			true
		);
	}

	private function pack_action_group( int $pack_id ): string {
		return self::GROUP . '-' . $pack_id;
	}

	private function is_stale_token( GeographyPack $pack, string $generation_token ): bool {
		$expected = $pack->target_token();
		if ( '' === $expected ) {
			return false;
		}

		return $generation_token !== $expected;
	}

	private function acquire_lifecycle_lock( int $pack_id, string $holder ): bool {
		$key = 'cetech_de_geo_pack_cas_' . $pack_id;
		if ( function_exists( 'add_option' ) ) {
			return add_option( $key, $holder, '', false );
		}

		return true;
	}

	private function release_lifecycle_lock( int $pack_id ): void {
		$key = 'cetech_de_geo_pack_cas_' . $pack_id;
		if ( function_exists( 'delete_option' ) ) {
			delete_option( $key );
		}
	}

	private function store_generation_file( string $country_code, string $generation_token, string $checksum, string $source_path ): string {
		$dir = $this->storage_dir();
		$seg = $this->safe_token_segment( $generation_token );
		if ( '' === $dir || ! is_readable( $source_path ) || '' === $seg ) {
			return '';
		}
		$dest = $dir . '/' . strtoupper( $country_code ) . '.' . $seg . '.' . substr( $checksum, 0, 12 ) . '.txt';
		if ( $dest === $source_path ) {
			return $source_path;
		}
		if ( ! @copy( $source_path, $dest ) ) {
			return '';
		}

		return $dest;
	}

	private function safe_token_segment( string $token ): string {
		$segment = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $token ) ?? '';

		return trim( $segment, '-' );
	}

	private function preflight_error_message( string $code ): string {
		return match ( $code ) {
			'empty' => 'The geography pack file is empty.',
			'corrupt' => 'The geography pack file is not valid GeoNames tabular data.',
			'wrong_country' => 'The geography pack does not contain rows for the selected country.',
			'zero_relevant_geography' => 'The geography pack has no usable geography rows.',
			'minimum_hierarchy_missing' => 'The geography pack is missing a minimum viable administrative hierarchy.',
			'unreadable' => 'The geography pack file is not readable.',
			default => 'The geography pack failed preflight validation.',
		};
	}

	private function bump_revision(): void {
		$current = (int) get_option( self::REVISION_OPTION, 0 );
		update_option( self::REVISION_OPTION, $current + 1, false );
	}

	private function storage_dir(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return '';
		}
		$base = (string) ( $uploads['basedir'] ?? '' );
		if ( '' === $base ) {
			return '';
		}
		$dir = $base . '/cetech-delivery-engine/geography';
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}

		return is_dir( $dir ) && is_writable( $dir ) ? $dir : '';
	}

	private function safe_download( string $url, string $destination ): bool {
		if ( ! function_exists( 'wp_safe_remote_get' ) ) {
			return false;
		}
		if ( is_file( $destination ) ) {
			$this->safe_unlink( $destination );
		}
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 120,
				'redirection'         => 2,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => self::MAX_ARCHIVE_BYTES,
			]
		);
		if ( is_wp_error( $response ) ) {
			$this->safe_unlink( $destination );

			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! is_readable( $destination ) ) {
			$this->safe_unlink( $destination );

			return false;
		}
		$size = filesize( $destination );
		if ( ! is_int( $size ) || $size <= 0 || $size > self::MAX_ARCHIVE_BYTES ) {
			$this->safe_unlink( $destination );

			return false;
		}

		return true;
	}

	private function extract_gazetteer_zip( string $zip_path, string $dest_dir, string $country_code, string $generation_token = '' ): string {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return '';
		}
		$size = @filesize( $zip_path );
		if ( ! is_int( $size ) || $size <= 0 || $size > self::MAX_ARCHIVE_BYTES ) {
			$this->safe_unlink( $zip_path );

			return '';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return '';
		}

		$extracted = '';
		$dest_real = realpath( $dest_dir );
		if ( ! is_string( $dest_real ) ) {
			$zip->close();

			return '';
		}

		$expected = strtoupper( $country_code ) . '.txt';
		$chosen   = null;
		$uncompressed = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) ? (string) ( $stat['name'] ?? '' ) : (string) $zip->getNameIndex( $i );
			if ( '' === $name || str_contains( $name, '..' ) || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
				$zip->close();
				$this->safe_unlink( $zip_path );

				return '';
			}
			$uncompressed += (int) ( is_array( $stat ) ? ( $stat['size'] ?? 0 ) : 0 );
			if ( $uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
				$zip->close();
				$this->safe_unlink( $zip_path );

				return '';
			}
			$base = basename( $name );
			if ( ! str_ends_with( strtolower( $base ), '.txt' ) ) {
				continue;
			}
			if ( 0 === strcasecmp( $base, $expected ) ) {
				$chosen = $name;
			} elseif ( null === $chosen ) {
				$chosen = $name;
			}
		}
		if ( null === $chosen || 0 !== strcasecmp( basename( $chosen ), $expected ) ) {
			$zip->close();

			return '';
		}

		$target = $dest_real . DIRECTORY_SEPARATOR . $country_code . '.' . $this->safe_token_segment( '' !== $generation_token ? $generation_token : 'incoming' ) . '.incoming.txt';
		if ( ! str_starts_with( $target, $dest_real ) ) {
			$zip->close();

			return '';
		}
		$stream = $zip->getStream( $chosen );
		if ( ! is_resource( $stream ) ) {
			$zip->close();

			return '';
		}
		$out = fopen( $target, 'wb' );
		if ( false === $out ) {
			fclose( $stream );
			$zip->close();

			return '';
		}
		$written = 0;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 8192 );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			$written += strlen( $chunk );
			if ( $written > self::MAX_UNCOMPRESSED_BYTES ) {
				fclose( $stream );
				fclose( $out );
				$zip->close();
				$this->safe_unlink( $target );

				return '';
			}
			fwrite( $out, $chunk );
		}
		fclose( $stream );
		fclose( $out );
		$zip->close();
		$extracted = $target;

		return $extracted;
	}

	private function safe_unlink( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path );
		}
	}
}
