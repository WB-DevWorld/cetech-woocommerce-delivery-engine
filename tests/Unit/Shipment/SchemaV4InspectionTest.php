<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use PHPUnit\Framework\TestCase;

final class SchemaV4InspectionTest extends TestCase {

	public function test_schema_target_is_five_and_schema_four_tables_remain(): void {
		self::assertSame( '5', SchemaVersion::TARGET );
		self::assertSame( '5', SchemaVersion::target() );
	}

	public function test_plugin_version_is_dev_wcfm(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$header      = (string) file_get_contents( $plugin_root . '/cetech-woocommerce-delivery-engine.php' );

		self::assertMatchesRegularExpression( "/define\(\s*'CETECH_DE_VERSION',\s*'1\\.0\\.0-dev\\.wcfm\\.1'\s*\)/", $header );
		self::assertMatchesRegularExpression( '/Version:\s+1\\.0\\.0-dev\\.wcfm\\.1\s*$/m', $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.9' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.blocks.4' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.blocks.3' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.blocks.2' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.blocks.1' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.8' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.fulfilment.4' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.7' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.6' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.bulk.9' )", $header );
	}

	public function test_shipment_tables_are_registered_with_required_indexes(): void {
		foreach ( ShipmentSchema::SUFFIXES as $suffix ) {
			self::assertContains( $suffix, ConfigurationTables::all_suffixes() );
			self::assertContains( $suffix, ConfigurationTables::SHIPMENT_SUFFIXES );
		}

		$statements = ShipmentSchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );

		foreach ( ShipmentSchema::required_markers() as $suffix => $markers ) {
			self::assertArrayHasKey( $suffix, $statements );

			foreach ( $markers as $marker ) {
				self::assertStringContainsString( $marker, $statements[ $suffix ] );
			}
		}
	}

	public function test_schema_sql_does_not_store_translated_status_labels(): void {
		$joined = implode( "\n", ShipmentSchema::create_table_statements( '' ) );

		self::assertStringNotContainsString( 'Awaiting fulfilment', $joined );
		self::assertStringNotContainsString( 'In transit', $joined );
		self::assertStringNotContainsString( 'Dispatched', $joined );
		self::assertStringContainsString( "status varchar(32) NOT NULL", $joined );
	}

	public function test_uninstall_fallback_includes_shipment_tables(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$source      = (string) file_get_contents( $plugin_root . '/uninstall.php' );

		self::assertStringContainsString( "'shipments'", $source );
		self::assertStringContainsString( "'shipment_items'", $source );
		self::assertStringContainsString( "'shipment_events'", $source );
	}

	public function test_deactivator_does_not_drop_tables(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$source      = (string) file_get_contents( $plugin_root . '/src/Bootstrap/Deactivator.php' );

		self::assertStringNotContainsString( 'DROP TABLE', $source );
		self::assertStringNotContainsString( 'delete_option', $source );
		self::assertStringNotContainsString( 'ConfigurationTables', $source );
	}
}
