<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	public function test_exact_preserved_three_table_identity_and_sealed_scheduling_facts(): void {
		$sql = RuleLifecycleSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'proof_delivery_engine_' );
		self::assertSame( [ 'rule_family_guards', 'logical_rules', 'rule_versions' ], array_keys( $sql ) );
		self::assertSame( [ 'proof_delivery_engine_rule_family_guards', 'proof_delivery_engine_logical_rules', 'proof_delivery_engine_rule_versions' ], RuleLifecycleSchema::tables( 'proof_' ) );
		self::assertStringContainsString( 'UNIQUE KEY site_family (site_id, family_code)', $sql['rule_family_guards'] );
		self::assertStringContainsString( 'UNIQUE KEY site_uuid (site_id, logical_uuid)', $sql['logical_rules'] );
		self::assertStringContainsString( 'UNIQUE KEY logical_sequence (site_id, logical_rule_id, version_sequence)', $sql['rule_versions'] );
		self::assertStringContainsString( 'KEY activation_due (site_id, state, effective_from, id)', $sql['rule_versions'] );
		foreach ( [ 'scheduled_revision', 'scheduled_logical_revision', 'scheduled_predecessor_row_revision' ] as $field ) {
			self::assertStringContainsString( $field . ' bigint unsigned NULL', $sql['rule_versions'] );
			self::assertSame( [ 'bigint unsigned', true, null, '', null ], RuleLifecycleSchema::columns( 'rule_versions' )[ $field ] );
		}
		self::assertStringContainsString( 'version_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $sql['rule_versions'] );
		self::assertStringContainsString( 'content_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $sql['rule_versions'] );
		self::assertSame( [ 'int', false, null, '', null ], RuleLifecycleSchema::columns( 'rule_versions' )['priority'] );
		self::assertSame( [ 'datetime(6)', true, null, '', null ], RuleLifecycleSchema::columns( 'rule_versions' )['effective_from'] );
		self::assertSame( [ 'text', false, null, '', 'site' ], RuleLifecycleSchema::columns( 'rule_versions' )['change_reason'] );
		self::assertCount( 8, RuleLifecycleSchema::columns( 'rule_family_guards' ) );
		self::assertCount( 14, RuleLifecycleSchema::columns( 'logical_rules' ) );
		self::assertCount( 26, RuleLifecycleSchema::columns( 'rule_versions' ) );
		foreach ( $sql as $suffix => $statement ) {
			self::assertStringContainsString( 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $statement );
			self::assertStringNotContainsString( 'FOREIGN KEY', $statement );
			self::assertStringNotContainsString( 'expires_at', $statement );
			self::assertStringNotContainsString( 'request_token', $statement );
			self::assertNotContains( $suffix, ConfigurationTables::all_suffixes() );
		}
	}

	public function test_names_and_charset_inputs_cannot_become_sql(): void {
		foreach ( [ [ 'DEFAULT CHARACTER SET utf8mb4; PRIVATE_PAYLOAD', 'safe_' ], [ 'DEFAULT CHARACTER SET utf8mb4', 'unsafe`_' ], [ 'DEFAULT CHARACTER SET utf8mb4', str_repeat( 'p', 64 ) ], [ '', 'safe_' ] ] as [ $charset, $prefix ] ) {
			try { RuleLifecycleSchema::create_table_statements( $charset, $prefix ); self::fail( 'Unsafe schema input accepted.' ); }
			catch ( \InvalidArgumentException $exception ) { self::assertStringNotContainsString( 'PRIVATE_PAYLOAD', $exception->getMessage() ); self::assertStringNotContainsString( 'unsafe`', $exception->getMessage() ); }
		}
		$this->expectException( \InvalidArgumentException::class );
		RuleLifecycleSchema::tables( '' );
	}
}
