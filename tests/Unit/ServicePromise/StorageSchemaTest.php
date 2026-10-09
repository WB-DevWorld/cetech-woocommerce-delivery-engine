<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredAssignment;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredObject;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageSchemaTest extends TestCase {
	public function test_three_new_tables_use_explicit_same_site_prefix_and_closed_typed_columns(): void {
		self::assertSame( [ 'proof_delivery_engine_promise_objects', 'proof_delivery_engine_promise_versions', 'proof_delivery_engine_promise_assignments' ], PromiseStorageSchema::tables( 'proof_' ) );
		self::assertSame( PromiseStoredObject::FIELDS, array_keys( PromiseStorageSchema::columns( PromiseStorageSchema::OBJECTS_SUFFIX ) ) );
		self::assertSame( PromiseStoredVersion::FIELDS, array_keys( PromiseStorageSchema::columns( PromiseStorageSchema::VERSIONS_SUFFIX ) ) );
		self::assertSame( PromiseStoredAssignment::FIELDS, array_keys( PromiseStorageSchema::columns( PromiseStorageSchema::ASSIGNMENTS_SUFFIX ) ) );
		foreach ( PromiseStorageSchema::SUFFIXES as $suffix ) { self::assertSame( [ 'bigint unsigned', false, null, '', null ], PromiseStorageSchema::columns( $suffix )['site_id'] ); self::assertSame( [ 'varchar(64)', false, null, '', 'ascii_bin' ], PromiseStorageSchema::columns( $suffix )['site_key'] ); }
	}
	public function test_ddl_adds_only_owned_immutable_history_without_foreign_keys_or_update_defaults(): void {
		$statements = PromiseStorageSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'proof_delivery_engine_' ); self::assertCount( 3, $statements );
		foreach ( $statements as $suffix => $sql ) { self::assertStringStartsWith( "CREATE TABLE proof_delivery_engine_{$suffix} (", $sql ); self::assertStringContainsString( 'ENGINE=InnoDB', $sql ); self::assertStringContainsString( 'CHARACTER SET ascii COLLATE ascii_bin', $sql ); self::assertStringNotContainsString( 'FOREIGN KEY', $sql ); self::assertStringNotContainsString( 'ON UPDATE', $sql ); self::assertStringNotContainsString( 'DROP ', $sql ); self::assertStringNotContainsString( 'ALTER ', $sql ); }
	}
	public function test_identity_indexes_use_exact_full_width_scope_and_explicit_global_zero(): void {
		$indexes = PromiseStorageSchema::indexes( PromiseStorageSchema::ASSIGNMENTS_SUFFIX ); self::assertSame( [ 'unique' => true, 'columns' => PromiseStoredAssignment::KEY_FIELDS ], $indexes['scope_service_endpoint'] );
		self::assertFalse( PromiseStorageSchema::columns( PromiseStorageSchema::ASSIGNMENTS_SUFFIX )['scope_id'][1] );
		self::assertSame( [ 'bigint unsigned', false, '0', '', null ], PromiseStorageSchema::columns( PromiseStorageSchema::ASSIGNMENTS_SUFFIX )['generation'] );
		self::assertSame( [ 'site_id', 'site_key', 'object_id', 'domain_version' ], PromiseStorageSchema::indexes( PromiseStorageSchema::VERSIONS_SUFFIX )['object_sequence']['columns'] );
	}
	public function test_private_column_limits_are_independent_of_retained_quote_and_rule_limits(): void {
		self::assertSame( [ 'body_json' => 32768, 'create_receipt_json' => 16384, 'publication_receipt_json' => 16384, 'source_receipt_json' => 16384, 'reason' => 256 ], PromiseStorageSchema::payload_limits( PromiseStorageSchema::VERSIONS_SUFFIX ) );
		self::assertSame( [ 'policy_reference_json' => 4096, 'source_receipt_json' => 16384 ], PromiseStorageSchema::payload_limits( PromiseStorageSchema::ASSIGNMENTS_SUFFIX ) ); self::assertSame( 98304, PromiseStorageSchema::ROW_BYTES );
	}
	#[DataProvider( 'invalid_prefixes' )]
	public function test_invalid_or_overlong_table_prefix_refuses_before_sql( string $prefix ): void { $this->expectException( \InvalidArgumentException::class ); PromiseStorageSchema::tables( $prefix ); }
	public static function invalid_prefixes(): array { return [ [ '' ], [ 'a b' ], [ '`malicious`' ], [ '日本_' ], [ str_repeat( 'a', 34 ) ] ]; }
	public function test_invalid_charset_declaration_is_not_interpolated(): void { $this->expectException( \InvalidArgumentException::class ); PromiseStorageSchema::create_table_statements( 'utf8mb4; DROP TABLE users' ); }
	public function test_exact_identifier_budget_and_plus_one_are_enforced(): void {
		$fixed = strlen( 'delivery_engine_' . PromiseStorageSchema::ASSIGNMENTS_SUFFIX ); $raw = str_repeat( 'a', 64 - $fixed ); self::assertSame( 64, strlen( PromiseStorageSchema::tables( $raw )[2] ) );
		$this->expectException( \InvalidArgumentException::class ); PromiseStorageSchema::tables( $raw . 'a' );
	}
}
