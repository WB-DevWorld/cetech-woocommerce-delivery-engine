<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use PHPUnit\Framework\TestCase;

final class OperationStoreSchemaTest extends TestCase {

	public function test_exact_two_preserved_tables_with_full_unique_identity_and_transactional_storage(): void {
		$sql = OperationStoreSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'proof_delivery_engine_' );
		self::assertSame( [ 'operation_records', 'operation_changes' ], array_keys( $sql ) );
		self::assertStringContainsString( 'UNIQUE KEY site_namespace (site_id, namespace_hash)', $sql['operation_records'] );
		self::assertStringContainsString( 'KEY site_state_id (site_id, state, id)', $sql['operation_records'] );
		self::assertStringContainsString( 'UNIQUE KEY operation_event (site_id, operation_id)', $sql['operation_changes'] );
		foreach ( $sql as $statement ) {
			self::assertStringContainsString( 'ENGINE=InnoDB', $statement );
			self::assertStringContainsString( 'datetime(6)', $statement );
			self::assertStringNotContainsString( 'FOREIGN KEY', $statement );
			self::assertStringNotContainsString( 'expires_at', $statement );
			self::assertStringNotContainsString( 'request_token', $statement );
		}
		self::assertSame( [ 'id', 'site_id', 'namespace_hash', 'intent_hash', 'namespace_format', 'intent_format', 'record_format', 'operation', 'operation_version', 'target_hash', 'state', 'publication_state', 'completion_json', 'audit_id', 'row_version', 'created_at', 'updated_at', 'completed_at' ], array_keys( OperationStoreSchema::columns( 'operation_records' ) ) );
		self::assertSame( [ 'id', 'site_id', 'operation_id', 'event_format', 'event_json', 'created_at' ], array_keys( OperationStoreSchema::columns( 'operation_changes' ) ) );
		self::assertStringContainsString( 'namespace_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $sql['operation_records'] );
		self::assertStringContainsString( 'completion_json longtext NULL', $sql['operation_records'] );
		self::assertStringContainsString( 'audit_id bigint unsigned NULL', $sql['operation_records'] );
		self::assertStringContainsString( 'event_json longtext NOT NULL', $sql['operation_changes'] );
		self::assertNotContains( 'operation_records', ConfigurationTables::all_suffixes() );
		self::assertNotContains( 'operation_changes', ConfigurationTables::all_suffixes() );
	}

	public function test_server_prefix_and_charset_identifiers_cannot_become_sql(): void {
		foreach ( [ [ 'DEFAULT CHARACTER SET utf8mb4; DROP TABLE secret', 'safe_' ], [ 'DEFAULT CHARACTER SET utf8mb4', 'unsafe`_' ], [ 'DEFAULT CHARACTER SET utf8mb4', str_repeat( 'a', 64 ) ] ] as [ $charset, $prefix ] ) {
			try {
				OperationStoreSchema::create_table_statements( $charset, $prefix );
				self::fail( 'Unsafe schema input was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringNotContainsString( 'secret', $exception->getMessage() );
				self::assertStringNotContainsString( 'unsafe`', $exception->getMessage() );
			}
		}
	}
}
