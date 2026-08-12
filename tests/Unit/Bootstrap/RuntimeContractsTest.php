<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bootstrap;

use CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface;
use CetechDeliveryEngine\Application\Runtime\WooCommerceVariationRelationshipInspector;
use CetechDeliveryEngine\Bootstrap\RuntimeContracts;
use CetechDeliveryEngine\Core\Versioning\MigrationInterface;
use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use PHPUnit\Framework\TestCase;

final class RuntimeContractsTest extends TestCase {

	public function test_contract_files_exist_and_load(): void {
		$plugin_root = dirname( __DIR__, 3 );

		self::assertSame( [], RuntimeContracts::missing_paths( $plugin_root ) );
		self::assertTrue( RuntimeContracts::load( $plugin_root ) );
		self::assertTrue( interface_exists( VariationRelationshipInspectorInterface::class ) );
		self::assertTrue( interface_exists( VerifiableMigrationInterface::class ) );
		self::assertTrue( interface_exists( MigrationInterface::class ) );
	}

	public function test_variation_inspector_implements_contract(): void {
		$inspector = new WooCommerceVariationRelationshipInspector();

		self::assertInstanceOf( VariationRelationshipInspectorInterface::class, $inspector );
	}

	public function test_schema_three_migration_loads_after_contracts(): void {
		$plugin_root = dirname( __DIR__, 3 );
		self::assertTrue( RuntimeContracts::load( $plugin_root ) );

		$migration = require $plugin_root . '/database/migrations/20260810160000_create_scoped_configuration_tables.php';

		self::assertInstanceOf( VerifiableMigrationInterface::class, $migration );
		self::assertSame( '3', $migration->get_version() );
	}

	public function test_missing_paths_reports_absent_files(): void {
		$missing = RuntimeContracts::missing_paths( sys_get_temp_dir() . '/cetech-de-missing-contracts' );

		self::assertSame( RuntimeContracts::relative_paths(), $missing );
	}
}
