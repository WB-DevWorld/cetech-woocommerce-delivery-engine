<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteLifecycle;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class LifecycleTransitionsTest extends TestCase {
	public function test_finite_legacy_profile_can_be_accepted_without_admitting_unknown_providers(): void {
		$context = QuoteFixtures::context(); $facts = QuoteFixtures::terms()->private_facts();
		$facts['groups'][0]['provider'] = [ 'code' => 'legacy_fixed_base_v1', 'version' => 1 ];
		$facts['groups'][0]['promotion']['provider'] = [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ];
		$terms = QuoteTerms::from_array( $facts ); $id = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate();
		$header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $terms, QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'issue' ), 'accept' => QuoteFixtures::digest( 'accept' ), 'invalidate' => QuoteFixtures::digest( 'invalidate' ) ], 'legacy_fixed_base_v1', 1, QuoteFixtures::reference( $id ) );
		$quote = DeliveryQuote::issue( $header, $context, $terms );
		self::assertNull( $quote->reason_at( QuoteFixtures::time(), $context ) );
		self::assertSame( 'accepted', $quote->accept( QuoteFixtures::owner(), QuoteFixtures::reference( $id ), $context, QuoteFixtures::time(), 1, $header->body_digest(), $header->expires_at() )->state() );
		foreach ( [ [ 'code' => 'legacy_fixed_base_v1', 'version' => 2 ], [ 'code' => 'unknown_price_provider', 'version' => 1 ] ] as $provider ) {
			$changed = $facts; $changed['groups'][0]['provider'] = $provider;
			$other_terms = QuoteTerms::from_array( $changed );
			$other_header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $other_terms, QuoteFixtures::time(), $header->namespace_hashes(), 'legacy_fixed_base_v1', 1, QuoteFixtures::reference( $id ) );
			self::assertSame( 'quote_unavailable', DeliveryQuote::issue( $other_header, $context, $other_terms )->reason_at( QuoteFixtures::time(), $context ) );
		}
		$changed = $facts; $changed['groups'][0]['promotion']['provider'] = [ 'code' => 'fixture_none_v1', 'version' => 1 ];
		$other_terms = QuoteTerms::from_array( $changed );
		$other_header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $other_terms, QuoteFixtures::time(), $header->namespace_hashes(), 'legacy_fixed_base_v1', 1, QuoteFixtures::reference( $id ) );
		self::assertSame( 'quote_unavailable', DeliveryQuote::issue( $other_header, $context, $other_terms )->reason_at( QuoteFixtures::time(), $context ) );
	}

	public function test_typed_unknown_current_evidence_does_not_fabricate_invalidation(): void {
		$quote = QuoteFixtures::issue(); $base = QuoteFixtures::context()->private_facts(); $variants = [];
		$facts = $base; $facts['lines'][0]['inventory']['status'] = 'unknown'; $variants[] = $facts;
		$facts = $base; $facts['groups'][0]['origin'] = [ 'state' => 'unknown', 'id' => null ]; $variants[] = $facts;
		foreach ( $variants as $facts ) {
			$context = QuoteContext::from_array( $facts ); $result = ( new QuoteLifecycle() )->invalidate( $quote, QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), $context, QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
			self::assertFalse( $result->completed() ); self::assertSame( 'quote_unavailable', $result->reason_code() ); self::assertSame( $quote, $result->quote() ); self::assertSame( 'issued', $quote->state() );
			try { $quote->invalidate( QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), $context, QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() ); self::fail( 'Unknown facts cannot invalidate directly.' ); }
			catch ( \DomainException ) { self::assertSame( 'issued', $quote->state() ); }
		}
	}
	public function test_conclusive_ineligible_inventory_can_invalidate_without_fabricating_unknown_facts(): void {
		$quote = QuoteFixtures::issue(); $facts = QuoteFixtures::context()->private_facts(); $facts['lines'][0]['inventory']['status'] = 'ineligible'; $context = QuoteContext::from_array( $facts );
		$result = ( new QuoteLifecycle() )->invalidate( $quote, QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), $context, QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertTrue( $context->material_evidence_available() ); self::assertFalse( $context->checkout_acceptable() ); self::assertTrue( $result->completed() ); self::assertSame( 'invalidated', $result->quote()->state() ); self::assertSame( $quote->terms(), $result->quote()->terms() ); self::assertSame( 'issued', $quote->state() );
	}
	public function test_correct_recomputed_body_digest_cannot_hydrate_incompatible_terms_manifest(): void {
		$quote = QuoteFixtures::issue(); $context = QuoteFixtures::context(); $facts = QuoteFixtures::terms()->private_facts(); $facts['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'different_policy' ); $terms = QuoteTerms::from_array( $facts );
		$header_facts = $quote->header()->private_facts(); $header_facts['body_digest'] = hash( 'sha256', 'cetech-quote-body-v1:' . QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ) );
		$this->expectException( \InvalidArgumentException::class ); DeliveryQuote::issue( QuoteHeader::from_array( $header_facts ), $context, $terms );
	}
	public function test_accept_and_confirmed_invalidation_preserve_original_body_and_acceptance_history(): void {
		$quote = QuoteFixtures::issue(); $reference = QuoteFixtures::reference( $quote->header()->id() ); $accepted_at = QuoteFixtures::time()->plus_seconds( 1 );
		$accepted = $quote->accept( QuoteFixtures::owner(), $reference, QuoteFixtures::context(), $accepted_at, 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		$facts = QuoteFixtures::context()->private_facts(); $facts['lines'][0]['quantity'] = '3'; $changed = QuoteContext::from_array( $facts );
		$invalidated = $accepted->invalidate( QuoteFixtures::owner(), $reference, $changed, QuoteFixtures::time()->plus_seconds( 2 ), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertSame( 'issued', $quote->state() ); self::assertSame( 'accepted', $accepted->state() ); self::assertSame( 'invalidated', $invalidated->state() ); self::assertSame( 3, $invalidated->revision() );
		self::assertSame( $quote->header(), $invalidated->header() ); self::assertSame( $quote->context(), $invalidated->context() ); self::assertSame( $quote->terms(), $invalidated->terms() ); self::assertSame( $accepted_at, $invalidated->accepted_at() ); self::assertSame( 'quote_invalidated', $invalidated->reason_at( QuoteFixtures::time()->plus_seconds( 2 ) ) );
		self::assertFalse( ( new QuoteLifecycle() )->accept( $invalidated, QuoteFixtures::owner(), $reference, QuoteFixtures::context(), QuoteFixtures::time()->plus_seconds( 3 ), 1, $quote->header()->body_digest(), $quote->header()->expires_at() )->completed() );
	}
	public function test_all_current_owner_coordinates_are_required_even_with_correct_handle(): void {
		$quote = QuoteFixtures::issue(); $reference = QuoteFixtures::reference( $quote->header()->id() ); $facts = QuoteFixtures::owner()->facts(); $service = new QuoteLifecycle();
		foreach ( [ 'site_id' => 2, 'kind' => 'customer', 'principal_hash' => QuoteFixtures::digest( 'other_customer' ), 'session_hash' => QuoteFixtures::digest( 'other_cart' ), 'key_epoch' => 'other_key' ] as $field => $value ) {
			$owner = QuoteOwner::from_array( array_replace( $facts, [ $field => $value ] ) ); $result = $service->accept( $quote, $owner, $reference, QuoteFixtures::context(), QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
			self::assertFalse( $result->completed(), $field ); self::assertSame( $quote, $result->quote() ); self::assertNull( $quote->accepted_at() );
		}
	}
	public function test_changed_handle_opened_revision_body_or_original_expiry_refuses_without_effect(): void {
		$quote = QuoteFixtures::issue(); $reference = QuoteFixtures::reference( $quote->header()->id() ); $header = $quote->header(); $service = new QuoteLifecycle();
		$wrong_handle = QuoteReference::from_array( [ 'quote_id' => $header->id()->value(), 'acceptance_handle' => QuoteFixtures::digest( 'new_handle' ) ] );
		foreach ( [ [ $wrong_handle, 1, $header->body_digest(), $header->expires_at() ], [ $reference, 2, $header->body_digest(), $header->expires_at() ], [ $reference, 1, QuoteFixtures::digest( 'wrong_body' ), $header->expires_at() ], [ $reference, 1, $header->body_digest(), $header->expires_at()->plus_seconds( 1 ) ] ] as $args ) {
			$result = $service->accept( $quote, QuoteFixtures::owner(), $args[0], QuoteFixtures::context(), QuoteFixtures::time(), $args[1], $args[2], $args[3] ); self::assertFalse( $result->completed() ); self::assertSame( $quote, $result->quote() );
		}
		self::assertSame( 1, $quote->revision() ); self::assertSame( 'issued', $quote->state() );
	}
	public function test_unknown_clock_owner_or_current_read_keeps_accepted_facts_and_never_invalidates(): void {
		$quote = QuoteFixtures::issue(); $reference = QuoteFixtures::reference( $quote->header()->id() ); $service = new QuoteLifecycle();
		$accepted = $quote->accept( QuoteFixtures::owner(), $reference, QuoteFixtures::context(), QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		foreach ( [ [ null, QuoteFixtures::context(), QuoteFixtures::time() ], [ QuoteFixtures::owner(), null, QuoteFixtures::time() ], [ QuoteFixtures::owner(), QuoteFixtures::context(), null ] ] as $args ) {
			$read = $service->current( $accepted, ...$args ); $invalidate = $service->invalidate( $accepted, $args[0], $reference, $args[1], $args[2], 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
			self::assertFalse( $read->completed() ); self::assertSame( 'quote_unavailable', $read->reason_code() ); self::assertSame( $accepted, $read->quote() ); self::assertFalse( $invalidate->completed() ); self::assertSame( $accepted, $invalidate->quote() );
		}
		self::assertSame( 'accepted', $accepted->state() ); self::assertSame( 2, $accepted->revision() );
	}
	public function test_material_change_requires_new_quote_not_repriced_original(): void {
		$quote = QuoteFixtures::issue(); $facts = QuoteFixtures::context()->private_facts(); $facts['selection_digest'] = QuoteFixtures::digest( 'new_selection' ); $changed = QuoteContext::from_array( $facts );
		$read = ( new QuoteLifecycle() )->current( $quote, QuoteFixtures::owner(), $changed, QuoteFixtures::time() );
		self::assertFalse( $read->completed() ); self::assertSame( 'quote_invalidated', $read->reason_code() ); self::assertSame( 'issued', $quote->state() ); self::assertSame( $quote, $read->quote() );
		$accept = ( new QuoteLifecycle() )->accept( $quote, QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), $changed, QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() ); self::assertFalse( $accept->completed() );
		self::assertSame( '12.50', $quote->terms()->private_facts()['groups'][0]['final']['amount'] );
	}
	public function test_model_strip_removes_private_payload_at_retention_boundary_but_retains_header(): void {
		$quote = QuoteFixtures::issue(); $header = $quote->header(); $reference = QuoteFixtures::reference( $header->id() ); $boundary = $header->expires_at()->plus_seconds( 1800 ); $service = new QuoteLifecycle();
		$early = $service->strip_for_model( $quote, QuoteFixtures::owner(), $reference, $boundary->plus_seconds( -1 ), 1, $header->body_digest(), $header->expires_at() ); self::assertFalse( $early->completed() ); self::assertSame( $quote, $early->quote() );
		$stripped = $service->strip_for_model( $quote, QuoteFixtures::owner(), $reference, $boundary, 1, $header->body_digest(), $header->expires_at() )->quote();
		self::assertSame( 'stripped', $stripped->state() ); self::assertNull( $stripped->context() ); self::assertNull( $stripped->terms() ); self::assertSame( $header, $stripped->header() ); self::assertSame( 2, $stripped->revision() ); self::assertSame( 'quote_unavailable', $stripped->reason_at( $boundary ) ); self::assertFalse( $stripped->usable_at( $boundary ) );
		self::assertFalse( $service->accept( $stripped, QuoteFixtures::owner(), $reference, QuoteFixtures::context(), $boundary, 1, $header->body_digest(), $header->expires_at() )->completed() );
	}
	public function test_accepted_history_cannot_be_stripped_even_long_after_expiry(): void {
		$quote = QuoteFixtures::issue(); $reference = QuoteFixtures::reference( $quote->header()->id() ); $accepted = $quote->accept( QuoteFixtures::owner(), $reference, QuoteFixtures::context(), QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		$result = ( new QuoteLifecycle() )->strip_for_model( $accepted, QuoteFixtures::owner(), $reference, QuoteFixtures::time()->plus_seconds( 86400 ), 1, $quote->header()->body_digest(), $quote->header()->expires_at() ); self::assertFalse( $result->completed() ); self::assertSame( $accepted, $result->quote() ); self::assertNotNull( $accepted->terms() ); self::assertNotNull( $accepted->accepted_at() );
	}
	public function test_acceptance_has_no_reservation_placement_payment_or_stock_hold_fact(): void {
		$quote = QuoteFixtures::issue(); $accepted = $quote->accept( QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), QuoteFixtures::context(), QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertSame( [ 'format_version', 'groups' ], array_keys( $accepted->terms()->private_facts() ) ); self::assertSame( $quote->terms()->to_private_json(), $accepted->terms()->to_private_json() ); self::assertSame( $quote->header()->to_private_json(), $accepted->header()->to_private_json() );
		foreach ( [ 'reserve', 'place', 'pay', 'stock_hold', 'capacity_expiry', 'native_hold_expires_at' ] as $name ) { self::assertFalse( method_exists( DeliveryQuote::class, $name ) ); self::assertArrayNotHasKey( $name, $accepted->header()->private_facts() ); }
	}
	public function test_aggregate_refuses_generic_json_instead_of_exposing_private_members(): void { $this->expectException( \LogicException::class ); json_encode( QuoteFixtures::issue(), JSON_THROW_ON_ERROR ); }
}
