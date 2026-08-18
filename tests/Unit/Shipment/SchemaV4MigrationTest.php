<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use PHPUnit\Framework\TestCase;

final class SchemaV4MigrationTest extends TestCase {

	public function test_migration_is_schema_four_and_verifiable(): void {
		$migration = $this->load_migration();

		self::assertInstanceOf( VerifiableMigrationInterface::class, $migration );
		self::assertSame( '4', $migration->get_version() );
		self::assertSame( '20260818140000_create_shipment_tables', $migration->get_id() );
	}

	public function test_migration_creates_canonical_tables_idempotently(): void {
		$source = $this->migration_source();

		self::assertStringContainsString( 'dbDelta', $source );
		self::assertStringContainsString( 'ShipmentSchema::create_table_statements', $source );
		self::assertStringContainsString( 'foreach ( ShipmentSchema::SUFFIXES', $source );
		self::assertStringNotContainsString( 'DROP TABLE', $source );
		self::assertStringNotContainsString( 'CREATE TABLE IF NOT EXISTS', $source );
	}

	public function test_migration_verify_requires_unique_and_query_indexes(): void {
		$source = $this->migration_source();

		self::assertStringContainsString( "assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'idempotency_key' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'order_group' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'order_id' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'status' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'status_updated' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::ITEMS_SUFFIX, 'shipment_item' )", $source );
		self::assertStringContainsString( "assert_index_present( ShipmentSchema::EVENTS_SUFFIX, 'shipment_time' )", $source );
	}

	public function test_create_sql_pins_innodb_for_transactional_aggregates(): void {
		$sql = ShipmentSchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );

		foreach ( ShipmentSchema::SUFFIXES as $suffix ) {
			self::assertArrayHasKey( $suffix, $sql );
			self::assertMatchesRegularExpression(
				'/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',
				$sql[ $suffix ],
				$suffix . ' CREATE TABLE must pin InnoDB before charset'
			);
		}
	}

	public function test_v3_sql_still_excludes_shipment_tables(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$v3          = (string) file_get_contents( $plugin_root . '/database/migrations/20260810160000_create_scoped_configuration_tables.php' );

		self::assertStringContainsString( 'shipments', $v3 );
		self::assertStringContainsString( 'unexpected shipment table', $v3 );
		self::assertSame(
			[ 'shipments', 'shipment_items', 'shipment_events' ],
			ShipmentSchema::SUFFIXES
		);
	}

	private function load_migration(): VerifiableMigrationInterface {
		$plugin_root = dirname( __DIR__, 3 );
		$migration   = require $plugin_root . '/database/migrations/20260818140000_create_shipment_tables.php';

		self::assertInstanceOf( VerifiableMigrationInterface::class, $migration );

		return $migration;
	}

	private function migration_source(): string {
		$plugin_root = dirname( __DIR__, 3 );

		return (string) file_get_contents( $plugin_root . '/database/migrations/20260818140000_create_shipment_tables.php' );
	}
}
