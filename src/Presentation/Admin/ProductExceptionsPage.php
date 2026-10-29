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

	/** Query key naming the list a scoped-configuration editor should return to. */
	public const RETURN_TO_KEY = 'return_to';

	/** Prefix of the scalar query keys that carry the list filters to restore on return. */
	public const RETURN_ARG_PREFIX = 'return_';

	private const RETURN_FILTER_KEYS = [ 's', 'fulfilment', 'exception_type', 'exception_status' ];

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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$return_filters = self::sanitize_return_filters( wp_unslash( $_POST ) );

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
			$this->action_handler->redirect( self::SLUG, $return_filters );
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
			$this->action_handler->redirect( self::SLUG, $return_filters );
		}

		if ( $this->admin_service->reset_was_replayed() && $ok ) {
			$this->action_handler->notices()->flash_success(
				__( 'This reset was already completed. The recorded settings were not changed again.', 'cetech-woocommerce-delivery-engine' )
			);
		} elseif ( $ok ) {
			$this->action_handler->notices()->flash_success(
				'variation' === $type
					? __( 'This variation now uses the product settings again.', 'cetech-woocommerce-delivery-engine' )
					: __( 'This product now uses Site-wide Defaults again.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_error( __( 'Nothing was reset. The item may already be using inherited settings.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG, $return_filters );
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
		echo '<label class="screen-reader-text" for="cetech-de-exception-search">' . esc_html__( 'Search products', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<input type="search" id="cetech-de-exception-search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search products', 'cetech-woocommerce-delivery-engine' ) . '" />';
		echo '<label class="screen-reader-text" for="cetech-de-exception-fulfilment">' . esc_html__( 'Filter by fulfilment type', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-exception-fulfilment" name="fulfilment"><option value="">' . esc_html__( 'All fulfilment types', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			echo '<option value="' . esc_attr( $profile->key ) . '"' . selected( $filters['fulfilment'], $profile->key, false ) . '>' . esc_html( $profile->label ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="cetech-de-exception-type">' . esc_html__( 'Filter by exception type', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-exception-type" name="exception_type"><option value="">' . esc_html__( 'All exception types', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="product"' . selected( $filters['type'], 'product', false ) . '>' . esc_html__( 'Product exceptions', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="variation"' . selected( $filters['type'], 'variation', false ) . '>' . esc_html__( 'Variation exceptions', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		echo '<label class="screen-reader-text" for="cetech-de-exception-status">' . esc_html__( 'Filter by status', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-exception-status" name="exception_status"><option value="">' . esc_html__( 'All statuses', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="ready"' . selected( $filters['status'], 'ready', false ) . '>' . esc_html__( 'Ready', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="needs_attention"' . selected( $filters['status'], 'needs_attention', false ) . '>' . esc_html__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</form>';

		if ( [] === $items ) {
			if ( [] !== self::list_query_args( $filters ) ) {
				AdminPageLayout::render_empty_state(
					__( 'No product exceptions match these filters.', 'cetech-woocommerce-delivery-engine' ),
					__( 'Other products may still have exceptions. Clear the search and filters to see every product that differs from the Site-wide Defaults.', 'cetech-woocommerce-delivery-engine' ),
					__( 'Clear filters', 'cetech-woocommerce-delivery-engine' ),
					AdminPageRenderer::list_url( self::SLUG )
				);
			} else {
				AdminPageLayout::render_empty_state(
					__( 'No product exceptions.', 'cetech-woocommerce-delivery-engine' ),
					__( 'All products currently follow their normal delivery defaults.', 'cetech-woocommerce-delivery-engine' )
				);
			}
			AdminPageLayout::close_page();
			return;
		}

		$return_args = self::return_context_args( self::list_query_args( $filters ) );

		$rows = [];
		foreach ( $items as $item ) {
			$edit_url = add_query_arg(
				[
					'page'       => ScopedConfigurationPage::SLUG,
					'scope_type' => 'variation' === $item['type'] ? ConfigurationScopeType::Variation->value : ConfigurationScopeType::Product->value,
					'scope_id'   => $item['id'],
					'slice_key'  => $item['slice_key'],
					'customize'  => 1,
				] + ( null !== $item['parent_id'] ? [ 'parent_product_id' => $item['parent_id'] ] : [] ) + $return_args,
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
			$reset .= self::return_context_hidden_inputs( $return_args );
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
	/**
	 * Non-empty list filters as the query arguments this page reads back.
	 *
	 * @param array{search: string, fulfilment: string, type: string, status: string} $filters
	 * @return array<string, string>
	 */
	public static function list_query_args( array $filters ): array {
		$args = [
			's'                => (string) ( $filters['search'] ?? '' ),
			'fulfilment'       => (string) ( $filters['fulfilment'] ?? '' ),
			'exception_type'   => (string) ( $filters['type'] ?? '' ),
			'exception_status' => (string) ( $filters['status'] ?? '' ),
		];

		return array_filter( $args, static fn ( string $value ): bool => '' !== $value );
	}

	/**
	 * Reads the `return_<filter>` keys out of unslashed GET or POST data.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, string> Filter key => value, only the known list filters.
	 */
	public static function sanitize_return_filters( array $source ): array {
		$filters = [];
		foreach ( self::RETURN_FILTER_KEYS as $key ) {
			$raw = $source[ self::RETURN_ARG_PREFIX . $key ] ?? null;
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$value = 's' === $key
				? sanitize_text_field( (string) $raw )
				: sanitize_key( (string) $raw );
			if ( '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		return $filters;
	}

	/**
	 * Query arguments that let the scoped-configuration editor link back to this filtered list.
	 *
	 * @param array<string, string> $filters
	 * @return array<string, mixed>
	 */
	public static function return_context_args( array $filters ): array {
		if ( [] === $filters ) {
			return [];
		}

		$args = [ self::RETURN_TO_KEY => self::SLUG ];
		foreach ( $filters as $key => $value ) {
			$args[ self::RETURN_ARG_PREFIX . $key ] = $value;
		}

		return $args;
	}

	/**
	 * Hidden inputs that carry a return context through a POST form.
	 *
	 * @param array<string, mixed> $return_args Output of return_context_args().
	 */
	public static function return_context_hidden_inputs( array $return_args ): string {
		if ( [] === $return_args ) {
			return '';
		}
		$html = '';
		foreach ( $return_args as $key => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		return $html;
	}

	/**
	 * The list URL with the given filters restored.
	 *
	 * @param array<string, string> $filters
	 */
	public static function filtered_list_url( array $filters ): string {
		return add_query_arg( $filters, AdminPageRenderer::list_url( self::SLUG ) );
	}

	private function target_guard(): ScopedConfigurationTargetGuard {
		return new ScopedConfigurationTargetGuard( new ProductVariationScopeGuard( $this->product_target_resolver ), $this->authorization );
	}
}
