<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\OperationalState;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Products whose effective delivery configuration is incomplete.
 */
final class NeedsAttentionPage {

	public const SLUG = 'cetech-delivery-engine-needs-attention';

	public function __construct(
		private readonly NeedsAttentionQuery $query,
		private readonly AdminActionHandler $action_handler,
		private readonly OperationalStateService $operational_state
	) {
	}

	public function handle_actions(): void {
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		$this->action_handler->notices()->render_notices();

		$items = $this->query->list( 200 );
		$op    = $this->operational_state->current();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ),
			__( 'An operational to-do list for products that are missing a usable delivery setup.', 'cetech-woocommerce-delivery-engine' )
		);

		if ( $op->customers_still_use_previous_rules() && ! $op->sitewide_setup_complete ) {
			AdminPageLayout::render_status_banner(
				[
					'tone'  => $op->overview_tone,
					'title' => $op->overview_title,
					'text'  => $op->overview_text,
				]
			);
		}

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

			$problem = esc_html( $item['reason'] );
			if ( $op->customers_still_use_previous_rules() ) {
				$problem .= '<br /><span class="description">' . esc_html__( 'Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', 'cetech-woocommerce-delivery-engine' ) . '</span>';
			} elseif ( OperationalState::CUSTOMER_RUNTIME_ECR === $op->customer_runtime ) {
				$problem .= '<br /><span class="description">' . esc_html__( 'Customers cannot currently use delivery for this product.', 'cetech-woocommerce-delivery-engine' ) . '</span>';
			}

			$rows[] = [
				'<a href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['label'] ) . '</strong></a>',
				$problem,
				'<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Fix Now', 'cetech-woocommerce-delivery-engine' ) . '</a>',
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Product', 'cetech-woocommerce-delivery-engine' ),
				__( 'Problem', 'cetech-woocommerce-delivery-engine' ),
				__( 'Action', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_page();
	}
}
