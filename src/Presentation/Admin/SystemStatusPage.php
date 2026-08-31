<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkQueueHealth;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationDiagnostic;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\FeaturesCompatibility;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;

/**
 * Read-only system status page with capability re-sync action.
 */
final class SystemStatusPage {

	private const RESYNC_ACTION = 'cetech_de_resync_capabilities';

	private const RESYNC_NOTICE_KEY = 'cetech_de_caps_synced';

	public function __construct(
		private Requirements $requirements,
		private FeatureFlags $feature_flags,
		private IntegrationRegistry $integration_registry,
		private Capabilities $capabilities,
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private DestinationZoneRepositoryInterface $destination_zone_repository,
		private DestinationRuleRepositoryInterface $destination_rule_repository,
		private PickupLocationRepositoryInterface $pickup_location_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private RateCardRepositoryInterface $rate_card_repository,
		private ProductDeliveryRuleRepositoryInterface $product_rule_repository,
		private ConfigurationHealthChecker $configuration_health_checker,
		private ?BulkQueueHealth $bulk_queue_health = null
	) {
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( \CetechDeliveryEngine\Core\Capabilities\Capabilities::DIAGNOSTICS ) ) {
			return;
		}

		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );

		if ( self::RESYNC_ACTION !== $action ) {
			return;
		}

		check_admin_referer( self::RESYNC_ACTION, 'cetech_de_nonce' );

		$this->capabilities->sync();

		$redirect = add_query_arg(
			[
				'page'                    => AdminMenu::SYSTEM_STATUS_SLUG,
				self::RESYNC_NOTICE_KEY   => '1',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function render(): void {
		AdminPageAccess::require_capability( \CetechDeliveryEngine\Core\Capabilities\Capabilities::DIAGNOSTICS );

		$this->render_admin_notices();

		$integrations = $this->integration_registry->get_detection_statuses();
		$display_keys = [ 'woodmart', 'wpml', 'wcml', 'wcfm', 'vitepos', 'wc_blocks' ];

		echo '<div class="wrap cetech-de-admin-page">';
		$this->render_admin_ui_styles();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			AdminLanguage::technical_diagnostic_tools(),
			AdminLanguage::technical_diagnostic_tools_intro()
		);

		$report = $this->diagnostic_report_text();
		echo '<p><button type="button" class="button button-secondary" id="cetech-de-copy-diagnostic" data-copied="' . esc_attr__( 'Copied', 'cetech-woocommerce-delivery-engine' ) . '">' . esc_html__( 'Copy Diagnostic Report', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '<textarea id="cetech-de-diagnostic-report" class="large-text" rows="8" readonly hidden>' . esc_textarea( $report ) . '</textarea>';

		echo '<details id="cetech-de-advanced-details" class="cetech-de-advanced">';
		echo '<summary>' . esc_html__( 'Advanced system details', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
		echo '<p class="description">' . esc_html__(
			'Support-only technical details. Opening this screen does not change store configuration.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		$this->render_configuration_warnings();

		$this->render_table(
			__( 'Environment', 'cetech-woocommerce-delivery-engine' ),
			[
				__( 'Plugin version', 'cetech-woocommerce-delivery-engine' ) => CETECH_DE_VERSION,
				__( 'PHP version', 'cetech-woocommerce-delivery-engine' )    => PHP_VERSION,
				__( 'WordPress version', 'cetech-woocommerce-delivery-engine' ) => get_bloginfo( 'version' ),
				__( 'WooCommerce active', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $this->requirements->is_woocommerce_active() ),
				__( 'WooCommerce version', 'cetech-woocommerce-delivery-engine' ) => $this->woocommerce_version(),
				__( 'HPOS compatibility declaration attempted', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( FeaturesCompatibility::hpos_declaration_attempted() ),
				__( 'Cart and Checkout Blocks compatibility declaration attempted', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( FeaturesCompatibility::blocks_declaration_attempted() ),
				__( 'Schema up to date', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( SchemaVersion::is_up_to_date() ),
				__( 'Target schema version', 'cetech-woocommerce-delivery-engine' ) => SchemaVersion::target(),
				__( 'Installed schema version', 'cetech-woocommerce-delivery-engine' ) => SchemaVersion::get(),
				__( 'Configuration tables present', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( ConfigurationTables::all_exist() ),
				__( 'Missing configuration tables', 'cetech-woocommerce-delivery-engine' ) => $this->format_missing_tables(),
				__( 'Last migration status', 'cetech-woocommerce-delivery-engine' ) => $this->format_migration_status(),
				__( 'Composer autoload present', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( is_readable( CETECH_DE_PATH . 'vendor/autoload.php' ) ),
			]
		);

		$this->render_table(
			__( 'Configuration records', 'cetech-woocommerce-delivery-engine' ),
			[
				__( 'Logistics profiles', 'cetech-woocommerce-delivery-engine' ) => (string) $this->logistics_profile_repository->count_all(),
				__( 'Delivery offers', 'cetech-woocommerce-delivery-engine' ) => (string) $this->delivery_offer_repository->count_all(),
				__( 'Destination zones', 'cetech-woocommerce-delivery-engine' ) => (string) $this->destination_zone_repository->count_all(),
				__( 'Destination rules', 'cetech-woocommerce-delivery-engine' ) => (string) $this->destination_rule_repository->count_all(),
				__( 'Pickup locations', 'cetech-woocommerce-delivery-engine' ) => (string) $this->pickup_location_repository->count_all(),
				__( 'Suppliers', 'cetech-woocommerce-delivery-engine' ) => (string) $this->supplier_repository->count_all(),
				__( 'Origins', 'cetech-woocommerce-delivery-engine' ) => (string) $this->origin_repository->count_all(),
				__( 'Rate cards', 'cetech-woocommerce-delivery-engine' ) => (string) $this->rate_card_repository->count_all(),
				__( 'Product delivery rules', 'cetech-woocommerce-delivery-engine' ) => (string) $this->product_rule_repository->count_all(),
			]
		);

		$capture_enabled = $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' );
		$selector_enabled = $this->feature_flags->is_enabled( 'enable_product_delivery_selector' );
		$checkout_validation_enabled = $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' );
		$shipping_calculation_enabled = $this->feature_flags->is_enabled( ShippingRateCalculationGate::SHIPPING_FLAG );
		$capture_active   = $capture_enabled && $selector_enabled;
		$checkout_validation_active = $checkout_validation_enabled && $capture_active;
		$shipping_runtime_active = $shipping_calculation_enabled && $checkout_validation_active;
		$snapshot_flag_enabled   = $this->feature_flags->is_enabled( OrderDeliverySnapshotGate::SNAPSHOT_FLAG );
		$snapshot_runtime_active = $snapshot_flag_enabled && $shipping_runtime_active;
		$customer_summary_enabled = $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG );
		$email_summary_enabled    = $this->feature_flags->is_enabled( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG );

		$this->render_table(
			__( 'Runtime readiness (admin/test only)', 'cetech-woocommerce-delivery-engine' ),
			[
				__( 'Product rule resolver registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( ProductDeliveryRuleResolver::class ) ),
				__( 'Resolver contract version', 'cetech-woocommerce-delivery-engine' ) => ProductRuleResolutionResult::CONTRACT_VERSION,
				__( 'Legacy rules admin check location', 'cetech-woocommerce-delivery-engine' ) => __( 'Internal compatibility reader only. Not shown in the normal Delivery Engine menu.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Product delivery selector flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $selector_enabled ),
				__( 'Cart delivery selection capture flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $capture_enabled ),
				__( 'Product delivery selector renderer', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( ProductDeliverySelectorRenderer::class ) ),
				__( 'Selector option contract version', 'cetech-woocommerce-delivery-engine' ) => ProductDeliveryOption::CONTRACT_VERSION,
				__( 'Selector options builder registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( ProductDeliveryOptionsBuilder::class ) ),
				__( 'Add-to-cart capture registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $capture_active && class_exists( CartDeliverySelectionCapture::class ) ),
				__( 'Cart session restore hook', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $capture_active && class_exists( CartDeliverySelectionCapture::class ) ),
				__( 'Cart selection revalidator registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $capture_active && class_exists( CartDeliverySelectionRevalidator::class ) ),
				__( 'Cart selection revalidation mode', 'cetech-woocommerce-delivery-engine' ) => $capture_active
					? __( 'Cart page warnings only; no checkout blocking', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Checkout delivery validation flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $checkout_validation_enabled ),
				__( 'Checkout validation registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $checkout_validation_active && class_exists( CheckoutDeliverySelectionValidator::class ) ),
				__( 'Checkout validation mode', 'cetech-woocommerce-delivery-engine' ) => $checkout_validation_active
					? __( 'Preflight validation only; no shipping calculation', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Checkout delivery selector', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Selection validator registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( ProductDeliverySelectionValidator::class ) ),
				__( 'Selection intent contract version', 'cetech-woocommerce-delivery-engine' ) => ProductDeliverySelectionIntent::CONTRACT_VERSION,
				__( 'Cart item selection persistence', 'cetech-woocommerce-delivery-engine' ) => $capture_active
					? __( 'Enabled (cart item data only; no checkout/order)', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add-to-cart validation', 'cetech-woocommerce-delivery-engine' ) => $capture_active
					? __( 'Enabled when capture flag active', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Rate quote engine registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( RateQuoteEngine::class ) ),
				__( 'Rate quote mode', 'cetech-woocommerce-delivery-engine' ) => $this->describe_rate_quote_mode( $shipping_runtime_active ),
				__( 'Rate quote admin check location', 'cetech-woocommerce-delivery-engine' ) => __( 'Delivery Engine → Technical Diagnostic Tools → Technical details', 'cetech-woocommerce-delivery-engine' ),
				__( 'WooCommerce selected-offer shipping flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $shipping_calculation_enabled ),
				__( 'Selected-offer shipping method registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( function_exists( 'WC' ) && class_exists( SelectedOfferShippingMethod::class ) ),
				__( 'Shipping method ID', 'cetech-woocommerce-delivery-engine' ) => SelectedOfferShippingMethod::METHOD_ID,
				__( 'Shipping calculation mode', 'cetech-woocommerce-delivery-engine' ) => $this->describe_shipping_calculation_mode( $shipping_calculation_enabled, $shipping_runtime_active ),
				__( 'Destination zone resolver', 'cetech-woocommerce-delivery-engine' ) => $this->describe_destination_resolver(),
				__( 'WooCommerce shipping method', 'cetech-woocommerce-delivery-engine' ) => $this->describe_shipping_method_registration( $shipping_runtime_active ),
				__( 'Cart/checkout totals', 'cetech-woocommerce-delivery-engine' ) => $this->describe_cart_checkout_totals( $shipping_runtime_active ),
				__( 'Missing quote behavior', 'cetech-woocommerce-delivery-engine' ) => __( 'No rate / no free fallback', 'cetech-woocommerce-delivery-engine' ),
				__( 'Order delivery snapshot flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $snapshot_flag_enabled ),
				__( 'Order snapshot persistence registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $snapshot_runtime_active && class_exists( OrderDeliverySnapshotPersister::class ) ),
				__( 'Snapshot mode', 'cetech-woocommerce-delivery-engine' ) => $this->describe_snapshot_mode( $snapshot_flag_enabled, $snapshot_runtime_active ),
				__( 'Order snapshot', 'cetech-woocommerce-delivery-engine' ) => $this->describe_order_snapshot_status( $snapshot_runtime_active ),
				__( 'Order snapshot admin display', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( OrderDeliverySnapshotAdminDisplay::class ) && function_exists( 'WC' ) ),
				__( 'Snapshot reader registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( OrderDeliverySnapshotReader::class ) ),
				__( 'Snapshot integrity checker registered', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( class_exists( OrderDeliverySnapshotIntegrity::class ) ),
				__( 'Snapshot display mode', 'cetech-woocommerce-delivery-engine' ) => __( 'Read-only admin only', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customer order delivery summary flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $customer_summary_enabled ),
				__( 'Customer order delivery summary renderer', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $customer_summary_enabled && class_exists( CustomerOrderDeliverySummaryRenderer::class ) && function_exists( 'WC' ) ),
				__( 'Customer summary mode', 'cetech-woocommerce-delivery-engine' ) => $customer_summary_enabled
					? __( 'Read-only snapshot display', 'cetech-woocommerce-delivery-engine' )
					: __( 'Disabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customer email delivery summary flag', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $email_summary_enabled ),
				__( 'Customer email delivery summary renderer', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( $email_summary_enabled && class_exists( CustomerOrderDeliveryEmailSummaryRenderer::class ) && function_exists( 'WC' ) ),
				__( 'Email summary mode', 'cetech-woocommerce-delivery-engine' ) => $email_summary_enabled
					? __( 'Read-only snapshot display', 'cetech-woocommerce-delivery-engine' )
					: __( 'Disabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Shipment tracking in emails', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Carrier email updates', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Email delivery summary', 'cetech-woocommerce-delivery-engine' ) => $email_summary_enabled
					? __( 'Enabled (customer emails only)', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Shipment tracking timeline', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Public shipment page', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Shipment creation', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Shipping calculation', 'cetech-woocommerce-delivery-engine' ) => $shipping_runtime_active
					? __( 'Enabled (WooCommerce shipping rates only)', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Order delivery persistence', 'cetech-woocommerce-delivery-engine' ) => __( 'Not enabled', 'cetech-woocommerce-delivery-engine' ),
				__( 'Selector storefront output', 'cetech-woocommerce-delivery-engine' ) => $this->describe_selector_storefront_output( $selector_enabled, $capture_active ),
			]
		);

		$this->render_configuration_health();
		$this->render_bulk_queue_health();

		$flag_rows = [];

		echo '<h2>' . esc_html__( 'Feature flags', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Feature flags are managed on the Delivery Settings page. The table below is read-only for support and troubleshooting.',
			'cetech-woocommerce-delivery-engine'
		) . ' ';
		printf(
			'<a href="%1$s">%2$s</a></p>',
			esc_url( AdminPageRenderer::list_url( DeliverySettingsPage::SLUG ) ),
			esc_html__( 'Open Delivery Settings', 'cetech-woocommerce-delivery-engine' )
		);

		foreach ( $this->feature_flags->all() as $flag => $enabled ) {
			$flag_rows[ FeatureFlagLabels::label( $flag ) ] = $this->yes_no( $enabled );
		}

		$this->render_table( __( 'Feature flags (read-only)', 'cetech-woocommerce-delivery-engine' ), $flag_rows );

		$integration_rows = [];

		foreach ( $display_keys as $key ) {
			$integration_rows[ $key ] = $this->yes_no( ! empty( $integrations[ $key ] ) );
		}

		$this->render_table( __( 'Optional integrations (detected)', 'cetech-woocommerce-delivery-engine' ), $integration_rows );

		$capability_rows = [];

		foreach ( Capabilities::ALL as $capability ) {
			$capability_rows[ $capability ] = $this->yes_no( current_user_can( $capability ) );
		}

		$this->render_table( __( 'Current user delivery capabilities', 'cetech-woocommerce-delivery-engine' ), $capability_rows );

		$this->render_resync_form();

		echo '</details>';
		echo '</div>';
	}

	private function operations_dashboard(): AdminOperationsDashboard {
		return new AdminOperationsDashboard(
			$this->requirements,
			$this->feature_flags,
			$this->destination_zone_repository,
			$this->delivery_offer_repository,
			$this->rate_card_repository,
			$this->configuration_health_checker,
			new ShippingRateCalculationGate( $this->feature_flags, $this->requirements )
		);
	}

	/**
	 * @param array<string, string> $rows
	 */
	private function render_admin_ui_styles(): void {
		echo '<style>
			.cetech-de-severity-error { color: #b32d2e; font-weight: 600; }
			.cetech-de-severity-warning { color: #996800; font-weight: 600; }
			.cetech-de-severity-info { color: #2271b1; font-weight: 600; }
			.cetech-de-severity-ok { color: #007017; font-weight: 600; }
			.cetech-de-status-active { color: #007017; font-weight: 600; }
			.cetech-de-status-inactive { color: #646970; }
		</style>';
	}

	private function render_configuration_health(): void {
		$result     = $this->configuration_health_checker->run();
		$summary    = $result['summary'];
		$diagnostics = $result['diagnostics'];

		echo '<h2>' . esc_html__( 'Configuration Health', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Read-only diagnostics for configuration records. Does not modify data or expose customer information.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		$this->render_table(
			__( 'Diagnostics summary', 'cetech-woocommerce-delivery-engine' ),
			[
				__( 'Errors', 'cetech-woocommerce-delivery-engine' )   => (string) ( $summary['error'] ?? 0 ),
				__( 'Warnings', 'cetech-woocommerce-delivery-engine' ) => (string) ( $summary['warning'] ?? 0 ),
				__( 'Info', 'cetech-woocommerce-delivery-engine' )     => (string) ( $summary['info'] ?? 0 ),
			]
		);

		if ( 0 === ( $summary['error'] ?? 0 ) && 0 === ( $summary['warning'] ?? 0 ) ) {
			echo '<div class="notice notice-success inline"><p>';
			echo esc_html__( 'No critical configuration issues found.', 'cetech-woocommerce-delivery-engine' );
			echo '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Severity', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Title', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Message', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Related entity', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( [] === $diagnostics ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No diagnostics to display.', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		} else {
			foreach ( $diagnostics as $diagnostic ) {
				if ( ! $diagnostic instanceof ConfigurationDiagnostic ) {
					continue;
				}

				echo '<tr>';
				echo '<td>' . wp_kses_post( AdminUiHelper::diagnostic_severity_badge( $diagnostic->severity ) ) . '</td>';
				echo '<td>' . esc_html( $diagnostic->title ) . '</td>';
				echo '<td>' . esc_html( $diagnostic->message ) . '</td>';
				echo '<td>' . esc_html( $this->format_related_entity( $diagnostic ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	private function format_related_entity( ConfigurationDiagnostic $diagnostic ): string {
		if ( null === $diagnostic->entity_type ) {
			return '—';
		}

		$parts = [ $diagnostic->entity_type ];

		if ( null !== $diagnostic->entity_id && $diagnostic->entity_id > 0 ) {
			$parts[] = 'ID ' . (string) $diagnostic->entity_id;
		}

		if ( null !== $diagnostic->details && '' !== trim( $diagnostic->details ) ) {
			$parts[] = $diagnostic->details;
		}

		return implode( ' · ', $parts );
	}

	private function render_bulk_queue_health(): void {
		if ( ! $this->bulk_queue_health instanceof BulkQueueHealth ) {
			return;
		}

		$health = $this->bulk_queue_health->snapshot();
		$scheduler = (string) ( $health['scheduler_health'] ?? 'unknown' );
		$scheduler_label = match ( $scheduler ) {
			'unavailable' => __( 'Unavailable', 'cetech-woocommerce-delivery-engine' ),
			'not_running' => __( 'Apparently not running', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Available', 'cetech-woocommerce-delivery-engine' ),
		};

		$this->render_table(
			__( 'Bulk background processing', 'cetech-woocommerce-delivery-engine' ),
			[
				__( 'Background queue available', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( ! empty( $health['available'] ) ),
				__( 'Immediate async enqueue supported', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( ! empty( $health['async_enqueue_supported'] ) ),
				__( 'WordPress cron disabled', 'cetech-woocommerce-delivery-engine' ) => $this->yes_no( ! empty( $health['wp_cron_disabled'] ) ),
				__( 'Queue scheduled (pending actions)', 'cetech-woocommerce-delivery-engine' ) => (string) ( $health['pending_actions'] ?? 0 ),
				__( 'Active bulk jobs', 'cetech-woocommerce-delivery-engine' ) => (string) ( $health['active_jobs'] ?? 0 ),
				__( 'Stale queued jobs', 'cetech-woocommerce-delivery-engine' ) => (string) ( $health['stale_job_count'] ?? 0 ),
				__( 'Last worker activity (UTC)', 'cetech-woocommerce-delivery-engine' ) => (string) ( $health['last_worker_activity'] ?? '—' ),
				__( 'Scheduler health', 'cetech-woocommerce-delivery-engine' ) => $scheduler_label,
			]
		);
	}

	private function render_table( string $title, array $rows ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Item', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Value', 'cetech-woocommerce-delivery-engine' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( (string) $label ) . '</th>';
			echo '<td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function render_resync_form(): void {
		echo '<h2>' . esc_html__( 'Maintenance', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( self::RESYNC_ACTION, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::RESYNC_ACTION ) . '" />';
		submit_button(
			__( 'Repair administrator Delivery Engine access', 'cetech-woocommerce-delivery-engine' ),
			'secondary',
			'submit',
			false
		);
		echo '<p class="description">' . esc_html__(
			'Restores full Delivery Engine access for the WordPress Administrator role. Does not reset other WordPress roles. An independent recovery notice is also available to users with manage_options even when Technical Diagnostics is inaccessible.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '</form>';
	}

	private function render_admin_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::RESYNC_NOTICE_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( (string) $_GET[ self::RESYNC_NOTICE_KEY ] ) );

		if ( '1' !== $status ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html__( 'Delivery capabilities were re-synced successfully.', 'cetech-woocommerce-delivery-engine' );
		echo '</p></div>';
	}

	private function describe_selector_storefront_output( bool $selector_enabled, bool $capture_active ): string {
		if ( ! $selector_enabled ) {
			return __( 'Disabled (flag off by default)', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $capture_active ) {
			return __( 'Enabled on simple product pages (radio selection inside add-to-cart form; no checkout/shipping)', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Enabled on product pages (display-only; no cart/checkout)', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_rate_quote_mode( bool $shipping_runtime_active ): string {
		if ( $shipping_runtime_active ) {
			return __( 'RateQuoteEngine-backed WooCommerce shipping calculation', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Admin/test only; no WooCommerce shipping calculation', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_shipping_calculation_mode( bool $shipping_flag_enabled, bool $shipping_runtime_active ): string {
		if ( $shipping_runtime_active ) {
			return __( 'RateQuoteEngine-backed / enabled', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $shipping_flag_enabled ) {
			return __( 'Disabled (upstream flags not ready)', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Disabled', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_destination_resolver(): string {
		if ( ! class_exists( DestinationZoneMatcher::class ) || ! class_exists( PackageDestinationZoneResolver::class ) ) {
			return __( 'Unavailable', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Package destination → active zone rules (+ explicit fallback zone when configured)', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_shipping_method_registration( bool $shipping_runtime_active ): string {
		if ( ! function_exists( 'WC' ) || ! class_exists( SelectedOfferShippingMethod::class ) ) {
			return __( 'Not registered', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $shipping_runtime_active ) {
			return __( 'Registered for shipping zones; checkout rates enabled', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Registered for shipping zones; checkout rates not enabled', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_cart_checkout_totals( bool $shipping_runtime_active ): string {
		if ( $shipping_runtime_active ) {
			return __( 'WooCommerce shipping rates only (no direct total hooks)', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Not modified', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_snapshot_mode( bool $snapshot_flag_enabled, bool $snapshot_runtime_active ): string {
		if ( $snapshot_runtime_active ) {
			return __( 'Order item protected meta only', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $snapshot_flag_enabled ) {
			return __( 'Disabled (upstream flags not ready)', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Disabled', 'cetech-woocommerce-delivery-engine' );
	}

	private function describe_order_snapshot_status( bool $snapshot_runtime_active ): string {
		if ( $snapshot_runtime_active ) {
			return sprintf(
				/* translators: %s: protected meta key prefix */
				__( 'Enabled (%s on order items/orders)', 'cetech-woocommerce-delivery-engine' ),
				OrderDeliverySnapshot::META_LINE_SNAPSHOT
			);
		}

		return __( 'Not enabled', 'cetech-woocommerce-delivery-engine' );
	}

	private function yes_no( bool $value ): string {
		return $value
			? __( 'Yes', 'cetech-woocommerce-delivery-engine' )
			: __( 'No', 'cetech-woocommerce-delivery-engine' );
	}

	private function render_configuration_warnings(): void {
		$profile_count = $this->logistics_profile_repository->count_all();
		$offer_count   = $this->delivery_offer_repository->count_all();
		$zone_count    = $this->destination_zone_repository->count_all();
		$pickup_count  = $this->pickup_location_repository->count_all();
		$supplier_count = $this->supplier_repository->count_all();
		$origin_count   = $this->origin_repository->count_all();
		$rate_card_count = $this->rate_card_repository->count_all();
		$product_rule_count = $this->product_rule_repository->count_all();

		if ( $profile_count > 0 && $offer_count > 0 && $zone_count > 0 && $pickup_count > 0 && $supplier_count > 0 && $origin_count > 0 && $rate_card_count > 0 && $product_rule_count > 0 ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';

		if ( 0 === $profile_count ) {
			echo esc_html__( 'Warning: no logistics profiles are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $offer_count ) {
			echo esc_html__( 'Warning: no delivery offers are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $zone_count ) {
			echo esc_html__( 'Warning: no destination zones are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $pickup_count ) {
			echo esc_html__( 'Warning: no pickup locations are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $supplier_count ) {
			echo esc_html__( 'Warning: no suppliers are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $origin_count ) {
			echo esc_html__( 'Warning: no origins are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $rate_card_count ) {
			echo esc_html__( 'Warning: no rate cards are configured yet.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		}

		if ( 0 === $product_rule_count ) {
			echo esc_html__( 'Warning: no product delivery rules are configured yet.', 'cetech-woocommerce-delivery-engine' );
		}

		echo '</p></div>';
	}

	private function format_missing_tables(): string {
		$missing = ConfigurationTables::missing();

		if ( [] === $missing ) {
			return __( 'None', 'cetech-woocommerce-delivery-engine' );
		}

		return implode( ', ', $missing );
	}

	private function format_migration_status(): string {
		$status = MigrationStatus::get();

		if ( null === $status ) {
			return __( 'N/A', 'cetech-woocommerce-delivery-engine' );
		}

		$parts = [];

		if ( isset( $status['status'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: migration outcome (success or failed) */
				__( 'Status: %s', 'cetech-woocommerce-delivery-engine' ),
				(string) $status['status']
			);
		}

		if ( isset( $status['migration_id'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: migration identifier */
				__( 'Migration: %s', 'cetech-woocommerce-delivery-engine' ),
				(string) $status['migration_id']
			);
		}

		if ( isset( $status['to_version'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: schema version number */
				__( 'Version: %s', 'cetech-woocommerce-delivery-engine' ),
				(string) $status['to_version']
			);
		}

		if ( isset( $status['recorded_at'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: UTC timestamp */
				__( 'Recorded: %s UTC', 'cetech-woocommerce-delivery-engine' ),
				(string) $status['recorded_at']
			);
		}

		if ( isset( $status['error'] ) && '' !== (string) $status['error'] ) {
			$parts[] = sprintf(
				/* translators: %s: error message */
				__( 'Error: %s', 'cetech-woocommerce-delivery-engine' ),
				(string) $status['error']
			);
		}

		return implode( ' | ', $parts );
	}

	private function woocommerce_version(): string {
		if ( ! $this->requirements->is_woocommerce_active() || ! defined( 'WC_VERSION' ) ) {
			return __( 'N/A', 'cetech-woocommerce-delivery-engine' );
		}

		return WC_VERSION;
	}

	private function diagnostic_report_text(): string {
		$lines = [
			'CETECH Delivery Engine diagnostic report',
			'Plugin version: ' . CETECH_DE_VERSION,
			'PHP: ' . PHP_VERSION,
			'WordPress: ' . get_bloginfo( 'version' ),
			'WooCommerce: ' . $this->woocommerce_version(),
			'Schema: ' . SchemaVersion::get() . ' / target ' . SchemaVersion::target(),
			'',
			'Health',
		];

		$health = $this->configuration_health_checker->run();
		$diagnostics = is_array( $health['diagnostics'] ?? null ) ? $health['diagnostics'] : [];
		if ( [] === $diagnostics ) {
			$lines[] = 'No configuration health warnings.';
		} else {
			foreach ( $diagnostics as $item ) {
				$title = is_object( $item ) && isset( $item->title ) ? (string) $item->title : (string) ( $item['title'] ?? 'Issue' );
				$lines[] = '- ' . $title;
			}
		}

		$lines[] = '';
		$lines[] = 'Bulk background processing';
		if ( $this->bulk_queue_health instanceof BulkQueueHealth ) {
			$health = $this->bulk_queue_health->snapshot();
			$lines[] = 'queue available: ' . ( ! empty( $health['available'] ) ? 'yes' : 'no' );
			$lines[] = 'async enqueue: ' . ( ! empty( $health['async_enqueue_supported'] ) ? 'yes' : 'no' );
			$lines[] = 'wp cron disabled: ' . ( ! empty( $health['wp_cron_disabled'] ) ? 'yes' : 'no' );
			$lines[] = 'pending actions: ' . (string) ( $health['pending_actions'] ?? 0 );
			$lines[] = 'stale jobs: ' . (string) ( $health['stale_job_count'] ?? 0 );
			$lines[] = 'scheduler health: ' . (string) ( $health['scheduler_health'] ?? 'unknown' );
		} else {
			$lines[] = 'Not collected.';
		}

		$lines[] = '';
		$lines[] = 'Feature flags';
		foreach ( $this->feature_flags->all() as $flag => $enabled ) {
			$lines[] = $flag . ': ' . ( $enabled ? 'on' : 'off' );
		}

		return implode( "\n", $lines );
	}
}
