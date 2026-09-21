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
	];

	/** @var list<string> */
	public const COUNTRY_FEATURE_CODES = [
		'PCLI',
		'PCLD',
		'PCLF',
		'PCLS',
		'PCLIX',
	];

	public function is_relevant_feature( string $feature_class, string $feature_code ): bool {
		if ( 'P' === $feature_class ) {
			return in_array( $feature_code, self::LOCALITY_FEATURE_CODES, true );
		}

		if ( 'A' === $feature_class ) {
			return in_array( $feature_code, self::ADMIN_FEATURE_CODES, true )
				|| $this->is_country_feature( $feature_code );
		}

		return false;
	}

	/**
	 * Exact GeoNames country/territory identity-enrichment codes. WooCommerce
	 * remains the canonical country-name authority. Historical PCLH and
	 * generic PCL rows are political features, not the WooCommerce country root.
	 */
	public function is_country_feature( string $feature_code ): bool {
		return in_array( $feature_code, self::COUNTRY_FEATURE_CODES, true );
	}

	/**
	 * Higher wins. Zero means the code must not map onto a country root.
	 */
	public function country_identity_rank( string $feature_code ): int {
		return match ( $feature_code ) {
			'PCLI'  => 100,
			'PCLIX' => 90,
			'PCLS'  => 80,
			'PCLF'  => 70,
			'PCLD'  => 60,
			default => 0,
		};
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
			'is_admin'       => 'A' === $feature_class && ! $this->is_country_feature( $feature_code ),
			'is_country'     => 'A' === $feature_class && $this->is_country_feature( $feature_code ),
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
	 * Stream gazetteer rows with a hard scan bound so one tick cannot scan an
	 * unlimited number of irrelevant lines while waiting for N relevant features.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function iterate_file(
		string $path,
		int $after_offset = 0,
		int $max_relevant = 100,
		int $max_scan = 2500,
		float $max_seconds = 4.0
	): \Generator {
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

		$max_relevant = max( 1, min( 250, $max_relevant ) );
		$max_scan     = max( $max_relevant, min( 20000, $max_scan ) );
		$started      = microtime( true );
		$emitted      = 0;
		$scanned      = 0;
		$eof          = true;

		while ( $emitted < $max_relevant && $scanned < $max_scan && ( microtime( true ) - $started ) < $max_seconds ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$eof = false;
			++$scanned;
			$parsed = $this->parse_line( $line );
			$offset = ftell( $handle );
			$cursor = false === $offset ? $after_offset : $offset;
			if ( is_array( $parsed ) ) {
				$parsed['_file_offset'] = $cursor;
				$parsed['_scanned']     = $scanned;
				yield $parsed;
				++$emitted;
			} else {
				yield [
					'_skip'        => true,
					'_file_offset' => $cursor,
					'_scanned'     => $scanned,
				];
			}
		}

		if ( ! $eof ) {
			$pos  = ftell( $handle );
			$peek = fgetc( $handle );
			if ( false === $peek ) {
				$eof = true;
			} elseif ( false !== $pos ) {
				fseek( $handle, $pos );
			}
		}

		$end = ftell( $handle );
		fclose( $handle );

		yield [
			'_batch_end'   => true,
			'_eof'         => $eof,
			'_file_offset' => false === $end ? $after_offset : $end,
			'_scanned'     => $scanned,
			'_emitted'     => $emitted,
		];
	}
}
