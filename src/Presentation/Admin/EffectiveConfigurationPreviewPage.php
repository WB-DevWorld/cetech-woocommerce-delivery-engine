<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EffectiveConfigurationPreviewViewModel;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Read-only Effective Configuration Preview backed by EffectiveConfigurationResolver.
 */
final class EffectiveConfigurationPreviewPage {

	public const SLUG = 'cetech-delivery-engine-effective-preview';

	private const ACTION_PREVIEW = 'cetech_de_preview_effective_configuration';

	public function __construct(
		private ScopedConfigurationAdminService $admin_service,
		private ProductTargetResolver $product_target_resolver,
		private AdminActionHandler $action_handler,
		private ScopedConfigurationAuthorization $authorization
	) {
	}

	public function handle_actions(): void {
		// Preview is read-only. Reject any POST that attempts mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );

		if ( self::ACTION_PREVIEW === $action ) {
			// Convert POST preview requests into GET redirects — never mutate.
			if ( ! $this->action_handler->verify_post( self::ACTION_PREVIEW, self::ACTION_PREVIEW, ScopedConfigurationAuthorization::CAPABILITY_PREVIEW, self::SLUG ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$slice_key = isset( $_POST['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slice_key'] ) ) : ConfigurationScope::DEFAULT_SLICE_KEY;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$parent_product_id = isset( $_POST['parent_product_id'] ) ? absint( wp_unslash( $_POST['parent_product_id'] ) ) : 0;

			$args = [
				'product_id' => $product_id,
				'slice_key'  => $slice_key,
				'preview'    => '1',
			];
			if ( $variation_id > 0 ) {
				$args['variation_id'] = $variation_id;
			}
			if ( $parent_product_id > 0 ) {
				$args['parent_product_id'] = $parent_product_id;
			}

			$this->action_handler->redirect( self::SLUG, $args );
		}

		$this->action_handler->notices()->flash_error(
			__( 'Effective configuration preview cannot modify configuration.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->action_handler->redirect( self::SLUG );
	}

	public function render(): void {
		AdminPageAccess::require_capability( ScopedConfigurationAuthorization::CAPABILITY_PREVIEW );
		$this->action_handler->notices()->render_notices();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			__( 'Effective Configuration Preview', 'cetech-woocommerce-delivery-engine' ),
			__( 'Read-only inheritance preview using the Stage 3 EffectiveConfigurationResolver. Not a shipping quote.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Scoped Configuration', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( ScopedConfigurationPage::SLUG ),
			],
			[
				'label' => __( 'Legacy Product Rules', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( ProductDeliveryRulesPage::SLUG ),
			]
		);

		echo '<div class="notice notice-info cetech-de-scoped-transitional" role="status">';
		echo '<p><strong>' . esc_html( ScopedConfigurationNotices::TRANSITIONAL_TITLE ) . '</strong></p>';
		echo '<p>' . esc_html( ScopedConfigurationNotices::TRANSITIONAL_MESSAGE ) . '</p>';
		echo '</div>';

		echo '<div class="notice notice-warning cetech-de-preview-limitation" role="status">';
		echo '<p><strong>' . esc_html( ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE ) . '</strong></p>';
		echo '<p>' . esc_html( ScopedConfigurationNotices::PREVIEW_LIMITATION_MESSAGE ) . '</p>';
		echo '<p>' . esc_html( ScopedConfigurationNotices::HARD_CONSTRAINT_NOTE ) . '</p>';
		echo '</div>';

		$this->render_preview_form();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$do_preview = isset( $_GET['preview'] ) && '1' === (string) wp_unslash( $_GET['preview'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;

		if ( $do_preview && $product_id > 0 ) {
			if ( ! $this->product_exists( $product_id ) ) {
				AdminPageLayout::render_warning(
					__( 'Invalid product', 'cetech-woocommerce-delivery-engine' ),
					__( 'Select a valid product. The supplied product ID could not be loaded in WooCommerce.', 'cetech-woocommerce-delivery-engine' )
				);
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$variation_id = isset( $_GET['variation_id'] ) ? absint( wp_unslash( $_GET['variation_id'] ) ) : 0;
				$variation_id = $variation_id > 0 ? $variation_id : null;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$slice_key = isset( $_GET['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['slice_key'] ) ) : ConfigurationScope::DEFAULT_SLICE_KEY;
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$parent_product_id = isset( $_GET['parent_product_id'] ) ? absint( wp_unslash( $_GET['parent_product_id'] ) ) : 0;
				$parent_product_id = $parent_product_id > 0 ? $parent_product_id : ( null !== $variation_id ? $product_id : null );

				$category_ids = $this->resolve_product_category_ids( $product_id );
				$model        = $this->admin_service->preview(
					$product_id,
					$variation_id,
					$slice_key,
					$parent_product_id,
					$category_ids
				);

				$this->render_preview_results( $model );
			}
		}

		AdminPageLayout::close_page();
	}

	private function render_preview_form(): void {
		AdminPageLayout::open_section(
			__( 'Preview inputs', 'cetech-woocommerce-delivery-engine' ),
			__( 'Select a product, optional variation, and slice. Loading this preview never writes configuration.', 'cetech-woocommerce-delivery-engine' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variation_id = isset( $_GET['variation_id'] ) ? absint( wp_unslash( $_GET['variation_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slice_key = isset( $_GET['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['slice_key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parent_product_id = isset( $_GET['parent_product_id'] ) ? absint( wp_unslash( $_GET['parent_product_id'] ) ) : 0;

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-preview-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="preview" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="product_id">' . esc_html__( 'Product ID', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
		echo '<td><input type="number" min="1" required class="small-text" id="product_id" name="product_id" value="' . esc_attr( (string) $product_id ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="parent_product_id">' . esc_html__( 'Parent product ID (variation)', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
		echo '<td><input type="number" min="1" class="small-text" id="parent_product_id" name="parent_product_id" value="' . esc_attr( $parent_product_id > 0 ? (string) $parent_product_id : '' ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="variation_id">' . esc_html__( 'Variation ID (optional)', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
		echo '<td><input type="number" min="1" class="small-text" id="variation_id" name="variation_id" value="' . esc_attr( $variation_id > 0 ? (string) $variation_id : '' ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="slice_key">' . esc_html__( 'Slice', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<select id="slice_key" name="slice_key">';
		$slices = [
			'' => __( 'Default (native root slice)', 'cetech-woocommerce-delivery-engine' ),
			'international_fulfilment' => __( 'International fulfilment', 'cetech-woocommerce-delivery-engine' ),
			'in_store' => __( 'In store', 'cetech-woocommerce-delivery-engine' ),
			'in_warehouse' => __( 'In warehouse', 'cetech-woocommerce-delivery-engine' ),
		];
		foreach ( $slices as $key => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $slice_key, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Run effective preview', 'cetech-woocommerce-delivery-engine' ), 'primary', 'submit', false );
		echo '</form>';
		AdminPageLayout::close_section();
	}

	private function render_preview_results( EffectiveConfigurationPreviewViewModel $model ): void {
		if ( ! empty( $model->category_warning['has_legacy_category_rules'] ) ) {
			AdminPageLayout::render_warning(
				(string) $model->category_warning['warning_title'],
				(string) $model->category_warning['warning_message']
			);
		}

		$product_label = $this->product_target_resolver->resolve_label( ProductTargetType::Product->value, $model->product_id );
		$variation_label = null !== $model->variation_id
			? $this->product_target_resolver->resolve_label( ProductTargetType::Variation->value, $model->variation_id )
			: null;

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Overall state', 'cetech-woocommerce-delivery-engine' ),
					'value' => $model->overall_state_label,
				],
				[
					'label' => __( 'Slice', 'cetech-woocommerce-delivery-engine' ),
					'value' => $model->slice_label,
				],
				[
					'label' => __( 'Resolver', 'cetech-woocommerce-delivery-engine' ),
					'value' => $model->used_authoritative_resolver
						? __( 'Stage 3 EffectiveConfigurationResolver', 'cetech-woocommerce-delivery-engine' )
						: __( 'Unknown', 'cetech-woocommerce-delivery-engine' ),
				],
			]
		);

		echo '<p>';
		if ( null !== $product_label ) {
			echo esc_html__( 'Product:', 'cetech-woocommerce-delivery-engine' ) . ' ' . esc_html( $product_label ) . ' ';
		}
		if ( null !== $variation_label ) {
			echo esc_html__( 'Variation:', 'cetech-woocommerce-delivery-engine' ) . ' ' . esc_html( $variation_label );
		}
		echo '</p>';

		if ( [] !== $model->overall_reasons ) {
			echo '<ul class="cetech-de-validation-messages" role="status">';
			foreach ( $model->overall_reasons as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul>';
		}

		AdminPageLayout::open_section(
			__( 'Field-by-field effective result', 'cetech-woocommerce-delivery-engine' ),
			__( 'Values and provenance come from the authoritative resolver. No shipping prices are shown.', 'cetech-woocommerce-delivery-engine' )
		);

		if ( [] === $model->fields ) {
			echo '<p>' . esc_html__( 'No field results available for this request.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} else {
			echo '<table class="widefat striped cetech-de-preview-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Field', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'State', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Effective value', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Source', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Notes', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $model->fields as $field ) {
				echo '<tr>';
				echo '<th scope="row">' . esc_html( (string) $field['label'] ) . '</th>';
				echo '<td><span class="cetech-de-state cetech-de-state--' . esc_attr( (string) $field['effective_state_tone'] ) . '">';
				echo esc_html( (string) $field['effective_state_label'] );
				echo '</span></td>';

				if ( ! empty( $field['is_collection'] ) ) {
					$labels = $field['effective_member_labels'] ?? [];
					$display = [] === $labels
						? __( '(explicitly empty / none)', 'cetech-woocommerce-delivery-engine' )
						: implode( ', ', array_map( 'strval', $labels ) );
					echo '<td>' . esc_html( $display ) . '</td>';
				} else {
					echo '<td>' . esc_html( (string) ( $field['effective_value_label'] ?? '—' ) ) . '</td>';
				}

				echo '<td>' . esc_html( (string) ( $field['provenance_label'] ?? '' ) ) . '</td>';
				$notes = array_merge(
					$field['provenance_lines'] ?? [],
					$field['validation_messages'] ?? []
				);
				echo '<td>' . esc_html( [] === $notes ? '—' : implode( ' · ', $notes ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<details class="cetech-de-technical-details"><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary><ul>';
		foreach ( $model->version as $key => $value ) {
			printf(
				'<li><code>%s</code>: %s</li>',
				esc_html( (string) $key ),
				esc_html( is_scalar( $value ) ? (string) $value : '' )
			);
		}
		echo '<li><code>slice_key_raw</code>: ' . esc_html( $model->slice_key ) . '</li>';
		echo '</ul></details>';

		AdminPageLayout::close_section();
	}

	private function product_exists( int $product_id ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof \WC_Product;
	}

	/**
	 * @return list<int>
	 */
	private function resolve_product_category_ids( int $product_id ): array {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return [];
		}

		$ids = $product->get_category_ids();
		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
	}
}
