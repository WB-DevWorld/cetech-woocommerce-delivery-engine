<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCountQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentActivityCursor;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;

/**
 * Registers Delivery Engine admin menus for the WordPress-native RC.3 UX.
 */
final class AdminMenu {

	public const PARENT_SLUG = 'cetech-delivery-engine';

	public const SYSTEM_STATUS_SLUG = 'cetech-delivery-engine-system-status';

	public const HIDDEN_PARENT = 'options.php';

	public function __construct(
		private SystemStatusPage $system_status_page,
		private DeliverySettingsPage $delivery_settings_page,
		private LogisticsProfilesPage $logistics_profiles_page,
		private DeliveryOffersPage $delivery_offers_page,
		private DestinationZonesPage $destination_zones_page,
		private PickupLocationsPage $pickup_locations_page,
		private SuppliersOriginsPage $suppliers_origins_page,
		private RateCardsPage $rate_cards_page,
		private ProductDeliveryRulesPage $product_delivery_rules_page,
		private ScopedConfigurationPage $scoped_configuration_page,
		private EffectiveConfigurationPreviewPage $effective_configuration_preview_page,
		private DeliverySettingsHomePage $delivery_settings_home_page,
		private ProductExceptionsPage $product_exceptions_page,
		private NeedsAttentionPage $needs_attention_page,
		private OverviewPage $overview_page,
		private SetupWizardPage $setup_wizard_page,
		private ScopedConfigurationAdminAssets $scoped_configuration_admin_assets,
		private AdminUxAssets $admin_ux_assets,
		private SetupWizardProgress $wizard_progress,
		private FeatureFlags $feature_flags,
		private ShipmentsPage $shipments_page,
		private ?NeedsAttentionCountQuery $needs_attention_count = null,
		private ?ShipmentActivityCursor $shipment_activity = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menus' ] );
		add_filter( 'submenu_file', [ $this, 'highlight_setup_guide' ], 10, 2 );
		add_action( 'admin_init', [ $this->system_status_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->delivery_settings_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->logistics_profiles_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->delivery_offers_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->destination_zones_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->pickup_locations_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->suppliers_origins_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->rate_cards_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->product_delivery_rules_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->scoped_configuration_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->effective_configuration_preview_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->delivery_settings_home_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->product_exceptions_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->needs_attention_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->shipments_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->overview_page, 'handle_actions' ] );
		add_action( 'admin_init', [ $this->setup_wizard_page, 'handle_actions' ] );
		$this->scoped_configuration_admin_assets->register();
		$this->admin_ux_assets->register();
	}

