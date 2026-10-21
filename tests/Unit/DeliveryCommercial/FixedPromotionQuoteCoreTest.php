<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryCommercial\FixedPromotionQuoteCapture;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteStoredRow, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as Fixture;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Pure replay over synthetic native observations; no campaign or acceptance authority. */
final class FixedPromotionQuoteCoreTest extends TestCase {
	public function test_full_private_capture_and_forward_bodies_round_trip_without_rewriting_originals(): void {
		$provider = Fixture::provider(); $context = $provider->context(); $terms = $provider->terms(); $capture = $context->fixed_promotion_capture();
		self::assertInstanceOf( FixedPromotionQuoteCapture::class, $capture );
		self::assertSame( $capture->to_private_json(), FixedPromotionQuoteCapture::from_json( $capture->to_private_json() )->to_private_json() );
		self::assertSame( $capture->digest(), $terms->private_facts()['promotion_capture_digest'] );
		self::assertSame( Fixture::original_context()->to_private_json(), $capture->original_context()->to_private_json() );
		self::assertSame( Fixture::original_terms()->to_private_json(), $capture->original_terms()->to_private_json() );
		self::assertSame( $context->to_private_json(), QuoteContext::from_json( $context->to_private_json() )->to_private_json() );
		self::assertSame( $terms->to_private_json(), QuoteTerms::from_json( $terms->to_private_json() )->to_private_json() );
		self::assertSame( 1, $context->base_context()->format_version() ); self::assertSame( 1, $terms->base_terms()->format_version() );
		self::assertNull( $terms->promise_packet() ); self::assertSame( [], $context->promise_groups() );
		self::assertTrue( $context->checkout_acceptable() ); self::assertTrue( $terms->checkout_acceptable() );
		$header = Fixture::header( $provider ); $header->assert_body( $context, $terms );
		self::assertSame( 3, QuoteHeader::from_json( $header->to_private_json() )->format_version() );
		self::assertSame( '12.5000', $terms->display_money()[0]['list']['amount'] );
		self::assertSame( '3.0000', $terms->display_money()[0]['promotion']['amount']['amount'] );
		self::assertSame( '9.5000', $terms->display_money()[0]['final']['amount'] );
		self::assertStringNotContainsString( 'funding_source', json_encode( $terms->display_money(), JSON_THROW_ON_ERROR ) );
	}

	public function test_paired_null_unadjusted_slots_are_complete_and_preserve_their_original_final(): void {
		$provider = Fixture::mixed_provider(); $capture = $provider->context()->fixed_promotion_capture();
		self::assertCount( 2, $capture->private_facts()['groups'] ); $nulls = 0;
		foreach ( $capture->private_facts()['groups'] as $slot ) { if ( null === $slot['rule'] ) { self::assertNull( $slot['plan'] ); ++$nulls; } }
		self::assertSame( 1, $nulls );
		foreach ( $provider->terms()->private_facts()['groups'] as $group ) {
			if ( Fixture::second_key() !== $group['component_key'] ) { continue; }
			self::assertSame( 'none', $group['promotion']['state'] ); self::assertSame( '12.5000', $group['final']['amount'] );
		}
		Fixture::header( $provider )->assert_body( $provider->context(), $provider->terms() );
	}

	#[DataProvider( 'capture_corruptions' )]
	public function test_full_original_rule_plan_and_slot_bindings_refuse_changes( string $edit ): void {
		$data = Fixture::provider()->context()->fixed_promotion_capture()->private_facts();
		switch ( $edit ) {
			case 'missing_slot': $data['groups'] = []; break;
			case 'duplicate_slot': $data['groups'][] = $data['groups'][0]; break;
			case 'foreign_slot': $data['groups'][0]['component_key'] = QuoteFixtures::digest( 'foreign' ); break;
			case 'null_rule_only': $data['groups'][0]['rule'] = null; break;
			case 'null_plan_only': $data['groups'][0]['plan'] = null; break;
			case 'all_unadjusted': $data['groups'][0]['rule'] = null; $data['groups'][0]['plan'] = null; break;
			case 'funding': $data['groups'][0]['rule']['funding_source']['code'] = 'other_funder'; break;
			case 'plan_discount': $data['groups'][0]['plan']['money']['discount']['amount'] = '2.00'; break;
			case 'plan_terms': $data['groups'][0]['plan']['terms_digest'] = QuoteFixtures::digest( 'foreign' ); break;
			case 'original_header': $data['original_header']['body_digest'] = QuoteFixtures::digest( 'foreign' ); break;
			case 'original_material': $data['original_context']['selection_digest'] = QuoteFixtures::digest( 'foreign' ); break;
			case 'original_terms': $data['original_terms']['groups'][0]['customer_label'] = 'Changed label'; break;
			case 'extra': $data['extra'] = true; break;
		}
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionQuoteCapture::from_array( $data );
	}
	public static function capture_corruptions(): array { return array_map( static fn( string $edit ): array => [ $edit ], [ 'missing_slot', 'duplicate_slot', 'foreign_slot', 'null_rule_only', 'null_plan_only', 'all_unadjusted', 'funding', 'plan_discount', 'plan_terms', 'original_header', 'original_material', 'original_terms', 'extra' ] ); }

