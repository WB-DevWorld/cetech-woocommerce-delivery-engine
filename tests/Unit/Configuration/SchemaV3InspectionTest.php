<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use PHPUnit\Framework\TestCase;

final class SchemaV3InspectionTest extends TestCase {

	public function test_v3_migration_version_remains_three(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$migration   = require $plugin_root . '/database/migrations/20260810160000_create_scoped_configuration_tables.php';

		self::assertSame( '3', $migration->get_version() );
	}

	public function test_v3_tables_are_registered_and_markers_present(): void {
		foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) {
			self::assertContains( $suffix, ConfigurationTables::all_suffixes() );
		}

		$statements = ScopedConfigurationSchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );

		foreach ( ScopedConfigurationSchema::required_markers() as $suffix => $markers ) {
			self::assertArrayHasKey( $suffix, $statements );

			foreach ( $markers as $marker ) {
				self::assertStringContainsString( $marker, $statements[ $suffix ] );
			}
		}

		$joined = implode( "\n", $statements );
		self::assertStringNotContainsString( 'shipment', $joined );
		self::assertContains( 'product_delivery_rules', ConfigurationTables::PRODUCT_RULE_SUFFIXES );
	}
}
