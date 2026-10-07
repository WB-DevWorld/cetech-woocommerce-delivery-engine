<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

final class StorageCodecTest extends TestCase {
	public function test_native_sql_numeric_strings_normalize_without_losing_original_private_bytes(): void {
		$original = QuoteStorageFixtures::quote(); $row = $original->row(); foreach ( [ 'id', 'site_id', 'format_version', 'profile_version', 'revision', 'retention_revision' ] as $key ) { $row[$key] = (string) $row[$key]; }
		$stored = QuoteStoredRow::from_row( $row ); self::assertSame( $original->row(), $stored->row() ); self::assertSame( $original->header()->to_private_json(), $stored->header()->to_private_json() );
		$detached = $stored->row(); $detached['state'] = 'stripped'; self::assertSame( 'issued', $stored->state() ); self::assertSame( $original->context()->digest(), $stored->context()->digest() );
	}
	#[DataProvider( 'invalid_quote_scalars' )]
	public function test_invalid_native_scalars_and_header_backlinks_refuse( string $field, mixed $value ): void {
		$row = QuoteStorageFixtures::quote()->row(); $row[$field] = $value; $this->expectException( \InvalidArgumentException::class ); QuoteStoredRow::from_row( $row );
	}
	public static function invalid_quote_scalars(): array {
		return [ [ 'id', 0 ], [ 'id', '01' ], [ 'id', '+1' ], [ 'id', 1.0 ], [ 'id', true ], [ 'id', '9223372036854775808' ], [ 'site_id', 2 ], [ 'format_version', 2 ], [ 'profile_version', 2 ], [ 'profile_code', 'unregistered' ], [ 'purpose', 'estimate' ], [ 'principal_hash', str_repeat( 'a', 64 ) ], [ 'owner_digest', str_repeat( 'b', 64 ) ], [ 'material_digest', str_repeat( 'c', 64 ) ], [ 'body_digest', str_repeat( 'd', 64 ) ], [ 'issue_namespace_hash', str_repeat( 'e', 64 ) ], [ 'accept_namespace_hash', str_repeat( 'f', 64 ) ], [ 'invalidate_namespace_hash', str_repeat( '0', 64 ) ], [ 'state', 'expired' ], [ 'created_at', '2026-10-07 05:00:01.000000' ], [ 'expires_at', '2026-10-07 05:06:00.000000' ], [ 'header_json', str_repeat( 'x', 4097 ) ], [ 'private_body_json', str_repeat( 'x', 65537 ) ], [ 'private_body_json', null ], [ 'revision', 2 ], [ 'retention_revision', 2 ] ];
	}
	public function test_missing_or_extra_row_fields_and_noncanonical_stored_json_refuse(): void {
		$base = QuoteStorageFixtures::quote()->row(); $variants = []; $row = $base; unset( $row['state'] ); $variants[] = $row; $row = $base; $row['renamed_private'] = 'secret'; $variants[] = $row; $row = $base; $row['header_json'] = ' ' . $row['header_json']; $variants[] = $row; $row = $base; $row['private_body_json'] = ' ' . $row['private_body_json']; $variants[] = $row;
		foreach ( $variants as $row ) { try { QuoteStoredRow::from_row( $row ); self::fail( 'Malformed persisted facts passed.' ); } catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid delivery quote facts.', $error->getMessage() ); } }
	}
	public function test_recomputed_body_hash_does_not_bypass_original_semantic_links(): void {
		$row = QuoteStorageFixtures::quote()->row(); $body = QuoteJson::decode( $row['private_body_json'] ); $body['terms']['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'other_policy' ); $row['private_body_json'] = QuoteJson::encode( $body ); $row['body_digest'] = hash( 'sha256', 'cetech-quote-body-v1:' . $row['private_body_json'] ); $header = QuoteJson::decode( $row['header_json'] ); $header['body_digest'] = $row['body_digest']; $row['header_json'] = QuoteJson::encode( $header, 4096 );
		$this->expectException( \InvalidArgumentException::class ); QuoteStoredRow::from_row( $row );
	}
	public function test_accepted_invalidated_and_stripped_shapes_preserve_original_header_and_history(): void {
		$accepted = QuoteStorageFixtures::quote( state: 'accepted' ); $row = $accepted->row(); $row['state'] = 'invalidated'; $row['revision'] = 3; $row['transition_at'] = QuoteFixtures::time()->plus_seconds( 2 )->sql(); $invalidated = QuoteStoredRow::from_row( $row );
		self::assertSame( $accepted->header()->to_private_json(), $invalidated->header()->to_private_json() ); self::assertSame( $accepted->accepted_at()->sql(), $invalidated->accepted_at()->sql() ); self::assertSame( $accepted->terms()->to_private_json(), $invalidated->terms()->to_private_json() );
		$stripped = QuoteStorageFixtures::quote( state: 'stripped' ); self::assertNull( $stripped->context() ); self::assertNull( $stripped->terms() ); self::assertSame( 1, $stripped->header()->revision() ); self::assertSame( 2, $stripped->row()['retention_revision'] );
	}
	public function test_acceptance_at_expiry_or_missing_history_and_early_stripping_refuse(): void {
		$accepted = QuoteStorageFixtures::quote( state: 'accepted' )->row(); $variants = []; $row = $accepted; $row['accepted_at'] = $row['expires_at']; $row['transition_at'] = $row['expires_at']; $variants[] = $row; $row = $accepted; $row['accepted_at'] = null; $variants[] = $row;
		$row = QuoteStorageFixtures::quote( state: 'stripped' )->row(); $row['transition_at'] = QuoteFixtures::time()->plus_seconds( 2099 )->sql(); $variants[] = $row;
		foreach ( $variants as $row ) { try { QuoteStoredRow::from_row( $row ); self::fail( 'Invalid history passed.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } }
	}
	public function test_binding_has_exact_accepted_parent_membership_native_evidence_and_detached_mapping(): void {
		$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $binding = QuoteStorageFixtures::binding( $quote ); $row = $binding->row(); foreach ( [ 'id', 'site_id', 'format_version', 'order_id', 'revision' ] as $field ) { $row[$field] = (string) $row[$field]; }
		self::assertSame( $binding->row(), QuoteBinding::from_row( $row, $quote )->row() ); $mapping = $binding->mapping(); $mapping['groups'][0]['lines'][0]['quantity'] = '10'; self::assertSame( '2', $binding->mapping()['groups'][0]['lines'][0]['quantity'] );
		self::assertSame( $quote->context()->private_facts()['tax']['native_money_digest'], $binding->row()['native_money_digest'] );
	}
	public function test_binding_recomputed_mapping_digest_cannot_swap_member_or_quantity(): void {
		$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $binding = QuoteStorageFixtures::binding( $quote ); $row = $binding->row(); $mapping = $binding->mapping(); $mapping['groups'][0]['lines'][0]['quantity'] = '3'; $row['mapping_json'] = QuoteJson::encode( $mapping ); $row['managed_group_manifest_digest'] = QuoteBinding::manifest_digest( $mapping );
		$this->expectException( \InvalidArgumentException::class ); QuoteBinding::from_row( $row, $quote );
	}
	public function test_binding_wrong_parent_native_evidence_and_false_seal_refuse(): void {
		$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $base = QuoteStorageFixtures::binding( $quote )->row(); $variants = [];
		foreach ( [ 'site_id' => 2, 'accepted_body_digest' => QuoteFixtures::digest( 'other_body' ), 'native_money_digest' => QuoteFixtures::digest( 'other_money' ), 'revision' => 99, 'state' => 'sealed', 'context_digest' => QuoteFixtures::digest( 'partial_verification' ) ] as $field => $value ) { $variants[] = array_replace( $base, [ $field => $value ] ); }
		foreach ( $variants as $row ) { try { QuoteBinding::from_row( $row, $quote ); self::fail( 'Invalid binding passed.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } }
		$this->expectException( \InvalidArgumentException::class ); QuoteBinding::from_row( $base, QuoteStorageFixtures::quote() );
	}
	public function test_mapping_order_is_canonical_but_duplicate_physical_members_refuse(): void {
		$base = QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ) )->mapping(); $second = $base['groups'][0]['lines'][0]; $second['line_key'] = 'line_two'; $second['item_id'] = 902; $base['groups'][0]['lines'][] = $second; $reverse = $base; $reverse['groups'][0]['lines'] = array_reverse( $reverse['groups'][0]['lines'] );
		self::assertSame( QuoteBinding::manifest_digest( $base ), QuoteBinding::manifest_digest( $reverse ) ); $base['groups'][0]['lines'][1]['item_id'] = $base['groups'][0]['lines'][0]['item_id']; $this->expectException( \InvalidArgumentException::class ); QuoteBinding::canonical_mapping( $base );
	}
	public function test_budget_classes_use_finite_site_sentinel_full_session_key_and_fixed_lease(): void {
		$site = QuoteStorageFixtures::budget( admission: false ); $admission = QuoteStorageFixtures::budget(); self::assertSame( QuoteBudgetSlot::site_slot_key( 1 ), $site->row()['slot_key'] ); self::assertNull( $site->row()['principal_hash'] ); self::assertSame( QuoteBudgetSlot::admission_slot_key( $admission->row()['admission_namespace_hash'] ), $admission->row()['slot_key'] );
		self::assertSame( QuoteFixtures::time()->plus_seconds( 60 )->sql(), $admission->row()['lease_expires_at'] );
		$owner = QuoteFixtures::owner(); $other = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner::from_array( array_replace( $owner->facts(), [ 'session_hash' => QuoteFixtures::digest( 'another_cart' ) ] ) ); self::assertNotSame( QuoteBudgetSlot::session_slot_key( $owner ), QuoteBudgetSlot::session_slot_key( $other ) );
		$row = $site->row(); $row['slot_kind'] = 'session_minute'; $row['slot_key'] = QuoteBudgetSlot::session_slot_key( $owner ); $row['principal_hash'] = $owner->facts()['principal_hash']; $row['attempt_count'] = '20'; self::assertSame( 20, QuoteBudgetSlot::from_row( $row )->row()['attempt_count'] ); $row['attempt_count'] = 21; $this->expectException( \InvalidArgumentException::class ); QuoteBudgetSlot::from_row( $row );
	}
	public function test_alternate_site_slots_admission_alias_and_lease_renewal_refuse(): void {
		$variants = []; $row = QuoteStorageFixtures::budget( admission: false )->row(); $row['slot_key'] = QuoteFixtures::digest( 'other_site_slot' ); $variants[] = $row; $row = QuoteStorageFixtures::budget()->row(); $row['lease_expires_at'] = QuoteFixtures::time()->plus_seconds( 61 )->sql(); $variants[] = $row; $row = QuoteStorageFixtures::budget()->row(); $row['slot_key'] = QuoteFixtures::digest( 'alternate_admission' ); $variants[] = $row; $row = QuoteStorageFixtures::budget()->row(); $row['window_start'] = QuoteFixtures::time()->plus_seconds( 1 )->sql(); $variants[] = $row;
		foreach ( $variants as $row ) { try { QuoteBudgetSlot::from_row( $row ); self::fail( 'Invalid budget passed.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } }
	}
	#[DataProvider( 'bad_consumed_links' )]
	public function test_consumed_admission_requires_exact_principal_and_quote_creation_before_consumption( string $bad ): void {
		$quote = $this->later_quote(); $row = $this->consumed_row( $quote ); if ( 'principal' === $bad ) { $row['principal_hash'] = QuoteFixtures::digest( 'another_principal' ); } else { $row['consumed_at'] = QuoteFixtures::time()->plus_seconds( 5 )->sql(); }
		$this->expectException( \InvalidArgumentException::class ); QuoteBudgetSlot::from_row( $row, $quote );
	}
	public static function bad_consumed_links(): array { return [ [ 'principal' ], [ 'time' ] ]; }
	public function test_consumed_admission_preserves_exact_parent_namespace_and_tombstone(): void {
		$quote = $this->later_quote(); $row = $this->consumed_row( $quote ); $slot = QuoteBudgetSlot::from_row( $row, $quote ); self::assertSame( 'consumed', $slot->row()['lease_state'] ); self::assertSame( $quote->header()->id()->value(), $slot->row()['consumed_quote_uuid'] );
		try { QuoteBudgetSlot::from_row( $row ); self::fail( 'Missing physical quote was accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		$this->expectException( \InvalidArgumentException::class ); QuoteBudgetSlot::from_row( $row, QuoteStorageFixtures::quote() );
	}
	public function test_stored_private_carriers_refuse_generic_json_serialization(): void {
		$quote = QuoteStorageFixtures::quote( state: 'accepted' ); foreach ( [ $quote, QuoteStorageFixtures::binding( $quote ), QuoteStorageFixtures::budget() ] as $carrier ) {
			foreach ( [ static fn() => json_encode( $carrier, JSON_THROW_ON_ERROR ), static fn() => serialize( $carrier ) ] as $serialize ) { try { $serialize(); self::fail( 'Private carrier was serialized.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( 'principal', $error->getMessage() ); } }
			$name = get_class( $carrier ); try { unserialize( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}', [ 'allowed_classes' => [ $name ] ] ); self::fail( 'Object transport bypassed row hydration.' ); } catch ( \LogicException $error ) { self::assertStringContainsString( 'strict row hydration', $error->getMessage() ); }
		}
	}
	private function later_quote(): QuoteStoredRow {
		$row = QuoteStorageFixtures::quote()->row(); $header = $row['header_json']; $facts = QuoteHeader::from_json( $header )->private_facts(); $facts['created_at'] = QuoteFixtures::time()->plus_seconds( 10 )->sql(); $facts['expires_at'] = QuoteFixtures::time()->plus_seconds( 310 )->sql(); $row['header_json'] = QuoteHeader::from_array( $facts )->to_private_json(); $row['created_at'] = $facts['created_at']; $row['expires_at'] = $facts['expires_at']; return QuoteStoredRow::from_row( $row );
	}
	private function consumed_row( QuoteStoredRow $quote ): array {
		$row = QuoteStorageFixtures::budget()->row(); $row['admission_namespace_hash'] = $quote->row()['issue_namespace_hash']; $row['slot_key'] = QuoteBudgetSlot::admission_slot_key( $row['admission_namespace_hash'] ); $row['lease_state'] = 'consumed'; $row['revision'] = 2; $row['consumed_quote_uuid'] = $quote->header()->id()->value(); $row['consumed_at'] = QuoteFixtures::time()->plus_seconds( 11 )->sql(); $row['last_seen_at'] = QuoteFixtures::time()->plus_seconds( 12 )->sql(); return $row;
	}
}
