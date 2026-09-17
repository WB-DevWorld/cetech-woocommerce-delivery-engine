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

		$this->enqueue_tick( $pack->id, $file_path );

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
		$version  = $this->dataset_version( $file_path, $checksum );
		$pack     = $this->importer->begin_dataset( $pack, $file_path, $checksum, $version );
		$this->bump_revision();
		$this->enqueue_tick( $pack->id, $file_path );

		return $this->packs->find_by_id( $pack->id ) ?? $pack;
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

		$this->packs->update_progress(
			$pack->id,
			GeographyPackStatus::Importing,
			$pack->import_cursor,
			$pack->progress,
			''
		);
		$this->enqueue_tick( $pack->id, $file_path );

		return $this->packs->find_by_id( $pack->id ) ?? $pack;
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

		$this->packs->save(
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
				'progress'         => [ 'phase' => 'download', 'processed' => 0, 'imported' => 0, 'skipped' => 0, 'total' => 0 ],
				'last_error'       => '',
			]
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::DOWNLOAD_HOOK,
				[
					'pack_id'      => $pack->id,
					'country_code' => $country_code,
				],
				self::GROUP
			);
		}

		return $this->packs->find_by_id( $pack->id ) ?? $pack;
	}

	/**
	 * Background download + ZIP extract. Never runs as a single admin HTTP request.
	 *
	 * @return array<string, mixed>
	 */
	public function download_tick( int $pack_id, string $country_code ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$pack         = $this->packs->find_by_id( $pack_id );
		if ( ! $pack instanceof GeographyPack ) {
			return [ 'status' => 'missing' ];
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

		$zip_path = $dir . '/' . $country_code . '.zip';
		$fetched  = $this->safe_download( $url, $zip_path );
		if ( ! $fetched ) {
			$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'Official GeoNames download failed.' );

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'download_failed' ];
		}

		$txt = $this->extract_gazetteer_zip( $zip_path, $dir, $country_code );
		$this->safe_unlink( $zip_path );
		if ( '' === $txt || ! is_readable( $txt ) ) {
			$this->packs->update_progress( $pack->id, GeographyPackStatus::Failed, '0', $pack->progress, 'The GeoNames archive did not contain a country gazetteer file.' );

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'extract_failed' ];
		}

		$pack = $this->update( $country_code, $txt );

		return [ 'status' => $pack->status->value, 'pack_id' => $pack->id, 'source' => basename( $txt ) ];
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
		if ( 'txt' === $ext ) {
			$dest = $dir . '/' . $country_code . '.txt';
			if ( ! @copy( $tmp_path, $dest ) ) {
				return '';
			}

			return $dest;
		}

		if ( 'zip' === $ext ) {
			$zip_dest = $dir . '/' . $country_code . '-upload.zip';
			if ( ! @copy( $tmp_path, $zip_dest ) ) {
				return '';
			}
			$txt = $this->extract_gazetteer_zip( $zip_dest, $dir, $country_code );
			$this->safe_unlink( $zip_dest );

			return $txt;
		}

		return '';
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
				'Gazetteer file is not readable. Upload a pack or fetch the official GeoNames country file.'
			);

			return [ 'status' => GeographyPackStatus::Failed->value, 'error' => 'file_unreadable' ];
		}

		if ( '' !== $pack->checksum ) {
			$checksum = $this->checksum_of( $file_path );
			if ( $checksum !== $pack->checksum ) {
				return $this->update( $pack->country_code, $file_path )->publicAdminRow() + [ 'status' => GeographyPackStatus::Importing->value ];
			}
		}

		$result = $this->importer->import_batch( $pack, $file_path, $batch_size );
		$status = (string) ( $result['status'] ?? '' );
		if ( GeographyPackStatus::Importing->value === $status ) {
			$this->enqueue_tick( $pack_id, $file_path );
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

	private function enqueue_tick( int $pack_id, string $file_path ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK,
				[
					'pack_id'     => $pack_id,
					'source_path' => $file_path,
				],
				self::GROUP
			);
		}
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

	private function extract_gazetteer_zip( string $zip_path, string $dest_dir, string $country_code ): string {
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

		$target = $dest_real . DIRECTORY_SEPARATOR . $country_code . '.txt';
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
