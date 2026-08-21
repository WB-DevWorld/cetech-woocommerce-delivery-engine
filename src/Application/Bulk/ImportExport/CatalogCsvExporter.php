<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\ImportExport;

/**
 * Escapes catalog CSV cells. Formula injection is prefixed; blank remains blank.
 */
final class CatalogCsvExporter {

	/**
	 * @param list<string>              $headers
	 * @param list<array<string, mixed>> $rows
	 */
	public function to_csv( array $headers, array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return '';
		}
		fputcsv( $handle, $headers );
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $headers as $header ) {
				$line[] = CatalogCsvMapper::escape_csv_value( (string) ( $row[ $header ] ?? '' ) );
			}
			fputcsv( $handle, $line );
		}
		rewind( $handle );
		$csv = stream_get_contents( $handle ) ?: '';
		fclose( $handle );

		return $csv;
	}
}
