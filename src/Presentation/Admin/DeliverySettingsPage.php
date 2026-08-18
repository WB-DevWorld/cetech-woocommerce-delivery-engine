<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * Friendly delivery settings page for store administrators.
 */
final class DeliverySettingsPage {

	public const SLUG = 'cetech-delivery-engine-settings';

	private const ACTION_SAVE = 'cetech_de_save_delivery_settings';

	private const ACTION_SETUP_AGAIN = 'cetech_de_run_setup_guide_again';

	private const ACTION_ACTIVATE = 'cetech_de_activate_delivery_engine';

	/** @var list<string> */
	private const UNAVAILABLE_EXPERIMENTAL_FLAGS = [
		'enable_customer_timeline',
		'enable_bulk_import',
	];

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private RateCardRepositoryInterface $rate_card_repository,
		private ShippingRateCalculationGate $shipping_gate,
		private AdminActionHandler $action_handler,
		private ?SetupWizardProgress $wizard_progress = null,
		private ?ClassicCheckoutRuntimeActivation $runtime = null,
		private ?WooCommerceShippingReadiness $shipping_readiness = null,
		private ?SiteWideDefaultsSettings $defaults_settings = null,
		private ?OperationalStateService $operational_state = null,
		private ?RoleAccessService $role_access = null
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_delivery_settings', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_SETUP_AGAIN, self::ACTION_SETUP_AGAIN, 'manage_delivery_settings', self::SLUG ) ) {
			$this->handle_setup_again();
		}

		if ( $this->action_handler->verify_post( self::ACTION_ACTIVATE, self::ACTION_ACTIVATE, 'manage_delivery_settings', self::SLUG ) ) {
			$this->handle_activate();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_settings' );

		$this->action_handler->notices()->render_notices();

		$flags  = $this->feature_flags->all();
		$state  = $this->build_summary_state( $flags );
		$delete = (bool) (int) get_option( Uninstaller::DELETE_DATA_OPTION, 0 );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			__( 'Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Control how the Delivery Engine works across your store.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to Overview', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( AdminMenu::PARENT_SLUG ),
			]
		);

		$shipping_ready = $this->shipping_readiness?->is_ready() ?? false;
		$runtime_active = $this->runtime?->is_active() ?? false;
		$setup_complete = $this->defaults_settings?->is_setup_complete() ?? false;
		$op             = $this->operational_state?->current();
		$primary        = $this->defaults_settings?->primary_profile_key();
		$primary_label  = FulfilmentProfileRegistry::get( (string) $primary )?->label ?? __( 'Not chosen', 'cetech-woocommerce-delivery-engine' );
		$status_label   = $op?->settings_status_label
			?? ( $runtime_active
				? __( 'Active', 'cetech-woocommerce-delivery-engine' )
				: __( 'Not active', 'cetech-woocommerce-delivery-engine' ) );
		$status_empty   = $op
			? ! $op->customers_use_sitewide_runtime()
			: ! $runtime_active;

		AdminPageLayout::open_section(
			__( 'General', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Engine status and store setup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Delivery Engine status', 'cetech-woocommerce-delivery-engine' ),
					'value' => $status_label,
					'empty' => $status_empty,
				],
				[
					'label' => __( 'Primary fulfilment', 'cetech-woocommerce-delivery-engine' ),
					'value' => $primary_label,
				],
				[
					'label' => __( 'Setup', 'cetech-woocommerce-delivery-engine' ),
					'value' => $setup_complete
						? __( 'Complete', 'cetech-woocommerce-delivery-engine' )
						: __( 'In progress', 'cetech-woocommerce-delivery-engine' ),
					'empty' => ! $setup_complete,
				],
				[
					'label' => __( 'WooCommerce shipping', 'cetech-woocommerce-delivery-engine' ),
					'value' => $shipping_ready
						? __( 'Ready', 'cetech-woocommerce-delivery-engine' )
						: __( 'Action needed', 'cetech-woocommerce-delivery-engine' ),
					'empty' => ! $shipping_ready,
				],
			]
		);
		if ( null !== $op ) {
			echo '<p class="description">' . esc_html( $op->settings_status_detail ) . '</p>';
		}
		if ( ! $shipping_ready ) {
			AdminPageLayout::render_warning(
				__( 'WooCommerce shipping needs configuration', 'cetech-woocommerce-delivery-engine' ),
				$this->shipping_readiness?->explanation() ?? $state['checkout_warning'],
				__( 'Configure WooCommerce Shipping', 'cetech-woocommerce-delivery-engine' ),
				$this->shipping_readiness?->settings_url() ?? $this->woocommerce_shipping_settings_url()
			);
		}
		if ( $setup_complete && $shipping_ready && ! $runtime_active && null !== $this->runtime ) {
			echo '<form method="post" action="">';
			AdminFormHelper::nonce_field( self::ACTION_ACTIVATE );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_ACTIVATE ) . '" />';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate Delivery Engine', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
			echo '</form>';
		}
		AdminPageLayout::close_section();

		echo '<form id="cetech-de-settings-form" method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		AdminPageLayout::open_section(
			__( 'Customer experience', 'cetech-woocommerce-delivery-engine' ),
			__( 'Optional presentation choices for customers.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'What customers see', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
		echo '<p>' . esc_html__( 'Shown automatically whenever the Delivery Option or Site-wide Default contains an estimate.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</td></tr>';
		foreach ( $this->customer_experience_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		AdminPageLayout::open_section(
			__( 'Orders', 'cetech-woocommerce-delivery-engine' ),
			__( 'What staff and customers see after an order is placed.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'Order display', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->order_display_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		AdminPageLayout::open_section(
			__( 'Shipments', 'cetech-woocommerce-delivery-engine' ),
			__( 'Optional staff shipment records and customer tracking links. These stay off after an upgrade until you turn them on.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'Shipment records and tracking', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->shipment_release_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		echo '<tr><th scope="row"></th><td>';
		echo '<p class="description">' . esc_html__(
			'Customer Track shipment still requires shipment records to be on, a tracking link setting that is on, and a safe http or https tracking URL. Turning on tracking links alone does not create shipment records or change checkout.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '</td></tr>';
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		$this->render_access_section();

		AdminPageLayout::open_advanced(
			__( 'Advanced', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<p class="description">' . esc_html__(
			'Technical runtime controls. Normal stores do not need to change these. Completing setup activates the supported checkout chain automatically.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		if ( current_user_can( Capabilities::DIAGNOSTICS ) ) {
			echo '<p><a class="button button-secondary" href="' . esc_url( AdminPageRenderer::list_url( AdminMenu::SYSTEM_STATUS_SLUG ) ) . '">' . esc_html__( 'Open Technical Diagnostics', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
			echo '<p class="description">' . esc_html__( 'Support-only health and diagnostic details. Opening that screen does not change store configuration.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}

		AdminPageLayout::open_form_panel(
			__( 'Checkout runtime', 'cetech-woocommerce-delivery-engine' ),
			__( 'Low-level checkout stages. Leave these to the setup guide unless CETECH support asks you to change them.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->runtime_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags, true );
		}
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Integrations', 'cetech-woocommerce-delivery-engine' ),
			__( 'Optional adapters for themes, multilingual stores, and third-party plugins.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->integration_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags, true );
		}
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Experimental and future features', 'cetech-woocommerce-delivery-engine' ),
			__( 'Not required for basic delivery pricing at checkout.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->experimental_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags, true, ! empty( $setting['unavailable'] ) );
		}
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Maintenance', 'cetech-woocommerce-delivery-engine' ),
			__( 'Uninstall and data handling options.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::checkbox_field(
			'delete_data_on_uninstall',
			__( 'Delete plugin data when uninstalling', 'cetech-woocommerce-delivery-engine' ),
			$delete,
			__( 'When enabled, removing the plugin from WordPress will also remove Delivery Engine configuration tables and settings. Leave off unless you want a full clean uninstall.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td>';
		AdminPageLayout::open_technical_details();
		echo '<p class="description cetech-de-setting-code">' . esc_html(
			Uninstaller::DELETE_DATA_OPTION
		) . '</p>';
		AdminPageLayout::close_technical_details();
		echo '</td></tr>';
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( __( 'Save Changes', 'cetech-woocommerce-delivery-engine' ) );
		echo '</div></form>';

		AdminPageLayout::open_section(
			__( 'Setup Guide', 'cetech-woocommerce-delivery-engine' ),
			__( 'Review the guided setup without resetting your store or overwriting product exceptions.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SETUP_AGAIN );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SETUP_AGAIN ) . '" />';
		echo '<p><button type="submit" class="button">' . esc_html( AdminLanguage::run_setup_guide_again() ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'This reopens your current configuration for guided review. It does not treat the installation as new.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</form>';
		AdminPageLayout::close_section();

		$this->render_help_section();

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_flags = isset( $_POST['flags'] ) && is_array( $_POST['flags'] ) ? wp_unslash( $_POST['flags'] ) : [];

		foreach ( array_keys( $this->feature_flags->defaults() ) as $flag ) {
			if ( in_array( $flag, self::UNAVAILABLE_EXPERIMENTAL_FLAGS, true ) ) {
				continue;
			}
			$enabled = isset( $raw_flags[ $flag ] ) && '1' === (string) $raw_flags[ $flag ];
			$this->feature_flags->set( $flag, $enabled );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$delete_on_uninstall = isset( $_POST['delete_data_on_uninstall'] );
		update_option( Uninstaller::DELETE_DATA_OPTION, $delete_on_uninstall ? 1 : 0, false );

		if ( null !== $this->role_access && $this->role_access->can_edit() && isset( $_POST['access'] ) && is_array( $_POST['access'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->role_access->apply( wp_unslash( $_POST['access'] ) );
		}

		$this->action_handler->notices()->flash_success(
			__( 'Delivery settings saved.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_activate(): void {
		if ( null === $this->runtime || ! $this->runtime->can_activate() ) {
			$this->action_handler->notices()->flash_error( __( 'Finish delivery setup before activating the Delivery Engine for customers.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
			return;
		}

		if ( null !== $this->shipping_readiness && ! $this->shipping_readiness->is_ready() ) {
			$this->action_handler->notices()->flash_error( $this->shipping_readiness->explanation() );
			$this->action_handler->redirect( self::SLUG );
			return;
		}

		$this->runtime->activate();
		$this->action_handler->notices()->flash_success( __( 'Delivery Engine is active for Classic Checkout.', 'cetech-woocommerce-delivery-engine' ) );
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_setup_again(): void {
		if ( null !== $this->wizard_progress ) {
			$this->wizard_progress->begin_review();
		}

		$this->action_handler->notices()->flash_success(
			__( 'Setup guide opened with your current configuration. Product and variation exceptions stay protected.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->action_handler->redirect( SetupWizardPage::SLUG, [ 'step' => 1 ] );
	}

	/**
	 * @param array<string, bool> $flags
	 *
	 * @return array{
	 *     engine_status: string,
	 *     checkout_visibility: string,
	 *     checkout_ready: bool,
	 *     rate_calculation: string,
	 *     rates_ready: bool,
	 *     advanced_mode: string,
	 *     checkout_warning: string
	 * }
	 */
	private function build_summary_state( array $flags ): array {
		$woocommerce_active = $this->requirements->is_woocommerce_active();
		$checkout_ready     = $this->shipping_gate->is_runtime_active();
		$active_rate_cards  = $this->count_active_rate_cards();
		$rates_ready        = $flags[ ShippingRateCalculationGate::SHIPPING_FLAG ] && $active_rate_cards > 0;
		$advanced_on        = $this->count_advanced_flags_enabled( $flags ) > 0;

		$checkout_warning = __( 'Delivery fees will not appear at checkout until the everyday settings below are enabled in order and your rate cards are configured.', 'cetech-woocommerce-delivery-engine' );

		if ( $flags[ ShippingRateCalculationGate::SHIPPING_FLAG ] && ! $this->shipping_gate->is_upstream_ready() ) {
			$checkout_warning = __( 'Show delivery fees at checkout is on, but earlier steps in the delivery choice pipeline still need to be enabled.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! $flags[ ShippingRateCalculationGate::SHIPPING_FLAG ] ) {
			$checkout_warning = __( 'Turn on “Show delivery fees at checkout” below when you are ready for customers to see delivery pricing.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( 0 === $active_rate_cards ) {
			$checkout_warning = __( 'Add at least one active Delivery Charge so checkout has a delivery price to show.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! $woocommerce_active ) {
			$checkout_warning = __( 'WooCommerce must be active before delivery options can appear at checkout.', 'cetech-woocommerce-delivery-engine' );
		}

		return [
			'engine_status'       => $woocommerce_active
				? __( 'Active', 'cetech-woocommerce-delivery-engine' )
				: __( 'Not active', 'cetech-woocommerce-delivery-engine' ),
			'checkout_visibility' => $checkout_ready
				? __( 'Ready', 'cetech-woocommerce-delivery-engine' )
				: __( 'Not showing yet', 'cetech-woocommerce-delivery-engine' ),
			'checkout_ready'      => $checkout_ready,
			'rate_calculation'    => $rates_ready
				? __( 'Ready', 'cetech-woocommerce-delivery-engine' )
				: __( 'Needs setup', 'cetech-woocommerce-delivery-engine' ),
			'rates_ready'         => $rates_ready,
			'advanced_mode'       => $advanced_on
				? __( 'On', 'cetech-woocommerce-delivery-engine' )
				: __( 'Off', 'cetech-woocommerce-delivery-engine' ),
			'checkout_warning'    => $checkout_warning,
		];
	}

	private function count_active_rate_cards(): int {
		$count = 0;

		foreach ( $this->rate_card_repository->list( [ 'limit' => 500 ] ) as $rate_card ) {
			if ( RecordStatus::Active->value === (string) ( $rate_card['status'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param array<string, bool> $flags
	 */
	private function count_advanced_flags_enabled( array $flags ): int {
		$count = 0;

		foreach ( $this->advanced_flag_keys() as $flag ) {
			if ( ! empty( $flags[ $flag ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @return list<string>
	 */
	private function advanced_flag_keys(): array {
		$keys = [];

		foreach ( $this->runtime_settings() as $setting ) {
			$keys[] = $setting['flag'];
		}

		foreach ( $this->integration_settings() as $setting ) {
			$keys[] = $setting['flag'];
		}

		foreach ( $this->experimental_settings() as $setting ) {
			$keys[] = $setting['flag'];
		}

		return $keys;
	}

	/**
	 * @param array{flag: string, label: string, description: string, caution?: string, unavailable?: bool} $setting
	 * @param array<string, bool>                                                         $flags
	 */
	private function render_setting_checkbox( array $setting, array $flags, bool $show_technical_name = false, bool $unavailable = false ): void {
		$flag    = $setting['flag'];
		$name    = 'flags[' . $flag . ']';
		$checked = ! empty( $flags[ $flag ] );
		$disabled = $unavailable || ! empty( $setting['unavailable'] );

		echo '<tr><th scope="row"><label for="' . esc_attr( $flag ) . '">' . esc_html( $setting['label'] ) . '</label></th><td>';
		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s /></label>',
			esc_attr( $flag ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			disabled( $disabled, true, false )
		);
		echo '<p class="description">' . esc_html( $setting['description'] ) . '</p>';

		if ( $disabled ) {
			echo '<p class="description"><em>' . esc_html__( 'Unavailable in this release. Reserved for a future Delivery Engine version.', 'cetech-woocommerce-delivery-engine' ) . '</em></p>';
		}

		if ( ! empty( $setting['caution'] ) ) {
			echo '<p class="description" style="color:#996800;"><strong>' . esc_html__( 'Caution:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $setting['caution'] ) . '</p>';
		}

		if ( $show_technical_name ) {
			echo '<details class="cetech-de-technical-details"><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
			echo '<p class="description cetech-de-setting-code">' . esc_html( $flag ) . '</p>';
			echo '</details>';
		}

		echo '</td></tr>';
	}

	private function render_access_section(): void {
		$access = $this->role_access ?? new RoleAccessService();
		$roles  = $access->editable_roles();
		$can_edit = $access->can_edit();
		$matrix = $access->current_matrix();
		$permissions = RoleAccessService::permissions();

		AdminPageLayout::open_section(
			__( 'Access', 'cetech-woocommerce-delivery-engine' ),
			__( 'Administrators always retain full Delivery Engine access. Configure access for other WordPress roles below.', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<div class="cetech-de-access-protected-admin" data-cetech-de-access-protected-admin="1">';
		echo '<p class="cetech-de-access-protected-title"><span class="cetech-de-access-protected-role">' . esc_html__( 'Administrator', 'cetech-woocommerce-delivery-engine' ) . '</span> ';
		echo '<span class="cetech-de-access-protected-lock" aria-hidden="true">🔒</span></p>';
		echo '<p class="cetech-de-access-protected-status"><strong>' . esc_html__( 'Full Delivery Engine access', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'Administrators always retain full Delivery Engine access.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</div>';

		if ( [] === $roles ) {
			echo '<p class="description">' . esc_html__( 'No other WordPress roles are available to configure.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			AdminPageLayout::close_section();
			return;
		}

		if ( ! $can_edit ) {
			echo '<p class="description">' . esc_html__( 'Only a WordPress administrator can change these permissions.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}

		echo '<div class="cetech-de-access-table-wrap">';
		echo '<table class="widefat striped cetech-de-access-table" data-cetech-de-access-table>';
		echo '<thead><tr><th>' . esc_html__( 'Role', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		foreach ( $permissions as $permission ) {
			echo '<th>' . esc_html( $permission['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $roles as $role ) {
			$slug = $role['slug'];
			echo '<tr data-cetech-de-access-role="' . esc_attr( $slug ) . '">';
			echo '<th scope="row">' . esc_html( $role['name'] ) . '</th>';
			foreach ( $permissions as $permission ) {
				$checked  = ! empty( $matrix[ $slug ][ $permission['key'] ] );
				$disabled = ! $can_edit;
				$name     = 'access[' . $slug . '][' . $permission['key'] . ']';
				echo '<td>';
				printf(
					'<input type="checkbox" class="cetech-de-access-cap" name="%1$s" value="1" %2$s %3$s data-cetech-de-access-cap="%4$s" %5$s %6$s />',
					esc_attr( $name ),
					checked( $checked, true, false ),
					disabled( $disabled, true, false ),
					esc_attr( $permission['key'] ),
					$permission['implies_view'] ? 'data-cetech-de-implies-view="1"' : '',
					RoleAccessService::PERMISSION_VIEW === $permission['key'] ? 'data-cetech-de-access-view="1"' : ''
				);
				echo '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table></div>';
		AdminPageLayout::open_technical_details();
		echo '<p class="description">' . esc_html__( 'These checkboxes grant or revoke WordPress capabilities for subordinate roles only. Administrator capabilities are protected server-side. WordPress remains the authority for every Delivery Engine page and save action.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		AdminPageLayout::close_technical_details();
		AdminPageLayout::close_section();
	}

	private function render_help_section(): void {
		AdminPageLayout::open_section(
			__( 'Help', 'cetech-woocommerce-delivery-engine' ),
			__( 'If delivery rates are not showing at checkout, check these first.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<div class="cetech-de-help-card">';
		echo '<ol class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Complete the Setup Guide so site-wide defaults are saved.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one Delivery Area exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one Delivery Option exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one active Delivery Charge exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'In WooCommerce Shipping, add the CETECH Delivery shipping method to the shipping zone that serves your customers.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Activate Delivery Engine, then test with a customer address that matches a configured delivery area.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ol>';
		printf(
			'<p class="cetech-de-help-action"><a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( AdminPageRenderer::list_url( AdminMenu::PARENT_SLUG ) ),
			esc_html__( 'Back to Overview', 'cetech-woocommerce-delivery-engine' )
		);
		if ( $this->requirements->is_woocommerce_active() ) {
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a></p>',
				esc_url( $this->woocommerce_shipping_settings_url() ),
				esc_html__( 'Open WooCommerce Shipping', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			echo '</p>';
		}
		echo '</div>';
		AdminPageLayout::close_section();
	}

	/**
	 * @return list<array{flag: string, label: string, description: string, caution?: string}>
	 */
	private function customer_experience_settings(): array {
		return [
			[
				'flag'        => 'enable_customer_order_delivery_summary',
				'label'       => __( 'Show delivery summary on customer order pages', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Displays chosen delivery service details on the customer’s order view.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_customer_email_delivery_summary',
				'label'       => __( 'Include delivery summary in customer emails', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Adds delivery details to WooCommerce customer order emails.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	private function order_display_settings(): array {
		return [
			[
				'flag'        => 'enable_order_delivery_snapshot_persistence',
				'label'       => __( 'Save delivery details on orders', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Stores a read-only delivery snapshot on each order for staff reference.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	/**
	 * @return list<array{flag: string, label: string, description: string}>
	 */
	private function shipment_release_settings(): array {
		return [
			[
				'flag'        => 'enable_shipment_records',
				'label'       => __( 'Enable shipment records', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Turns on shipment creation and the staff Shipments workspace for eligible paid Delivery Engine orders.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_tracking_links',
				'label'       => __( 'Enable customer tracking links', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Allows customers to use Track shipment when a shipment has a valid tracking URL. This does not contact carriers or update tracking automatically.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	private function runtime_settings(): array {
		return array_merge(
			[
				[
					'flag'        => 'enable_product_delivery_selector',
					'label'       => __( 'Product-page delivery choices', 'cetech-woocommerce-delivery-engine' ),
					'description' => __( 'Lets shoppers choose a delivery service when adding a product to the cart.', 'cetech-woocommerce-delivery-engine' ),
				],
				[
					'flag'        => 'enable_cart_delivery_selection_capture',
					'label'       => __( 'Remember the customer’s delivery choice in the cart', 'cetech-woocommerce-delivery-engine' ),
					'description' => __( 'Keeps the selected delivery service attached to cart items through checkout.', 'cetech-woocommerce-delivery-engine' ),
				],
				[
					'flag'        => 'enable_checkout_delivery_selection_validation',
					'label'       => __( 'Validate delivery choice at checkout', 'cetech-woocommerce-delivery-engine' ),
					'description' => __( 'Checks that the customer selected a valid delivery service before checkout completes.', 'cetech-woocommerce-delivery-engine' ),
				],
				[
					'flag'        => ShippingRateCalculationGate::SHIPPING_FLAG,
					'label'       => __( 'Show delivery fees at checkout', 'cetech-woocommerce-delivery-engine' ),
					'description' => __( 'Calculates delivery pricing and shows it as a WooCommerce shipping rate.', 'cetech-woocommerce-delivery-engine' ),
				],
			],
			$this->checkout_settings()
		);
	}

	/**
	 * @return list<array{flag: string, label: string, description: string, caution?: string}>
	 */
	private function checkout_settings(): array {
		return [
			[
				'flag'        => 'enable_classic_checkout_adapter',
				'label'       => __( 'Support classic WooCommerce checkout', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Recommended for most stores using the standard checkout page.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_blocks_adapter',
				'label'       => __( 'Support WooCommerce Blocks checkout', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Turn on only if your store uses the block-based checkout experience.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Experimental. Test thoroughly before using on a live store.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	/**
	 * @return list<array{flag: string, label: string, description: string, caution?: string}>
	 */
	private function integration_settings(): array {
		return [
			[
				'flag'        => 'enable_wpml_adapter',
				'label'       => __( 'WPML integration', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Enable only when WPML is installed and CETECH support has confirmed compatibility.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_wcml_adapter',
				'label'       => __( 'WooCommerce Multilingual integration', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Enable only when WCML is installed and configured.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_woodmart_adapter',
				'label'       => __( 'Woodmart theme integration', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Optional adapter for Woodmart theme compatibility.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_wcfm_adapter',
				'label'       => __( 'WCFM Marketplace integration', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Enable only when WCFM Marketplace is in use.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_vitepos_adapter',
				'label'       => __( 'VitePOS integration', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Enable only when VitePOS is in use.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	/**
	 * @return list<array{flag: string, label: string, description: string, caution?: string}>
	 */
	private function experimental_settings(): array {
		return [
			[
				'flag'        => 'enable_effective_configuration_runtime',
				'label'       => __( 'Use Site-wide Defaults at checkout', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'When enabled, eligible products use Site-wide Defaults and Product Exceptions for live checkout.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_variable_product_ecr_runtime',
				'label'       => __( 'Use Site-wide Defaults for product variations', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Allows individual WooCommerce variations to inherit or override delivery settings. Requires Site-wide Defaults at checkout.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'         => 'enable_customer_timeline',
				'label'        => __( 'Customer delivery timeline (future feature)', 'cetech-woocommerce-delivery-engine' ),
				'description'  => __( 'Reserved for a future customer-facing tracking timeline. Not part of this release.', 'cetech-woocommerce-delivery-engine' ),
				'unavailable'  => true,
			],
			[
				'flag'         => 'enable_bulk_import',
				'label'        => __( 'Bulk import tools', 'cetech-woocommerce-delivery-engine' ),
				'description'  => __( 'Enables bulk import utilities when available.', 'cetech-woocommerce-delivery-engine' ),
				'unavailable'  => true,
			],
			[
				'flag'        => 'enable_category_rules',
				'label'       => __( 'Category-based product rules', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Allows product delivery rules to target product categories.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_site_fallback_rule',
				'label'       => __( 'Site-wide fallback product rule', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Uses a fallback rule when no product-specific rule matches.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'demo_data_on_activation',
				'label'       => __( 'Load demo data on plugin activation', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'For testing environments only. Do not enable on production stores.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Can create sample delivery areas, delivery options, and delivery charges automatically.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
	}

	private function woocommerce_shipping_settings_url(): string {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			return admin_url( 'plugins.php' );
		}

		return admin_url( 'admin.php?page=wc-settings&tab=shipping' );
	}
}
