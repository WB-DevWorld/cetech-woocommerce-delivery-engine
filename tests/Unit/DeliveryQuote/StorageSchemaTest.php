<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageSchemaTest extends TestCase {
	public function test_three_additive_tables_have_finite_original_namespace_and_reference_keys(): void {
		$sql = DeliveryQuoteSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'proof_delivery_engine_' );
		self::assertSame( DeliveryQuoteSchema::SUFFIXES, array_keys( $sql ) ); self::assertCount( 3, $sql );
		foreach ( $sql as $suffix => $statement ) { self::assertStringContainsString( 'CREATE TABLE proof_delivery_engine_' . $suffix, $statement ); self::assertStringContainsString( 'ENGINE=InnoDB', $statement ); self::assertStringContainsString( 'CHARACTER SET ascii COLLATE ascii_bin', $statement ); foreach ( [ 'DROP ', 'DELETE ', 'UPDATE ', 'CASCADE', 'FOREIGN KEY' ] as $operation ) { self::assertStringNotContainsString( $operation, $statement ); } }
		self::assertSame( [ 'site_id', 'issue_namespace_hash' ], DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::QUOTES_SUFFIX )['site_issue']['columns'] ); self::assertTrue( DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::QUOTES_SUFFIX )['site_accept']['unique'] ); self::assertTrue( DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::BINDINGS_SUFFIX )['site_quote']['unique'] );
		self::assertSame( [ 'site_id', 'order_id', 'managed_group_manifest_digest' ], DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::BINDINGS_SUFFIX )['site_order_manifest']['columns'] ); self::assertSame( [ 'site_id', 'purpose', 'slot_kind', 'slot_key', 'window_start' ], DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::BUDGET_SUFFIX )['budget_slot']['columns'] );
	}
	public function test_storage_columns_preserve_body_tombstone_and_incomplete_binding_distinctions(): void {
		$quote = DeliveryQuoteSchema::columns( DeliveryQuoteSchema::QUOTES_SUFFIX ); $binding = DeliveryQuoteSchema::columns( DeliveryQuoteSchema::BINDINGS_SUFFIX ); $budget = DeliveryQuoteSchema::columns( DeliveryQuoteSchema::BUDGET_SUFFIX );
		self::assertCount( 23, $quote ); self::assertCount( 19, $binding ); self::assertCount( 19, $budget ); self::assertSame( [ 'longtext', true, null, '', 'site' ], $quote['private_body_json'] ); self::assertSame( [ 'longtext', false, null, '', 'site' ], $quote['header_json'] );
		foreach ( [ 'snapshot_digest', 'context_digest', 'verified_at', 'sealed_at' ] as $field ) { self::assertTrue( $binding[$field][1] ); } self::assertFalse( $binding['native_money_digest'][1] ); self::assertTrue( $budget['admission_namespace_hash'][1] ); self::assertTrue( DeliveryQuoteSchema::indexes( DeliveryQuoteSchema::BUDGET_SUFFIX )['site_admission_namespace']['unique'] );
		self::assertSame( [ 'datetime(6)', false, null, '', null ], $quote['expires_at'] ); self::assertSame( [ 'bigint unsigned', false, '1', '', null ], $quote['revision'] ); self::assertSame( [ 'smallint unsigned', false, '1', '', null ], $budget['format_version'] );
	}
	public function test_range_index_maps_currency_to_actual_legacy_source_without_a_new_currency_field(): void {
		self::assertSame( 'ALTER TABLE `proof_delivery_engine_rate_cards` ADD KEY quote_candidate_range (delivery_offer_id, destination_zone_id, base_currency, id)', DeliveryQuoteSchema::rate_index_statement( 'proof_' ) ); self::assertSame( [ 'delivery_offer_id', 'destination_zone_id', 'base_currency', 'id' ], DeliveryQuoteSchema::rate_index()['columns'] ); self::assertArrayNotHasKey( 'currency_code', DeliveryQuoteSchema::rate_columns() ); self::assertSame( [ 'char(3)', false, '', '', 'site' ], DeliveryQuoteSchema::rate_columns()['base_currency'] );
	}
	public function test_explicit_current_site_prefix_is_not_inferred_from_a_global_table_route(): void { self::assertSame( [ 'site_22_delivery_engine_delivery_quotes', 'site_22_delivery_engine_delivery_quote_bindings', 'site_22_delivery_engine_delivery_quote_budget_windows' ], DeliveryQuoteSchema::tables( 'site_22_' ) ); }
	#[DataProvider( 'invalid_prefixes' )]
	public function test_unsafe_or_unrepresentable_prefix_refuses( string $prefix ): void { $this->expectException( \InvalidArgumentException::class ); DeliveryQuoteSchema::tables( $prefix ); }
	public static function invalid_prefixes(): array { return [ [ '' ], [ 'site-22_' ], [ 'database.site_' ], [ 'site_`' ], [ 'site_;DROP_TABLE_' ], [ str_repeat( 'a', 64 ) ] ]; }
	#[DataProvider( 'invalid_charsets' )]
	public function test_charset_declaration_cannot_append_ddl( string $declaration ): void { $this->expectException( \InvalidArgumentException::class ); DeliveryQuoteSchema::create_table_statements( $declaration, 'proof_delivery_engine_' ); }
	public static function invalid_charsets(): array { return [ [ '' ], [ 'utf8mb4' ], [ 'CHARACTER SET utf8mb4; DROP TABLE private' ], [ 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=MyISAM' ] ]; }
}
