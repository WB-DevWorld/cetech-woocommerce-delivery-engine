<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationStoreMigrationTest extends TestCase {

	public function test_new_forward_verifiable_migration_preserves_existing_generation(): void {
		$migration = require dirname( __DIR__, 3 ) . '/database/migrations/20261006191156_create_operation_tables.php';
		self::assertInstanceOf( VerifiableMigrationInterface::class, $migration );
		self::assertSame( '20261006191156_create_operation_tables', $migration->get_id() );
		self::assertSame( '7', $migration->get_version() );
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new OperationMetadataWpdb();
		try {
			$migration->verify();
			self::assertSame( '7', $GLOBALS['wpdb']->options['cetech_de_db_version'] );
			self::assertSame( [], $GLOBALS['wpdb']->tables['proof_delivery_engine_operation_records']['rows'] );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}

	public function test_incompatible_existing_store_stops_before_loading_upgrade_or_ddl(): void {
		$migration = require dirname( __DIR__, 3 ) . '/database/migrations/20261006191156_create_operation_tables.php';
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new OperationMetadataWpdb();
		$db->options['cetech_de_db_version'] = '6';
		$db->tables['proof_delivery_engine_operation_records']['status']['Engine'] = 'MyISAM';
		$GLOBALS['wpdb'] = $db;
		try {
			try { $migration->up(); self::fail( 'Incompatible store was altered.' ); }
			catch ( RuntimeException $exception ) { self::assertSame( 'Operation store could not be verified.', $exception->getMessage() ); }
			self::assertSame( '6', $db->options['cetech_de_db_version'] );
			self::assertSame( 'MyISAM', $db->tables['proof_delivery_engine_operation_records']['status']['Engine'] );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}
}
