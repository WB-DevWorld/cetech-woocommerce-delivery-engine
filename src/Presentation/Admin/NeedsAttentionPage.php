<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Products whose effective delivery configuration is incomplete.
 */
final class NeedsAttentionPage {

	public const SLUG = 'cetech-delivery-engine-needs-attention';

	public function __construct(
		private readonly NeedsAttentionQuery $query,
		private readonly AdminActionHandler $action_handler
	) {
	}

	public function handle_actions(): void {
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		$this->action_handler->notices()->render_notices();

		$items = $this->query->list( 200 );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ),
			__( 'These products do not currently have a complete, usable delivery setup.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( DeliverySettingsHomePage::SLUG ),
			]
		);

		if ( [] === $items ) {
			AdminPageLayout::render_empty_state(
				__( 'Nothing needs attention', 'cetech-woocommerce-delivery-engine' ),
				__( 'Every listed product currently has a usable delivery setup.', 'cetech-woocommerce-delivery-engine' )
			);
			AdminPageLayout::close_page();
			return;
		}

		$rows = [];
		foreach ( $items as $item ) {
			$edit_url = add_query_arg(
				[
					'page'       => ScopedConfigurationPage::SLUG,
					'scope_type' => ConfigurationScopeType::Product->value,
					'scope_id'   => $item['id'],
					'slice_key'  => ConfigurationScope::DEFAULT_SLICE_KEY,
				],
				admin_url( 'admin.php' )
			);

			$rows[] = [
				'<a href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['label'] ) . '</strong></a>',
				esc_html( $item['reason'] ),
				'<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open delivery settings', 'cetech-woocommerce-delivery-engine' ) . '</a>',
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Why it needs attention', 'cetech-woocommerce-delivery-engine' ),
				__( 'Action', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_page();
	}
}
