<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope,QuoteNativeOrderFacts,QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime;
use PHPUnit\Framework\TestCase;

final class NativePlacementRuntimeTest extends TestCase {
	private array $actions;
	private array $filters;
	private mixed $user;
	private array $caps;
	private array $query;
	private array $post;
	protected function setUp(): void { $this->actions = $GLOBALS['cetech_de_test_actions'] ?? []; $this->filters = $GLOBALS['cetech_de_test_filters'] ?? []; $this->user = $GLOBALS['cetech_de_test_user_id'] ?? null; $this->caps = $GLOBALS['cetech_de_test_caps'] ?? []; $this->query = $_GET; $this->post = $_POST; $GLOBALS['blog_id'] = 1; }
	protected function tearDown(): void { $GLOBALS['cetech_de_test_actions'] = $this->actions; $GLOBALS['cetech_de_test_filters'] = $this->filters; $GLOBALS['cetech_de_test_caps'] = $this->caps; if ( null === $this->user ) { unset( $GLOBALS['cetech_de_test_user_id'] ); } else { $GLOBALS['cetech_de_test_user_id'] = $this->user; } $_GET = $this->query; $_POST = $this->post; unset( $GLOBALS['blog_id'] ); }
	private function runtime( bool $active, bool $managed, ?CheckoutQuoteFixture $legacy = null ): QuotePlacementRuntime {
		$factory = new class implements OperationConnectionFactory { public function open(): OperationSession { throw new \LogicException( 'Unexpected private database lookup.' ); } };
		$cart = ( new \ReflectionClass( QuoteCartPlacementEvidenceReader::class ) )->newInstanceWithoutConstructor();
		$stager = ( new \ReflectionClass( QuoteNativeOrderStager::class ) )->newInstanceWithoutConstructor();
		return new QuotePlacementRuntime( $factory, $cart, $stager, $legacy ?? new CheckoutQuoteFixture(), static fn(): bool => $active, static fn(): bool => $managed );
	}
	public function test_exact_native_staging_precedes_final_c07_and_legacy_writer(): void {
		$runtime = $this->runtime( false, true ); $runtime->register(); $runtime->register();
		$expect = [ 'woocommerce_checkout_create_order_line_item' => [ 'bind_native_line', -100, 4 ], 'woocommerce_checkout_order_created' => [ 'stage_classic', PHP_INT_MAX - 1, 1 ], 'woocommerce_store_api_checkout_update_order_from_request' => [ 'remember_store_post', PHP_INT_MAX - 1, 2 ], 'woocommerce_store_api_checkout_order_processed' => [ 'stage_store_post', 9, 1 ], 'woocommerce_resume_order' => [ 'resume_classic', -100, 1 ] ];
		foreach ( $expect as $hook => [ $method, $priority, $args ] ) {
			$rows = array_values( array_filter( $GLOBALS['cetech_de_test_actions'][$hook] ?? [], static fn( array $row ): bool => $row['callback'] === [ $runtime, $method ] ) );
			self::assertCount( 1, $rows ); self::assertSame( $priority, $rows[0]['priority'] ); self::assertSame( $args, $rows[0]['args'] );
		}
	}
	public function test_original_cart_key_disambiguates_native_identical_product_lines(): void {
		$runtime = $this->runtime( true, true ); $order = new \WC_Order( [ 'id' => 19 ] );
		$one = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ); $two = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] );
		$runtime->bind_native_line( $one, 'first-original-key', [], $order ); $runtime->bind_native_line( $two, 'second-original-key', [], $order );
		self::assertSame( 'first-original-key', $one->get_meta( QuoteNativeOrderFacts::META_LINE_KEY, true ) ); self::assertSame( 'second-original-key', $two->get_meta( QuoteNativeOrderFacts::META_LINE_KEY, true ) ); self::assertFalse( $one->meta_exists( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) );
	}
	public function test_changed_original_cart_key_is_refused_before_saved_packet(): void {
		$runtime = $this->runtime( true, true ); $item = new \WC_Order_Item_Product(); $item->update_meta_data( QuoteNativeOrderFacts::META_LINE_KEY, 'original' );
		$this->expectException( \Exception::class ); $runtime->bind_native_line( $item, 'changed', [], new \WC_Order( [ 'id' => 19 ] ) );
	}
	public function test_adoption_off_preserves_legacy_validation_and_writer(): void {
		$legacy = new CheckoutQuoteFixture(); $runtime = $this->runtime( false, true, $legacy ); $order = new \WC_Order( [ 'id' => 19 ] );
		self::assertFalse( $runtime->owns_order( $order ) ); self::assertTrue( $runtime->validate_order( $order, 'classic' ) ); self::assertSame( 1, $legacy->calls );
		$binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertTrue( $runtime->complete( $order, 'classic', 1, $binding ) );
	}
	public function test_managed_adopted_order_without_staging_cannot_enter_payment(): void {
		$legacy = new CheckoutQuoteFixture(); $runtime = $this->runtime( true, true, $legacy ); $order = new \WC_Order( [ 'id' => 19 ] );
		self::assertTrue( $runtime->owns_order( $order ) ); self::assertFalse( $runtime->validate_order( $order, 'store_api' ) ); self::assertSame( 0, $legacy->calls );
		$binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertFalse( $runtime->complete( $order, 'store_api', 1, $binding ) );
	}
	public function test_mandatory_malformed_marker_is_never_legacy_after_rollback(): void {
		$legacy = new CheckoutQuoteFixture(); $runtime = $this->runtime( false, false, $legacy ); $order = new \WC_Order( [ 'id' => 19 ] ); $order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, null );
		self::assertTrue( $runtime->owns_order( $order ) ); self::assertFalse( $runtime->validate_order( $order, 'order_pay' ) ); self::assertSame( 0, $legacy->calls );
		$binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertFalse( $runtime->complete( $order, 'order_pay', 1, $binding ) ); self::assertFalse( $runtime->admitted( $order, 'order_pay', 1, $binding ) );
	}
	public function test_logged_in_guest_order_requires_exact_key_and_native_payment_capability(): void {
		$runtime = $this->runtime( false, false ); $GLOBALS['cetech_de_test_user_id'] = 9; $GLOBALS['cetech_de_test_caps'] = [ 'pay_for_order' => true ]; $_GET = [ 'key' => 'retained-native-order-key' ]; $_POST = [];
		$order = new PlacementPayOrderFixture( 0 ); self::assertTrue( $runtime->authorize_saved_order( $order ) );
		$_GET['key'] = 'guessed-order-key'; self::assertFalse( $runtime->authorize_saved_order( $order ) );
		$_GET['key'] = 'retained-native-order-key'; $GLOBALS['cetech_de_test_caps']['pay_for_order'] = false; self::assertFalse( $runtime->authorize_saved_order( $order ) );
	}
	public function test_foreign_customer_order_is_denied_even_with_key_and_broad_capability(): void {
		$runtime = $this->runtime( false, false ); $GLOBALS['cetech_de_test_user_id'] = 9; $GLOBALS['cetech_de_test_caps'] = [ 'pay_for_order' => true ]; $_GET = [ 'key' => 'retained-native-order-key' ]; $_POST = [];
		self::assertFalse( $runtime->authorize_saved_order( new PlacementPayOrderFixture( 17 ) ) );
	}
	public function test_current_customer_order_requires_native_exact_key_before_private_lookup(): void {
		$runtime = $this->runtime( false, false ); $GLOBALS['cetech_de_test_user_id'] = 9; $GLOBALS['cetech_de_test_caps'] = [ 'pay_for_order' => true ]; $_GET = []; $_POST = [];
		$order = new PlacementPayOrderFixture( 9 ); self::assertFalse( $runtime->authorize_saved_order( $order ) );
		$_GET['key'] = 'retained-native-order-key'; self::assertTrue( $runtime->authorize_saved_order( $order ) );
	}
}

final class PlacementPayOrderFixture extends \WC_Order {
	public function __construct( private int $customer ) { parent::__construct( [ 'id' => 19 ] ); }
	public function get_customer_id( string $context = 'view' ): int { return $this->customer; }
	public function get_order_key( string $context = 'view' ): string { return 'retained-native-order-key'; }
}
