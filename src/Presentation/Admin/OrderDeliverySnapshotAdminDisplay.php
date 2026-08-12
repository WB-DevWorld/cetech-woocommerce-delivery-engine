<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Order;
use WC_Order_Item_Product;
use WP_Post;

/**
 * Read-only operational delivery information on WooCommerce order admin screens.
 *
 * Protected snapshot meta remains stored; this class never exposes raw JSON,
 * versions, fingerprints, group hashes, or internal IDs in the primary staff view.
 */
final class OrderDeliverySnapshotAdminDisplay {

	private const META_BOX_ID = 'cetech_de_order_delivery_snapshot';

	public function __construct(
		private OrderDeliverySnapshotReader $reader,
		private OrderDeliverySnapshotIntegrity $integrity
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ], 30, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_protected_order_item_meta' ] );
	}

	/**
	 * Keep protected Delivery Engine order-item meta stored but invisible in normal WC item UI.
	 *
	 * @param list<string> $hidden
	 *
	 * @return list<string>
	 */
	public function hide_protected_order_item_meta( array $hidden ): array {
		$protected = [
			OrderDeliverySnapshot::META_LINE_SNAPSHOT,
			OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,
			OrderShippingItemPresentationGuard::GROUP_ID_META_KEY,
		];

		foreach ( $protected as $key ) {
			if ( ! in_array( $key, $hidden, true ) ) {
				$hidden[] = $key;
			}
		}

		return $hidden;
	}

	/**
	 * @param string              $post_type
	 * @param WP_Post|WC_Order|null $post
	 */
	public function register_meta_box( string $post_type, $post ): void {
		unset( $post_type, $post );

		$screen = function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			self::META_BOX_ID,
			__( 'Delivery information', 'cetech-woocommerce-delivery-engine' ),
			[ $this, 'render_meta_box' ],
			$screen,
			'normal',
			'default'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );

		if ( null === $order || ! $this->user_can_view_order( $order ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view delivery information for this order.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

			return;
		}

		$line_entries   = $this->collect_line_entries( $order );
		$package_read   = $this->reader->read_package( $order );
		$line_reads     = array_map( static fn ( array $entry ) => $entry['read'], $line_entries );
		$lines_status   = $this->integrity->classify_order_lines( $line_reads );
		$package_status = $this->integrity->classify_package( $package_read );

		if (
			OrderDeliverySnapshotIntegrity::STATUS_MISSING === $lines_status
			&& OrderDeliverySnapshotIntegrity::STATUS_MISSING === $package_status
		) {
			echo '<p>' . esc_html__( 'No delivery information is saved on this order.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

			return;
		}

		$grouped = $this->group_line_entries( $line_entries, $package_read );

		if ( $this->should_render_grouped( $grouped ) ) {
			$this->render_grouped_entries( $grouped );
		} else {
			$this->render_line_items( $line_entries );
		}

		if ( OrderDeliveryPackageReadResult::ERROR_MISSING !== $package_read->error ) {
			$this->render_package_summary( $package_read );
		}
	}

	/**
	 * @param list<array{
	 *     title: string,
	 *     group: ?OrderDeliveryGroupSnapshot,
	 *     entries: list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}>
	 * }> $grouped
	 */
	private function should_render_grouped( array $grouped ): bool {
		if ( count( $grouped ) > 1 ) {
			return true;
		}

		foreach ( $grouped as $group ) {
			if ( count( $group['entries'] ) > 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}> $line_entries
	 */
	private function render_line_items( array $line_entries ): void {
		foreach ( $line_entries as $entry ) {
			$read = $entry['read'];

			if ( null === $read->snapshot ) {
				continue;
			}

			$status = $this->integrity->classify_line( $read );

			echo '<div class="cetech-de-order-delivery-info" style="margin-bottom:16px;padding:12px;border:1px solid #ccd0d4;background:#fff;">';
			echo '<table class="widefat striped"><tbody>';
			$this->render_row( DeliveryPresentationLabels::product(), esc_html( $entry['item']->get_name() ) );
			$this->render_operational_line_rows( $read->snapshot, $status );
			echo '</tbody></table>';
			echo '</div>';
		}
	}

	/**
	 * @param list<array{
	 *     title: string,
	 *     group: ?OrderDeliveryGroupSnapshot,
	 *     entries: list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}>
	 * }> $grouped
	 */
	private function render_grouped_entries( array $grouped ): void {
		foreach ( $grouped as $group ) {
			echo '<div class="cetech-de-order-delivery-group" style="margin-bottom:20px;padding:12px;border:1px solid #ccd0d4;background:#fff;">';
			echo '<h4 style="margin:0 0 12px;">' . esc_html( $group['title'] ) . '</h4>';

			$product_names = [];
			foreach ( $group['entries'] as $entry ) {
				$product_names[] = $entry['item']->get_name();
			}

			echo '<table class="widefat striped"><tbody>';
			$this->render_row(
				__( 'Products', 'cetech-woocommerce-delivery-engine' ),
				esc_html( implode( ', ', $product_names ) )
			);

			$first_snapshot = null;
			$first_status   = OrderDeliverySnapshotIntegrity::STATUS_MISSING;

			foreach ( $group['entries'] as $entry ) {
				if ( null !== $entry['read']->snapshot ) {
					$first_snapshot = $entry['read']->snapshot;
					$first_status   = $this->integrity->classify_line( $entry['read'] );
					break;
				}
			}

			if ( null !== $first_snapshot ) {
				$this->render_operational_line_rows( $first_snapshot, $first_status, false );
			}

			$group_snapshot = $group['group'];
			if ( $group_snapshot instanceof OrderDeliveryGroupSnapshot ) {
				$charge = $this->format_amount(
					$group_snapshot->package_total_delivery_amount,
					$first_snapshot?->currency_code ?? ''
				);
				if ( '—' !== $charge && ! $group_snapshot->is_pickup ) {
					$this->render_row( DeliveryPresentationLabels::delivery_charge(), esc_html( $charge ) );
				}
			}

			echo '</tbody></table>';
			echo '</div>';
		}
	}

	private function render_operational_line_rows(
		OrderDeliveryLineSnapshot $snapshot,
		string $status,
		bool $include_line_charge = true
	): void {
		$this->render_row(
			DeliveryPresentationLabels::fulfilment(),
			esc_html( $this->format_fulfilment_availability( $snapshot->fulfilment_availability ) )
		);
		$this->render_row(
			DeliveryPresentationLabels::method_label_for_choice( $snapshot->fulfilment_choice ),
			esc_html( $this->format_fulfilment_choice( $snapshot->fulfilment_choice ) )
		);

		$offer_label = trim( (string) ( $snapshot->delivery_offer_public_label ?? '' ) );
		if ( '' !== $offer_label ) {
			$this->render_row( DeliveryPresentationLabels::delivery_option(), esc_html( $offer_label ) );
		}

		$estimate = trim( (string) ( $snapshot->estimate_text ?? '' ) );
		if ( '' !== $estimate ) {
			$this->render_row(
				DeliveryPresentationLabels::estimate_label_for_choice( $snapshot->fulfilment_choice ),
				esc_html( DeliveryPresentationLabels::strip_estimated_prefix( $estimate ) )
			);
		}

		if ( $include_line_charge ) {
			$charge = $this->format_amount( $snapshot->quoted_amount, $snapshot->currency_code );
			if ( '—' !== $charge ) {
				$this->render_row( DeliveryPresentationLabels::delivery_charge(), esc_html( $charge ) );
			}
		}

		$this->render_row(
			DeliveryPresentationLabels::status(),
			esc_html( $this->format_operational_status( $status ) )
		);
	}

	private function render_package_summary( OrderDeliveryPackageReadResult $read ): void {
		if ( null === $read->snapshot ) {
			return;
		}

		$snapshot = $read->snapshot;

		if ( count( $snapshot->groups ) > 1 ) {
			echo '<div class="cetech-de-order-delivery-package" style="margin-top:8px;">';
			echo '<h4>' . esc_html__( 'Order delivery summary', 'cetech-woocommerce-delivery-engine' ) . '</h4>';
			echo '<table class="widefat striped"><tbody>';
			$this->render_row(
				DeliveryPresentationLabels::shipping_method(),
				esc_html( $snapshot->shipping_method_label ?? __( 'Multiple deliveries', 'cetech-woocommerce-delivery-engine' ) )
			);

			$charge = $this->format_amount( $snapshot->package_total_delivery_amount, $snapshot->currency_code );
			if ( '—' !== $charge ) {
				$this->render_row( DeliveryPresentationLabels::delivery_charge(), esc_html( $charge ) );
			}

			echo '</tbody></table>';
			echo '</div>';

			return;
		}

		echo '<div class="cetech-de-order-delivery-package" style="margin-top:8px;">';
		echo '<h4>' . esc_html__( 'Order delivery summary', 'cetech-woocommerce-delivery-engine' ) . '</h4>';
		echo '<table class="widefat striped"><tbody>';
		$this->render_row(
			DeliveryPresentationLabels::shipping_method(),
			esc_html( $snapshot->shipping_method_label ?? __( 'Delivery', 'cetech-woocommerce-delivery-engine' ) )
		);

		$charge = $this->format_amount( $snapshot->package_total_delivery_amount, $snapshot->currency_code );
		if ( '—' !== $charge ) {
			$this->render_row( DeliveryPresentationLabels::delivery_charge(), esc_html( $charge ) );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:220px;">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
	}

	/**
	 * @return list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}>
	 */
	private function collect_line_entries( WC_Order $order ): array {
		$entries = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$entries[] = [
				'item' => $item,
				'read' => $this->reader->read_line( $item ),
			];
		}

		return $entries;
	}

	/**
	 * @param list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}> $line_entries
	 *
	 * @return list<array{
	 *     title: string,
	 *     group: ?OrderDeliveryGroupSnapshot,
	 *     entries: list<array{item: WC_Order_Item_Product, read: OrderDeliveryLineReadResult}>
	 * }>
	 */
	private function group_line_entries( array $line_entries, OrderDeliveryPackageReadResult $package_read ): array {
		$by_group = [];
		$ungrouped = [];

		foreach ( $line_entries as $entry ) {
			$snapshot = $entry['read']->snapshot;

			if ( null === $snapshot ) {
				continue;
			}

			$group_id = trim( (string) ( $snapshot->delivery_group_id ?? '' ) );

			if ( '' === $group_id ) {
				$ungrouped[] = $entry;
				continue;
			}

			if ( ! isset( $by_group[ $group_id ] ) ) {
				$by_group[ $group_id ] = [];
			}

			$by_group[ $group_id ][] = $entry;
		}

		if ( [] === $by_group ) {
			return [];
		}

		$package_groups = [];
		if ( null !== $package_read->snapshot ) {
			foreach ( $package_read->snapshot->groups as $group ) {
				$package_groups[ $group->group_id ] = $group;
			}
		}

		$result         = [];
		$delivery_index = 0;
		$pickup_index   = 0;

		foreach ( $by_group as $group_id => $entries ) {
			$package_group = $package_groups[ $group_id ] ?? null;
			$first         = $entries[0]['read']->snapshot;
			$is_pickup     = $package_group?->is_pickup
				?? ( null !== $first && FulfilmentChoice::StorePickup->value === $first->fulfilment_choice );

			if ( $is_pickup ) {
				++$pickup_index;
				$title = $package_group?->shipping_method_label
					?? sprintf(
						/* translators: %d: pickup group number */
						__( 'Store pickup %d', 'cetech-woocommerce-delivery-engine' ),
						$pickup_index
					);
			} else {
				++$delivery_index;
				$title = $package_group?->shipping_method_label
					?? sprintf(
						/* translators: %d: delivery group number */
						__( 'Delivery %d', 'cetech-woocommerce-delivery-engine' ),
						$delivery_index
					);
			}

			$result[] = [
				'title'   => $title,
				'group'   => $package_group,
				'entries' => $entries,
			];
		}

		foreach ( $ungrouped as $entry ) {
			$result[] = [
				'title'   => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
				'group'   => null,
				'entries' => [ $entry ],
			];
		}

		return $result;
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	private function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( ! $post_or_order instanceof WP_Post ) {
			return null;
		}

		$order = wc_get_order( $post_or_order->ID );

		return $order instanceof WC_Order ? $order : null;
	}

	private function user_can_view_order( WC_Order $order ): bool {
		$order_id = $order->get_id();

		if ( $order_id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_shop_order', $order_id )
			|| current_user_can( 'read_shop_order', $order_id )
			|| current_user_can( 'manage_woocommerce' );
	}

	private function format_operational_status( string $status ): string {
		return match ( $status ) {
			OrderDeliverySnapshotIntegrity::STATUS_PRESENT_VALID,
			OrderDeliverySnapshotIntegrity::STATUS_SELECTION_ONLY => __( 'Saved', 'cetech-woocommerce-delivery-engine' ),
			OrderDeliverySnapshotIntegrity::STATUS_MISSING => __( 'Not saved', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Needs review', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	private function format_fulfilment_availability( string $value ): string {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $value ) {
				return match ( $case ) {
					FulfilmentAvailability::InternationalFulfilment => __( 'International fulfilment', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InStore => __( 'In store', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentAvailability::InWarehouse => __( 'In warehouse', 'cetech-woocommerce-delivery-engine' ),
				};
			}
		}

		return ucwords( str_replace( '_', ' ', $value ) );
	}

	private function format_fulfilment_choice( string $value ): string {
		foreach ( FulfilmentChoice::cases() as $case ) {
			if ( $case->value === $value ) {
				return match ( $case ) {
					FulfilmentChoice::Delivery => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
					FulfilmentChoice::StorePickup => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
				};
			}
		}

		return ucwords( str_replace( '_', ' ', $value ) );
	}

	private function format_amount( ?string $amount, string $currency_code ): string {
		if ( null === $amount || '' === trim( $amount ) ) {
			return '—';
		}

		if ( function_exists( 'wc_price' ) ) {
			$formatted = wc_price( $amount, [ 'currency' => $currency_code ] );

			return wp_strip_all_tags( (string) $formatted );
		}

		return trim( $amount . ' ' . $currency_code );
	}
}
