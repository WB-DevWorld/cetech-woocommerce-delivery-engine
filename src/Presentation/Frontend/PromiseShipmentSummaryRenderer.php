<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseService;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseShape, PublicPromiseView};
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;

/** Customer order details keep the immutable original and a separate current estimate explicit. */
final class PromiseShipmentSummaryRenderer {
	private \Closure $enabled;
	private \Closure $resolve;
	public function __construct( private ShipmentRepositoryInterface $shipments, callable $resolver, ?callable $enabled = null ) { $this->resolve = \Closure::fromCallable( $resolver ); $this->enabled = null === $enabled ? static fn(): bool => false : \Closure::fromCallable( $enabled ); }
	public function register(): void { if ( true === ( $this->enabled )() ) { add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 20, 1 ); } }
	public function render( \WC_Order $order ): void {
		try {
			if ( true !== ( $this->enabled )() || ! self::authorized( $order ) ) { return; }
			$service = ( $this->resolve )( $order ); if ( ! $service instanceof ShipmentPromiseService || ! self::authorized( $order ) ) { return; }
			$rows = []; $shipments = $this->shipments->findByOrderId( $order->get_id() ); if ( count( $shipments ) > 200 ) { return; }
			foreach ( $shipments as $shipment ) { $stored = $service->read_for_order( $shipment, $order ); if ( null === $stored ) { continue; } $rows[] = [ 'original' => $stored->original_public(), 'current' => $stored->current()?->public_facts() ]; }
			if ( ! self::authorized( $order ) || true !== ( $this->enabled )() || [] === $rows ) { return; }
			echo self::html( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- explicit closed renderer escapes every field.
		} catch ( \Throwable ) { /* No raw row, private reason or weaker history fallback is displayed. */ }
	}
	/** Re-load exact native order authority; a spoofed hook object cannot pick another customer's history. */
	public static function authorized( \WC_Order $order ): bool {
		try {
			$received = function_exists( 'is_order_received_page' ) && is_order_received_page(); $view = function_exists( 'is_view_order_page' ) && is_view_order_page();
			if ( ! $received && ! $view || $order->get_id() < 1 || ! function_exists( 'wc_get_order' ) || ! function_exists( 'get_current_user_id' ) || ! method_exists( $order, 'get_customer_id' ) || ! method_exists( $order, 'get_order_key' ) ) { return false; }
			$fresh = wc_get_order( $order->get_id() ); if ( ! $fresh instanceof \WC_Order || $fresh->get_id() !== $order->get_id() || $fresh->get_customer_id() !== $order->get_customer_id() || $fresh->get_order_key() !== $order->get_order_key() ) { return false; }
			$user = get_current_user_id(); $customer = (int) $fresh->get_customer_id();
			if ( $customer > 0 ) { return $user > 0 && $user === $customer; }
			$key = $_GET['key'] ?? null; $native = (string) $fresh->get_order_key(); return $received && is_string( $key ) && strlen( $key ) <= 128 && '' !== $native && hash_equals( $native, $key );
		} catch ( \Throwable ) { return false; }
	}
	/** Only explicit public rows are accepted; staff reasons, runtime provenance and event IDs refuse. */
	public static function html( array $rows, ?PublicPromiseFormatter $formatter = null ): string {
		PromiseShape::list( $rows, 1, 200 ); $formatter ??= new PublicPromiseFormatter(); $html = '<section class="cetech-de-shipment-promises" aria-label="' . esc_attr__( 'Delivery windows', 'cetech-woocommerce-delivery-engine' ) . '">';
		$html .= '<h2>' . esc_html__( 'Delivery windows', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		foreach ( $rows as $row ) {
			PromiseShape::fields( $row, [ 'original', 'current' ] ); $original = PromiseShape::object( $row['original'] ); PromiseShape::fields( $original, [ 'views', 'customer_text' ] ); foreach ( PromiseShape::list( $original['views'], 1, 16 ) as $view ) { PublicPromiseView::from_array( $view ); }
			$html .= '<article class="cetech-de-shipment-promise"><dl><dt>' . esc_html__( 'Original recorded delivery estimate', 'cetech-woocommerce-delivery-engine' ) . '</dt><dd data-cetech-de-shipment-original="1">' . esc_html( PromiseShape::text( $original['customer_text'], 2048 ) ) . '</dd>';
			$current_text = __( 'A current delivery estimate has not been recorded.', 'cetech-woocommerce-delivery-engine' ); $source = '';
			if ( null !== $row['current'] ) {
				$current = PromiseShape::object( $row['current'] ); PromiseShape::fields( $current, [ 'state', 'views', 'source' ] ); PromiseShape::choice( $current['state'], [ 'absolute_window', 'unavailable' ] ); PromiseShape::choice( $current['source'], [ 'payment_confirmed', 'staff_revision' ] );
				$views = PromiseShape::list( $current['views'], 'absolute_window' === $current['state'] ? 1 : 0, 16 ); if ( 'unavailable' === $current['state'] && [] !== $views ) { PromiseShape::invalid(); }
				$texts = []; foreach ( $views as $view ) { PromiseShape::fields( $view, [ 'service_label', 'display_timezone', 'from', 'until' ] ); $texts[] = $formatter->text( PublicPromiseView::from_array( [ 'format_version' => 1, 'state' => 'absolute_window', 'reason_codes' => [] ] + $view ) ); }
				$current_text = 'unavailable' === $current['state'] ? __( 'The current delivery estimate is unavailable.', 'cetech-woocommerce-delivery-engine' ) : PromiseShape::text( implode( '; ', $texts ), 2048 );
				$source = 'payment_confirmed' === $current['source'] ? __( 'Calculated after payment confirmation.', 'cetech-woocommerce-delivery-engine' ) : __( 'Updated by the delivery team.', 'cetech-woocommerce-delivery-engine' );
			}
			$html .= '<dt>' . esc_html__( 'Current delivery estimate', 'cetech-woocommerce-delivery-engine' ) . '</dt><dd data-cetech-de-shipment-current="1">' . esc_html( $current_text ) . ( '' !== $source ? '<br><small>' . esc_html( $source ) . '</small>' : '' ) . '</dd></dl></article>';
		}
		return $html . '</section>';
	}
}
