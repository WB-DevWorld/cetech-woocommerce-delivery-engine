<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class CoreMaterialTest extends TestCase {
	public function test_member_and_object_order_do_not_change_the_material_fingerprint(): void {
		$data = QuoteFixtures::context()->private_facts(); $second = $data['lines'][0]; $second['line_key'] = 'line_two'; $second['product_id'] = 11; $data['lines'][] = $second; $data['groups'][0]['line_keys'][] = 'line_two'; $a = QuoteContext::from_array( $data );
		$data['lines'] = array_reverse( $data['lines'] ); $data['groups'][0]['line_keys'] = array_reverse( $data['groups'][0]['line_keys'] ); $data = array_reverse( $data, true ); $b = QuoteContext::from_array( $data ); self::assertSame( $a->digest(), $b->digest() ); self::assertSame( $a->to_private_json(), $b->to_private_json() );
	}
	#[DataProvider( 'material_edits' )]
	public function test_each_actual_material_edit_changes_the_same_sku_fingerprint( string $field ): void {
		$data = QuoteFixtures::context()->private_facts(); $before = QuoteContext::from_array( $data );
		switch ( $field ) {
			case 'quantity': $data['lines'][0]['quantity'] = '3'; break;
			case 'variation': $data['lines'][0]['variation_id'] = 12; $data['lines'][0]['parent_id'] = 10; break;
			case 'destination': $data['destination']['digest'] = QuoteFixtures::digest( 'another_destination' ); break;
			case 'source_route': $data['lines'][0]['source']['route'] = 'legacy'; break;
			case 'source_revision': ++$data['lines'][0]['source']['revision']; break;
			case 'inventory': $data['lines'][0]['inventory']['evidence_digest'] = QuoteFixtures::digest( 'another_inventory' ); break;
			case 'policy': $data['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'another_policy' ); break;
			case 'candidate': $data['groups'][0]['candidate_digest'] = QuoteFixtures::digest( 'new_higher_precedence_candidate' ); break;
			case 'supplier': $data['groups'][0]['supplier'] = [ 'state' => 'known', 'id' => 99 ]; break;
			case 'tax': $data['tax']['context_digest'] = QuoteFixtures::digest( 'another_tax_context' ); break;
			case 'native_money': $data['tax']['native_money_digest'] = QuoteFixtures::digest( 'another_native_money' ); break;
			case 'currency': $data['currency'] = [ 'base' => 'USD', 'presentment' => 'USD', 'charged' => 'USD', 'precision' => 2 ]; break;
		}
		self::assertNotSame( $before->digest(), QuoteContext::from_array( $data )->digest() );
	}
	public static function material_edits(): array { return array_map( static fn( string $field ): array => [ $field ], [ 'quantity', 'variation', 'destination', 'source_route', 'source_revision', 'inventory', 'policy', 'candidate', 'supplier', 'tax', 'native_money', 'currency' ] ); }
	public function test_only_proven_absence_normalizes_null_and_zero(): void {
		$data = QuoteFixtures::context()->private_facts(); $a = QuoteContext::from_array( $data ); $data['groups'][0]['supplier']['id'] = 0; $b = QuoteContext::from_array( $data ); self::assertSame( $a->digest(), $b->digest() );
		$data['groups'][0]['supplier'] = [ 'state' => 'unknown', 'id' => null ]; $unknown = QuoteContext::from_array( $data ); self::assertNotSame( $a->digest(), $unknown->digest() ); self::assertTrue( $a->checkout_acceptable() ); self::assertFalse( $unknown->checkout_acceptable() );
	}
	public function test_estimate_unknown_inventory_and_unverified_fx_never_become_checkout_acceptable(): void {
		$data = QuoteFixtures::context()->private_facts(); $data['kind'] = 'estimate'; self::assertFalse( QuoteContext::from_array( $data )->checkout_acceptable() ); $data['kind'] = 'checkout'; $data['destination']['kind'] = 'estimate'; self::assertFalse( QuoteContext::from_array( $data )->checkout_acceptable() );
		$data = QuoteFixtures::context()->private_facts(); $data['lines'][0]['inventory']['status'] = 'unknown'; self::assertFalse( QuoteContext::from_array( $data )->checkout_acceptable() ); $data = QuoteFixtures::context()->private_facts(); $data['currency']['charged'] = 'USD'; self::assertFalse( QuoteContext::from_array( $data )->checkout_acceptable() );
	}
	public function test_only_conclusive_material_evidence_can_support_invalidation(): void {
		self::assertTrue( QuoteFixtures::context()->material_evidence_available() );
		$data = QuoteFixtures::context()->private_facts(); $data['lines'][0]['inventory']['status'] = 'ineligible'; $known = QuoteContext::from_array( $data ); self::assertTrue( $known->material_evidence_available() ); self::assertFalse( $known->checkout_acceptable() );
		$data['lines'][0]['inventory']['status'] = 'unknown'; self::assertFalse( QuoteContext::from_array( $data )->material_evidence_available() );
		foreach ( [ 'origin', 'supplier', 'profile' ] as $field ) { $data = QuoteFixtures::context()->private_facts(); $data['groups'][0][$field] = [ 'state' => 'unknown', 'id' => null ]; self::assertFalse( QuoteContext::from_array( $data )->material_evidence_available() ); }
		$data = QuoteFixtures::context()->private_facts(); $data['destination']['kind'] = 'estimate'; self::assertFalse( QuoteContext::from_array( $data )->material_evidence_available() );
		$data = QuoteFixtures::context()->private_facts(); $data['kind'] = 'estimate'; self::assertFalse( QuoteContext::from_array( $data )->material_evidence_available() );
	}
	#[DataProvider( 'invalid_context_edits' )]
	public function test_invalid_relationships_scope_and_partial_receipts_refuse( string $field ): void {
		$data = QuoteFixtures::context()->private_facts();
		switch ( $field ) {
			case 'unknown_version': $data['format_version'] = 2; break;
			case 'unknown_field': $data['raw_address'] = 'PRIVATE_ADDRESS'; break;
			case 'wrong_parent': $data['lines'][0]['variation_id'] = 12; $data['lines'][0]['parent_id'] = 99; break;
			case 'simple_parent': $data['lines'][0]['parent_id'] = 10; break;
			case 'zero_quantity': $data['lines'][0]['quantity'] = '0.000000'; break;
			case 'float_quantity': $data['lines'][0]['quantity'] = 2.0; break;
			case 'duplicate_line': $data['lines'][] = $data['lines'][0]; break;
			case 'duplicate_membership': $data['groups'][0]['line_keys'][] = $data['groups'][0]['line_keys'][0]; break;
			case 'missing_member': $data['groups'][0]['line_keys'] = [ 'not_the_actual_line' ]; break;
			case 'positive_unknown_id': $data['groups'][0]['supplier'] = [ 'state' => 'unknown', 'id' => 99 ]; break;
			case 'overflow_candidate': $data['groups'][0]['candidate_count'] = 1001; break;
			case 'overflow_lines': $data['lines'] = array_fill( 0, 201, $data['lines'][0] ); break;
		}
		$this->expectException( \InvalidArgumentException::class ); $this->expectExceptionMessage( 'Invalid delivery quote facts.' ); QuoteContext::from_array( $data );
	}
	public static function invalid_context_edits(): array { return array_map( static fn( string $field ): array => [ $field ], [ 'unknown_version', 'unknown_field', 'wrong_parent', 'simple_parent', 'zero_quantity', 'float_quantity', 'duplicate_line', 'duplicate_membership', 'missing_member', 'positive_unknown_id', 'overflow_candidate', 'overflow_lines' ] ); }
	public function test_context_detaches_both_input_and_exported_nested_references(): void { $data = QuoteFixtures::context()->private_facts(); $source = $data['lines'][0]['source']; $data['lines'][0]['source'] = &$source; $context = QuoteContext::from_array( $data ); $digest = $context->digest(); $source['revision'] = 999; $export = $context->private_facts(); $export['lines'][0]['quantity'] = '99'; self::assertSame( $digest, $context->digest() ); self::assertSame( 3, $context->private_facts()['lines'][0]['source']['revision'] ); }
	public function test_header_fixed_half_open_ttl_never_slides_and_clock_regression_refuses(): void {
		$header = self::header(); $original = $header->to_private_json(); $created = $header->created_at(); self::assertFalse( $header->valid_at( QuoteTime::from_epoch_microseconds( $created->epoch_microseconds() - 1 ) ) ); self::assertTrue( $header->valid_at( $created ) ); self::assertTrue( $header->valid_at( $created->plus_seconds( 299 ) ) ); self::assertFalse( $header->valid_at( $created->plus_seconds( 300 ) ) ); self::assertFalse( $header->valid_at( $created->plus_seconds( 301 ) ) ); self::assertSame( $original, $header->to_private_json() ); self::assertSame( 300000000, $header->expires_at()->epoch_microseconds() - $created->epoch_microseconds() );
	}
	public function test_header_binds_the_original_public_reference_without_disclosing_its_handle(): void { $id = QuoteId::generate(); $reference = QuoteReference::generate( $id ); $header = self::header( $id, $reference ); self::assertTrue( $header->matches_reference( $reference ) ); self::assertFalse( $header->matches_reference( QuoteReference::generate( $id ) ) ); self::assertStringNotContainsString( $reference->handle(), $header->to_private_json() ); self::assertSame( $header->to_private_json(), QuoteHeader::from_json( $header->to_private_json() )->to_private_json() ); }
	#[DataProvider( 'invalid_header_edits' )]
	public function test_header_unknown_profile_version_or_extended_expiry_refuses( string $field ): void { $data = self::header()->private_facts(); switch ( $field ) { case 'format': $data['format_version'] = 2; break; case 'profile': $data['profile'] = 'unknown_provider'; break; case 'profile_version': $data['profile_version'] = 2; break; case 'revision': $data['revision'] = 2; break; case 'expiry': $data['expires_at'] = QuoteTime::parse( $data['expires_at'] )->plus_seconds( 1 )->sql(); break; case 'namespace_alias': $data['namespace_hashes']['accept'] = $data['namespace_hashes']['issue']; break; case 'private_extension': $data['private_note'] = 'RAW_PRIVATE_DATA'; break; } $this->expectException( \InvalidArgumentException::class ); QuoteHeader::from_array( $data ); }
	public static function invalid_header_edits(): array { return array_map( static fn( string $field ): array => [ $field ], [ 'format', 'profile', 'profile_version', 'revision', 'expiry', 'namespace_alias', 'private_extension' ] ); }
	public function test_header_detects_cross_body_policy_mismatch_before_a_quote_exists(): void { $data = QuoteFixtures::context()->private_facts(); $data['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'edited_policy' ); $this->expectException( \InvalidArgumentException::class ); QuoteHeader::issue( QuoteId::generate(), QuoteFixtures::owner(), QuoteContext::from_array( $data ), QuoteFixtures::terms(), QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'i' ), 'accept' => QuoteFixtures::digest( 'a' ), 'invalidate' => QuoteFixtures::digest( 'x' ) ] ); }
	public function test_private_context_refuses_generic_json(): void { $this->expectException( \LogicException::class ); json_encode( QuoteFixtures::context(), JSON_THROW_ON_ERROR ); }
	private static function header( ?QuoteId $id = null, ?QuoteReference $reference = null ): QuoteHeader { $id ??= QuoteId::generate(); return QuoteHeader::issue( $id, QuoteFixtures::owner(), QuoteFixtures::context(), QuoteFixtures::terms(), QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'issue' ), 'accept' => QuoteFixtures::digest( 'accept' ), 'invalidate' => QuoteFixtures::digest( 'invalidate' ) ], 'fixture_v1', 1, $reference ); }
}