	public function test_fresh_context_allows_only_native_tax_evidence_to_change(): void {
		$provider = Fixture::provider(); $capture = $provider->context()->fixed_promotion_capture(); $facts = $provider->context()->base_context()->private_facts();
		$facts['tax']['context_digest'] = QuoteFixtures::digest( 'another fresh capture' ); $capture->assert_context( QuoteContext::from_array( $facts ) );
		$facts['selection_digest'] = QuoteFixtures::digest( 'changed selection' );
		$this->expectException( \InvalidArgumentException::class ); $capture->assert_context( QuoteContext::from_array( $facts ) );
	}

	#[DataProvider( 'term_corruptions' )]
	public function test_recomputed_valid_terms_still_must_match_original_list_and_frozen_plan( string $edit ): void {
		$provider = Fixture::provider(); $capture = $provider->context()->fixed_promotion_capture(); $data = $provider->terms()->base_terms()->private_facts();
		switch ( $edit ) {
			case 'money': $data['groups'][0]['list']['amount'] = '13.5000'; $data['groups'][0]['promotion']['amount']['amount'] = '4.0000'; break;
			case 'provider': $data['groups'][0]['provider']['code'] = 'legacy_fixed_base_v1'; break;
			case 'promotion_provider': $data['groups'][0]['promotion']['provider']['code'] = 'other_fixed_provider'; break;
			case 'policy': $data['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'other policy' ); break;
			case 'cost': $data['groups'][0]['cost']['reason'] = 'unsupported_context'; break;
		}
		$terms = QuoteTerms::from_array( $data );
		$this->expectException( \InvalidArgumentException::class ); $capture->assert_terms( $terms );
	}
	public static function term_corruptions(): array { return array_map( static fn( string $edit ): array => [ $edit ], [ 'money', 'provider', 'promotion_provider', 'policy', 'cost' ] ); }

	public function test_terms_capture_digest_is_verified_against_context_before_a_header_can_be_issued(): void {
		$provider = Fixture::provider(); $data = $provider->terms()->private_facts(); $data['promotion_capture_digest'] = QuoteFixtures::digest( 'wrong capture' ); $terms = QuoteTerms::from_array( $data );
		$this->expectException( \InvalidArgumentException::class );
		QuoteHeader::issue( QuoteId::generate(), Fixture::owner(), $provider->context(), $terms, QuoteFixtures::time(), Fixture::header( $provider )->namespace_hashes(), 'fixed_promotion_native_v1' );
	}

	public function test_new_header_cannot_swap_the_captured_original_owner(): void {
		$provider = Fixture::provider(); $owner = Fixture::owner()->facts(); $owner['session_hash'] = QuoteFixtures::digest( 'another actor' );
		$this->expectException( \InvalidArgumentException::class );
		QuoteHeader::issue( QuoteId::generate(), QuoteOwner::from_array( $owner ), $provider->context(), $provider->terms(), QuoteFixtures::time(), Fixture::header( $provider )->namespace_hashes(), 'fixed_promotion_native_v1' );
	}

	public function test_promise_v2_is_refused_intact_rather_than_stripped_to_an_original(): void {
		$context = PromiseHandoffFixture::context(); $terms = PromiseHandoffFixture::terms(); $header = PromiseHandoffFixture::header( $context, $terms ); $before = $context->to_private_json();
		try { FixedPromotionQuoteCapture::from_captured( $header, $context, $terms, [ [ 'component_key' => Fixture::key(), 'rule' => Fixture::rule(), 'plan' => Fixture::plan() ] ] ); self::fail(); }
		catch ( \InvalidArgumentException ) { self::assertSame( $before, $context->to_private_json() ); }
	}

	public function test_fx_original_is_refused_even_when_header_body_are_self_consistent(): void {
		$context_data = Fixture::original_context()->private_facts(); $context_data['currency']['presentment'] = 'USD'; $context = QuoteContext::from_array( $context_data ); $terms = Fixture::original_terms(); $header = Fixture::original_header( $context, $terms );
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionQuoteCapture::from_captured( $header, $context, $terms, [ [ 'component_key' => Fixture::key(), 'rule' => Fixture::rule(), 'plan' => Fixture::plan() ] ] );
	}

	public function test_forward_format_is_structurally_readable_but_never_usable_or_acceptable(): void {
		$provider = Fixture::provider(); $reference = QuoteFixtures::reference( QuoteId::generate() ); $header = Fixture::header( $provider, $reference ); $quote = DeliveryQuote::issue( $header, $provider->context(), $provider->terms() );
		self::assertFalse( $quote->usable_at( QuoteFixtures::time() ) ); self::assertSame( 'quote_unavailable', $quote->reason_at( QuoteFixtures::time() ) );
		$this->expectException( \DomainException::class );
		$quote->accept( Fixture::owner(), $reference, $provider->context(), QuoteFixtures::time(), 1, $header->body_digest(), $header->expires_at() );
	}

	public function test_issued_storage_round_trip_is_additive_but_accepted_storage_is_refused(): void {
		$row = self::issued_row(); $stored = QuoteStoredRow::from_row( $row );
		self::assertSame( $row, $stored->row() ); self::assertSame( 3, $stored->context()->format_version() ); self::assertSame( 3, $stored->terms()->format_version() );
		$row['state'] = 'accepted'; $row['revision'] = 2; $row['accepted_at'] = QuoteFixtures::time()->plus_seconds( 1 )->sql(); $row['transition_at'] = $row['accepted_at'];
		$this->expectException( \InvalidArgumentException::class ); QuoteStoredRow::from_row( $row );
	}

	public function test_capture_never_implicitly_exports_its_funding_body(): void {
		$capture = Fixture::provider()->context()->fixed_promotion_capture();
		self::assertSame( [ 'private_promotion_capture' => 'redacted' ], $capture->__debugInfo() );
		foreach ( [ static fn() => json_encode( $capture, JSON_THROW_ON_ERROR ), static fn() => serialize( $capture ) ] as $export ) {
			try { $export(); self::fail( 'Implicit private export must refuse.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		}
	}

	public function test_capture_bearing_context_refuses_implicit_php_serialization_and_redacts_debug(): void {
		$context = Fixture::provider()->context();
		self::assertSame( [ 'format_version', 'material_digest', 'promotion_capture' ], array_keys( $context->__debugInfo() ) );
		self::assertStringNotContainsString( 'private_fixed_funder', json_encode( $context->__debugInfo(), JSON_THROW_ON_ERROR ) );
		$this->expectException( \LogicException::class ); serialize( $context );
	}

	public function test_old_context_php_property_representation_and_strict_round_trip_are_preserved(): void {
		foreach ( [ Fixture::original_context(), PromiseHandoffFixture::context() ] as $context ) {
			$class = QuoteContext::class; $json_key = "\0" . $class . "\0json"; $acceptable_key = "\0" . $class . "\0acceptable";
			$expected = 'O:' . strlen( $class ) . ':"' . $class . '":2:{' . serialize( $json_key ) . serialize( $context->to_private_json() ) . serialize( $acceptable_key ) . serialize( $context->checkout_acceptable() ) . '}';
			self::assertSame( $expected, serialize( $context ) );
			self::assertSame( $context->to_private_json(), unserialize( $expected, [ 'allowed_classes' => [ QuoteContext::class ] ] )->to_private_json() );
		}
	}

	private static function issued_row(): array {
		$provider = Fixture::provider(); $header = Fixture::header( $provider ); $owner = $header->owner();
		return [ 'id' => 1, 'site_id' => $owner->site_id(), 'quote_uuid' => $header->id()->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'profile_version' => 1, 'purpose' => 'checkout',
			'principal_hash' => $owner->facts()['principal_hash'], 'owner_digest' => $owner->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(),
			'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ),
			'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'],
			'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ];
	}
}
