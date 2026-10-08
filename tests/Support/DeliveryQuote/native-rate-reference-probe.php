<?php
declare(strict_types=1);
// Isolated native-shape metadata protocol; actual Woo proof runs separately.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/bootstrap.php';
require __DIR__ . '/CartQuoteFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteSessionStore,CartQuoteSessionEnvelope,NativeCartQuotePreparation,QuoteCartDraft};
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteOwner,QuoteTerms,QuoteTime};
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtures,CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,QuoteDurableFixtureFactory,LegacyQuoteProviderFixtures};

class WC_Shipping_Method {}
class WC_Shipping_Rate {
	protected array $data; protected array $meta_data; public static int $getters = 0;
	public function __construct( string $group ) { $this->data = [ 'id' => 'delivery_engine_selected_offer:1:' . substr( hash( 'sha256', $group ), 0, 12 ), 'method_id' => 'delivery_engine_selected_offer', 'instance_id' => 1, 'label' => 'Same visible label', 'cost' => '7.50', 'taxes' => [ 1 => '0.75' ], 'tax_status' => 'taxable' ]; $this->meta_data = [ 'cetech_de_group_id' => $group, 'foreign_note' => 'preserve', QuoteRateReferenceRuntime::META_PREFIX . 'private_body' => 'PRIVATE-CALLER-INJECTION' ]; }
	public function get_id(): never { ++self::$getters; throw new LogicException( 'Filtered getter invoked.' ); }
	public function get_cost(): never { ++self::$getters; throw new LogicException( 'Filtered getter invoked.' ); }
	public function packet(): array { return $this->data; }
	public function meta(): array { return $this->meta_data; }
	public function change_group(): void { $this->meta_data['cetech_de_group_id'] = 'wrong-group'; }
}
class ForeignRate extends WC_Shipping_Rate {}
class WC_Cart {}
class WC_Shipping { protected static ?self $_instance = null; public function __construct( public array $packages ) { self::$_instance = $this; } }
class WC_Cart_Session { public function __construct( protected WC_Cart $cart ) {} public function set_session(): never { throw new LogicException( 'Hook inspection invoked session callback.' ); } }
class WP_Hook { public array $callbacks = []; }

