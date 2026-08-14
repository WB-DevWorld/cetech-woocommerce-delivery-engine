<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Review products and variations that differ from site-wide defaults.
 */
final class ProductExceptionsPage {

	public const SLUG = 'cetech-delivery-engine-product-exceptions';

	private const ACTION_RESET = 'cetech_de_reset_exception';

	public function __construct(
		private readonly ProductExceptionsQuery $query,
		private readonly SiteWideDefaultsService $defaults,
		private readonly AdminActionHandler $action_handler
	) {
	}

	public function handle_actions(): void {
		if ( ! $this->action_handler->verify_post( self::ACTION_RESET, self::ACTION_RESET, 'manage_product_delivery_rules', self::SLUG ) ) {
			return;
		}

		$type = isset( $_POST['item_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['item_type'] ) ) : '';
		$id   = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;

		$ok = 'variation' === $type
			? $this->defaults->reset_variation_to_product( $id )
			: $this->defaults->reset_product_to_site_wide( $id );

		if ( $ok ) {
			$this->action_handler->notices()->flash_success(
				'variation' === $type
					? __( 'This variation now uses the product settings again.', 'cetech-woocommerce-delivery-engine' )
					: __( 'This product now uses Site-wide Defaults again.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_error( __( 'Nothing was reset. The item may already be using inherited settings.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		$this->action_handler->notices()->render_notices();

		$filters = [
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '',
			'fulfilment'  => isset( $_GET['fulfilment'] ) ? sanitize_key( wp_unslash( (string) $_GET['fulfilment'] ) ) : '',
			'type'        => isset( $_GET['exception_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['exception_type'] ) ) : '',
			'status'      => isset( $_GET['exception_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['exception_status'] ) ) : '',
		];
		$items = $this->query->list( 200, $filters );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Product Exceptions', 'cetech-woocommerce-delivery-engine' ),
			__( 'Which products are different from the normal Site-wide Defaults?', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="get" class="cetech-de-list-toolbar">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search products', 'cetech-woocommerce-delivery-engine' ) . '" />';
		echo '<select name="fulfilment"><option value="">' . esc_html__( 'All fulfilment types', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			echo '<option value="' . esc_attr( $profile->key ) . '"' . selected( $filters['fulfilment'], $profile->key, false ) . '>' . esc_html( $profile->label ) . '</option>';
		}
		echo '</select>';
		echo '<select name="exception_type"><option value="">' . esc_html__( 'All exception types', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="product"' . selected( $filters['type'], 'product', false ) . '>' . esc_html__( 'Product exceptions', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="variation"' . selected( $filters['type'], 'variation', false ) . '>' . esc_html__( 'Variation exceptions', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		echo '<select name="exception_status"><option value="">' . esc_html__( 'All statuses', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="ready"' . selected( $filters['status'], 'ready', false ) . '>' . esc_html__( 'Ready', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="needs_attention"' . selected( $filters['status'], 'needs_attention', false ) . '>' . esc_html__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</form>';

		if ( [] === $items ) {
			AdminPageLayout::render_empty_state(
				__( 'No product exceptions.', 'cetech-woocommerce-delivery-engine' ),
				__( 'All products currently follow their normal delivery defaults.', 'cetech-woocommerce-delivery-engine' )
			);
			AdminPageLayout::close_page();
			return;
		}

		$rows = [];
		foreach ( $items as $item ) {
			$edit_url = add_query_arg(
				[
					'page'       => ScopedConfigurationPage::SLUG,
					'scope_type' => 'variation' === $item['type'] ? ConfigurationScopeType::Variation->value : ConfigurationScopeType::Product->value,
					'scope_id'   => $item['id'],
					'slice_key'  => ConfigurationScope::DEFAULT_SLICE_KEY,
					'customize'  => 1,
				] + ( null !== $item['parent_id'] ? [ 'parent_product_id' => $item['parent_id'] ] : [] ),
				admin_url( 'admin.php' )
			);

			$reset  = '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Reset these custom delivery settings?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
			$reset .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_RESET ) . '" />';
			$reset .= '<input type="hidden" name="item_type" value="' . esc_attr( $item['type'] ) . '" />';
			$reset .= '<input type="hidden" name="item_id" value="' . esc_attr( (string) $item['id'] ) . '" />';
			$reset .= wp_nonce_field( self::ACTION_RESET, 'cetech_de_nonce', true, false );
			$reset .= '<button type="submit" class="button">' . esc_html(
				'variation' === $item['type']
					? __( 'Reset to Product Settings', 'cetech-woocommerce-delivery-engine' )
					: __( 'Reset to Site-wide Defaults', 'cetech-woocommerce-delivery-engine' )
			) . '</button></form>';

			$customized = $item['customized'] ?? [];
			$customized_label = is_array( $customized ) && [] !== $customized
				? implode( ', ', array_map( 'strval', $customized ) )
				: '—';
			$status = (string) ( $item['status'] ?? '' );
			$status_class = str_contains( strtolower( $status ), 'attention' ) ? 'cetech-de-badge--attention' : 'cetech-de-badge--ready';

			$rows[] = [
				'<strong>' . esc_html( $item['label'] ) . '</strong>'
					. ( '' !== (string) ( $item['currently_using'] ?? '' )
						? '<br /><span class="description">' . esc_html( (string) $item['currently_using'] ) . '</span>'
						: '' ),
				esc_html( (string) ( $item['fulfilment'] ?? '' ) ),
				esc_html( (string) ( $item['type_label'] ?? '' ) ),
				esc_html( $customized_label ),
				'<span class="cetech-de-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span>',
				'<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'View / Edit', 'cetech-woocommerce-delivery-engine' ) . '</a> · ' . $reset,
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ),
				__( 'Exception', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customized', 'cetech-woocommerce-delivery-engine' ),
				__( 'Status', 'cetech-woocommerce-delivery-engine' ),
				__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_page();
	}
}
