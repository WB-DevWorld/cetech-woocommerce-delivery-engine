<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * Friendly delivery settings page for store administrators.
 */
final class DeliverySettingsPage {

	public const SLUG = 'cetech-delivery-engine-settings';

	private const ACTION_SAVE = 'cetech_de_save_delivery_settings';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private RateCardRepositoryInterface $rate_card_repository,
		private ShippingRateCalculationGate $shipping_gate,
		private AdminActionHandler $action_handler
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_delivery_settings', self::SLUG ) ) {
			$this->handle_save();
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
			__( 'Control checkout features and advanced switches. Product and variation delivery values are edited under Delivery Settings.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Save Settings', 'cetech-woocommerce-delivery-engine' ),
				'url'   => '#cetech-de-settings-form',
				'class' => 'primary',
			],
			[
				'label' => __( 'Back to Dashboard', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( AdminMenu::SYSTEM_STATUS_SLUG ),
			]
		);

		AdminPageLayout::open_section(
			__( 'Checkout readiness', 'cetech-woocommerce-delivery-engine' ),
			__( 'A quick summary of whether delivery is ready for customers.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Delivery Engine status', 'cetech-woocommerce-delivery-engine' ),
					'value' => $state['engine_status'],
				],
				[
					'label' => __( 'Checkout visibility', 'cetech-woocommerce-delivery-engine' ),
					'value' => $state['checkout_visibility'],
					'empty' => ! $state['checkout_ready'],
				],
				[
					'label' => __( 'Rate calculation', 'cetech-woocommerce-delivery-engine' ),
					'value' => $state['rate_calculation'],
					'empty' => ! $state['rates_ready'],
				],
				[
					'label' => __( 'Advanced mode', 'cetech-woocommerce-delivery-engine' ),
					'value' => $state['advanced_mode'],
				],
			]
		);
		AdminPageLayout::close_section();

		if ( ! $state['checkout_ready'] ) {
			AdminPageLayout::render_warning(
				__( 'Checkout delivery is not fully active', 'cetech-woocommerce-delivery-engine' ),
				$state['checkout_warning'],
				$this->woocommerce_shipping_settings_url() !== admin_url( 'plugins.php' )
					? __( 'Open WooCommerce Shipping', 'cetech-woocommerce-delivery-engine' )
					: null,
				$this->woocommerce_shipping_settings_url() !== admin_url( 'plugins.php' )
					? $this->woocommerce_shipping_settings_url()
					: null
			);
		}

		echo '<form id="cetech-de-settings-form" method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		AdminPageLayout::open_section(
			__( 'Everyday settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'These options are safe for everyday administrators. Turn features on step by step when you are ready to test checkout.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'Customer delivery choices', 'cetech-woocommerce-delivery-engine' ),
			__( 'Controls whether shoppers can pick a delivery service and see fees during checkout.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->everyday_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		AdminPageLayout::open_section(
			__( 'Checkout behavior', 'cetech-woocommerce-delivery-engine' ),
			__( 'How Delivery Engine connects to WooCommerce checkout and shipping.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'WooCommerce checkout support', 'cetech-woocommerce-delivery-engine' ),
			__( 'The Delivery Engine shipping method must also be enabled under WooCommerce → Settings → Shipping for customers to see delivery fees.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->checkout_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		echo '<tr><th scope="row">' . esc_html__( 'WooCommerce shipping', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
		if ( $this->requirements->is_woocommerce_active() ) {
			printf(
				'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
				esc_url( $this->woocommerce_shipping_settings_url() ),
				esc_html__( 'Open WooCommerce Shipping settings', 'cetech-woocommerce-delivery-engine' )
			);
			echo '<p class="description">' . esc_html__(
				'Confirm the CETECH Delivery Engine method is added to the shipping zone that serves your customers.',
				'cetech-woocommerce-delivery-engine'
			) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__(
				'WooCommerce must be active before delivery fees can appear at checkout.',
				'cetech-woocommerce-delivery-engine'
			) . '</p>';
		}
		echo '</td></tr>';
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		AdminPageLayout::open_section(
			__( 'Admin and customer follow-up', 'cetech-woocommerce-delivery-engine' ),
			__( 'Optional features for order records, customer order pages, and emails.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::open_form_panel(
			__( 'After checkout', 'cetech-woocommerce-delivery-engine' ),
			__( 'These settings do not change checkout pricing. They control what customers and staff see after an order is placed.', 'cetech-woocommerce-delivery-engine' )
		);
		foreach ( $this->follow_up_settings() as $setting ) {
			$this->render_setting_checkbox( $setting, $flags );
		}
		AdminPageLayout::close_form_panel();
		AdminPageLayout::close_section();

		AdminPageLayout::open_advanced(
			__( 'Advanced settings', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<p class="description">' . esc_html__(
			'Technical or experimental options. Change these only when you understand the impact, or when CETECH support asks you to.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

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
			$this->render_setting_checkbox( $setting, $flags, true );
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
		submit_button( __( 'Save Settings', 'cetech-woocommerce-delivery-engine' ) );
		echo '</div></form>';

		$this->render_help_section();

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_flags = isset( $_POST['flags'] ) && is_array( $_POST['flags'] ) ? wp_unslash( $_POST['flags'] ) : [];

		foreach ( array_keys( $this->feature_flags->defaults() ) as $flag ) {
			$enabled = isset( $raw_flags[ $flag ] ) && '1' === (string) $raw_flags[ $flag ];
			$this->feature_flags->set( $flag, $enabled );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$delete_on_uninstall = isset( $_POST['delete_data_on_uninstall'] );
		update_option( Uninstaller::DELETE_DATA_OPTION, $delete_on_uninstall ? 1 : 0, false );

		$this->action_handler->notices()->flash_success(
			__( 'Delivery settings saved.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->action_handler->redirect( self::SLUG );
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
			$checkout_warning = __( 'Add at least one active rate card so checkout has a delivery price to show.', 'cetech-woocommerce-delivery-engine' );
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

		foreach ( $this->integration_settings() as $setting ) {
			$keys[] = $setting['flag'];
		}

		foreach ( $this->experimental_settings() as $setting ) {
			$keys[] = $setting['flag'];
		}

		return $keys;
	}

	/**
	 * @param array{flag: string, label: string, description: string, caution?: string} $setting
	 * @param array<string, bool>                                                         $flags
	 */
	private function render_setting_checkbox( array $setting, array $flags, bool $show_technical_name = false ): void {
		$flag    = $setting['flag'];
		$name    = 'flags[' . $flag . ']';
		$checked = ! empty( $flags[ $flag ] );

		echo '<tr><th scope="row"><label for="' . esc_attr( $flag ) . '">' . esc_html( $setting['label'] ) . '</label></th><td>';
		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /></label>',
			esc_attr( $flag ),
			esc_attr( $name ),
			checked( $checked, true, false )
		);
		echo '<p class="description">' . esc_html( $setting['description'] ) . '</p>';

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

	private function render_help_section(): void {
		AdminPageLayout::open_section(
			__( 'Help', 'cetech-woocommerce-delivery-engine' ),
			__( 'If delivery rates are not showing at checkout, check these first.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<div class="cetech-de-help-card">';
		echo '<ol class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Confirm the everyday settings above are enabled in order (product page → cart → checkout validation → checkout fees).', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one Delivery Zone exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one Delivery Offer exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Make sure at least one active Rate Card exists.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'In WooCommerce Shipping, enable the CETECH Delivery Engine shipping method for the customer’s zone.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Test with a customer address that matches a configured delivery zone.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ol>';
		printf(
			'<p class="cetech-de-help-action"><a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( AdminPageRenderer::list_url( AdminMenu::SYSTEM_STATUS_SLUG ) ),
			esc_html__( 'Back to Dashboard', 'cetech-woocommerce-delivery-engine' )
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
	private function everyday_settings(): array {
		return [
			[
				'flag'        => 'enable_product_delivery_selector',
				'label'       => __( 'Show delivery choices on product pages', 'cetech-woocommerce-delivery-engine' ),
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
				'description' => __( 'Calculates delivery pricing from your rate cards and shows it as a WooCommerce shipping rate.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Enable earlier steps first, and confirm WooCommerce Shipping is configured.', 'cetech-woocommerce-delivery-engine' ),
			],
		];
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
	private function follow_up_settings(): array {
		return [
			[
				'flag'        => 'enable_order_delivery_snapshot_persistence',
				'label'       => __( 'Save delivery details on orders', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Stores a read-only delivery snapshot on each order for staff reference.', 'cetech-woocommerce-delivery-engine' ),
			],
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
				'label'       => __( 'Use the New Delivery Settings System', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'When enabled, eligible products use the new inherited delivery settings instead of the legacy product-rule system.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_variable_product_ecr_runtime',
				'label'       => __( 'Use New Delivery Settings for Product Variations', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Allows individual WooCommerce variations to inherit or override delivery settings. Requires the New Delivery Settings System.', 'cetech-woocommerce-delivery-engine' ),
				'caution'     => __( 'Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_shipment_records',
				'label'       => __( 'Shipment records (future feature)', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Reserved for future shipment tracking features. Not required for checkout pricing.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_customer_timeline',
				'label'       => __( 'Customer delivery timeline (future feature)', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Reserved for future customer-facing tracking timelines.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_tracking_links',
				'label'       => __( 'Carrier tracking links (future feature)', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Reserved for future carrier tracking link support.', 'cetech-woocommerce-delivery-engine' ),
			],
			[
				'flag'        => 'enable_bulk_import',
				'label'       => __( 'Bulk import tools', 'cetech-woocommerce-delivery-engine' ),
				'description' => __( 'Enables bulk import utilities when available.', 'cetech-woocommerce-delivery-engine' ),
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
				'caution'     => __( 'Can create sample zones, offers, and rate cards automatically.', 'cetech-woocommerce-delivery-engine' ),
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