	public function add_menus(): void {
		if ( ! $this->current_user_has_any_menu_cap() ) {
			return;
		}

		$setup_open   = current_user_can( 'manage_delivery_settings' ) && $this->wizard_progress->should_open_on_entry();
		$show_setup   = $this->should_show_setup_guide_in_normal_menu();
		$parent_slug  = $setup_open ? SetupWizardPage::SLUG : self::PARENT_SLUG;
		$parent_cb    = $setup_open ? [ $this->setup_wizard_page, 'render' ] : [ $this->overview_page, 'render' ];
		$parent_title = $setup_open
			? __( 'Setup Guide', 'cetech-woocommerce-delivery-engine' )
			: __( 'Overview', 'cetech-woocommerce-delivery-engine' );

		$parent_menu_title = __( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' );
		$attention_count   = $this->needs_attention_badge_count();

		if ( $attention_count > 0 && $this->current_user_can_see_needs_attention_menu() ) {
			$parent_menu_title = AdminMenuBadgeMarkup::append(
				$parent_menu_title,
				$attention_count,
				AdminMenuBadgeMarkup::needs_attention_screen_reader( $attention_count )
			);
		}

		add_menu_page(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			$parent_menu_title,
			$this->resolve_parent_menu_capability(),
			$parent_slug,
			$parent_cb,
			'dashicons-location-alt',
			56
		);

		add_submenu_page(
			$parent_slug,
			$parent_title,
			$parent_title,
			$this->resolve_parent_menu_capability(),
			$parent_slug,
			$parent_cb
		);

		if ( $setup_open ) {
			add_submenu_page(
				$parent_slug,
				__( 'Overview', 'cetech-woocommerce-delivery-engine' ),
				__( 'Overview', 'cetech-woocommerce-delivery-engine' ),
				$this->resolve_parent_menu_capability(),
				self::PARENT_SLUG,
				[ $this->overview_page, 'render' ]
			);
		} elseif ( $show_setup ) {
			add_submenu_page(
				$parent_slug,
				__( 'Setup Guide', 'cetech-woocommerce-delivery-engine' ),
				__( 'Setup Guide', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				SetupWizardPage::SLUG,
				[ $this->setup_wizard_page, 'render' ]
			);
		} elseif ( current_user_can( 'manage_delivery_settings' ) ) {
			$this->register_hidden_page(
				__( 'Setup Guide', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				SetupWizardPage::SLUG,
				[ $this->setup_wizard_page, 'render' ]
			);
		}

		if ( current_user_can( Capabilities::SITE_WIDE ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ),
				__( 'Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ),
				Capabilities::SITE_WIDE,
				DeliverySettingsHomePage::SLUG,
				[ $this->delivery_settings_home_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_offers' ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_offers',
				DeliveryOffersPage::SLUG,
				[ $this->delivery_offers_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_zones' ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_zones',
				DestinationZonesPage::SLUG,
				[ $this->destination_zones_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_rate_cards' ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_rate_cards',
				RateCardsPage::SLUG,
				[ $this->rate_cards_page, 'render' ]
			);
		}

		if ( current_user_can( Capabilities::PICKUP ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
				__( 'Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
				Capabilities::PICKUP,
				PickupLocationsPage::SLUG,
				[ $this->pickup_locations_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_product_delivery_rules' ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Product Exceptions', 'cetech-woocommerce-delivery-engine' ),
				__( 'Product Exceptions', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				ProductExceptionsPage::SLUG,
				[ $this->product_exceptions_page, 'render' ]
			);
		}

		if ( $this->should_show_shipments_menu() ) {
			$shipments_title = __( 'Shipments', 'cetech-woocommerce-delivery-engine' );
			$activity_count  = $this->shipments_activity_badge_count();

			if ( $activity_count > 0 ) {
				$shipments_title = AdminMenuBadgeMarkup::append(
					$shipments_title,
					$activity_count,
					AdminMenuBadgeMarkup::shipments_activity_screen_reader( $activity_count )
				);
			}

			add_submenu_page(
				$parent_slug,
				__( 'Shipments', 'cetech-woocommerce-delivery-engine' ),
				$shipments_title,
				'manage_shipments',
				ShipmentsPage::SLUG,
				[ $this->shipments_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_product_delivery_rules' ) || $this->should_show_shipments_menu() ) {
			$attention_cap   = current_user_can( 'manage_product_delivery_rules' )
				? 'manage_product_delivery_rules'
				: 'manage_shipments';
			$attention_title = __( 'Needs Attention', 'cetech-woocommerce-delivery-engine' );

			if ( $attention_count > 0 ) {
				$attention_title = AdminMenuBadgeMarkup::append(
					$attention_title,
					$attention_count,
					AdminMenuBadgeMarkup::needs_attention_screen_reader( $attention_count )
				);
			}

			add_submenu_page(
				$parent_slug,
				__( 'Needs Attention', 'cetech-woocommerce-delivery-engine' ),
				$attention_title,
				$attention_cap,
				NeedsAttentionPage::SLUG,
				[ $this->needs_attention_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_settings' ) ) {
			add_submenu_page(
				$parent_slug,
				__( 'Settings', 'cetech-woocommerce-delivery-engine' ),
				__( 'Settings', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				DeliverySettingsPage::SLUG,
				[ $this->delivery_settings_page, 'render' ]
			);
		}

		$this->register_hidden_support_pages();
	}

	/**
	 * Setup Guide appears in the normal menu only while setup is incomplete
	 * (or while an administrator has reopened it for review). Completed stores
	 * reopen it from Settings → Run Setup Guide Again.
	 */
	public function should_show_setup_guide_in_normal_menu(): bool {
		if ( ! current_user_can( 'manage_delivery_settings' ) ) {
			return false;
		}

		if ( $this->wizard_progress->should_open_on_entry() ) {
			return true;
		}

		$wizard = $this->wizard_progress->read();

		return SetupWizardProgress::STATUS_COMPLETE !== $wizard['status'];
	}

	/**
	 * Titles that belong in the everyday Delivery Engine submenu.
	 *
	 * @return list<string>
	 */
	public static function normal_menu_titles(): array {
		return [
			'Overview',
			'Site-wide Defaults',
			'Delivery Options',
			'Delivery Areas',
			'Delivery Charges',
			'Pickup Locations',
			'Product Exceptions',
			'Shipments',
			'Needs Attention',
			'Settings',
		];
	}

	/**
	 * Titles that must never appear as everyday submenu items.
	 *
	 * @return list<string>
	 */
	public static function retired_normal_menu_titles(): array {
		return [
			'Legacy Delivery Rules',
			'Technical Diagnostic Tools',
			'Delivery Settings Preview',
			'Logistics Profiles',
			'Suppliers & Origins',
			'Rate Cards',
			'Destination Zones',
			'Delivery Offers',
		];
	}

	private function register_hidden_support_pages(): void {
		if ( current_user_can( Capabilities::DIAGNOSTICS ) ) {
			$this->register_hidden_page(
				__( 'Technical diagnostic tools', 'cetech-woocommerce-delivery-engine' ),
				Capabilities::DIAGNOSTICS,
				self::SYSTEM_STATUS_SLUG,
				[ $this->system_status_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_product_delivery_rules' ) ) {
			$this->register_hidden_page(
				__( 'Delivery Settings Preview', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				EffectiveConfigurationPreviewPage::SLUG,
				[ $this->effective_configuration_preview_page, 'render' ]
			);

			$this->register_hidden_page(
				__( 'Product Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				ScopedConfigurationPage::SLUG,
				[ $this->scoped_configuration_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_private_sources' ) ) {
			$this->register_hidden_page(
				__( 'Suppliers & Origins', 'cetech-woocommerce-delivery-engine' ),
				'manage_private_sources',
				SuppliersOriginsPage::SLUG,
				[ $this->suppliers_origins_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_logistics_profiles' ) ) {
			$this->register_hidden_page(
				__( 'Logistics Profiles', 'cetech-woocommerce-delivery-engine' ),
				'manage_logistics_profiles',
				LogisticsProfilesPage::SLUG,
				[ $this->logistics_profiles_page, 'render' ]
			);
		}
	}

	private function register_hidden_page( string $title, string $capability, string $slug, callable $callback ): void {
		add_submenu_page(
			self::HIDDEN_PARENT,
			$title,
			$title,
			$capability,
			$slug,
			$callback
		);
	}

	private function current_user_has_any_menu_cap(): bool {
		foreach ( $this->menu_capabilities() as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Use the first delivery capability the current user has so custom roles
	 * with subset caps can still see the parent menu entry.
	 */
	private function resolve_parent_menu_capability(): string {
		foreach ( $this->menu_capabilities() as $capability ) {
			if ( current_user_can( $capability ) ) {
				return $capability;
			}
		}

		return Capabilities::VIEW;
	}

	/**
	 * @return list<string>
	 */
	private function menu_capabilities(): array {
		return [
			Capabilities::VIEW,
			'manage_delivery_settings',
			Capabilities::SITE_WIDE,
			'manage_delivery_offers',
			'manage_delivery_zones',
			Capabilities::PICKUP,
			'manage_delivery_rate_cards',
			'manage_product_delivery_rules',
			'manage_logistics_profiles',
			'manage_private_sources',
			'manage_shipments',
		];
	}

	public function should_show_shipments_menu(): bool {
		return $this->feature_flags->is_enabled( 'enable_shipment_records' )
			&& current_user_can( 'manage_shipments' );
	}

	private function needs_attention_badge_count(): int {
		if ( ! $this->needs_attention_count instanceof NeedsAttentionCountQuery ) {
			return 0;
		}

		if ( ! $this->needs_attention_count->current_user_can_see_needs_attention() ) {
			return 0;
		}

		return max( 0, $this->needs_attention_count->unresolved_count_for_current_user() );
	}

	private function shipments_activity_badge_count(): int {
		if ( ! $this->shipment_activity instanceof ShipmentActivityCursor ) {
			return 0;
		}

		if ( ! $this->should_show_shipments_menu() ) {
			return 0;
		}

		return max( 0, $this->shipment_activity->unreviewed_shipment_count_for_current_user() );
	}

	private function current_user_can_see_needs_attention_menu(): bool {
		return current_user_can( 'manage_product_delivery_rules' ) || $this->should_show_shipments_menu();
	}

	/**
	 * @param string|false $submenu_file
	 * @param string       $parent_file
	 * @return string|false
	 */
	public function highlight_setup_guide( $submenu_file, string $parent_file ) {
		if ( ! $this->wizard_progress->should_open_on_entry() ) {
			return $submenu_file;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( SetupWizardPage::SLUG === $page || SetupWizardPage::SLUG === $parent_file ) {
			return SetupWizardPage::SLUG;
		}

		return $submenu_file;
	}
}
