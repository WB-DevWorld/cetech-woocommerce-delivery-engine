<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use PHPUnit\Framework\TestCase;

final class CatalogCsvMapperTest extends TestCase {

	public function test_blank_cells_mean_no_change(): void {
		$mapper = new CatalogCsvMapper();
		$csv    = "sku,de_fulfilment_mode,de_fulfilment_availability\nABC-1,,\n";
		$result = $mapper->parse_csv( $csv );
		self::assertTrue( $result['ok'] );
		self::assertSame( [], $result['rows'][0]['actions'] );
	}

	public function test_override_blank_value_is_rejected(): void {
		$mapper = new CatalogCsvMapper();
		$csv    = "sku,de_fulfilment_mode,de_fulfilment_availability\nABC-1,override,\n";
		$result = $mapper->parse_csv( $csv );
		self::assertFalse( $result['ok'] );
		self::assertNotEmpty( $result['errors'] );
	}

	public function test_inherit_mode_clears_override(): void {
		$mapper = new CatalogCsvMapper();
		$csv    = "sku,de_fulfilment_mode,de_fulfilment_availability\nABC-1,inherit,international_fulfilment\n";
		$result = $mapper->parse_csv( $csv );
		self::assertTrue( $result['ok'] );
		self::assertSame( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, $result['rows'][0]['actions'][0]['field_key'] );
		self::assertSame( 'clear_override', $result['rows'][0]['actions'][0]['action'] );
	}

	public function test_formula_injection_is_escaped_on_export_values(): void {
		self::assertSame( "'=CMD", CatalogCsvMapper::escape_csv_value( '=CMD' ) );
		self::assertSame( 'Accra', CatalogCsvMapper::escape_csv_value( 'Accra' ) );
	}

	public function test_duplicate_and_missing_sku_rows_are_reported(): void {
		$mapper = new CatalogCsvMapper();
		$csv    = "sku,de_fulfilment_mode,de_fulfilment_availability\n,override,in_store\n";
		$result = $mapper->parse_csv( $csv );
		self::assertFalse( $result['ok'] );
	}

	public function test_twelve_hundred_rows_parse_completely(): void {
		$mapper = new CatalogCsvMapper();
		$lines  = [ 'sku,de_fulfilment_mode,de_fulfilment_availability' ];
		for ( $i = 1; $i <= 1200; $i++ ) {
			$lines[] = 'SKU-' . $i . ',inherit,';
		}
		$result = $mapper->parse_csv( implode( "\n", $lines ) . "\n" );
		self::assertTrue( $result['ok'] );
		self::assertCount( 1200, $result['rows'] );
		self::assertSame( 'SKU-1', $result['rows'][0]['sku'] );
		self::assertSame( 'SKU-1200', $result['rows'][1199]['sku'] );
	}
}
