<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

require_once __DIR__ . '/QuoteDurableFixtures.php';
require_once __DIR__ . '/LegacyQuoteProviderFixtures.php';

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteEnvironment,CartQuoteService,CartQuoteSessionEnvelope,CartQuoteSessionStore,LegacyQuotePreparedCapture,QuoteCartCurrentEvidence,QuoteCartDraft,QuoteIssueCommand,QuoteNativeContextIdentity,QuotePreparationGate};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteHeader,QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Local protocol/transport model with real C03 storage; no native Woo or lock qualification. */
final class CartQuoteFixtures {
	public static function identity( string $secret = 'synthetic-cart-quote-private-key-one' ): QuoteNativeContextIdentity { return QuoteNativeContextIdentity::from_private_key( $secret ); }
	public static function owner( ?QuoteNativeContextIdentity $identity = null ): QuoteOwner { return QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'key_epoch' => ( $identity ?? self::identity() )->key_epoch() ] ) ); }
	public static function raw( int $quantity = 2 ): array {
		$selection = [ 'contract_version' => '1', 'product_id' => 10, 'variation_id' => null, 'target_type' => 'product', 'target_id' => 10, 'display_key' => 'in_warehouse:delivery:20', 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'rule_id' => 3, 'issued_at' => '2026-10-07T05:00:00Z' ];
		$address = [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address' => 'PRIVATE-Q05-ADDRESS', 'address_2' => '' ];
		$customer = [ 'contract_version' => 1, 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'pickup_location_id' => null, 'matching_location' => [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001' ], 'delivery_address' => [ ...$address, 'recipient' => [ 'first_name' => 'PRIVATE-Q05-RECIPIENT' ] ], 'matching_identity' => QuoteFixtures::digest( 'matching' ), 'delivery_location_identity' => QuoteFixtures::digest( 'location' ) ];
		$cart = [ 'line_one' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => $quantity, 'data' => new \stdClass(), 'cetech_de_delivery_selection' => $selection, 'cetech_de_delivery_selection_hash' => QuoteFixtures::digest( 'selection' ), 'cetech_de_customer_context' => $customer ] ];
		return [ $cart, [ [ 'contents' => $cart, 'destination' => $address, 'cetech_de' => [ 'managed' => true, 'group_id' => 'in_warehouse|delivery|20|synthetic' ] ] ], [ 'cetech_selected_offer:1:synthetic' ] ];
	}
	public static function draft( int $quantity = 2, ?QuoteNativeContextIdentity $identity = null ): QuoteCartDraft { $identity ??= self::identity(); [$cart,$packages] = self::raw( $quantity ); return QuoteCartDraft::from_loaded_cart( self::owner( $identity ), $cart, $packages[0]['destination'], 'GHS', 2, $identity ); }
	public static function context(): QuoteContext { $facts = LegacyQuoteProviderFixtures::context()->private_facts(); $facts['destination']['key_epoch'] = self::owner()->key_epoch(); return QuoteContext::from_array( $facts ); }
	public static function service( QuoteDurableFixtureFactory $factory, CartQuoteFixtureEnvironment $environment, CartQuoteFixtureSessions $sessions, ?QuoteFixturePublication $publication = null ): CartQuoteService {
		$control = new QuoteFixtureControl( $factory ); $gate = new QuotePreparationGate( $factory, [ $environment, 'authorize' ], static function(): void {}, $control );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		return new CartQuoteService( $environment, $gate, $sessions, $factory, $ready, null, $control, $publication ?? new QuoteFixturePublication() );
	}
}
final class CartQuoteFixtureEnvironment implements CartQuoteEnvironment {
	public ?QuoteCartDraft $current;
	public int $preparations = 0; public int $evidence_reads = 0; public bool $evidence_available = true; public bool $fail_preparation = false; public bool $read_authorized = true; public ?\Closure $before_prepare = null;
	public function __construct( public QuoteDurableFixtureFactory $factory ) { $this->current = CartQuoteFixtures::draft(); }
	public function draft(): ?QuoteCartDraft { return $this->current; }
	public function authorize( QuoteOwner $owner, string $operation ): bool { return $this->factory->authorized && $this->read_authorized && null !== $this->current && $this->current->owner()->equals( $owner ); }
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture {
		++$this->preparations; if ( null !== $this->before_prepare ) { ( $this->before_prepare )(); } if ( $this->fail_preparation ) { throw new \RuntimeException( 'PRIVATE-Q05-SOURCE-FAILURE' ); }
		$this->factory->pdo->exec( "UPDATE durable_fence SET context_digest='" . CartQuoteFixtures::context()->digest() . "'" );
		return new LegacyQuotePreparedCapture( $draft->owner(), CartQuoteFixtures::context(), LegacyQuoteProviderFixtures::terms(), new QuoteFixtureEvidence( $this->factory ) );
	}
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence { ++$this->evidence_reads; return $this->evidence_available ? new QuoteCartCurrentEvidence( $original->context(), new QuoteFixtureEvidence( $this->factory ) ) : null; }
}
final class CartQuoteFixtureSessions implements CartQuoteSessionStore {
	public ?CartQuoteSessionEnvelope $current = null; public int $writes = 0; public bool $lose_stage_ack = false; public bool $lose_accepting_ack = false; public bool $lose_publication_ack = false; public bool $refuse_publication = false;
	public function load( QuoteOwner $owner ): ?CartQuoteSessionEnvelope { return null !== $this->current && $this->current->owner()->equals( $owner ) ? CartQuoteSessionEnvelope::from_private_json( $this->current->to_private_json() ) : null; }
	public function compare_and_swap( QuoteOwner $owner, ?CartQuoteSessionEnvelope $expected, CartQuoteSessionEnvelope $replacement ): bool {
		if ( ! $replacement->owner()->equals( $owner ) || ! $replacement->follows( $expected ) || $this->current?->to_private_json() !== $expected?->to_private_json() ) { return false; }
		if ( $this->refuse_publication && in_array( $replacement->phase(), [ 'issued', 'confirmed' ], true ) ) { return false; }
		$this->current = CartQuoteSessionEnvelope::from_private_json( $replacement->to_private_json() ); ++$this->writes;
		if ( $this->lose_stage_ack && 'staged' === $replacement->phase() ) { $this->lose_stage_ack = false; return false; }
		if ( $this->lose_accepting_ack && 'accepting' === $replacement->phase() ) { $this->lose_accepting_ack = false; return false; }
		if ( $this->lose_publication_ack && in_array( $replacement->phase(), [ 'issued', 'confirmed' ], true ) ) { $this->lose_publication_ack = false; return false; } return true;
	}
	public function expires_at( QuoteOwner $owner ): int { return 1791352800; }
}
