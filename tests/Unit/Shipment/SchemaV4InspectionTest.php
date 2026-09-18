<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use PHPUnit\Framework\TestCase;

final class SchemaV4InspectionTest extends TestCase {

	public function test_schema_target_is_six_and_schema_four_tables_remain(): void {
		self::assertSame( '6', SchemaVersion::TARGET );
		self::assertSame( '6', SchemaVersion::target() );
	}

	public function test_plugin_version_is_geo_development_identity(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$header      = (string) file_get_contents( $plugin_root . '/cetech-woocommerce-delivery-engine.php' );

		self::assertMatchesRegularExpression( "/define\(\s*'CETECH_DE_VERSION',\s*'1\\.0\\.0-dev\\.geo\\.10'\s*\)/", $header );
		self::assertMatchesRegularExpression( '/Version:\s+1\\.0\\.0-dev\\.geo\\.10\s*$/m', $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.9' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.8' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.7' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.6' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.5' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.4' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.3' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.2' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.geo.1' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.11' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.12' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.pdp-price.3' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.pdp-price.2' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.pdp-price.1' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-rc.10' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.qual.1' )", $header );
		self::assertStringNotContainsString( "define( 'CETECH_DE_VERSION', '1.0.0-dev.integrated.2' )", $header );
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

	public function test_delivery_group_id_column_fits_v2_identifier(): void {
		$statements = ShipmentSchema::create_table_statements( '' );
		$sql        = $statements[ ShipmentSchema::SHIPMENTS_SUFFIX ];

		self::assertStringContainsString( 'delivery_group_id varchar(191) NOT NULL', $sql );
		self::assertStringContainsString( 'idempotency_key varchar(255) NOT NULL', $sql );
		self::assertSame( 6, (int) SchemaVersion::TARGET );
		self::assertLessThanOrEqual(
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::COLUMN_LENGTH,
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::worstCaseLength( true )
		);
		self::assertLessThanOrEqual(
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::COLUMN_LENGTH,
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::worstCaseLength( false )
		);
		$idempotency = 20 + 1 + \CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::worstCaseLength( false );
		self::assertLessThanOrEqual(
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::IDEMPOTENCY_KEY_LENGTH,
			$idempotency
		);
	}

	public function test_rc9_tag_is_untouched(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$git         = 'git -C ' . escapeshellarg( $plugin_root ) . ' rev-parse --verify "v1.0.0-rc.9^{commit}"';
		$sha         = trim( (string) shell_exec( $git . ' 2>/dev/null' ) );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
			$sha = trim( (string) shell_exec( $git . ' 2>NUL' ) );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
			self::markTestSkipped( 'v1.0.0-rc.9 is not present in this Git clone (shallow CI checkout).' );
		}

		self::assertSame( 'e6bc7fba16d9d7b96682f2945c518a33a9a16cd5', $sha );
	}

	public function test_deactivator_does_not_drop_tables(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$source      = (string) file_get_contents( $plugin_root . '/src/Bootstrap/Deactivator.php' );

		self::assertStringNotContainsString( 'DROP TABLE', $source );
		self::assertStringNotContainsString( 'delete_option', $source );
		self::assertStringNotContainsString( 'ConfigurationTables', $source );
	}
}
