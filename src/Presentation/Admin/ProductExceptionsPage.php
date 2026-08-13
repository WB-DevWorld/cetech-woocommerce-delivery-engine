<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

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

		$items = $this->query->list( 200 );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Product Exceptions', 'cetech-woocommerce-delivery-engine' ),
			__( 'These products and variations have their own delivery settings instead of only using the site-wide defaults.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( DeliverySettingsHomePage::SLUG ),
			]
		);

		if ( [] === $items ) {
			AdminPageLayout::render_empty_state(
				__( 'No product exceptions', 'cetech-woocommerce-delivery-engine' ),
				__( 'Every listed product is using site-wide defaults, or has not been customized yet.', 'cetech-woocommerce-delivery-engine' )
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
			$reset .= '<button type="submit" class="button-link">' . esc_html(
				'variation' === $item['type']
					? __( 'Reset this variation to product settings', 'cetech-woocommerce-delivery-engine' )
					: __( 'Reset this product to Site-wide Defaults', 'cetech-woocommerce-delivery-engine' )
			) . '</button></form>';

			$rows[] = [
				'<strong>' . esc_html( $item['label'] ) . '</strong>',
				esc_html( $item['fulfilment'] ),
				esc_html( $item['currently_using'] ),
				esc_html( implode( ', ', $item['customized'] ) ),
				'<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'View / Edit', 'cetech-woocommerce-delivery-engine' ) . '</a> ' . $reset,
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ),
				__( 'Currently using', 'cetech-woocommerce-delivery-engine' ),
				__( 'What is customized', 'cetech-woocommerce-delivery-engine' ),
				__( 'Action', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_page();
	}
}
