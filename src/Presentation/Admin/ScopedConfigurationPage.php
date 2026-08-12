<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Admin UX for Global / Product / Variation scoped configuration (Stage 4).
 *
 * Does not dual-write legacy product_delivery_rules. Does not affect customer runtime.
 */
final class ScopedConfigurationPage {

	public const SLUG = 'cetech-delivery-engine-scoped-config';

	private const ACTION_SAVE = 'cetech_de_save_scoped_configuration';

	public function __construct(
		private ScopedConfigurationAdminService $admin_service,
		private ProductTargetResolver $product_target_resolver,
		private AdminActionHandler $action_handler,
		private ScopedConfigurationAuthorization $authorization
	) {
	}

	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );
		if ( self::ACTION_SAVE !== $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope_type_raw = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope_type'] ) ) : 'global';
		$capability     = 'global' === $scope_type_raw
			? ScopedConfigurationAuthorization::CAPABILITY_GLOBAL
			: ScopedConfigurationAuthorization::CAPABILITY_PRODUCT;

		$auth_errors = $this->authorization->verify_write( $capability, self::ACTION_SAVE, true );
		if ( [] !== $auth_errors ) {
			$this->action_handler->notices()->flash_error( $auth_errors[0] );
			$this->action_handler->redirect( self::SLUG, $this->redirect_args_from_post() );
		}

		if ( ! $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, $capability, self::SLUG ) ) {
			return;
		}

		$this->handle_save( $scope_type_raw );
	}

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope_type_raw = isset( $_GET['scope_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['scope_type'] ) ) : 'global';
		$capability     = 'global' === $scope_type_raw
			? ScopedConfigurationAuthorization::CAPABILITY_GLOBAL
			: ScopedConfigurationAuthorization::CAPABILITY_PRODUCT;

		AdminPageAccess::require_capability( $capability );
		$this->action_handler->notices()->render_notices();

		$scope_type = ConfigurationScopeType::tryFrom( $scope_type_raw ) ?? ConfigurationScopeType::Global;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope_id = isset( $_GET['scope_id'] ) ? absint( wp_unslash( $_GET['scope_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slice_key = isset( $_GET['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['slice_key'] ) ) : ConfigurationScope::DEFAULT_SLICE_KEY;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parent_product_id = isset( $_GET['parent_product_id'] ) ? absint( wp_unslash( $_GET['parent_product_id'] ) ) : 0;
		$parent_product_id = $parent_product_id > 0 ? $parent_product_id : null;

		if ( ConfigurationScopeType::Global === $scope_type ) {
			$scope_id = 0;
			$slice_key = ConfigurationScope::DEFAULT_SLICE_KEY;
			$parent_product_id = null;
		}

		$product_label   = null;
		$variation_label = null;
		$category_ids    = [];

		if ( ConfigurationScopeType::Product === $scope_type && $scope_id > 0 ) {
			$product_label = $this->product_target_resolver->resolve_label( ProductTargetType::Product->value, $scope_id );
			$category_ids  = $this->resolve_product_category_ids( $scope_id );
		}

		if ( ConfigurationScopeType::Variation === $scope_type && $scope_id > 0 ) {
			$variation_label = $this->product_target_resolver->resolve_label( ProductTargetType::Variation->value, $scope_id );
			if ( null !== $parent_product_id ) {
				$product_label = $this->product_target_resolver->resolve_label( ProductTargetType::Product->value, $parent_product_id );
				$category_ids  = $this->resolve_product_category_ids( $parent_product_id );
			}
		}

		$model = $this->admin_service->load_edit_model(
			$scope_type,
			$scope_id,
			$slice_key,
			$parent_product_id,
			$product_label,
			$variation_label,
			$category_ids
		);

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Set default delivery settings, then optionally change them for a product or a variation. If you do not set a different value, the next level up is used.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Delivery Settings Preview', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( EffectiveConfigurationPreviewPage::SLUG ),
			],
			[
				'label' => __( 'Legacy Delivery Rules', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( ProductDeliveryRulesPage::SLUG ),
			]
		);

		$this->render_transitional_notice( $model->transitional_title, $model->transitional_message );
		$this->render_scope_switcher( $scope_type, $scope_id, $slice_key, $parent_product_id );

		if ( ConfigurationScopeType::Global !== $scope_type && $scope_id <= 0 ) {
			$this->render_target_picker( $scope_type );
			AdminPageLayout::close_page();
			return;
		}

		if ( ! empty( $model->category_warning['has_legacy_category_rules'] ) ) {
			AdminPageLayout::render_warning(
				$model->category_warning['warning_title'],
				$model->category_warning['warning_message']
			);
		}

		$this->render_context_summary( $model );
		$this->render_empty_state( $model );
		$this->render_editor_form( $model );
		AdminPageLayout::close_page();
	}

	private function handle_save( string $scope_type_raw ): void {
		$scope_type = ConfigurationScopeType::tryFrom( $scope_type_raw );
		if ( null === $scope_type ) {
			$this->action_handler->notices()->flash_error( __( 'Choose Default Settings, Product-Specific Settings, or Variation-Specific Settings.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope_id = isset( $_POST['scope_id'] ) ? absint( wp_unslash( $_POST['scope_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slice_key = isset( $_POST['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slice_key'] ) ) : ConfigurationScope::DEFAULT_SLICE_KEY;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parent_product_id = isset( $_POST['parent_product_id'] ) ? absint( wp_unslash( $_POST['parent_product_id'] ) ) : 0;
		$parent_product_id = $parent_product_id > 0 ? $parent_product_id : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$create_slice = isset( $_POST['create_slice'] ) && '1' === (string) wp_unslash( $_POST['create_slice'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_slice_key = isset( $_POST['new_slice_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['new_slice_key'] ) ) : '';

		if ( '' !== $new_slice_key && $create_slice ) {
			$slice_key = $new_slice_key;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_fields_input = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
		$raw_fields       = [];

		foreach ( $raw_fields_input as $field_key => $payload ) {
			$field_key = sanitize_key( (string) $field_key );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$mode = isset( $payload['mode'] ) ? sanitize_key( (string) $payload['mode'] ) : '';
			$entry = [ 'mode' => $mode ];

			if ( isset( $payload['value'] ) ) {
				$entry['value'] = sanitize_text_field( (string) $payload['value'] );
			}

			if ( isset( $payload['members'] ) ) {
				$members = $payload['members'];
				if ( is_string( $members ) ) {
					$members = preg_split( '/[\s,]+/', $members ) ?: [];
				}
				if ( ! is_array( $members ) ) {
					$members = [];
				}
				$entry['members'] = array_map( static fn ( $m ): string => sanitize_text_field( (string) $m ), $members );
			}

			$raw_fields[ $field_key ] = $entry;
		}

		$result = $this->admin_service->save(
			new ScopedConfigurationWriteCommand(
				$scope_type,
				$scope_id,
				$slice_key,
				$parent_product_id,
				$raw_fields,
				$create_slice || ConfigurationScopeType::Global === $scope_type || ConfigurationScope::DEFAULT_SLICE_KEY === $slice_key
			)
		);

		$redirect = [
			'scope_type' => $scope_type->value,
			'scope_id'   => $scope_id,
			'slice_key'  => $slice_key,
		];
		if ( null !== $parent_product_id ) {
			$redirect['parent_product_id'] = $parent_product_id;
		}

		if ( ! $result->success ) {
			$this->action_handler->notices()->flash_error(
				implode( ' ', $result->errors )
			);
			$this->action_handler->redirect( self::SLUG, $redirect );
		}

		if ( $result->version_changed ) {
			$this->action_handler->notices()->flash_success(
				sprintf(
					/* translators: %d: configuration version */
					__( 'Scoped configuration saved. Version is now %d.', 'cetech-woocommerce-delivery-engine' ),
					$result->version_after
				)
			);
		} else {
			$this->action_handler->notices()->flash_success(
				__( 'No semantic changes detected. Configuration version unchanged.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG, $redirect );
	}

	/**
	 * @return array<string, scalar>
	 */
	private function redirect_args_from_post(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$args = [
			'scope_type' => isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope_type'] ) ) : 'global',
			'scope_id'   => isset( $_POST['scope_id'] ) ? absint( wp_unslash( $_POST['scope_id'] ) ) : 0,
			'slice_key'  => isset( $_POST['slice_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slice_key'] ) ) : '',
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['parent_product_id'] ) ) {
			$args['parent_product_id'] = absint( wp_unslash( $_POST['parent_product_id'] ) );
		}

		return $args;
	}

	private function render_transitional_notice( string $title, string $message ): void {
		echo '<div class="notice notice-info cetech-de-scoped-transitional" role="status">';
		echo '<p><strong>' . esc_html( $title ) . '</strong></p>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '<p class="description">' . esc_html( ScopedConfigurationNotices::SCOPED_RUNTIME_LABEL ) . ' — ';
		echo esc_html( ScopedConfigurationNotices::LEGACY_RUNTIME_LABEL ) . '</p>';
		echo '</div>';
	}

	private function render_scope_switcher(
		ConfigurationScopeType $scope_type,
		int $scope_id,
		string $slice_key,
		?int $parent_product_id
	): void {
		AdminPageLayout::open_section(
			__( 'Which settings are you editing?', 'cetech-woocommerce-delivery-engine' ),
			__( 'Default Settings apply unless a product or variation sets a different value. Legacy Delivery Rules still control what shoppers see until the new system is turned on.', 'cetech-woocommerce-delivery-engine' )
		);

		$tabs = [
			'global'    => __( 'Default Settings', 'cetech-woocommerce-delivery-engine' ),
			'product'   => __( 'Product-Specific Settings', 'cetech-woocommerce-delivery-engine' ),
			'variation' => __( 'Variation-Specific Settings', 'cetech-woocommerce-delivery-engine' ),
		];

		echo '<nav class="cetech-de-scoped-tabs" aria-label="' . esc_attr__( 'Delivery settings levels', 'cetech-woocommerce-delivery-engine' ) . '">';
		echo '<ul class="cetech-de-scoped-tab-list">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg(
				[
					'page'       => self::SLUG,
					'scope_type' => $key,
				],
				admin_url( 'admin.php' )
			);
			$class = $scope_type->value === $key ? ' class="is-active"' : '';
			printf(
				'<li%s><a href="%s">%s</a></li>',
				$class, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_url( $url ),
				esc_html( $label )
			);
		}
		echo '</ul></nav>';
		AdminPageLayout::close_section();
	}

	private function render_target_picker( ConfigurationScopeType $scope_type ): void {
		AdminPageLayout::open_section(
			ConfigurationScopeType::Variation === $scope_type
				? __( 'Select parent product and variation', 'cetech-woocommerce-delivery-engine' )
				: __( 'Select a product', 'cetech-woocommerce-delivery-engine' ),
			__( 'Enter WooCommerce IDs. Variation must belong to the selected parent product.', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cetech-de-scoped-target-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="scope_type" value="' . esc_attr( $scope_type->value ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		if ( ConfigurationScopeType::Variation === $scope_type ) {
			echo '<tr><th scope="row"><label for="parent_product_id">' . esc_html__( 'Parent product ID', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
			echo '<td><input type="number" min="1" class="small-text" id="parent_product_id" name="parent_product_id" required /></td></tr>';
			echo '<tr><th scope="row"><label for="scope_id">' . esc_html__( 'Variation ID', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
			echo '<td><input type="number" min="1" class="small-text" id="scope_id" name="scope_id" required /></td></tr>';
		} else {
			echo '<tr><th scope="row"><label for="scope_id">' . esc_html__( 'Product ID', 'cetech-woocommerce-delivery-engine' ) . '</label></th>';
			echo '<td><input type="number" min="1" class="small-text" id="scope_id" name="scope_id" required /></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Load settings', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
		echo '</form>';
		AdminPageLayout::close_section();
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel $model
	 */
	private function render_context_summary( $model ): void {
		$stats = [
			[
				'label' => __( 'Settings level', 'cetech-woocommerce-delivery-engine' ),
				'value' => match ( $model->scope_type ) {
					'global' => __( 'Default Settings', 'cetech-woocommerce-delivery-engine' ),
					'product' => __( 'Product-Specific Settings', 'cetech-woocommerce-delivery-engine' ),
					'variation' => __( 'Variation-Specific Settings', 'cetech-woocommerce-delivery-engine' ),
					default => ucfirst( $model->scope_type ),
				},
			],
			[
				'label' => __( 'Delivery setup', 'cetech-woocommerce-delivery-engine' ),
				'value' => $model->slice_label,
			],
		];

		if ( $model->is_migrated && null !== $model->legacy_rule_id ) {
			$stats[] = [
				'label' => __( 'Migration', 'cetech-woocommerce-delivery-engine' ),
				'value' => sprintf(
					/* translators: %d: legacy rule id */
					__( 'Migrated from legacy rule #%d', 'cetech-woocommerce-delivery-engine' ),
					$model->legacy_rule_id
				),
			];
		}

		AdminPageLayout::render_summary_stats( $stats );

		if ( null !== $model->product_label || null !== $model->variation_label ) {
			echo '<p class="description">';
			if ( null !== $model->product_label ) {
				echo esc_html__( 'Product:', 'cetech-woocommerce-delivery-engine' ) . ' ' . esc_html( $model->product_label ) . ' ';
			}
			if ( null !== $model->variation_label ) {
				echo esc_html__( 'Variation:', 'cetech-woocommerce-delivery-engine' ) . ' ' . esc_html( $model->variation_label );
			}
			echo '</p>';
		}
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel $model
	 */
	private function render_empty_state( $model ): void {
		$message = '';

		if ( 'product' === $model->scope_type && 0 === $model->config_version ) {
			$message = AdminLanguage::empty_product_settings();
		} elseif ( 'variation' === $model->scope_type && 0 === $model->config_version ) {
			$message = AdminLanguage::empty_variation_settings();
		} elseif ( 'global' === $model->scope_type ) {
			$all_unconfigured = true;
			foreach ( $model->fields as $field ) {
				if ( 'Not configured' !== $field->configured_state_label ) {
					$all_unconfigured = false;
					break;
				}
			}
			if ( $all_unconfigured ) {
				$message = AdminLanguage::empty_default_settings();
			}
		}

		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice notice-info cetech-de-empty-state" role="status">';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel $model
	 */
	private function render_editor_form( $model ): void {
		AdminPageLayout::open_section(
			__( 'Delivery settings for this item', 'cetech-woocommerce-delivery-engine' ),
			__( 'Choose whether each setting uses the inherited value, a different value here, or is turned off. Leaving a setting unchanged keeps the value from Default Settings or the product.', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" class="cetech-de-scoped-editor">';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="scope_type" value="' . esc_attr( $model->scope_type ) . '" />';
		echo '<input type="hidden" name="scope_id" value="' . esc_attr( (string) $model->scope_id ) . '" />';
		if ( null !== $model->parent_product_id ) {
			echo '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $model->parent_product_id ) . '" />';
		}
		AdminFormHelper::nonce_field( self::ACTION_SAVE );

		$this->render_slice_controls( $model );

		foreach ( $model->fields as $field ) {
			$this->render_field_editor( $field );
		}

		echo '<details class="cetech-de-technical-details"><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
		echo '<ul>';
		foreach ( $model->technical_details as $key => $value ) {
			printf(
				'<li><code>%s</code>: %s</li>',
				esc_html( (string) $key ),
				esc_html( is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value ) )
			);
		}
		echo '</ul></details>';

		submit_button( __( 'Save delivery settings', 'cetech-woocommerce-delivery-engine' ) );
		echo '</form>';
		AdminPageLayout::close_section();
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel $model
	 */
	private function render_slice_controls( $model ): void {
		if ( 'global' === $model->scope_type ) {
			echo '<input type="hidden" name="slice_key" value="" />';
			echo '<p><strong>' . esc_html__( 'Delivery setup:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( $model->slice_label ) . '</p>';
			return;
		}

		echo '<fieldset class="cetech-de-slice-controls">';
		echo '<legend>' . esc_html__( 'Delivery setup', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<label for="slice_key">' . esc_html__( 'Delivery setup', 'cetech-woocommerce-delivery-engine' ) . '</label> ';
		echo '<select id="slice_key" name="slice_key">';
		foreach ( $model->available_slices as $slice ) {
			$label = $slice['label'];
			if ( ! empty( $slice['migrated'] ) && ! empty( $slice['legacy_rule_id'] ) ) {
				$label .= ' (' . sprintf(
					/* translators: %d: legacy rule id */
					__( 'migrated from legacy rule #%d', 'cetech-woocommerce-delivery-engine' ),
					(int) $slice['legacy_rule_id']
				) . ')';
			}
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slice['key'] ),
				selected( $model->slice_key, $slice['key'], false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		$availability = ConfigurationFieldCatalog::enum_options( \CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey::FULFILMENT_AVAILABILITY ) ?? [];
		echo '<p><label for="new_slice_key">' . esc_html__( 'Or add another delivery setup', 'cetech-woocommerce-delivery-engine' ) . '</label> ';
		echo '<select id="new_slice_key" name="new_slice_key"><option value="">' . esc_html__( '—', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $availability as $key => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label><input type="checkbox" name="create_slice" value="1" /> ' . esc_html__( 'Save using this additional delivery setup', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Choose the delivery setup you mean to edit before saving. Internal setup codes are listed under Technical details.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\FieldEditViewModel $field
	 */
	private function render_field_editor( $field ): void {
		$field_id = 'field_' . $field->field_key;
		echo '<div class="cetech-de-field-editor" data-field-key="' . esc_attr( $field->field_key ) . '" data-is-collection="' . ( $field->is_collection ? '1' : '0' ) . '">';
		echo '<h3 id="' . esc_attr( $field_id . '_title' ) . '">' . esc_html( $field->label ) . '</h3>';
		echo '<p class="description" id="' . esc_attr( $field_id . '_desc' ) . '">' . esc_html( $field->description ) . '</p>';
		echo '<p><span class="cetech-de-state cetech-de-state--' . esc_attr( $field->effective_state_tone ) . '">';
		echo esc_html( $field->configured_state_label );
		echo '</span>';
		if ( '' !== $field->effective_state_label ) {
			echo ' · ' . esc_html__( 'Will apply:', 'cetech-woocommerce-delivery-engine' ) . ' ';
			echo '<span class="cetech-de-state cetech-de-state--' . esc_attr( $field->effective_state_tone ) . '">' . esc_html( $field->effective_state_label ) . '</span>';
		}
		echo '</p>';

		echo '<fieldset aria-describedby="' . esc_attr( $field_id . '_desc' ) . '">';
		echo '<legend>' . esc_html__( 'How this setting is applied', 'cetech-woocommerce-delivery-engine' ) . '</legend>';

		$modes = $field->allowed_modes;
		if ( $field->is_global_root ) {
			array_unshift( $modes, 'not_configured' );
			$mode_labels = [ 'not_configured' => __( 'Not configured', 'cetech-woocommerce-delivery-engine' ) ] + $field->mode_labels;
		} else {
			$mode_labels = $field->mode_labels;
		}

		$selected_mode = '' === $field->current_mode && $field->is_global_root ? 'not_configured' : $field->current_mode;

		foreach ( $modes as $mode ) {
			$input_id = $field_id . '_mode_' . $mode;
			printf(
				'<label class="cetech-de-mode-option" for="%1$s"><input type="radio" id="%1$s" name="fields[%2$s][mode]" value="%3$s" %4$s data-mode="%3$s" /> %5$s</label> ',
				esc_attr( $input_id ),
				esc_attr( $field->field_key ),
				esc_attr( $mode ),
				checked( $selected_mode, $mode, false ),
				esc_html( $mode_labels[ $mode ] ?? $mode )
			);
		}
		echo '</fieldset>';

		if ( $field->is_collection ) {
			$this->render_collection_inputs( $field, $field_id );
		} else {
			$this->render_scalar_inputs( $field, $field_id );
		}

		if ( '' !== $field->provenance_label ) {
			echo '<p><strong>' . esc_html__( 'Currently using:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ' . esc_html( $field->provenance_label ) . '</p>';
		}
		if ( [] !== $field->provenance_lines ) {
			echo '<ul class="cetech-de-provenance-lines">';
			foreach ( $field->provenance_lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
		if ( [] !== $field->validation_messages ) {
			echo '<ul class="cetech-de-validation-messages" role="status">';
			foreach ( $field->validation_messages as $message ) {
				echo '<li>' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! $field->is_global_root ) {
			echo '<p class="description">' . esc_html__( 'If you do not set a different value here, this product uses the Default Settings. A variation uses the product’s delivery setting unless it has its own.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			if ( $field->is_collection ) {
				echo '<p><strong>' . esc_html__( 'Delivery options that will apply:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
				echo esc_html( [] === $field->effective_members ? __( 'No delivery options for this setup', 'cetech-woocommerce-delivery-engine' ) : implode( ', ', array_map( 'strval', $field->effective_members ) ) );
				echo '</p>';
			} else {
				echo '<p><strong>' . esc_html__( 'Value that will apply:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
				if ( 'disabled' === $field->effective_state ) {
					echo esc_html__( 'Turned off', 'cetech-woocommerce-delivery-engine' );
				} elseif ( null === $field->effective_value ) {
					echo esc_html__( 'Needs configuration', 'cetech-woocommerce-delivery-engine' );
				} else {
					echo esc_html( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' );
				}
				echo '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\FieldEditViewModel $field
	 */
	private function render_scalar_inputs( $field, string $field_id ): void {
		$value_id = $field_id . '_value';
		echo '<div class="cetech-de-mode-panel" data-show-for="override">';
		echo '<label for="' . esc_attr( $value_id ) . '">' . esc_html__( 'Value to use here', 'cetech-woocommerce-delivery-engine' ) . '</label> ';

		$current = is_scalar( $field->configured_value ) || null === $field->configured_value
			? (string) ( $field->configured_value ?? '' )
			: '';

		if ( is_array( $field->enum_options ) ) {
			echo '<select id="' . esc_attr( $value_id ) . '" name="fields[' . esc_attr( $field->field_key ) . '][value]" aria-describedby="' . esc_attr( $field_id . '_desc' ) . '">';
			echo '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
			foreach ( $field->enum_options as $value => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( (string) $value ),
					selected( $current, (string) $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		} elseif ( is_array( $field->selector_options ) ) {
			echo '<select id="' . esc_attr( $value_id ) . '" name="fields[' . esc_attr( $field->field_key ) . '][value]" class="cetech-de-entity-select" aria-describedby="' . esc_attr( $field_id . '_desc' ) . '">';
			echo '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
			foreach ( $field->selector_options as $id => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( (string) $id ),
					selected( $current, (string) $id, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="number" step="1" id="%1$s" name="fields[%2$s][value]" value="%3$s" aria-describedby="%4$s" />',
				esc_attr( $value_id ),
				esc_attr( $field->field_key ),
				esc_attr( $current ),
				esc_attr( $field_id . '_desc' )
			);
			echo '<p class="description">' . esc_html__( 'Zero is valid where the field allows it. Invalid numbers are rejected (never treated as free/zero shipping).', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param \CetechDeliveryEngine\Application\Configuration\Admin\FieldEditViewModel $field
	 */
	private function render_collection_inputs( $field, string $field_id ): void {
		echo '<div class="cetech-de-mode-panel" data-show-for="add,remove,replace">';
		echo '<p class="description">' . esc_html__( '“Add to inherited options” keeps the inherited list and adds more. “Remove from inherited options” keeps the inherited list minus the ones you select. “Use only these options” replaces the inherited list. An empty list means no delivery options for this setup.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

		$members_name = 'fields[' . $field->field_key . '][members][]';
		if ( is_array( $field->selector_options ) ) {
			echo '<fieldset><legend>' . esc_html__( 'Members', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			foreach ( $field->selector_options as $id => $label ) {
				$checked = in_array( (int) $id, array_map( 'intval', $field->configured_members ), true );
				printf(
					'<label style="display:block;"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
					esc_attr( $members_name ),
					esc_attr( (string) $id ),
					checked( $checked, true, false ),
					esc_html( $label )
				);
			}
			echo '</fieldset>';
		} else {
			$value = implode( ', ', array_map( 'strval', $field->configured_members ) );
			printf(
				'<label for="%1$s">%2$s</label> <input type="text" class="regular-text" id="%1$s" name="fields[%3$s][members]" value="%4$s" />',
				esc_attr( $field_id . '_members' ),
				esc_html__( 'Member IDs (comma-separated, order preserved)', 'cetech-woocommerce-delivery-engine' ),
				esc_attr( $field->field_key ),
				esc_attr( $value )
			);
		}
		echo '</div>';
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
