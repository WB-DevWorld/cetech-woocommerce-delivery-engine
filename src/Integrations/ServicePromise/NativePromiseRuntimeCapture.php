<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise;

/** Host capture only. The digest names actual runtime/tzdata bytes, never an arbitrary label. */
final class NativePromiseRuntimeCapture {
	private const MAX_FILE_BYTES = 134217728;
	private const MAX_TZ_BYTES = 16777216;
	private const MAX_ZONES = 2048;
	private const MAX_MAPPED_BYTES = 268435456;

	public function capture(): array {
		$version = timezone_version_get();
		if ( extension_loaded( 'timezonedb' ) || ( '0.system' !== $version && 1 !== preg_match( '/\A[0-9]{4}\.[0-9]{1,3}\z/D', $version ) ) ) { self::refuse(); }
		$binary = realpath( PHP_BINARY );
		if ( false === $binary ) { self::refuse(); }
		$runtime = [ 'php_version' => PHP_VERSION, 'php_version_id' => PHP_VERSION_ID, 'sapi' => PHP_SAPI, 'integer_bytes' => PHP_INT_SIZE, 'binary_digest' => self::file_digest( $binary, self::MAX_FILE_BYTES ), 'mapped_runtime_images' => $this->mapped_images(), 'timezone_data_version' => $version ];
		// Debian's 0.system date extension consumes the host TZif database. Bundled timelib
		// data is part of the attested interpreter binary and needs no unrelated system file.
		if ( '0.system' === $version ) {
			$root = realpath( '/usr/share/zoneinfo' );
			if ( false === $root || ! is_dir( $root ) ) { self::refuse(); }
			$zones = \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ); sort( $zones, SORT_STRING );
			if ( [] === $zones || count( $zones ) > self::MAX_ZONES ) { self::refuse(); }
			$total = 0; $files = [];
			foreach ( $zones as $zone ) {
				if ( 1 !== preg_match( '/\A[a-zA-Z0-9_+.-]+(?:\/[a-zA-Z0-9_+.-]+)*\z/D', $zone ) || str_contains( $zone, '..' ) ) { self::refuse(); }
				$file = realpath( $root . '/' . $zone );
				if ( false === $file || ! str_starts_with( $file, $root . '/' ) ) { self::refuse(); }
				$size = filesize( $file ); if ( false === $size || $size < 44 || $size > self::MAX_TZ_BYTES - $total ) { self::refuse(); } $total += $size;
				$handle = fopen( $file, 'rb' ); if ( false === $handle ) { self::refuse(); }
				try { if ( 'TZif' !== fread( $handle, 4 ) && ! in_array( $zone, [ 'leapseconds', 'tzdata.zi' ], true ) ) { self::refuse(); } } finally { fclose( $handle ); }
				$files[] = [ 'zone' => $zone, 'bytes' => $size, 'digest' => self::file_digest( $file, self::MAX_TZ_BYTES ) ];
			}
			$runtime['timezone_files'] = $files;
		}
		$json = json_encode( $runtime, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$digest = hash( 'sha256', 'cetech-native-promise-runtime-v1:' . $json );
		return [ 'timezone_data_version' => $version, 'runtime_id' => 'php-' . PHP_VERSION_ID . '-' . substr( $runtime['binary_digest'], 0, 24 ), 'digest' => $digest ];
	}
	/** The kernel's actual mapped executable files include dynamically loaded date/timelib code. */
	private function mapped_images(): array {
		$handle = fopen( '/proc/self/maps', 'rb' ); if ( false === $handle ) { self::refuse(); }
		try { $maps = stream_get_contents( $handle, 2097153 ); if ( false === $maps || strlen( $maps ) > 2097152 || ! feof( $handle ) ) { self::refuse(); } } finally { fclose( $handle ); }
		$files = [];
		foreach ( explode( "\n", $maps ) as $line ) {
			if ( '' === $line ) { continue; }
			if ( 1 !== preg_match( '/\A[a-f0-9]+-[a-f0-9]+\s+([-rwxps]{4})\s+[a-f0-9]+\s+[a-f0-9]+:[a-f0-9]+\s+[0-9]+(?:\s+(\S.*))?\s*\z/D', $line, $match ) ) { self::refuse(); }
			if ( ! str_contains( $match[1], 'x' ) || ! isset( $match[2] ) || ! str_starts_with( $match[2], '/' ) ) { continue; }
			$path = realpath( $match[2] ); if ( false === $path ) { self::refuse(); } $files[$path] = true;
		}
		if ( [] === $files || count( $files ) > 256 ) { self::refuse(); } ksort( $files, SORT_STRING ); $total = 0; $images = [];
		foreach ( array_keys( $files ) as $file ) { $size = filesize( $file ); if ( false === $size || $size < 1 || $size > self::MAX_MAPPED_BYTES - $total ) { self::refuse(); } $total += $size; $images[] = [ 'name' => basename( $file ), 'bytes' => $size, 'digest' => self::file_digest( $file, self::MAX_FILE_BYTES ) ]; }
		usort( $images, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) ?: strcmp( $a['digest'], $b['digest'] ) ); return $images;
	}
	private static function file_digest( string $file, int $limit ): string {
		$size = filesize( $file ); if ( false === $size || $size < 1 || $size > $limit || ! is_file( $file ) || ! is_readable( $file ) ) { self::refuse(); }
		$hash = hash_file( 'sha256', $file ); if ( false === $hash || filesize( $file ) !== $size ) { self::refuse(); } return $hash;
	}
	private static function refuse(): never { throw new \RuntimeException( 'The native promise runtime cannot be attested.' ); }
}
