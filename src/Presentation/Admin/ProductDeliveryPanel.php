<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use WC_Product;

/**
 * Simplified WooCommerce product/variation Delivery panel.
 */
final class ProductDeliveryPanel {

	public function __construct(
		private readonly ScopedConfigurationAdminService $admin_service,
		private readonly ProductTargetResolver $product_target_resolver
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_panel' ], 20, 3 );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		if ( AdminPageAccess::current_user_is_restricted() || ! current_user_can( 'manage_product_delivery_rules' ) ) {
			return $tabs;
		}

		$tabs['cetech_de_delivery'] = [
			'label'    => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
			'target'   => 'cetech_de_delivery_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 25,
		];

		return $tabs;
	}

	public function render_product_panel(): void {
		if ( AdminPageAccess::current_user_is_restricted() || ! current_user_can( 'manage_product_delivery_rules' ) ) {
			return;
		}

		global $post;
		$product_id = isset( $post->ID ) ? (int) $post->ID : 0;
		echo '<div id="cetech_de_delivery_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="cetech-de-product-panel">';
		if ( $product_id > 0 ) {
			$this->render_summary( ConfigurationScopeType::Product, $product_id, null );
		}
		echo '</div></div>';
	}

	/**
	 * @param int        $loop
	 * @param array<mixed> $variation_data
	 */
	public function render_variation_panel( int $loop, array $variation_data, \WP_Post $variation ): void {
		unset( $loop, $variation_data );
		if ( AdminPageAccess::current_user_is_restricted() || ! current_user_can( 'manage_product_delivery_rules' ) ) {
			return;
		}

		$parent_id = (int) $variation->post_parent;
		echo '<div class="cetech-de-product-panel">';
		$this->render_summary( ConfigurationScopeType::Variation, (int) $variation->ID, $parent_id > 0 ? $parent_id : null );
		echo '</div>';
	}

	private function render_summary( ConfigurationScopeType $scope_type, int $scope_id, ?int $parent_id ): void {
		$product_label = null;
		$variation_label = null;
		$category_ids = [];

		if ( ConfigurationScopeType::Product === $scope_type ) {
			$product_label = $this->product_target_resolver->resolve_label( ProductTargetType::Product->value, $scope_id );
			$category_ids  = $this->product_category_ids( $scope_id );
		} else {
			$variation_label = $this->product_target_resolver->resolve_label( ProductTargetType::Variation->value, $scope_id );
			if ( null !== $parent_id ) {
				$product_label = $this->product_target_resolver->resolve_label( ProductTargetType::Product->value, $parent_id );
				$category_ids  = $this->product_category_ids( $parent_id );
			}
		}

		$model = $this->admin_service->load_edit_model(
			$scope_type,
			$scope_id,
			ConfigurationScope::DEFAULT_SLICE_KEY,
			$parent_id,
			$product_label,
			$variation_label,
			$category_ids
		);

		$profile_label = $this->profile_label_from_model( $model );
		$customized    = $this->customized_fields( $model );
		$is_variation  = ConfigurationScopeType::Variation === $scope_type;
		$using         = $this->currently_using_label( $is_variation, [] !== $customized, $profile_label );

		echo '<h3>' . esc_html__( 'Delivery', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Currently using:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ' . esc_html( $using ) . '</p>';
		if ( [] !== $customized ) {
			echo '<p class="description">' . esc_html(
				$is_variation
					? __( 'Based on: Product Settings', 'cetech-woocommerce-delivery-engine' )
					: sprintf(
						/* translators: %s fulfilment type */
						__( 'Based on: %s Site-wide Default', 'cetech-woocommerce-delivery-engine' ),
						$profile_label
					)
			) . '</p>';
		}

		if ( [] === $customized ) {
			echo '<p>' . esc_html(
				$is_variation
					? __( 'This variation has no special delivery settings.', 'cetech-woocommerce-delivery-engine' )
					: __( 'This product has no special delivery settings.', 'cetech-woocommerce-delivery-engine' )
			) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d customized field count */
					_n( 'This item has %d customized setting.', 'This item has %d customized settings.', count( $customized ), 'cetech-woocommerce-delivery-engine' ),
					count( $customized )
				)
			) . '</p>';
		}

		echo '<dl class="cetech-de-definition-list">';
		foreach ( $this->summary_rows( $model ) as $row ) {
			echo '<dt>' . esc_html( $row['label'] ) . '</dt><dd>' . esc_html( $row['value'] ) . '</dd>';
		}
		echo '</dl>';

		$customize_url = add_query_arg(
			[
				'page'       => ScopedConfigurationPage::SLUG,
				'scope_type' => $scope_type->value,
				'scope_id'   => $scope_id,
				'slice_key'  => ConfigurationScope::DEFAULT_SLICE_KEY,
				'customize'  => 1,
			] + ( null !== $parent_id ? [ 'parent_product_id' => $parent_id ] : [] ),
			admin_url( 'admin.php' )
		);
		$preview_url = add_query_arg(
			[
				'page'       => EffectiveConfigurationPreviewPage::SLUG,
				'product_id' => $parent_id ?? $scope_id,
				'preview'    => '1',
			] + ( $is_variation ? [ 'variation_id' => $scope_id, 'parent_product_id' => $parent_id ] : [] ),
			admin_url( 'admin.php' )
		);

		echo '<p class="cetech-de-button-group">';
		echo '<a class="button" href="' . esc_url( $preview_url ) . '">' . esc_html__( 'Preview Delivery', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		echo '<a class="button button-primary" href="' . esc_url( $customize_url ) . '">' . esc_html(
			$is_variation ? AdminLanguage::customize_this_variation() : AdminLanguage::customize_this_product()
		) . '</a>';
		echo '</p>';

		if ( [] !== $customized ) {
			$confirm = $is_variation
				? __( 'Reset this variation to Product Settings? This does not change other variations.', 'cetech-woocommerce-delivery-engine' )
				: __( 'Reset this product to Site-wide Defaults? This does not change other products.', 'cetech-woocommerce-delivery-engine' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">';
			echo '<input type="hidden" name="page" value="' . esc_attr( ScopedConfigurationPage::SLUG ) . '" />';
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( ScopedConfigurationPage::ACTION_RESET ) . '" />';
			echo '<input type="hidden" name="scope_type" value="' . esc_attr( $scope_type->value ) . '" />';
			echo '<input type="hidden" name="scope_id" value="' . esc_attr( (string) $scope_id ) . '" />';
			echo '<input type="hidden" name="slice_key" value="' . esc_attr( ConfigurationScope::DEFAULT_SLICE_KEY ) . '" />';
			if ( null !== $parent_id ) {
				echo '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $parent_id ) . '" />';
			}
			AdminFormHelper::nonce_field( ScopedConfigurationPage::ACTION_RESET );
			echo '<p><button type="submit" class="button">';
			echo esc_html(
				$is_variation
					? __( 'Reset this variation to Product Settings', 'cetech-woocommerce-delivery-engine' )
					: __( 'Reset this product to Site-wide Defaults', 'cetech-woocommerce-delivery-engine' )
			);
			echo '</button></p></form>';
		}
	}

	/**
	 * @param object $model
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private function summary_rows( object $model ): array {
		$rows = [];
		foreach ( $model->fields as $field ) {
			if ( ! in_array( $field->field_key, [ 'fulfilment_availability', 'fulfilment_choice', 'delivery_offer_ids', 'estimated_delivery' ], true ) ) {
				continue;
			}
			$value = $this->display_value( $field );
			$rows[] = [
				'label' => (string) $field->label,
				'value' => '' !== $value ? $value : '—',
			];
		}

		return $rows;
	}

	public static function currently_using_label( bool $is_variation, bool $customized, string $profile_label ): string {
		if ( $customized ) {
			return $is_variation
				? __( 'Variation-specific delivery settings', 'cetech-woocommerce-delivery-engine' )
				: __( 'Product-specific delivery settings', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $is_variation ) {
			return __( 'Product Settings', 'cetech-woocommerce-delivery-engine' );
		}

		return sprintf(
			/* translators: %s fulfilment type */
			__( '%s Site-wide Default', 'cetech-woocommerce-delivery-engine' ),
			$profile_label
		);
	}

	/**
	 * @param object $model
	 *
	 * @return list<string>
	 */
	private function customized_fields( object $model ): array {
		$out      = [];
		$business = ConfigurationFieldCatalog::business_field_keys();
		foreach ( $model->fields as $field ) {
			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field->field_key ) {
				continue;
			}
			if ( ! in_array( $field->field_key, $business, true ) ) {
				continue;
			}
			if ( 'inherit' !== $field->current_mode && 'not_configured' !== $field->current_mode && 'Not configured' !== $field->configured_state_label ) {
				$out[] = (string) $field->label;
			}
		}

		return $out;
	}

	private function display_value( object $field ): string {
		if ( $field->is_collection && [] !== $field->effective_members && is_array( $field->selector_options ) ) {
			$names = [];
			foreach ( $field->effective_members as $id ) {
				$names[] = (string) ( $field->selector_options[ $id ] ?? $id );
			}

			return implode( ', ', $names );
		}

		$value = $field->effective_value ?? $field->configured_value;
		if ( is_array( $field->enum_options ) && is_scalar( $value ) && isset( $field->enum_options[ (string) $value ] ) ) {
			return (string) $field->enum_options[ (string) $value ];
		}

		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : '—';
	}

	private function profile_label_from_model( object $model ): string {
		foreach ( $model->fields as $field ) {
			if ( 'fulfilment_availability' !== $field->field_key ) {
				continue;
			}
			$value = is_string( $field->effective_value ) ? $field->effective_value : (string) $field->configured_value;
			$profile = FulfilmentProfileRegistry::get( $value );
			if ( null !== $profile ) {
				return $profile->label;
			}
		}

		return __( 'Site-wide', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @return list<int>
	 */
	private function product_category_ids( int $product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [];
		}

		return array_map( 'intval', $product->get_category_ids() );
	}
}
