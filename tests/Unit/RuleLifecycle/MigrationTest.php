<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/ReadinessTest.php';

final class MigrationTest extends TestCase {

	public function test_forward_verification_does_not_write_marker_or_previous_generation(): void {
		$migration = require dirname( __DIR__, 3 ) . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php';
		self::assertInstanceOf( VerifiableMigrationInterface::class, $migration );
		self::assertSame( '20261006214731_create_rule_lifecycle_tables', $migration->get_id() );
		self::assertSame( '8', $migration->get_version() );
		$previous = $GLOBALS['wpdb'] ?? null; $db = ReadinessTest::metadata(); $db->options['cetech_de_db_version'] = '7'; $before = serialize( [ $db->tables, $db->options ] ); $GLOBALS['wpdb'] = $db;
		try { $migration->verify(); self::assertSame( $before, serialize( [ $db->tables, $db->options ] ) ); }
		finally { $GLOBALS['wpdb'] = $previous; }
	}

	public function test_incompatible_existing_store_stops_before_upgrade_load_or_ddl(): void {
		$migration = require dirname( __DIR__, 3 ) . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php';
		$previous = $GLOBALS['wpdb'] ?? null; $db = ReadinessTest::metadata(); $db->options['cetech_de_db_version'] = '7'; $db->tables['proof_delivery_engine_rule_versions']['status']['Engine'] = 'MyISAM'; $before = serialize( [ $db->tables, $db->options ] ); $GLOBALS['wpdb'] = $db;
		try {
			try { $migration->up(); self::fail( 'Incompatible store altered.' ); }
			catch ( RuntimeException $exception ) { self::assertSame( 'Rule lifecycle store could not be verified.', $exception->getMessage() ); }
			self::assertSame( $before, serialize( [ $db->tables, $db->options ] ) );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}
}
