<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentCard;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use WC_Order;

/**
 * Customer-safe shipment cards on WooCommerce My Account → View Order.
 *
 * Does not render on thank-you, product, cart, checkout, or email surfaces.
 */
final class CustomerShipmentRenderer {

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly Requirements $requirements,
		private readonly CustomerShipmentQuery $query
	) {
	}

	public function register(): void {
		if ( ! $this->customer_surface_enabled() ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 16, 1 );
	}

	public function render( WC_Order $order ): void {
		if ( ! $this->customer_surface_enabled() ) {
			return;
		}

		if ( ! $this->is_view_order_surface() ) {
			return;
		}

		if ( ! $order instanceof WC_Order || $order->get_id() <= 0 ) {
			return;
		}

		$cards = $this->query->cards_for_order( $order );

		if ( [] === $cards ) {
			return;
		}

		echo '<section class="cetech-de-customer-shipments">';
		echo '<h2>' . esc_html__( 'Delivery shipments', 'cetech-woocommerce-delivery-engine' ) . '</h2>';

		foreach ( $cards as $card ) {
			$this->render_card( $card );
		}

		echo '</section>';
	}

	private function render_card( CustomerShipmentCard $card ): void {
		echo '<article class="cetech-de-customer-shipment">';
		echo '<h3 class="cetech-de-customer-shipment__title">';
		echo esc_html(
			sprintf(
				/* translators: %s: customer-facing shipment reference */
				__( 'Shipment %s', 'cetech-woocommerce-delivery-engine' ),
				$card->reference
			)
		);
		echo '</h3>';

		echo '<ul class="cetech-de-customer-shipment__details">';
		$this->detail_row( __( 'Delivery option', 'cetech-woocommerce-delivery-engine' ), $card->delivery_option_label );
		$this->status_row( $card->status_label );
		$this->eta_row( $card );

		$items = $this->items_text( $card );

		if ( '' !== $items ) {
			$this->detail_row( __( 'Items', 'cetech-woocommerce-delivery-engine' ), $items );
		}

		if ( null !== $card->carrier ) {
			$this->detail_row( __( 'Carrier', 'cetech-woocommerce-delivery-engine' ), $card->carrier );
		}

		if ( null !== $card->tracking_number ) {
			$this->detail_row( __( 'Tracking number', 'cetech-woocommerce-delivery-engine' ), $card->tracking_number );
		}

		if ( null !== $card->dispatch_date_display ) {
			$this->detail_row( __( 'Dispatch date', 'cetech-woocommerce-delivery-engine' ), $card->dispatch_date_display );
		}

		echo '</ul>';

		if ( $this->flags->is_enabled( 'enable_tracking_links' ) && null !== $card->tracking_url ) {
			$accessible = sprintf(
				/* translators: %s: customer-facing shipment reference */
				__( 'Track shipment %s', 'cetech-woocommerce-delivery-engine' ),
				$card->reference
			);

			echo '<p class="cetech-de-customer-shipment__track">';
			echo '<a class="cetech-de-customer-shipment__track-link" href="' . esc_url( $card->tracking_url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $accessible ) . '">';
			echo esc_html__( 'Track shipment', 'cetech-woocommerce-delivery-engine' );
			echo '</a>';
			echo '</p>';
		}

		if ( null !== $card->public_note ) {
			echo '<p class="cetech-de-customer-shipment__note">' . esc_html( $card->public_note ) . '</p>';
		}

		echo '</article>';
	}

	private function status_row( string $label ): void {
		if ( '' === $label ) {
			return;
		}

		echo '<li><span class="label">' . esc_html__( 'Status', 'cetech-woocommerce-delivery-engine' ) . ':</span> ';
		echo '<span class="cetech-de-customer-shipment__status">' . esc_html( $label ) . '</span></li>';
	}

	private function eta_row( CustomerShipmentCard $card ): void {
		if ( '' === $card->eta_text ) {
			return;
		}

		$label = __( 'Estimated delivery to your address', 'cetech-woocommerce-delivery-engine' );
		$value = $card->eta_text;

		if ( $card->eta_was_updated ) {
			$value = sprintf(
				/* translators: %s: current estimated delivery text */
				__( '%s (updated estimate)', 'cetech-woocommerce-delivery-engine' ),
				$card->eta_text
			);
		}

		$this->detail_row( $label, $value );
	}

	private function detail_row( string $label, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		echo '<li><span class="label">' . esc_html( $label ) . ':</span> ' . esc_html( $value ) . '</li>';
	}

	private function items_text( CustomerShipmentCard $card ): string {
		if ( $card->item_count < 1 ) {
			return '';
		}

		$count_label = sprintf(
			/* translators: %d: number of shipment items */
			_n( '%d item', '%d items', $card->item_count, 'cetech-woocommerce-delivery-engine' ),
			$card->item_count
		);
		$names       = [];
		$shown       = 0;

		foreach ( $card->items as $item ) {
			if ( $shown >= CustomerShipmentQuery::MAX_NAMED_ITEMS ) {
				break;
			}

			$name = $item['name'];

			if ( $item['quantity'] > 1 ) {
				$name .= ' × ' . (string) $item['quantity'];
			}

			$names[] = $name;
			++$shown;
		}

		$remaining = $card->item_count - $shown;

		if ( [] === $names ) {
			return $count_label;
		}

		$text = $count_label . ': ' . implode( ', ', $names );

		if ( $remaining > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of additional shipment items not listed */
				_n( 'and %d more', 'and %d more', $remaining, 'cetech-woocommerce-delivery-engine' ),
				$remaining
			);
		}

		return $text;
	}

	private function customer_surface_enabled(): bool {
		return $this->flags->is_enabled( 'enable_shipment_records' )
			&& $this->flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG );
	}

	private function is_view_order_surface(): bool {
		return function_exists( 'is_view_order_page' ) && is_view_order_page();
	}
}
