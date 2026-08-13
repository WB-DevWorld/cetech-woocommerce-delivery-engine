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
		return 'Dashboard';
	}

	public static function menu_settings(): string {
		return 'Settings';
	}

	public static function menu_delivery_settings(): string {
		return 'Delivery Settings';
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

	public static function empty_variation_settings(): string {
		return 'No variation-specific delivery settings have been added. This variation currently uses its parent product\'s delivery settings.';
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
		];
	}
}