$mode = $argv[1] ?? 'issued'; $factory = new QuoteDurableFixtureFactory(); $factory->utc = QuoteTime::now()->sql();
$environment = new CartQuoteFixtureEnvironment( $factory ); $backing = new CartQuoteFixtureSessions();
$sessions = new class( $backing ) implements CartQuoteSessionStore {
	public function __construct( private CartQuoteFixtureSessions $store ) {}
	public function load( QuoteOwner $owner ): ?CartQuoteSessionEnvelope { return $this->store->load( $owner ); }
	public function compare_and_swap( QuoteOwner $owner, ?CartQuoteSessionEnvelope $expected, CartQuoteSessionEnvelope $replacement ): bool { return $this->store->compare_and_swap( $owner, $expected, $replacement ); }
	public function expires_at( QuoteOwner $owner ): int { return time() + 3600; }
};
[$cart,$packages] = CartQuoteFixtures::raw(); $package = $packages[0]; $group = DeliveryGroupIdentity::fromCartItem( $cart['line_one'] ); $package['cetech_de']['group_id'] = $group; $component = NativeCartQuotePreparation::component_key( $group );
$facts = CartQuoteFixtures::context()->private_facts(); $facts['lines'][0]['component_key'] = $component; $facts['groups'][0]['component_key'] = $component; $environment->prepared_context = QuoteContext::from_array( $facts );
$facts = LegacyQuoteProviderFixtures::terms()->private_facts(); $facts['groups'][0]['component_key'] = $component; $environment->prepared_terms = QuoteTerms::from_array( $facts );
$second_package = null;
if ( 'two_components' === $mode ) {
	$second = $cart['line_one']; $second['cetech_de_delivery_selection']['delivery_offer_id'] = 21; $second['cetech_de_delivery_selection']['display_key'] = 'in_warehouse:delivery:21'; $second['cetech_de_customer_context']['delivery_offer_id'] = 21;
	$second_group = DeliveryGroupIdentity::fromCartItem( $second ); $second_component = NativeCartQuotePreparation::component_key( $second_group ); $second_package = [ 'contents' => [ 'line_two' => $second ], 'destination' => $package['destination'], 'cetech_de' => [ 'managed' => true, 'group_id' => $second_group ] ];
	$cart['line_two'] = $second; $environment->current = QuoteCartDraft::from_loaded_cart( CartQuoteFixtures::owner(), $cart, $package['destination'], 'GHS', 2, CartQuoteFixtures::identity() );
	$facts = $environment->prepared_context->private_facts(); $facts['lines'][] = array_replace( $facts['lines'][0], [ 'line_key' => 'line_two', 'component_key' => $second_component ] ); $facts['groups'][] = array_replace( $facts['groups'][0], [ 'component_key' => $second_component, 'line_keys' => [ 'line_two' ], 'offer_id' => 21, 'service_id' => 21 ] ); $environment->prepared_context = QuoteContext::from_array( $facts );
	$facts = $environment->prepared_terms->private_facts(); $facts['groups'][] = array_replace( $facts['groups'][0], [ 'component_key' => $second_component ] ); $environment->prepared_terms = QuoteTerms::from_array( $facts );
}
$control = new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtureControl( $factory );
$ready = new class implements \CetechDeliveryEngine\Application\Operation\OperationReadiness { public function assert_ready( \CetechDeliveryEngine\Domain\Operation\OperationSession $session ): void {} };
$gate = new \CetechDeliveryEngine\Application\DeliveryQuote\QuotePreparationGate( $factory, [ $environment, 'authorize' ], static function(): void {}, $control );
$service = new \CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteService( $environment, $gate, $sessions, $factory, $ready, null, $control, new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixturePublication() );
$result = $service->refresh( '12345678-1234-4abc-8abc-123456789abc', 0, RequestContext::create() )->shopper_facts();
if ( 'review_required' !== $result['status'] ) { throw new RuntimeException( 'Fixture failed to issue.' ); }
if ( 'confirmed' === $mode ) { $result = $service->confirm( 1, RequestContext::create() )->shopper_facts(); if ( 'confirmed' !== $result['status'] ) { throw new RuntimeException( 'Fixture failed to confirm.' ); } }
$rate = 'foreign' === $mode ? new ForeignRate( $group ) : new WC_Shipping_Rate( $group ); $packet = $rate->packet(); $id = $packet['id'];
switch ( $mode ) {
	case 'unauthorized': $environment->read_authorized = false; break;
	case 'changed_draft': $environment->current = CartQuoteFixtures::draft( 3 ); break;
	case 'quantity': $package['contents']['line_one']['quantity'] = 3; break;
	case 'same_sku': $package['contents']['other_line'] = $package['contents']['line_one']; unset( $package['contents']['line_one'] ); break;
	case 'destination': $package['destination']['city'] = 'Different'; break;
	case 'different_group': $package['cetech_de']['group_id'] = 'in_warehouse|delivery|20|another'; break;
	case 'rate_group': $rate->change_group(); break;
	case 'staged': $backing->current = CartQuoteSessionEnvelope::from_private_array( array_replace( json_decode( $backing->current->to_private_json(), true ), [ 'phase' => 'staged' ] ) ); break;
	case 'expired': $backing->current = CartQuoteSessionEnvelope::from_private_array( array_replace( json_decode( $backing->current->to_private_json(), true ), [ 'expires_at' => time() - 1 ] ) ); break;
}
$runtime = new QuoteRateReferenceRuntime( $environment, $sessions, 'off' !== $mode, 'gate_off' === $mode ? static fn(): bool => false : null );
$before = $backing->current->to_private_json(); $writes = $backing->writes; $preparations = $environment->preparations; $evidence = $environment->evidence_reads; $operations = $factory->count( 'operation_records' );
$hooks_supported = null;
if ( str_starts_with( $mode, 'hook_' ) ) {
	$native_cart = new WC_Cart(); $GLOBALS['woocommerce'] = new class( $native_cart ) { public function __construct( public WC_Cart $cart ) {} }; $session_cart = 'hook_detached_cart_session' === $mode ? new WC_Cart() : $native_cart;
	$hook = new WP_Hook(); $hook->callbacks = [ ( 'hook_wrong_priority' === $mode ? 901 : 900 ) => [ [ 'function' => [ 'hook_static' === $mode ? QuoteRateReferenceRuntime::class : $runtime, 'annotate_loaded_rates' ], 'accepted_args' => 'hook_wrong_args' === $mode ? 2 : 1 ] ], 1000 => [ [ 'function' => [ new WC_Cart_Session( $session_cart ), 'set_session' ], 'accepted_args' => 1 ] ] ]; $GLOBALS['wp_filter'] = [ 'woocommerce_after_calculate_totals' => $hook ];
	$hooks_supported = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource::supports_current_hooks();
}
if ( 'cache_hit' === $mode ) { $native_cart = new WC_Cart(); $GLOBALS['woocommerce'] = new class( $native_cart ) { public function __construct( public WC_Cart $cart ) {} }; $shipping = new WC_Shipping( [ array_replace( $package, [ 'rates' => [ $id => $rate ] ] ) ] ); $runtime->annotate_loaded_rates( $native_cart ); $rates = $shipping->packages[0]['rates']; }
else { $rates = $runtime->filter_rates( [ $id => $rate ], $package ); }
$meta = $rate->meta(); $public = []; foreach ( $meta as $key => $value ) { if ( str_starts_with( $key, QuoteRateReferenceRuntime::META_PREFIX ) ) { $public[$key] = $value; } }
$expected = null; foreach ( $backing->current->rate_references() as $reference ) { if ( $reference->component_key() === $component ) { $expected = $reference->public_fields(); } }
$second_metadata = null; $second_packet_unchanged = true;
if ( null !== $second_package ) { $second_rate = new WC_Shipping_Rate( $second_group ); $second_packet = $second_rate->packet(); $second_id = $second_packet['id']; $runtime->filter_rates( [ $second_id => $second_rate ], $second_package ); $second_metadata = array_filter( $second_rate->meta(), static fn( string $key ): bool => str_starts_with( $key, QuoteRateReferenceRuntime::META_PREFIX ), ARRAY_FILTER_USE_KEY ); $second_packet_unchanged = $second_packet === $second_rate->packet(); }
echo json_encode( [ 'rates_count' => count( $rates ), 'same_object' => ( $rates[$id] ?? null ) === $rate, 'same_packet' => $rate->packet() === $packet && $second_packet_unchanged, 'metadata' => $public, 'second_metadata' => $second_metadata, 'hooks_supported' => $hooks_supported, 'expected' => $expected, 'foreign_note' => $meta['foreign_note'], 'getters' => WC_Shipping_Rate::$getters, 'session_unchanged' => $before === $backing->current->to_private_json() && $writes === $backing->writes, 'no_prepare' => $preparations === $environment->preparations, 'no_evidence' => $evidence === $environment->evidence_reads, 'no_operation' => $operations === $factory->count( 'operation_records' ) ], JSON_THROW_ON_ERROR );
