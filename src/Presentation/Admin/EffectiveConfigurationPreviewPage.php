<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EffectiveConfigurationPreviewViewModel;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogIndexInterface;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\OperationalReadiness;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
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
		private ScopedConfigurationAuthorization $authorization,
		private OperationalReadinessAssessor $readiness,
		private ?CatalogIndexInterface $catalog = null,
		private ?ClassicCheckoutRuntimeActivation $runtime = null,
		private ?OperationalStateService $operational_state = null
	) {
	}

	public static function should_handle_posted_action( string $page, string $action ): bool {
		return self::SLUG === $page && '' !== $action;
	}

	public function handle_actions(): void {
		// Preview is read-only. Only handle POSTs aimed at this page — never steal
		// Setup Guide / Site-wide Defaults / Product Exceptions actions.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );
		if ( ! self::should_handle_posted_action( $page, $action ) ) {
			return;
		}

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
			__( 'Delivery Settings Preview cannot change settings. Use Delivery Settings to make edits.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->action_handler->redirect( self::SLUG );
	}

	public function render(): void {
		AdminPageAccess::require_capability( ScopedConfigurationAuthorization::CAPABILITY_PREVIEW );
		$this->action_handler->notices()->render_notices();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Settings Preview', 'cetech-woocommerce-delivery-engine' ),
			__( 'See what delivery settings will apply to a product or variation.', 'cetech-woocommerce-delivery-engine' )
		);

		$engine_active = $this->runtime?->is_active() ?? false;
		$op            = $this->operational_state?->current();
		$show_transitional = null !== $op
			? ! $op->customers_use_sitewide_runtime()
			: ! $engine_active;
		if ( $show_transitional ) {
			$title = $op?->overview_title ?? ScopedConfigurationNotices::TRANSITIONAL_TITLE;
			$text  = $op?->settings_status_detail ?? ScopedConfigurationNotices::TRANSITIONAL_MESSAGE;
			echo '<div class="notice notice-info cetech-de-scoped-transitional" role="status">';
			echo '<p><strong>' . esc_html( $title ) . '</strong></p>';
			echo '<p>' . esc_html( $text ) . '</p>';
			echo '</div>';
		}

		echo '<div class="notice notice-info cetech-de-preview-limitation" role="status">';
		echo '<p><strong>' . esc_html( ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE ) . '</strong></p>';
		echo '<p>' . esc_html( ScopedConfigurationNotices::PREVIEW_LIMITATION_MESSAGE ) . '</p>';
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

				$assessment = $this->readiness->assess( $product_id, $variation_id, $slice_key );
				$this->render_preview_results( $model, $assessment );
			}
		}

		AdminPageLayout::close_page();
	}

	private function render_preview_form(): void {
		AdminPageLayout::open_section(
			__( 'What do you want to preview?', 'cetech-woocommerce-delivery-engine' ),
			__( 'Choose a product, a variation if needed, and a delivery setup. Opening this preview never changes saved settings.', 'cetech-woocommerce-delivery-engine' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variation_id = isset( $_GET['variation_id'] ) ? absint( wp_unslash( $_GET['variation_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slice_key = isset( $_GET['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['slice_key'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parent_product_id = isset( $_GET['parent_product_id'] ) ? absint( wp_unslash( $_GET['parent_product_id'] ) ) : 0;

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-preview-form" data-cetech-de-preview-form>';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="preview" value="1" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="product_id">' . esc_html__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<select id="product_id" name="product_id" required data-cetech-de-preview-product>';
		echo '<option value="">' . esc_html__( 'Search/select product', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->catalog_products() as $id => $product ) {
			printf(
				'<option value="%1$s" %2$s data-variable="%3$s">%4$s</option>',
				esc_attr( (string) $id ),
				selected( $product_id, $id, false ),
				! empty( $product['variable'] ) ? '1' : '0',
				esc_html( (string) $product['label'] )
			);
		}
		echo '</select></td></tr>';
		echo '<tr class="cetech-de-preview-variation-row"' . ( $this->product_is_variable( $product_id ) ? '' : ' hidden' ) . '><th scope="row"><label for="variation_id">' . esc_html__( 'Variation', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<select id="variation_id" name="variation_id" data-cetech-de-preview-variation>';
		echo '<option value="">' . esc_html__( 'Select variation', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->catalog_variations( $product_id > 0 ? $product_id : $parent_product_id ) as $id => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $id ),
				selected( $variation_id, $id, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( $parent_product_id > 0 ) {
			echo '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $parent_product_id ) . '" />';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Show delivery settings', 'cetech-woocommerce-delivery-engine' ), 'primary', 'submit', false );
		echo '</form>';
		AdminPageLayout::close_section();
	}

	private function render_preview_results( EffectiveConfigurationPreviewViewModel $model, \CetechDeliveryEngine\Application\Configuration\OperationalReadiness $assessment ): void {
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

		$customized = 0;
		foreach ( $model->fields as $field ) {
			$provenance = strtolower( (string) ( $field['provenance_label'] ?? '' ) );
			if ( str_contains( $provenance, 'product-specific' ) || str_contains( $provenance, 'variation-specific' ) ) {
				++$customized;
			}
		}

		$is_ready = $assessment->is_ready;
		AdminPageLayout::render_status_banner(
			[
				'tone'  => $is_ready ? 'ready' : 'attention',
				'title' => $is_ready
					? __( 'Ready', 'cetech-woocommerce-delivery-engine' ) . ' ✓'
					: __( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ),
				'text'  => $is_ready
					? __( 'This product can use the delivery settings shown below.', 'cetech-woocommerce-delivery-engine' )
					: ( $assessment->reason ?? __( 'No usable delivery option is configured.', 'cetech-woocommerce-delivery-engine' ) ),
				'action_label' => $is_ready ? '' : __( 'Fix Product Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'action_url'   => $is_ready ? '' : $this->fix_url( $model ),
			]
		);

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Status', 'cetech-woocommerce-delivery-engine' ),
					'value' => $assessment->status_label,
				],
				[
					'label' => __( 'Settings source', 'cetech-woocommerce-delivery-engine' ),
					'value' => self::provenance_summary_label( $model->fields ),
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
			__( 'Delivery Preview', 'cetech-woocommerce-delivery-engine' ),
			__( 'Each row shows the result that will apply and whether it comes from the Site-wide Default, this product, or this variation.', 'cetech-woocommerce-delivery-engine' )
		);

		$primary_keys = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			ConfigurationFieldKey::FULFILMENT_CHOICE,
			ConfigurationFieldKey::DELIVERY_OFFER_IDS,
			ConfigurationFieldKey::ESTIMATED_DELIVERY,
		];
		$hidden_keys = [
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID,
			ConfigurationFieldKey::SUPPLIER_ID,
			ConfigurationFieldKey::ORIGIN_ID,
			ConfigurationFieldKey::PRIORITY,
		];

		if ( [] === $model->fields ) {
			echo '<p>' . esc_html__( 'No delivery settings are available for this product, variation, and delivery setup.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} else {
			echo '<table class="widefat striped cetech-de-preview-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Information', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Result', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Source', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $model->fields as $field ) {
				$key = (string) ( $field['field_key'] ?? '' );
				if ( in_array( $key, $hidden_keys, true ) || ( [] !== $primary_keys && ! in_array( $key, $primary_keys, true ) && '' !== $key ) ) {
					if ( ! in_array( $key, $primary_keys, true ) ) {
						continue;
					}
				}
				$field_ready = $this->readiness->assess_field_for(
					$model->product_id,
					$model->variation_id,
					$key !== '' ? $key : ConfigurationFieldKey::ESTIMATED_DELIVERY,
					$model->slice_key
				);
				echo '<tr>';
				echo '<th scope="row">' . esc_html( (string) $field['label'] ) . '</th>';
				if ( ! empty( $field['is_collection'] ) ) {
					$labels = $field['effective_member_labels'] ?? [];
					$display = [] === $labels
						? __( 'No delivery options for this setup', 'cetech-woocommerce-delivery-engine' )
						: implode( ', ', array_map( 'strval', $labels ) );
					if ( ! $field_ready->is_ready ) {
						$display = ( $field_ready->reason ?? $display ) . ( '' !== $display && $display !== ( $field_ready->reason ?? '' ) ? '' : '' );
						$display = $field_ready->reason ?? $display;
					}
					echo '<td>' . esc_html( $display );
					if ( ! $field_ready->is_ready ) {
						echo ' <span class="cetech-de-badge cetech-de-badge--attention">' . esc_html( OperationalReadiness::STATUS_NEEDS_ATTENTION ) . '</span>';
					}
					echo '</td>';
				} else {
					echo '<td>' . esc_html( (string) ( $field['effective_value_label'] ?? '—' ) ) . '</td>';
				}
				echo '<td>' . esc_html( self::source_label_for( (string) ( $field['provenance_label'] ?? '' ), null !== $model->variation_id ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<details class="cetech-de-technical-details"><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary><ul>';
		foreach ( $model->fields as $field ) {
			$key = (string) ( $field['field_key'] ?? $field['label'] ?? '' );
			if ( ! in_array( $key, $hidden_keys, true ) ) {
				continue;
			}
			echo '<li>' . esc_html( (string) $field['label'] ) . ': ';
			echo esc_html( (string) ( $field['effective_value_label'] ?? implode( ', ', $field['effective_member_labels'] ?? [] ) ?: '—' ) );
			echo '</li>';
		}
		foreach ( $model->version as $key => $value ) {
			printf(
				'<li><code>%s</code>: %s</li>',
				esc_html( (string) $key ),
				esc_html( is_scalar( $value ) ? (string) $value : '' )
			);
		}
		echo '</ul></details>';

		AdminPageLayout::close_section();
	}

	/**
	 * @return array<int, array{label: string, variable: bool}>
	 */
	private function catalog_products(): array {
		if ( null === $this->catalog ) {
			return [];
		}
		$out = [];
		foreach ( $this->catalog->published_product_ids( 0, 200 ) as $id ) {
			$out[ $id ] = [
				'label'    => $this->catalog->product_label( $id ),
				'variable' => $this->catalog->is_variable( $id ),
			];
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function catalog_variations( int $product_id ): array {
		if ( $product_id <= 0 || null === $this->catalog || ! $this->catalog->is_variable( $product_id ) ) {
			return [];
		}
		$out = [];
		foreach ( $this->catalog->variation_ids( $product_id ) as $id ) {
			$out[ $id ] = $this->catalog->variation_label( $id );
		}

		return $out;
	}

	private function product_is_variable( int $product_id ): bool {
		return $product_id > 0 && ( $this->catalog?->is_variable( $product_id ) ?? false );
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 */
	public static function provenance_summary_label( array $fields ): string {
		$buckets = [];
		foreach ( $fields as $field ) {
			$label = self::source_label_for( (string) ( $field['provenance_label'] ?? '' ), false );
			$buckets[ $label ] = true;
		}

		if ( [] === $buckets ) {
			return __( 'Site-wide Default', 'cetech-woocommerce-delivery-engine' );
		}

		if ( 1 === count( $buckets ) ) {
			return (string) array_key_first( $buckets );
		}

		return __( 'Mixed', 'cetech-woocommerce-delivery-engine' );
	}

	public static function source_label_for( string $provenance, bool $variation_context = false ): string {
		$normalized = strtolower( $provenance );
		if ( str_contains( $normalized, 'variation' ) ) {
			return __( 'Variation-specific', 'cetech-woocommerce-delivery-engine' );
		}
		if ( $variation_context ) {
			return __( 'Product Settings', 'cetech-woocommerce-delivery-engine' );
		}
		if ( str_contains( $normalized, 'product' ) ) {
			return __( 'Product-specific', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Site-wide Default', 'cetech-woocommerce-delivery-engine' );
	}

	private function source_label( string $provenance ): string {
		return self::source_label_for( $provenance, false );
	}

	private function fix_url( EffectiveConfigurationPreviewViewModel $model ): string {
		return add_query_arg(
			[
				'page'       => ScopedConfigurationPage::SLUG,
				'scope_type' => null !== $model->variation_id ? 'variation' : 'product',
				'scope_id'   => $model->variation_id ?? $model->product_id,
				'slice_key'  => $model->slice_key,
				'customize'  => 1,
			] + ( null !== $model->variation_id ? [ 'parent_product_id' => $model->product_id ] : [] ),
			admin_url( 'admin.php' )
		);
	}

	private function product_exists( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( isset( $this->catalog ) && in_array( $product_id, $this->catalog->published_product_ids( 0, 500 ), true ) ) {
			return true;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
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
