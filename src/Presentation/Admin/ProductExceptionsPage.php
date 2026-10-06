<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationTargetGuard;
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
		private readonly AdminActionHandler $action_handler,
		private readonly ProductTargetResolver $product_target_resolver,
		private readonly ScopedConfigurationAuthorization $authorization,
		private readonly ScopedConfigurationAdminService $admin_service
	) {
	}

	public function handle_actions(): void {
		if ( ! $this->action_handler->verify_post( self::ACTION_RESET, self::ACTION_RESET, 'manage_product_delivery_rules', self::SLUG ) ) {
			return;
		}

		try {
			$target = $this->target_guard()->resolve( wp_unslash( [
				'scope_type' => $_POST['item_type'] ?? '',
				'scope_id' => $_POST['item_id'] ?? 0,
				'slice_key' => $_POST['slice_key'] ?? ConfigurationScope::DEFAULT_SLICE_KEY,
				'parent_product_id' => $_POST['parent_product_id'] ?? null,
			] ) );
			if ( ConfigurationScopeType::Global === $target['scope_type'] ) {
				throw new \InvalidArgumentException( 'Global settings cannot be reset here.' );
			}
		} catch ( \InvalidArgumentException $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG );
		}
		$type = $target['scope_type']->value;
		try {
			$ok = $this->admin_service->reset(
				$target['scope_type'],
				$target['scope_id'],
				$target['slice_key'],
				$target['parent_product_id'],
				isset( $_POST['expected_revision'] ) ? (int) wp_unslash( $_POST['expected_revision'] ) : null,
				isset( $_POST['request_token'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['request_token'] ) ) : null,
				isset( $_POST['expected_scope_row_id'] ) ? (int) wp_unslash( $_POST['expected_scope_row_id'] ) : null
			);
		} catch ( \RuntimeException $exception ) {
			\CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository::replace_closed_connection();
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG );
		}

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
		$items = array_values( array_filter( $this->query->list( 200, $filters ), function ( array $item ): bool {
			try {
				$this->target_guard()->resolve( [ 'scope_type' => $item['type'], 'scope_id' => $item['id'], 'slice_key' => $item['slice_key'], 'parent_product_id' => $item['parent_id'] ] );
				return true;
			} catch ( \InvalidArgumentException $exception ) {
				return false;
			}
		} ) );

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
					'slice_key'  => $item['slice_key'],
					'customize'  => 1,
				] + ( null !== $item['parent_id'] ? [ 'parent_product_id' => $item['parent_id'] ] : [] ),
				admin_url( 'admin.php' )
			);

			$reset  = '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Reset these custom delivery settings?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
			$reset .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_RESET ) . '" />';
			$reset .= '<input type="hidden" name="item_type" value="' . esc_attr( $item['type'] ) . '" />';
			$reset .= '<input type="hidden" name="item_id" value="' . esc_attr( (string) $item['id'] ) . '" />';
			$reset .= '<input type="hidden" name="slice_key" value="' . esc_attr( $item['slice_key'] ) . '" />';
			$reset .= '<input type="hidden" name="expected_revision" value="' . esc_attr( (string) ( $item['config_version'] ?? 0 ) ) . '" />';
			$reset .= '<input type="hidden" name="expected_scope_row_id" value="' . esc_attr( (string) ( $item['scope_row_id'] ?? 0 ) ) . '" />';
			$reset .= '<input type="hidden" name="request_token" value="' . esc_attr( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) ) ) . '" />';
			if ( null !== $item['parent_id'] ) {
				$reset .= '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $item['parent_id'] ) . '" />';
			}
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
	private function target_guard(): ScopedConfigurationTargetGuard {
		return new ScopedConfigurationTargetGuard( new ProductVariationScopeGuard( $this->product_target_resolver ), $this->authorization );
	}
}
