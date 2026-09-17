<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

/**
 * Streams a GeoNames country gazetteer dump. Does not load the whole file.
 *
 * Columns: geonameid, name, asciiname, alternatenames, latitude, longitude,
 * feature class, feature code, country code, cc2, admin1, admin2, admin3,
 * admin4, population, elevation, dem, timezone, modification date.
 */
final class GeoNamesGazetteerParser {

	/** @var list<string> */
	public const LOCALITY_FEATURE_CODES = [
		'PPL',
		'PPLA',
		'PPLA2',
		'PPLA3',
		'PPLA4',
		'PPLC',
		'PPLG',
		'PPLS',
		'PPLX',
		'STLMT',
	];

	/** @var list<string> */
	public const ADMIN_FEATURE_CODES = [
		'ADM1',
		'ADM2',
		'ADM3',
		'ADM4',
		'PCLI',
	];

	public function is_relevant_feature( string $feature_class, string $feature_code ): bool {
		if ( 'P' === $feature_class ) {
			return in_array( $feature_code, self::LOCALITY_FEATURE_CODES, true );
		}

		if ( 'A' === $feature_class ) {
			return in_array( $feature_code, self::ADMIN_FEATURE_CODES, true );
		}

		return false;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function parse_line( string $line ): ?array {
		$line = trim( $line, "\r\n" );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			return null;
		}

		$parts = explode( "\t", $line );
		if ( count( $parts ) < 19 ) {
			return null;
		}

		$feature_class = (string) $parts[6];
		$feature_code  = (string) $parts[7];
		if ( ! $this->is_relevant_feature( $feature_class, $feature_code ) ) {
			return null;
		}

		$alternates = [];
		if ( '' !== trim( (string) $parts[3] ) ) {
			foreach ( explode( ',', (string) $parts[3] ) as $alias ) {
				$alias = trim( $alias );
				if ( '' !== $alias ) {
					$alternates[] = $alias;
				}
			}
		}

		return [
			'geoname_id'     => (string) $parts[0],
			'name'           => (string) $parts[1],
			'ascii_name'     => (string) $parts[2],
			'alternates'     => $alternates,
			'latitude'       => is_numeric( $parts[4] ) ? (float) $parts[4] : null,
			'longitude'      => is_numeric( $parts[5] ) ? (float) $parts[5] : null,
			'feature_class'  => $feature_class,
			'feature_code'   => $feature_code,
			'country_code'   => strtoupper( (string) $parts[8] ),
			'admin1'         => (string) $parts[10],
			'admin2'         => (string) $parts[11],
			'admin3'         => (string) $parts[12],
			'admin4'         => (string) $parts[13],
			'modified'       => (string) $parts[18],
			'is_locality'    => 'P' === $feature_class,
			'is_admin'       => 'A' === $feature_class,
			'admin_level'    => match ( $feature_code ) {
				'ADM1' => 1,
				'ADM2' => 2,
				'ADM3' => 3,
				'ADM4' => 4,
				default => null,
			},
		];
	}

	/**
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function iterate_file( string $path, int $after_offset = 0, int $limit = 100 ): \Generator {
		if ( ! is_readable( $path ) ) {
			return;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return;
		}

		if ( $after_offset > 0 ) {
			fseek( $handle, $after_offset );
		}

		$emitted = 0;
		while ( $emitted < $limit && false !== ( $line = fgets( $handle ) ) ) {
			$parsed = $this->parse_line( $line );
			$offset = ftell( $handle );
			if ( is_array( $parsed ) ) {
				$parsed['_file_offset'] = false === $offset ? $after_offset : $offset;
				yield $parsed;
				++$emitted;
			} elseif ( false !== $offset ) {
				yield [
					'_skip'         => true,
					'_file_offset'  => $offset,
				];
			}
		}

		fclose( $handle );
	}
}
