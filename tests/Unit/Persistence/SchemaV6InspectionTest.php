<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Persistence;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use PHPUnit\Framework\TestCase;

final class SchemaV6InspectionTest extends TestCase {

	public function test_schema_target_is_six(): void {
		self::assertSame( '6', SchemaVersion::TARGET );
		self::assertSame( '6', SchemaVersion::target() );
	}

	public function test_plugin_version_is_geo_development_identity(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$header      = (string) file_get_contents( $plugin_root . '/cetech-woocommerce-delivery-engine.php' );

		self::assertMatchesRegularExpression( "/define\(\s*'CETECH_DE_VERSION',\s*'1\\.0\\.0-dev\\.geo\\.1'\s*\)/", $header );
		self::assertMatchesRegularExpression( '/Version:\s+1\\.0\\.0-dev\\.geo\\.1\s*$/m', $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.11' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.12' )", $header );
	}

	public function test_geography_and_coverage_tables_are_registered(): void {
		foreach ( GeographySchema::SUFFIXES as $suffix ) {
			self::assertContains( $suffix, ConfigurationTables::all_suffixes() );
			self::assertContains( $suffix, ConfigurationTables::GEOGRAPHY_SUFFIXES );
		}
		foreach ( CoverageSchema::SUFFIXES as $suffix ) {
			self::assertContains( $suffix, ConfigurationTables::all_suffixes() );
			self::assertContains( $suffix, ConfigurationTables::COVERAGE_SUFFIXES );
		}

		$geo_sql = GeographySchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );
		foreach ( GeographySchema::required_markers() as $suffix => $markers ) {
			self::assertArrayHasKey( $suffix, $geo_sql );
			foreach ( $markers as $marker ) {
				self::assertStringContainsString( $marker, $geo_sql[ $suffix ] );
			}
		}

		$cov_sql = CoverageSchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );
		foreach ( CoverageSchema::required_markers() as $suffix => $markers ) {
			self::assertArrayHasKey( $suffix, $cov_sql );
			foreach ( $markers as $marker ) {
				self::assertStringContainsString( $marker, $cov_sql[ $suffix ] );
			}
		}
	}

	public function test_uninstall_includes_schema_six_tables_and_keeps_destination_rules(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		self::assertStringContainsString( "'geography_packs'", $source );
		self::assertStringContainsString( "'geography_locations'", $source );
		self::assertStringContainsString( "'destination_coverage_groups'", $source );
		$migration = (string) file_get_contents( dirname( __DIR__, 3 ) . '/database/migrations/20260917120000_create_geography_coverage_tables.php' );
		self::assertStringNotContainsString( 'DROP TABLE', $migration );
	}
}
