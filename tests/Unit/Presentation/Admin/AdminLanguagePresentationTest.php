<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\ProvenanceLabelMapper;
use CetechDeliveryEngine\Application\Configuration\Admin\ReasonCodeLabelMapper;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Presentation\Admin\AdminLanguage;
use CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminLanguagePresentationTest extends TestCase {

	public function test_menu_and_page_titles_use_operational_language(): void {
		self::assertSame( 'Delivery Engine', AdminLanguage::menu_parent() );
		self::assertSame( 'Overview', AdminLanguage::menu_dashboard() );
		self::assertSame( 'Settings', AdminLanguage::menu_settings() );
		self::assertSame( 'Site-wide Defaults', AdminLanguage::menu_delivery_settings() );
		self::assertSame( 'Delivery Settings Preview', AdminLanguage::menu_preview() );
		self::assertSame( 'Legacy Delivery Rules', AdminLanguage::menu_legacy_rules() );
		self::assertSame( 'Overview', AdminLanguage::menu_overview() );
		self::assertSame( 'Delivery Options', AdminLanguage::menu_delivery_options() );
		self::assertSame( 'Delivery Areas', AdminLanguage::menu_delivery_areas() );
		self::assertSame( 'Delivery Charges', AdminLanguage::menu_delivery_charges() );
		self::assertSame( 'Pickup Locations', AdminLanguage::menu_pickup_locations() );
		self::assertSame( 'Product Exceptions', AdminLanguage::menu_product_exceptions() );
		self::assertSame( 'Needs Attention', AdminLanguage::menu_needs_attention() );
		self::assertSame( 'Run Setup Guide Again', AdminLanguage::run_setup_guide_again() );
		self::assertSame( 'Setup Guide', AdminLanguage::menu_setup_guide() );
		self::assertSame( 'Set up Delivery', AdminLanguage::wizard_title() );
		self::assertSame( 'Use In Warehouse Site-wide Default', AdminLanguage::use_site_wide_default( 'In Warehouse' ) );
		self::assertSame( 'Use In Store Site-wide Default', AdminLanguage::use_site_wide_default( 'In Store' ) );
		self::assertSame( 'Use International Site-wide Default', AdminLanguage::use_site_wide_default( 'International' ) );
		self::assertSame( 'Site-wide Defaults', AdminLanguage::tab_default_settings() );
		self::assertSame( 'Product-Specific Settings', AdminLanguage::tab_product_settings() );
		self::assertSame( 'Variation-Specific Settings', AdminLanguage::tab_variation_settings() );
	}

	public function test_empty_states_explain_next_action(): void {
		self::assertStringContainsString( 'Site-wide Defaults', AdminLanguage::empty_product_settings() );
		self::assertStringContainsString( 'product settings', AdminLanguage::empty_variation_settings() );
		self::assertStringContainsString( 'site-wide delivery defaults', strtolower( AdminLanguage::empty_default_settings() ) );
	}

	public function test_feature_flag_labels_cover_defaults_without_raw_keys(): void {
		$defaults = ( new ReflectionClass( FeatureFlags::class ) )->getConstant( 'DEFAULTS' );
		self::assertIsArray( $defaults );

		foreach ( array_keys( $defaults ) as $flag ) {
			$label = FeatureFlagLabels::label( (string) $flag );
			self::assertNotSame( $flag, $label, 'Flag ' . $flag . ' still uses the raw option key as its primary label.' );
			self::assertStringNotContainsString( 'enable_effective_configuration_runtime', $label );
			self::assertStringNotContainsString( 'enable_variable_product_ecr_runtime', $label );
		}

		self::assertSame( 'Use Site-wide Defaults at checkout', FeatureFlagLabels::label( 'enable_effective_configuration_runtime' ) );
		self::assertSame( 'Use Site-wide Defaults for product variations', FeatureFlagLabels::label( 'enable_variable_product_ecr_runtime' ) );
		self::assertSame( 'Show delivery choices on product pages', FeatureFlagLabels::label( 'enable_product_delivery_selector' ) );
	}

	public function test_technical_diagnostic_tools_are_labelled_as_non_routine(): void {
		self::assertSame( 'Technical diagnostic tools', AdminLanguage::technical_diagnostic_tools() );
		self::assertStringContainsString( 'technical support', strtolower( AdminLanguage::technical_diagnostic_tools_intro() ) );
		self::assertStringContainsString( 'do not need them for normal delivery setup', strtolower( AdminLanguage::technical_diagnostic_tools_intro() ) );
		self::assertSame( 'Check which legacy delivery rule applies', AdminLanguage::check_applicable_legacy_rule() );
		self::assertSame( 'Check applicable rule', AdminLanguage::check_applicable_rule_button() );
		self::assertSame( 'Check a delivery choice', AdminLanguage::check_delivery_choice() );
		self::assertSame( 'Delivery choice identifier', AdminLanguage::delivery_choice_identifier() );
		self::assertSame( 'Developer information', AdminLanguage::developer_information_summary() );
		self::assertSame( 'Technical details', AdminLanguage::technical_details_summary() );
	}

	public function test_business_field_descriptions_explain_operational_consequence(): void {
		$availability = ConfigurationFieldCatalog::description( \CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$logistics    = ConfigurationFieldCatalog::description( \CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey::LOGISTICS_PROFILE_ID );
		$priority     = ConfigurationFieldCatalog::description( \CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey::PRIORITY );

		self::assertStringContainsString( 'fulfilled from', strtolower( $availability ) );
		self::assertStringContainsString( 'delivery methods', strtolower( $availability ) );
		self::assertStringContainsString( 'groups the delivery handling rules', strtolower( $logistics ) );
		self::assertStringContainsString( 'lower number', strtolower( $priority ) );
		self::assertStringContainsString( 'leave this unchanged', strtolower( $priority ) );
	}

	public function test_forbidden_primary_terms_include_diagnostic_jargon(): void {
		$terms = AdminLanguage::forbidden_primary_terms();

		self::assertContains( 'enable_product_delivery_selector', $terms );
		self::assertContains( 'display_key', $terms );
		self::assertContains( 'availability:choice:suffix', $terms );
		self::assertContains( 'Staff testing tools', $terms );
		self::assertContains( 'feature flag', $terms );
	}

	public function test_inheritance_and_status_labels_avoid_developer_jargon(): void {
		self::assertSame( 'Currently using: Site-wide default', ProvenanceLabelMapper::currently_using( 'global' ) );
		self::assertSame( 'Ready', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Valid ) );
		self::assertSame( 'Needs configuration', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Unresolved ) );
		self::assertSame( 'Configuration problem', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Invalid ) );
		self::assertSame( 'Default delivery setup', ConfigurationFieldCatalog::slice_label( '' ) );
		self::assertStringContainsString( 'category rule', strtolower( ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE ) );
		self::assertSame( CollectionConfigurationMode::Replace->value, 'replace' );
		self::assertStringNotContainsString( 'UNRESOLVED_GLOBAL_VALUE', ReasonCodeLabelMapper::explain( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ) );
	}

	public function test_legacy_rules_page_uses_operational_diagnostic_labels(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/ProductDeliveryRulesPage.php' );

		self::assertStringContainsString( 'AdminLanguage::technical_diagnostic_tools()', $source );
		self::assertStringContainsString( 'AdminLanguage::check_applicable_legacy_rule()', $source );
		self::assertStringContainsString( 'AdminLanguage::check_delivery_choice()', $source );
		self::assertStringContainsString( 'AdminLanguage::delivery_choice_identifier()', $source );
		self::assertStringNotContainsString( 'Staff testing tools', $source );
		self::assertStringNotContainsString( 'Test product rule resolution', $source );
		self::assertStringNotContainsString( 'Test delivery selection validation', $source );
		self::assertStringNotContainsString( 'Run resolution test', $source );
	}
}
