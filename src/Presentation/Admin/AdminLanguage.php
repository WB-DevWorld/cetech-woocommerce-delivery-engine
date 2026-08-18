<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Central administrator-facing wording for normal staff (not developers).
 *
 * Persistence keys, enums, and class names stay unchanged. This class maps them
 * to operational language for menus, modes, sources, and empty states.
 */
final class AdminLanguage {

	public static function menu_parent(): string {
		return 'Delivery Engine';
	}

	public static function menu_dashboard(): string {
		return 'Overview';
	}

	public static function menu_settings(): string {
		return 'Settings';
	}

	public static function menu_delivery_settings(): string {
		return 'Site-wide Defaults';
	}

	public static function menu_overview(): string {
		return 'Overview';
	}

	public static function menu_setup_guide(): string {
		return 'Setup Guide';
	}

	public static function wizard_title(): string {
		return 'Set up Delivery';
	}

	public static function menu_delivery_options(): string {
		return 'Delivery Options';
	}

	public static function menu_delivery_areas(): string {
		return 'Delivery Areas';
	}

	public static function menu_delivery_charges(): string {
		return 'Delivery Charges';
	}

	public static function menu_pickup_locations(): string {
		return 'Pickup Locations';
	}

	public static function menu_product_exceptions(): string {
		return 'Product Exceptions';
	}

	public static function menu_needs_attention(): string {
		return 'Needs Attention';
	}

	public static function menu_shipments(): string {
		return 'Shipments';
	}

	public static function run_setup_guide_again(): string {
		return 'Run Setup Guide Again';
	}

	public static function menu_preview(): string {
		return 'Delivery Settings Preview';
	}

	public static function menu_legacy_rules(): string {
		return 'Legacy Delivery Rules';
	}

	public static function page_delivery_settings(): string {
		return 'Delivery Settings';
	}

	public static function page_preview(): string {
		return 'Delivery Settings Preview';
	}

	public static function page_legacy_rules(): string {
		return 'Legacy Delivery Rules';
	}

	public static function tab_default_settings(): string {
		return 'Site-wide Defaults';
	}

	public static function tab_product_settings(): string {
		return 'Product-Specific Settings';
	}

	public static function tab_variation_settings(): string {
		return 'Variation-Specific Settings';
	}

	public static function delivery_setup_label(): string {
		return 'Delivery setup';
	}

	public static function currently_using( string $source_label ): string {
		return 'Currently using: ' . $source_label;
	}

	public static function empty_product_settings(): string {
		return 'This product has no special delivery settings. It uses Site-wide Defaults until you customize it.';
	}

	public static function customize_this_product(): string {
		return 'Customize This Product';
	}

	public static function customize_this_variation(): string {
		return 'Customize This Variation';
	}

	public static function use_site_wide_default( string $profile_label ): string {
		return 'Use ' . $profile_label . ' Site-wide Default';
	}

	public static function use_product_setting(): string {
		return 'Use Product Setting';
	}

	public static function set_a_different_value(): string {
		return 'Set a different value here';
	}

	public static function diagnostics_capability(): string {
		return 'view_delivery_diagnostics';
	}

	public static function empty_variation_settings(): string {
		return 'This variation has no special delivery settings. It currently uses its product settings.';
	}

	public static function empty_default_settings(): string {
		return 'No site-wide delivery defaults have been saved yet. Set them once so products can inherit them automatically.';
	}

	public static function technical_details_summary(): string {
		return 'Technical details';
	}

	public static function developer_information_summary(): string {
		return 'Developer information';
	}

	public static function technical_diagnostic_tools(): string {
		return 'Technical diagnostic tools';
	}

	public static function technical_diagnostic_tools_intro(): string {
		return 'These tools are intended for technical support and troubleshooting. You do not need them for normal delivery setup or daily operations.';
	}

	public static function check_applicable_legacy_rule(): string {
		return 'Check which legacy delivery rule applies';
	}

	public static function check_applicable_rule_button(): string {
		return 'Check applicable rule';
	}

	public static function check_delivery_choice(): string {
		return 'Check a delivery choice';
	}

	public static function delivery_choice_identifier(): string {
		return 'Delivery choice identifier';
	}

	/**
	 * @return list<string>
	 */
	public static function forbidden_primary_terms(): array {
		return [
			'EffectiveConfigurationResolver',
			'slice_key',
			'scope_id',
			'config_version',
			'configuration_fingerprint',
			'UNRESOLVED_GLOBAL_VALUE',
			'LEGACY_CATEGORY_COMPATIBILITY',
			'REPLACE []',
			'enable_product_delivery_selector',
			'enable_',
			'display_key',
			'availability:choice:suffix',
			'Staff testing tools',
			'feature flag',
			'New Delivery Settings System',
		];
	}
}
