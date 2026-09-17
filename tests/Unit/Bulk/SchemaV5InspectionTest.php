<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use PHPUnit\Framework\TestCase;

final class SchemaV5InspectionTest extends TestCase {

	public function test_schema_target_is_six(): void {
		self::assertSame( '6', SchemaVersion::TARGET );
	}

	public function test_bulk_tables_are_registered_with_required_indexes(): void {
		foreach ( BulkJobSchema::SUFFIXES as $suffix ) {
			self::assertContains( $suffix, ConfigurationTables::all_suffixes() );
			self::assertContains( $suffix, ConfigurationTables::BULK_JOB_SUFFIXES );
		}

		$statements = BulkJobSchema::create_table_statements( 'DEFAULT CHARSET=utf8mb4' );
		foreach ( BulkJobSchema::required_markers() as $suffix => $markers ) {
			self::assertArrayHasKey( $suffix, $statements );
			foreach ( $markers as $marker ) {
				self::assertStringContainsString( $marker, $statements[ $suffix ] );
			}
		}
	}

	public function test_schema_sql_does_not_store_translated_status_labels(): void {
		$joined = implode( "\n", BulkJobSchema::create_table_statements( '' ) );
		self::assertStringNotContainsString( 'Completed with errors', $joined );
		self::assertStringContainsString( 'status varchar(32) NOT NULL', $joined );
		self::assertStringContainsString( 'ENGINE=InnoDB', $joined );
	}

	public function test_uninstall_fallback_includes_bulk_tables(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		self::assertStringContainsString( "'bulk_jobs'", $source );
		self::assertStringContainsString( "'bulk_job_items'", $source );
		self::assertStringContainsString( "'bulk_recipes'", $source );
	}

	public function test_job_payloads_are_not_wp_options(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Infrastructure/Persistence/BulkJobSchema.php' );
		self::assertStringContainsString( 'longtext', $source );
		self::assertStringNotContainsString( 'update_option', $source );
		self::assertStringNotContainsString( 'add_option', $source );
	}
}
