<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/CartQuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteStorageFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCartDraft,QuoteCartPlacementEvidenceReader,QuoteDurableService,QuoteNativeWooSource,QuotePlacementService,QuoteProviderRegistry,QuoteSavedOrderAuthorization,QuoteSavedOrderPlacementEvidenceReader,QuoteSavedOrderNativeEvidence};
use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderFacts,QuoteNativeOrderStager};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,CartQuoteFixtures,QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixturePublication,QuoteFixtures,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

final class QuoteSavedOrderAuthorizationTest extends TestCase {
	private mixed $user; private array $get; private array $post; private mixed $filters;
	protected function setUp(): void { $this->user = $GLOBALS['current_user'] ?? null; $this->get = $_GET; $this->post = $_POST; $this->filters = $GLOBALS['wp_filter'] ?? null; $GLOBALS['current_user'] = (object) [ 'ID' => 0 ]; $_GET = [ 'key' => 'wc_order_guest_original' ]; $_POST = []; }
	protected function tearDown(): void { $GLOBALS['current_user'] = $this->user; $_GET = $this->get; $_POST = $this->post; $GLOBALS['wp_filter'] = $this->filters; }
	public function test_saved_private_draft_restores_exact_owner_body_and_digest_without_cart_or_session(): void {
		$draft = CartQuoteFixtures::draft(); $restored = QuoteCartDraft::from_private_facts( $draft->owner(), $draft->private_facts(), CartQuoteFixtures::identity() );
		self::assertSame( $draft->private_facts(), $restored->private_facts() ); self::assertSame( $draft->draft_digest(), $restored->draft_digest() );
		$facts = $draft->private_facts(); $facts['owner_digest'] = hash( 'sha256', 'foreign-owner' ); $this->expectException( \InvalidArgumentException::class ); QuoteCartDraft::from_private_facts( $draft->owner(), $facts, CartQuoteFixtures::identity() );
	}
	public function test_native_exact_order_auth_receipt_detects_key_user_site_and_order_changes_without_second_callback(): void {
		$order = new QuotePrivateAuthorizationOrder(); $calls = 0; $receipt = QuoteSavedOrderAuthorization::capture( $order, function() use ( &$calls ): bool { ++$calls; return true; } ); self::assertSame( 2, $calls ); self::assertTrue( $receipt->unchanged() );
		$_GET['key'] = 'wc_order_other'; self::assertFalse( $receipt->unchanged() ); $_GET['key'] = 'wc_order_guest_original'; self::assertTrue( $receipt->unchanged() );
		$GLOBALS['current_user'] = (object) [ 'ID' => 0 ]; self::assertFalse( $receipt->unchanged() ); self::assertSame( 2, $calls );
	}
	public function test_refused_native_authorization_performs_no_private_read_or_db_open(): void {
		$factory = new class implements OperationConnectionFactory { public int $opens = 0; public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'Private lookup not authorized.' ); } };
		$reader = new QuoteSavedOrderPlacementEvidenceReader( $factory, new QuoteNativeOrderStager( $factory ), static fn(): bool => false );
		self::assertNull( $reader->read( new QuotePrivateAuthorizationOrder(), RequestContext::create() ) ); self::assertSame( 0, $factory->opens );
	}
	public function test_revocation_during_native_authorization_capture_refuses_before_evidence(): void {
		$calls = 0; $this->expectException( \RuntimeException::class ); QuoteSavedOrderAuthorization::capture( new QuotePrivateAuthorizationOrder(), static function() use ( &$calls ): bool { return ++$calls < 2; } );
	}
	public function test_saved_money_comparison_requires_exact_decimal_but_tolerates_native_zero_padding(): void {
		$compare = new \ReflectionMethod( QuoteSavedOrderNativeEvidence::class, 'money_equal' ); $money = [ 'amount' => '12.50', 'currency' => 'GHS', 'precision' => 2 ];
		self::assertTrue( $compare->invoke( null, '12.500000', $money ) ); self::assertTrue( $compare->invoke( null, '12.5', $money ) ); self::assertFalse( $compare->invoke( null, '12.500001', $money ) ); self::assertFalse( $compare->invoke( null, 12.5, $money ) ); self::assertFalse( $compare->invoke( null, '1.25e1', $money ) );
	}
	public function test_post_key_capability_and_original_loaded_order_key_changes_revoke_pure_receipt(): void {
		$_GET = []; $_POST['key'] = 'wc_order_guest_original'; $GLOBALS['current_user']->allcaps = [ 'pay_for_order' => true ]; $order = new QuotePrivateAuthorizationOrder(); $receipt = QuoteSavedOrderAuthorization::capture( $order, static fn(): bool => true ); self::assertTrue( $receipt->unchanged() ); $_POST['key'] = 'wc_order_foreign'; self::assertFalse( $receipt->unchanged() ); $_POST['key'] = 'wc_order_guest_original'; $GLOBALS['current_user']->allcaps['pay_for_order'] = false; self::assertFalse( $receipt->unchanged() ); $GLOBALS['current_user']->allcaps['pay_for_order'] = true; self::assertTrue( $receipt->unchanged() ); $order->change_native_key( 'wc_order_rotated' ); self::assertFalse( $receipt->unchanged() );
	}
	public function test_saved_authorization_cannot_be_disclosed_by_generic_serialization(): void {
		$receipt = QuoteSavedOrderAuthorization::capture( new QuotePrivateAuthorizationOrder(), static fn(): bool => true ); foreach ( [ static fn() => json_encode( $receipt, JSON_THROW_ON_ERROR ), static fn() => serialize( $receipt ) ] as $export ) { try { $export(); self::fail( 'Private authorization was disclosed.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
	}
	public function test_pure_hook_absence_rejects_unknown_registrations_without_executing_them(): void {
		$GLOBALS['wp_filter'] = []; self::assertTrue( QuoteNativeWooSource::hooks_absent( [ 'saved-native-hook' ] ) ); $calls = 0; $GLOBALS['wp_filter']['saved-native-hook'] = (object) [ 'callbacks' => [ 10 => [ [ 'function' => static function() use ( &$calls ): void { ++$calls; }, 'accepted_args' => 1 ] ] ] ]; self::assertFalse( QuoteNativeWooSource::hooks_absent( [ 'saved-native-hook' ] ) ); self::assertSame( 0, $calls ); $GLOBALS['wp_filter'] = 'corrupt'; self::assertFalse( QuoteNativeWooSource::hooks_absent( [ 'saved-native-hook' ] ) );
	}
	public function test_authorized_historical_binding_read_survives_expiry_without_claiming_new_admission(): void {
		$f = new QuoteDurableFixtureFactory(); $env = new CartQuoteFixtureEnvironment( $f ); $sessions = new CartQuoteFixtureSessions(); $cart = CartQuoteFixtures::service( $f, $env, $sessions ); $cart->refresh( '12345678-1234-4abc-8abc-123456789abc', 0, RequestContext::create() ); $cart->confirm( 1, RequestContext::create() ); $ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} }; $control = new QuoteFixtureControl( $f ); $publication = new QuoteFixturePublication(); $evidence = ( new QuoteCartPlacementEvidenceReader( $env, $sessions, $f, $ready, $control, $publication ) )->current( RequestContext::create() ); self::assertNotNull( $evidence );
		$durable = new QuoteDurableService( $f, new QuoteProviderRegistry(), [ $env, 'authorize' ], readiness: $ready, publication: $publication, control: $control, evidence: $evidence->guard() ); $service = new QuotePlacementService( $durable, $evidence ); $prepared = $service->prepare( 701, QuoteStorageFixtures::binding( $evidence->quote_record() )->mapping(), RequestContext::create() ); $binding = $prepared->binding; self::assertNotNull( $binding );
		$order = new QuotePrivateAuthorizationOrder(); $order->update_meta_data( QuoteNativeOrderFacts::META_REFERENCE, QuoteJson::encode( $evidence->reference()->public_fields() ) ); $reader = new QuoteSavedOrderPlacementEvidenceReader( $f, new QuoteNativeOrderStager( $f ), static fn(): bool => true, $ready ); $f->utc = '2026-10-07 06:00:00.000000'; $found = $reader->read_binding( $order ); self::assertNotNull( $found ); self::assertSame( 'prepared', $found->state() ); self::assertSame( 1, $found->revision() ); self::assertSame( $binding->row(), $found->row() ); self::assertNull( $reader->read( $order, RequestContext::create() ) ); self::assertFalse( $f->pdo->inTransaction() );
		$order->update_meta_data( QuoteNativeOrderFacts::META_REFERENCE, QuoteJson::encode( [ 'quote_id' => $evidence->header()->id()->value(), 'acceptance_handle' => hash( 'sha256', 'foreign-private-reference' ) ] ) ); self::assertNull( $reader->read_binding( $order ) ); $order->update_meta_data( QuoteNativeOrderFacts::META_REFERENCE, QuoteJson::encode( $evidence->reference()->public_fields() ) ); $f->pdo->exec( "DELETE FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.bind'" ); self::assertNull( $reader->read_binding( $order ) );
	}}

final class QuotePrivateAuthorizationOrder extends \WC_Order {
	public int $id = 701;
	public array $changes = [];
	public function __construct() { parent::__construct( [ 'id' => 701, 'customer_id' => 0, 'order_key' => 'wc_order_guest_original' ] ); }
	public function change_native_key( string $key ): void { $property = new \ReflectionProperty( \WC_Order::class, 'data' ); $data = $property->getValue( $this ); $data['order_key'] = $key; $property->setValue( $this, $data ); }
}
