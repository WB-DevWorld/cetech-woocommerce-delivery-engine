<?php
declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote {
	/** This seam observes when the real runtime requests evidence, without providing authority. */
	final class QuoteCartPlacementEvidenceReader {
		public function current( mixed $request ): ?QuotePlacementEvidence {
			++\Calls::$reads; \Calls::$sequence[] = 'evidence';
			\Calls::$loaded_at_read = [] !== \WC()->cart->contents && 'store-api' === \WC()->cart->cart_context;
			return null;
		}
	}
}
namespace Automattic\WooCommerce\StoreApi\Routes\V1 { final class Checkout { public function get_response(): void {} } }
namespace Automattic\WooCommerce\StoreApi\Utilities {
	/** Pinned native loader semantics; actual WP/Store qualification is separate. */
	final class CartController {
		public function load_cart(): void {
			++\Calls::$loads; \Calls::$sequence[] = 'native_load';
			if ( 'loader_throw' === \Calls::$mode ) { throw new \RuntimeException( 'Native hydration unavailable.' ); }
			if ( ! \did_action( 'woocommerce_load_cart_from_session' ) ) { \wc_load_cart(); }
			\WC()->cart->cart_context = 'store-api'; \WC()->cart->get_cart();
		}
	}
}
namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader;
	use CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime;
	final class Calls { public static string $mode; public static int $loads = 0; public static int $reads = 0; public static bool $loaded_at_read = false; public static array $sequence = []; public static bool $loaded = false; }
	class WC_Order { public function get_id(): int { return 19; } public function get_customer_id( string $context ): int { return 9; } }
	class WC_Session_Handler { public array $data; public function get( string $key, mixed $default = null ): mixed { return $this->data[$key] ?? $default; } public function has_session(): bool { return true; } }
	class WP_REST_Request {
		public function get_method(): string { return 'get' === Calls::$mode ? 'GET' : 'POST'; }
		public function get_route(): string { return 'route' === Calls::$mode ? '/wc/store/v1/cart' : '/wc/store/v1/checkout'; }
		public function get_header( string $name ): string { return 'Nonce' === $name ? ( 'nonce' === Calls::$mode ? 'invalid' : 'native-valid' ) : ( 'token' === Calls::$mode ? 'foreign-cart-token' : '' ); }
	}
	class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
	function WC(): object { return $GLOBALS['native_wc']; }
	function get_current_user_id(): int { return 9; }
	function current_user_can( string $cap, int $id ): bool { Calls::$sequence[] = 'authorization'; return 'foreign' !== Calls::$mode && 'pay_for_order' === $cap && 19 === $id; }
	function wp_verify_nonce( string $nonce, string $action ): bool { Calls::$sequence[] = 'nonce'; return 'native-valid' === $nonce && 'wc_store_api' === $action; }
	function wc_get_order( int $id ): ?WC_Order { return 19 === $id ? new WC_Order() : null; }
	function wc_load_cart(): void { Calls::$sequence[] = 'wc_load_cart'; }
	function did_action( string $hook ): bool { return Calls::$loaded; }
	Calls::$mode = $argv[1] ?? 'matching';
	$session = new WC_Session_Handler(); $session->data = [ QuotePlacementRuntime::SESSION_CONTINUATION => [ 'format' => 1, 'order_id' => 19, 'placement_id' => '11111111-1111-4111-8111-111111111111', 'quote_id' => '22222222-2222-4222-8222-222222222222', 'route' => 'classic' ], 'store_api_draft_order' => 0 ];
	if ( 'no_original' === Calls::$mode ) { unset( $session->data[QuotePlacementRuntime::SESSION_CONTINUATION] ); }
	$cart = new class { public array $contents = []; public string $cart_context = 'shortcode'; public function get_cart(): array { Calls::$sequence[] = 'hydrate'; Calls::$loaded = true; return $this->contents = [ 'native-session-line' => [ 'quantity' => 1 ] ]; } };
	$GLOBALS['native_wc'] = (object) [ 'session' => $session, 'cart' => $cart ];
	$runtime = ( new \ReflectionClass( QuotePlacementRuntime::class ) )->newInstanceWithoutConstructor();
	( new \ReflectionProperty( $runtime, 'cart' ) )->setValue( $runtime, new QuoteCartPlacementEvidenceReader() );
	$before = $session->data;
	$result = $runtime->recover_store_post( null, new WP_REST_Request(), '/wc/store/v1/checkout', [ 'callback' => [ new \Automattic\WooCommerce\StoreApi\Routes\V1\Checkout(), 'get_response' ] ] );
	echo json_encode( [ 'loads' => Calls::$loads, 'reads' => Calls::$reads, 'loaded_at_read' => Calls::$loaded_at_read, 'sequence' => Calls::$sequence, 'unavailable' => $result instanceof WP_Error && 'cetech_de_quote_placement_unavailable' === $result->code, 'passthrough' => null === $result, 'pointer_unchanged' => $session->data === $before ], JSON_THROW_ON_ERROR );
}
