<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Shipment;

use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot};
use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseService;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Integrations\ServicePromise\Shipment\NativeShipmentPromiseRuntime;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Unit\Order\{DeliveryQuoteSnapshotFixtures, PromiseSnapshotFixtures};
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;

/** Tests host grants and lazy composition, not physical SQL or native WordPress dispatch. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NativeShipmentPromiseRuntimeTest extends TestCase {
	private OperationConnectionFactory $connections;
	private ShipmentRepositoryInterface $shipments;
	private array $persisted_orders = [];

	protected function setUp(): void {
		require_once dirname( __DIR__, 3 ) . '/Support/ServicePromise/Shipment/native-shipment-runtime-stubs.php';
		$GLOBALS['cetech_de_test_wc_orders'] = []; $GLOBALS['cetech_de_test_user_id'] = 0; $GLOBALS['cetech_de_test_is_admin'] = false; $GLOBALS['cetech_de_test_caps'] = [];
		$GLOBALS['cetech_de_test_is_order_received'] = false; $GLOBALS['cetech_de_runtime_active_hooks'] = []; $GLOBALS['cetech_de_runtime_query'] = []; $GLOBALS['blog_id'] = 7; $_GET = [];
		AdminPageAccess::bind( null );
		$this->connections = new class implements OperationConnectionFactory {
			public int $opens = 0;
			public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'Unexpected physical SQL in host-composition test.' ); }
		};
		$this->shipments = $this->createMock( ShipmentRepositoryInterface::class );
		foreach ( ( new \ReflectionClass( ShipmentRepositoryInterface::class ) )->getMethods() as $method ) { $this->shipments->expects( self::never() )->method( $method->getName() ); }
	}

	protected function tearDown(): void { self::assertSame( 0, $this->connections->opens ); }

	private function order( int $id = 91, int $customer = 20, string $key = 'wc_order_saved_key', string $state = 'absolute_window', array $native = [] ): \WC_Order {
		$data = $native + [ 'id' => $id, 'status' => 'processing', 'date_paid' => new \DateTimeImmutable( '2026-10-09T12:00:00Z' ), 'meta' => [
			OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => json_encode( PromiseSnapshotFixtures::package( PromiseSnapshotFixtures::envelope( $state ) ), JSON_THROW_ON_ERROR ),
			OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '3', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '2',
		] ];
		return new class( $data, $customer, $key ) extends \WC_Order {
			public function __construct( array $data, private int $customer, private string $key ) { parent::__construct( $data ); }
			public function get_customer_id(): int { return $this->customer; }
			public function get_order_key(): string { return $this->key; }
		};
	}

	private function runtime( ?callable $prediction = null ): NativeShipmentPromiseRuntime { return new NativeShipmentPromiseRuntime( $this->connections, $this->shipments, $prediction, fn( int $id ): ?\WC_Order => $this->persisted_orders[$id] ?? null ); }
	private function persist( \WC_Order $order ): void { $this->persisted_orders[$order->get_id()] = clone $order; $GLOBALS['cetech_de_test_wc_orders'][$order->get_id()] = $order; }
	private function authority( ShipmentPromiseService $service ): \Closure { return ( new \ReflectionProperty( $service, 'authority' ) )->getValue( $service ); }
	private function service( NativeShipmentPromiseRuntime $runtime, \WC_Order $order ): ShipmentPromiseService { $service = $runtime->service_for_order( $order ); self::assertInstanceOf( ShipmentPromiseService::class, $service ); return $service; }
	private function enter( NativeShipmentPromiseRuntime $runtime, string $hook, int|\WC_Order $id ): void { $GLOBALS['cetech_de_runtime_hook'] = $hook; $GLOBALS['cetech_de_runtime_active_hooks'][$hook] = true; $runtime->enter_event( $id ); }

	public function test_saved_packet_resolves_its_recorded_site_when_adoption_is_off_without_reading_sql_or_rewriting_history(): void {
		$order = $this->order(); $this->persist( $order ); $GLOBALS['cetech_de_test_user_id'] = 20;
		$GLOBALS['cetech_de_test_options'] = [ PromiseQuotePlacementActivation::OPTION => QuoteJson::encode( [ 'format' => 1, 'profile' => PromiseQuotePlacementActivation::PROFILE, 'enabled' => false, 'revision' => 42, 'binding' => PromiseSiteBinding::bind( 7, 'changed-current-site-key' )->private_facts(), 'registry' => [ 'format' => 1, 'entries' => [] ] ], PromiseQuotePlacementActivation::MAX_BYTES ), 'cetech_de_schema_version' => 999, 'timezone_string' => 'Pacific/Auckland' ];
		self::assertFalse( PromiseQuotePlacementActivation::decode( $GLOBALS['cetech_de_test_options'][PromiseQuotePlacementActivation::OPTION] )['enabled'] );
		$raw = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ); $service = $this->service( $this->runtime(), $order );
		$binding = ( new \ReflectionProperty( $service, 'binding' ) )->getValue( $service ); self::assertInstanceOf( PromiseSiteBinding::class, $binding );
		self::assertSame( 7, $binding->native_site_id() ); self::assertSame( 'site-1', $binding->site_key() ); self::assertTrue( ( $this->authority( $service ) )( $order, 'read', null ) );
		self::assertSame( $raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) );
	}

	public function test_customer_grant_is_bound_to_exact_order_and_reloads_persisted_ownership(): void {
		$order = $this->order(); $this->persist( $order ); $GLOBALS['cetech_de_test_user_id'] = 20; $runtime = $this->runtime(); $grant = $this->authority( $this->service( $runtime, $order ) );
		self::assertTrue( $grant( $order, 'read', null ) ); self::assertFalse( $grant( $this->order( 92 ), 'read', null ) );
		$this->persist( $this->order( customer: 21 ) ); self::assertFalse( $grant( $order, 'read', null ) ); self::assertNull( $runtime->service_for_order( $order ) );
		$this->persist( $order ); $GLOBALS['cetech_de_test_user_id'] = 21; self::assertFalse( $grant( $order, 'read', null ) );
		$GLOBALS['cetech_de_test_user_id'] = 20; self::assertFalse( $grant( $order, 'update_current', 20 ) ); self::assertFalse( $grant( $order, 'create', null ) );
	}

	public function test_dirty_factory_cached_customer_object_cannot_replace_the_independent_persisted_owner(): void {
		$saved = $this->order( customer: 20 ); $this->persist( $saved ); $dirty = $this->order( customer: 7 ); $GLOBALS['cetech_de_test_wc_orders'][91] = $dirty; $GLOBALS['cetech_de_test_user_id'] = 7;
		self::assertSame( $dirty, wc_get_order( 91 ) ); $runtime = $this->runtime(); self::assertNull( $runtime->service_for_order( $dirty ) ); self::assertSame( 20, $this->persisted_orders[91]->get_customer_id() );
		$GLOBALS['cetech_de_test_user_id'] = 20; $grant = $this->authority( $this->service( $runtime, $saved ) ); self::assertTrue( $grant( $saved, 'read', null ) ); self::assertFalse( $grant( $dirty, 'read', null ) );
	}

	public function test_guest_grant_requires_current_native_order_endpoint_and_exact_persisted_key(): void {
		$order = $this->order( customer: 0 ); $this->persist( $order ); $runtime = $this->runtime(); $_GET['key'] = $order->get_order_key();
		self::assertNull( $runtime->service_for_order( $order ) ); $GLOBALS['cetech_de_test_is_order_received'] = true; $GLOBALS['cetech_de_runtime_query']['order-received'] = 91;
		$grant = $this->authority( $this->service( $runtime, $order ) ); self::assertTrue( $grant( $order, 'read', null ) );
		$GLOBALS['cetech_de_runtime_query']['order-received'] = 92; self::assertFalse( $grant( $order, 'read', null ) ); $GLOBALS['cetech_de_runtime_query']['order-received'] = 91;
		$_GET['key'] = 'foreign'; self::assertFalse( $grant( $order, 'read', null ) ); $_GET['key'] = $order->get_order_key();
		$this->persist( $this->order( customer: 0, key: 'wc_order_rotated_key' ) ); self::assertFalse( $grant( $order, 'read', null ) );
	}

	public function test_staff_revision_grant_requires_both_native_caps_current_actor_and_admin_surface(): void {
		$order = $this->order(); $this->persist( $order ); $GLOBALS['cetech_de_test_user_id'] = 7; $GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_caps'] = [ 'manage_shipments' => true, 'edit_shop_order' => true ];
		$grant = $this->authority( $this->service( $this->runtime(), $order ) ); self::assertTrue( $grant( $order, 'read', null ) ); self::assertTrue( $grant( $order, 'update_current', 7 ) ); self::assertTrue( $grant( $order, 'create', 7 ) );
		self::assertFalse( $grant( $order, 'update_current', 8 ) ); self::assertFalse( $grant( $order, 'payment_confirmed', null ) );
		$GLOBALS['cetech_de_test_caps']['edit_shop_order'] = false; self::assertFalse( $grant( $order, 'read', null ) ); self::assertFalse( $grant( $order, 'update_current', 7 ) );
		$GLOBALS['cetech_de_test_caps']['edit_shop_order'] = true; $GLOBALS['cetech_de_test_caps']['manage_shipments'] = false; self::assertFalse( $grant( $order, 'update_current', 7 ) );
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = true; $GLOBALS['cetech_de_test_is_admin'] = false; self::assertFalse( $grant( $order, 'update_current', 7 ) );
	}

	#[DataProvider( 'native_events' )]
	public function test_system_event_grant_requires_matching_live_hook_paid_evidence_and_closes_after_hook_exit( string $hook, bool $payment ): void {
		$order = $this->order(); $this->persist( $order ); $runtime = $this->runtime(); self::assertNull( $runtime->service_for_order( $order ) );
		$this->enter( $runtime, $hook, 92 ); self::assertNull( $runtime->service_for_order( $order ) );
		$this->enter( $runtime, $hook, $order ); $grant = $this->authority( $this->service( $runtime, $order ) ); self::assertTrue( $grant( $order, 'create', null ) ); self::assertSame( $payment, $grant( $order, 'payment_confirmed', null ) );
		self::assertFalse( $grant( $order, 'create', 7 ) ); self::assertFalse( $grant( $order, 'payment_confirmed', 7 ) );
		$GLOBALS['cetech_de_runtime_active_hooks'][$hook] = false; self::assertFalse( $grant( $order, 'read', null ) ); $GLOBALS['cetech_de_runtime_active_hooks'][$hook] = true;
		$this->persist( $this->order( native: [ 'status' => 'pending' ] ) ); self::assertFalse( $grant( $order, 'create', null ) );
		$this->persist( $this->order( native: [ 'date_paid' => null ] ) ); self::assertFalse( $grant( $order, 'create', null ) );
		$this->persist( $order ); $runtime->leave_event( $order ); self::assertFalse( $grant( $order, 'read', null ) ); self::assertNull( $runtime->service_for_order( $order ) );
	}
	public static function native_events(): iterable { yield 'payment-complete' => [ 'woocommerce_payment_complete', true ]; yield 'processing' => [ 'woocommerce_order_status_processing', false ]; yield 'completed' => [ 'woocommerce_order_status_completed', false ]; }

	public function test_unregistered_event_cannot_mint_system_authority(): void {
		$order = $this->order(); $this->persist( $order ); $runtime = $this->runtime(); $this->enter( $runtime, 'woocommerce_thankyou', 91 ); self::assertNull( $runtime->service_for_order( $order ) );
	}

	public function test_persisted_packet_change_revokes_old_object_grant_even_when_owner_and_key_match(): void {
		$order = $this->order(); $this->persist( $order ); $GLOBALS['cetech_de_test_user_id'] = 20; $runtime = $this->runtime(); $grant = $this->authority( $this->service( $runtime, $order ) );
		$this->persist( $this->order( state: 'relative_window' ) ); self::assertFalse( $grant( $order, 'read', null ) ); self::assertNull( $runtime->service_for_order( $order ) );
		$fresh = wc_get_order( 91 ); self::assertFalse( $grant( $fresh, 'read', null ) ); self::assertInstanceOf( ShipmentPromiseService::class, $runtime->service_for_order( $fresh ) );
	}

	public function test_legacy_missing_and_unknown_saved_history_never_resolve_promise_service_or_open_sql(): void {
		$GLOBALS['cetech_de_test_user_id'] = 20; $runtime = $this->runtime();
		$cases = [ [], [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"snapshot_version":"3","broken":', OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '3' ], [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => json_encode( DeliveryQuoteSnapshotFixtures::package(), JSON_THROW_ON_ERROR ) ] ];
		foreach ( $cases as $meta ) { $order = $this->order( native: [ 'meta' => $meta ] ); $this->persist( $order ); self::assertNull( $runtime->service_for_order( $order ) ); }
		$order = $this->order(); $order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, '999' ); $this->persist( $order ); self::assertNull( $runtime->service_for_order( $order ) ); self::assertSame( '999', $order->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) );
	}

	public function test_prediction_defaults_off_and_disabled_callback_does_not_even_resolve_order_identity(): void {
		$order = new class extends \WC_Order { public int $reads = 0; public function get_id(): int { ++$this->reads; throw new \LogicException( 'Disabled prediction must not resolve native order.' ); } };
		$this->runtime()->predict_paid_order( $order ); $checks = 0; $this->runtime( static function() use ( &$checks ): bool { ++$checks; return false; } )->predict_paid_order( $order ); self::assertSame( 1, $checks ); self::assertSame( 0, $order->reads );
	}
}
