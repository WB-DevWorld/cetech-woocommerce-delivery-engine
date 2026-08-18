<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use PHPUnit\Framework\TestCase;

final class SchemaUpgradeDoesNotTouchSnapshotsTest extends TestCase {

	public function test_rc4_snapshot_meta_keys_are_unchanged(): void {
		self::assertSame( '1', OrderDeliverySnapshot::VERSION );
		self::assertSame( '_cetech_de_delivery_snapshot', OrderDeliverySnapshot::META_LINE_SNAPSHOT );
		self::assertSame( '_cetech_de_delivery_snapshot_version', OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION );
		self::assertSame( '_cetech_de_delivery_quote_snapshot', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT );
		self::assertSame( '_cetech_de_order_delivery_snapshot_version', OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION );
	}

	public function test_schema_four_migration_does_not_mention_order_snapshots(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$migration   = (string) file_get_contents( $plugin_root . '/database/migrations/20260818140000_create_shipment_tables.php' );
		$schema      = (string) file_get_contents( $plugin_root . '/src/Infrastructure/Persistence/ShipmentSchema.php' );
		$repository  = (string) file_get_contents( $plugin_root . '/src/Infrastructure/Persistence/WpdbShipmentRepository.php' );

		foreach ( [ $migration, $schema, $repository ] as $source ) {
			self::assertStringNotContainsString( '_cetech_de_delivery_snapshot', $source );
			self::assertStringNotContainsString( '_cetech_de_delivery_quote_snapshot', $source );
			self::assertStringNotContainsString( 'cetech_de_group_id', $source );
			self::assertStringNotContainsString( 'postmeta', $source );
			self::assertStringNotContainsString( 'woocommerce_order_itemmeta', $source );
		}
	}

	public function test_snapshot_persister_source_is_untouched_by_schema_four_files(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$persister   = (string) file_get_contents( $plugin_root . '/src/Application/Order/OrderDeliverySnapshotPersister.php' );

		self::assertStringContainsString( 'OrderDeliverySnapshot', $persister );
		self::assertStringNotContainsString( 'ShipmentRepository', $persister );
		self::assertStringNotContainsString( 'enable_shipment_records', $persister );
	}
}
