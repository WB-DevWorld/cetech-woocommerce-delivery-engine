<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Registers Delivery Engine admin menus for Phase 2B5 + Stage 4 scoped configuration.
 */
final class AdminMenu {

	public const PARENT_SLUG = 'cetech-delivery-engine';

	public const SYSTEM_STATUS_SLUG = 'cetech-delivery-engine-system-status';

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
		private ScopedConfigurationAdminAssets $scoped_configuration_admin_assets
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menus' ] );
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
		$this->scoped_configuration_admin_assets->register();
	}

	public function add_menus(): void {
		if ( ! $this->current_user_has_any_menu_cap() ) {
			return;
		}

		add_menu_page(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			$this->resolve_parent_menu_capability(),
			self::PARENT_SLUG,
			[ $this->system_status_page, 'render' ],
			'dashicons-store',
			56
		);

		if ( current_user_can( 'manage_delivery_settings' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Dashboard', 'cetech-woocommerce-delivery-engine' ),
				__( 'Dashboard', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				self::SYSTEM_STATUS_SLUG,
				[ $this->system_status_page, 'render' ]
			);

			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				__( 'Settings', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				DeliverySettingsPage::SLUG,
				[ $this->delivery_settings_page, 'render' ]
			);

			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_settings',
				ScopedConfigurationPage::SLUG,
				[ $this->scoped_configuration_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_logistics_profiles' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Logistics Profiles', 'cetech-woocommerce-delivery-engine' ),
				__( 'Logistics Profiles', 'cetech-woocommerce-delivery-engine' ),
				'manage_logistics_profiles',
				LogisticsProfilesPage::SLUG,
				[ $this->logistics_profiles_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_offers' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Delivery Offers', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Offers', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_offers',
				DeliveryOffersPage::SLUG,
				[ $this->delivery_offers_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_zones' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Destination Zones', 'cetech-woocommerce-delivery-engine' ),
				__( 'Destination Zones', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_zones',
				DestinationZonesPage::SLUG,
				[ $this->destination_zones_page, 'render' ]
			);

			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
				__( 'Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_zones',
				PickupLocationsPage::SLUG,
				[ $this->pickup_locations_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_private_sources' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Suppliers & Origins', 'cetech-woocommerce-delivery-engine' ),
				__( 'Suppliers & Origins', 'cetech-woocommerce-delivery-engine' ),
				'manage_private_sources',
				SuppliersOriginsPage::SLUG,
				[ $this->suppliers_origins_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_delivery_rate_cards' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Rate Cards', 'cetech-woocommerce-delivery-engine' ),
				__( 'Rate Cards', 'cetech-woocommerce-delivery-engine' ),
				'manage_delivery_rate_cards',
				RateCardsPage::SLUG,
				[ $this->rate_cards_page, 'render' ]
			);
		}

		if ( current_user_can( 'manage_product_delivery_rules' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Legacy Delivery Rules', 'cetech-woocommerce-delivery-engine' ),
				__( 'Legacy Delivery Rules', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				ProductDeliveryRulesPage::SLUG,
				[ $this->product_delivery_rules_page, 'render' ]
			);

			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Delivery Settings Preview', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Settings Preview', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				EffectiveConfigurationPreviewPage::SLUG,
				[ $this->effective_configuration_preview_page, 'render' ]
			);
		}

		// Product/Variation scoped editor also reachable for product-rule managers.
		if ( current_user_can( 'manage_product_delivery_rules' ) && ! current_user_can( 'manage_delivery_settings' ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'manage_product_delivery_rules',
				ScopedConfigurationPage::SLUG,
				[ $this->scoped_configuration_page, 'render' ]
			);
		}

		remove_submenu_page( self::PARENT_SLUG, self::PARENT_SLUG );
	}

	private function current_user_has_any_menu_cap(): bool {
		return current_user_can( 'manage_delivery_settings' )
			|| current_user_can( 'manage_logistics_profiles' )
			|| current_user_can( 'manage_delivery_offers' )
			|| current_user_can( 'manage_delivery_zones' )
			|| current_user_can( 'manage_private_sources' )
			|| current_user_can( 'manage_delivery_rate_cards' )
			|| current_user_can( 'manage_product_delivery_rules' );
	}

	/**
	 * Use the first delivery capability the current user has so custom roles
	 * with subset caps can still see the parent menu entry.
	 */
	private function resolve_parent_menu_capability(): string {
		$caps = [
			'manage_delivery_settings',
			'manage_logistics_profiles',
			'manage_delivery_offers',
			'manage_delivery_zones',
			'manage_private_sources',
			'manage_delivery_rate_cards',
			'manage_product_delivery_rules',
		];

		foreach ( $caps as $capability ) {
			if ( current_user_can( $capability ) ) {
				return $capability;
			}
		}

		return 'manage_delivery_settings';
	}
}
