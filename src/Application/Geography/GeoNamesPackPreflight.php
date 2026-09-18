<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

/**
 * Validates a GeoNames gazetteer file before a pack can become Ready.
 */
final class GeoNamesPackPreflight {

	public const MIN_SCAN = 50;

	public const MAX_SCAN = 8000;

	public function __construct(
		private GeoNamesGazetteerParser $parser = new GeoNamesGazetteerParser()
	) {
	}

	/**
	 * @return array{ok:bool,error:string,country_rows:int,relevant:int,admin:int,locality:int,scanned:int}
	 */
	public function validate( string $file_path, string $country_code ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$empty        = [
			'ok'           => false,
			'error'        => 'empty',
			'country_rows' => 0,
			'relevant'     => 0,
			'admin'        => 0,
			'locality'     => 0,
			'scanned'      => 0,
		];
		if ( 2 !== strlen( $country_code ) || ! is_readable( $file_path ) ) {
			$empty['error'] = is_readable( $file_path ) ? 'country_invalid' : 'unreadable';

			return $empty;
		}
		$size = @filesize( $file_path );
		if ( ! is_int( $size ) || $size <= 0 ) {
			return $empty;
		}

		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			$empty['error'] = 'unreadable';

			return $empty;
		}

		$scanned      = 0;
		$tabular      = 0;
		$corrupt      = 0;
		$country_rows = 0;
		$relevant     = 0;
		$admin        = 0;
		$locality     = 0;
		$has_country  = false;
		$has_adm1     = false;
		while ( $scanned < self::MAX_SCAN ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			++$scanned;
			$trimmed = trim( $line, "\r\n" );
			if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
				continue;
			}
			$parts = explode( "\t", $trimmed );
			if ( count( $parts ) < 19 ) {
				++$corrupt;
				continue;
			}
			++$tabular;
			$row_country = strtoupper( (string) ( $parts[8] ?? '' ) );
			if ( $row_country === $country_code ) {
				++$country_rows;
			}
			$feature_class = (string) ( $parts[6] ?? '' );
			$feature_code  = (string) ( $parts[7] ?? '' );
			if ( ! $this->parser->is_relevant_feature( $feature_class, $feature_code ) ) {
				continue;
			}
			if ( $row_country !== $country_code ) {
				continue;
			}
			++$relevant;
			if ( $this->parser->is_country_feature( $feature_code ) ) {
				$has_country = true;
			} elseif ( 'A' === $feature_class ) {
				++$admin;
				if ( 'ADM1' === $feature_code ) {
					$has_adm1 = true;
				}
			} elseif ( 'P' === $feature_class ) {
				++$locality;
			}
		}
		if ( $scanned >= self::MAX_SCAN ) {
			$more       = fgets( $handle );
			$hit_budget = false !== $more || ! feof( $handle );
		} else {
			$hit_budget = false;
		}
		fclose( $handle );

		$out = [
			'ok'           => false,
			'error'        => '',
			'country_rows' => $country_rows,
			'relevant'     => $relevant,
			'admin'        => $admin,
			'locality'     => $locality,
			'scanned'      => $scanned,
		];

		if ( 0 === $scanned || ( 0 === $tabular && $corrupt > 0 ) ) {
			$out['error'] = 0 === $scanned ? 'empty' : 'corrupt';

			return $out;
		}
		if ( 0 === $tabular ) {
			$out['error'] = 'corrupt';

			return $out;
		}
		if ( $corrupt > 0 && 0 === $tabular ) {
			$out['error'] = 'corrupt';

			return $out;
		}
		if ( $corrupt > 0 && $tabular < max( 1, (int) floor( $scanned * 0.2 ) ) ) {
			$out['error'] = 'corrupt';

			return $out;
		}
		if ( $country_rows <= 0 ) {
			$out['error'] = $hit_budget ? 'indeterminate' : 'wrong_country';
			if ( $hit_budget ) {
				$out['status'] = 'indeterminate';
			}

			return $out;
		}
		if ( $relevant <= 0 ) {
			$out['error'] = $hit_budget ? 'indeterminate' : 'zero_relevant_geography';
			if ( $hit_budget ) {
				$out['status'] = 'indeterminate';
			}

			return $out;
		}
		if ( ! $has_country && ! $has_adm1 && $admin <= 0 && $locality <= 0 ) {
			$out['error'] = $hit_budget ? 'indeterminate' : 'minimum_hierarchy_missing';
			if ( $hit_budget ) {
				$out['status'] = 'indeterminate';
			}

			return $out;
		}
		if ( ! $has_adm1 && $admin <= 0 && $locality <= 0 ) {
			$out['error'] = $hit_budget ? 'indeterminate' : 'minimum_hierarchy_missing';
			if ( $hit_budget ) {
				$out['status'] = 'indeterminate';
			}

			return $out;
		}

		$out['ok'] = true;

		return $out;
	}
}
