<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use PHPUnit\Framework\TestCase;

final class SchemaV3InspectionTest extends TestCase {

	public function test_schema_target_is_three(): void {
		self::assertSame( '3', SchemaVersion::TARGET );
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
