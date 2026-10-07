<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/CartQuoteFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteRateReference,CartQuoteResult,CartQuoteSessionEnvelope,QuoteCartDraft,QuotePreparationCommand};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,CartQuoteFixtures,QuoteDurableFixtureFactory,QuoteFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartQuoteContractsTest extends TestCase {
	private const TOKEN = '12345678-1234-4abc-8abc-123456789abc';
	private function preparing(): CartQuoteSessionEnvelope { $draft = CartQuoteFixtures::draft(); $command = QuotePreparationCommand::create( $draft->owner(), $draft->draft_digest(), self::TOKEN, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1 ); return CartQuoteSessionEnvelope::begin( $command, 1, 1791352800 ); }
	private function issued(): CartQuoteSessionEnvelope { $factory = new QuoteDurableFixtureFactory(); $env = new CartQuoteFixtureEnvironment( $factory ); $sessions = new CartQuoteFixtureSessions(); CartQuoteFixtures::service( $factory, $env, $sessions )->refresh( self::TOKEN, 0, RequestContext::create() ); self::assertSame( 'issued', $sessions->current->phase() ); return $sessions->current; }
	public function test_cheap_draft_uses_keyed_identity_and_never_serializes_customer_facts(): void {
		$one = CartQuoteFixtures::identity(); $two = CartQuoteFixtures::identity( 'synthetic-cart-quote-private-key-two' ); $closed = '{"country":"GH","address":"PRIVATE-Q05-ADDRESS"}';
		self::assertNotSame( $one->cart_draft_digest( 1, QuoteFixtures::digest( 'same_owner' ), $closed ), $two->cart_draft_digest( 1, QuoteFixtures::digest( 'same_owner' ), $closed ) ); self::assertNotSame( $one->key_epoch(), $two->key_epoch() );
		$draft = CartQuoteFixtures::draft(); self::assertNotSame( hash( 'sha256', json_encode( $draft->private_facts(), JSON_THROW_ON_ERROR ) ), $draft->draft_digest() ); self::assertNotSame( $draft->draft_digest(), CartQuoteFixtures::draft( identity: $two )->draft_digest() );
		$facts = $draft->private_facts(); $facts['lines'][0]['customer_context']['delivery_address']['address'] = 'changed'; self::assertSame( 'PRIVATE-Q05-ADDRESS', $draft->private_facts()['lines'][0]['customer_context']['delivery_address']['address'] ); self::assertStringNotContainsString( 'PRIVATE-Q05-RECIPIENT', json_encode( $draft->private_facts(), JSON_THROW_ON_ERROR ) );
		try { json_encode( $draft, JSON_THROW_ON_ERROR ); self::fail( 'Draft was disclosed.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( 'PRIVATE-Q05', $error->getMessage() ); }
	}
	public function test_incidental_timestamps_and_product_objects_do_not_change_cheap_intent(): void {
		[$cart,$packages,$chosen] = CartQuoteFixtures::raw(); $first = CartQuoteFixtures::draft(); $cart['line_one']['cetech_de_delivery_selection']['issued_at'] = '2099-12-31T23:59:59Z'; $cart['line_one']['data'] = new \stdClass(); $packages[0]['contents'] = $cart;
		$second = QuoteCartDraft::from_loaded_cart( CartQuoteFixtures::owner(), $cart, $packages[0]['destination'], 'GHS', 2, CartQuoteFixtures::identity() ); self::assertSame( $first->draft_digest(), $second->draft_digest() ); self::assertNotSame( $first->draft_digest(), CartQuoteFixtures::draft( 3 )->draft_digest() );
	}
	public function test_fresh_uncalculated_draft_does_not_depend_on_transient_shipping_cache(): void {
		[$cart,$packages,$chosen] = CartQuoteFixtures::raw(); $owner = CartQuoteFixtures::owner(); $identity = CartQuoteFixtures::identity();
		$fresh = QuoteCartDraft::from_loaded_cart( $owner, $cart, $packages[0]['destination'], 'GHS', 2, $identity );
		$packages[0]['rates'] = [ new \stdClass() ]; $packages[0]['contents_cost'] = 99.0; $chosen[0] = 'automatically_selected';
		$calculated = QuoteCartDraft::from_loaded_cart( $owner, $cart, $packages[0]['destination'], 'GHS', 2, $identity );
		self::assertSame( $fresh->draft_digest(), $calculated->draft_digest() );
		self::assertArrayNotHasKey( 'packages', $fresh->private_facts() );
		self::assertArrayNotHasKey( 'chosen_shipping_methods', $fresh->private_facts() );
		$cart['line_one']['cetech_de_delivery_selection']['display_key'] = 'another_semantic_selection';
		self::assertNotSame( $fresh->draft_digest(), QuoteCartDraft::from_loaded_cart( $owner, $cart, $packages[0]['destination'], 'GHS', 2, $identity )->draft_digest() );
	}
	public function test_loaded_native_customer_destination_is_part_of_original_cheap_intent(): void {
		[$cart,$packages] = CartQuoteFixtures::raw(); $destination = $packages[0]['destination']; $owner = CartQuoteFixtures::owner(); $identity = CartQuoteFixtures::identity();
		$original = QuoteCartDraft::from_loaded_cart( $owner, $cart, $destination, 'GHS', 2, $identity );
		$destination['address'] = 'ANOTHER-Q05-CUSTOMER-ADDRESS';
		$changed = QuoteCartDraft::from_loaded_cart( $owner, $cart, $destination, 'GHS', 2, $identity );
		self::assertNotSame( $original->draft_digest(), $changed->draft_digest() );
		self::assertSame( 'PRIVATE-Q05-ADDRESS', $original->private_facts()['customer_destination']['address'] );
		self::assertSame( 'PRIVATE-Q05-ADDRESS', $changed->private_facts()['lines'][0]['customer_context']['delivery_address']['address'] );
	}
	#[DataProvider( 'malformed_draft' )]
	public function test_malformed_or_limit_plus_one_native_draft_refuses_before_any_capture( string $case ): void {
		[$cart,$packages,$chosen] = CartQuoteFixtures::raw();
		if ( 'quantity_coercion' === $case ) { $cart['line_one']['quantity'] = '2'; }
		elseif ( 'nested_object' === $case ) { $cart['line_one']['cetech_de_customer_context']['delivery_address']['address'] = new \stdClass(); }
		elseif ( 'unknown_destination' === $case ) { $packages[0]['destination']['private_extra'] = 'not_permitted'; }
		elseif ( 'malformed_selection' === $case ) { $cart['line_one']['cetech_de_delivery_selection']['delivery_offer_id'] = '20'; }
		elseif ( 'oversized_address' === $case ) { $packages[0]['destination']['address'] = str_repeat( 'x', 513 ); }
		else { for ( $i = 0; $i < 201; ++$i ) { $cart['copy_' . $i] = $cart['line_one']; } }
		$this->expectException( \InvalidArgumentException::class ); QuoteCartDraft::from_loaded_cart( CartQuoteFixtures::owner(), $cart, $packages[0]['destination'], 'GHS', 2, CartQuoteFixtures::identity() );
	}
	public static function malformed_draft(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'quantity_coercion', 'nested_object', 'unknown_destination', 'malformed_selection', 'oversized_address', 'line_limit' ] ); }
	public function test_original_envelope_roundtrip_preserves_exact_context_header_handle_and_has_no_terms(): void {
		$one = $this->issued(); $json = $one->to_private_json(); $two = CartQuoteSessionEnvelope::from_private_json( $json ); self::assertSame( $json, $two->to_private_json() ); self::assertSame( $one->original_issue()->intent_digest(), $two->original_issue()->intent_digest() ); self::assertSame( $one->header()->to_private_json(), $two->header()->to_private_json() ); self::assertSame( $one->reference()->public_fields(), $two->reference()->public_fields() );
		foreach ( [ 'private_body_json', 'terms', 'native_tax_receipt', 'PRIVATE-Q05-ADDRESS', 'PRIVATE-Q05-RECIPIENT' ] as $private ) { self::assertStringNotContainsString( '"' . $private . '"', $json ); }
		foreach ( [ $one, $one->rate_references()[0] ] as $carrier ) { try { json_encode( $carrier, JSON_THROW_ON_ERROR ); self::fail( 'Private envelope was disclosed.' ); } catch ( \LogicException ) { self::assertTrue( true ); } try { serialize( $carrier ); self::fail( 'Private envelope was serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
	}
	public function test_rate_reference_is_exact_quote_component_generation_and_replay_stable(): void {
		$envelope = $this->issued(); $ref = $envelope->rate_references()[0]; $component = $envelope->original_issue()->context()->private_facts()['groups'][0]['component_key']; self::assertTrue( $ref->matches( $envelope->header(), $component, 1 ) ); self::assertFalse( $ref->matches( $envelope->header(), QuoteFixtures::digest( 'other_component' ), 1 ) ); self::assertFalse( $ref->matches( $envelope->header(), $component, 2 ) );
		$public = $ref->public_fields(); self::assertSame( [ 'quote_id', 'component_handle', 'generation' ], array_keys( $public ) ); self::assertStringNotContainsString( $component, json_encode( $public, JSON_THROW_ON_ERROR ) ); self::assertSame( $public, CartQuoteSessionEnvelope::from_private_json( $envelope->to_private_json() )->rate_references()[0]->public_fields() );
		$facts = json_decode( $envelope->to_private_json(), true, 16, JSON_THROW_ON_ERROR ); $facts['rate_references'][0]['generation'] = 2; $this->expectException( \InvalidArgumentException::class ); CartQuoteSessionEnvelope::from_private_array( $facts );
	}
	public function test_lineage_cannot_replace_pending_or_retarget_existing_original_credentials(): void {
		$preparing = $this->preparing(); self::assertTrue( $preparing->follows( null ) ); $next = CartQuoteSessionEnvelope::begin( $preparing->preparation(), 2, $preparing->expires_at() ); self::assertFalse( $next->follows( $preparing ) );
		$issued = $this->issued(); $accepting = $issued->with_phase( 'accepting' ); self::assertTrue( $accepting->follows( $issued ) ); $confirmed = $accepting->with_phase( 'confirmed' ); self::assertTrue( $confirmed->follows( $accepting ) ); self::assertFalse( $issued->follows( $confirmed ) ); self::assertFalse( $accepting->follows( $confirmed ) );
		$replacement = CartQuoteSessionEnvelope::begin( $issued->preparation(), 2, $issued->expires_at() ); self::assertTrue( $replacement->follows( $confirmed ) );
	}
	#[DataProvider( 'envelope_corruption' )]
	public function test_unknown_or_conflicting_private_envelopes_are_refused( string $case ): void {
		$envelope = $this->issued(); $facts = json_decode( $envelope->to_private_json(), true, 16, JSON_THROW_ON_ERROR );
		if ( 'unknown_format' === $case ) { $facts['format_version'] = 2; } elseif ( 'extra_body' === $case ) { $facts['terms'] = []; } elseif ( 'changed_intent' === $case ) { $facts['issue_intent_digest'] = QuoteFixtures::digest( 'changed_intent' ); } elseif ( 'missing_component' === $case ) { $facts['rate_references'] = []; } elseif ( 'unsafe_generation' === $case ) { $facts['generation'] = 9007199254740992; } else { $facts['reference']['acceptance_handle'] = QuoteFixtures::digest( 'stolen_handle' ); }
		$this->expectException( \InvalidArgumentException::class ); CartQuoteSessionEnvelope::from_private_array( $facts );
	}
	public static function envelope_corruption(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'unknown_format', 'extra_body', 'changed_intent', 'missing_component', 'unsafe_generation', 'stolen_handle' ] ); }
	public function test_unconfirmed_dto_never_authorizes_refresh_or_exposes_private_projection(): void {
		$this->expectException( \InvalidArgumentException::class ); CartQuoteResult::create( 'unconfirmed', 1, RequestContext::create(), null, true, false, true );
	}
}
