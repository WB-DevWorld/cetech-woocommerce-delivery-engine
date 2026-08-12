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
		self::assertSame( 'Dashboard', AdminLanguage::menu_dashboard() );
		self::assertSame( 'Settings', AdminLanguage::menu_settings() );
		self::assertSame( 'Delivery Settings', AdminLanguage::menu_delivery_settings() );
		self::assertSame( 'Delivery Settings Preview', AdminLanguage::menu_preview() );
		self::assertSame( 'Legacy Delivery Rules', AdminLanguage::menu_legacy_rules() );
		self::assertSame( 'Default Settings', AdminLanguage::tab_default_settings() );
		self::assertSame( 'Product-Specific Settings', AdminLanguage::tab_product_settings() );
		self::assertSame( 'Variation-Specific Settings', AdminLanguage::tab_variation_settings() );
	}

	public function test_empty_states_explain_next_action(): void {
		self::assertStringContainsString( 'Default Settings', AdminLanguage::empty_product_settings() );
		self::assertStringContainsString( 'parent product', AdminLanguage::empty_variation_settings() );
		self::assertStringContainsString( 'default delivery settings', strtolower( AdminLanguage::empty_default_settings() ) );
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

		self::assertSame( 'Use the New Delivery Settings System', FeatureFlagLabels::label( 'enable_effective_configuration_runtime' ) );
		self::assertSame( 'Use New Delivery Settings for Product Variations', FeatureFlagLabels::label( 'enable_variable_product_ecr_runtime' ) );
	}

	public function test_inheritance_and_status_labels_avoid_developer_jargon(): void {
		self::assertSame( 'Currently using: Default Settings', ProvenanceLabelMapper::currently_using( 'global' ) );
		self::assertSame( 'Ready', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Valid ) );
		self::assertSame( 'Needs configuration', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Unresolved ) );
		self::assertSame( 'Configuration problem', ReasonCodeLabelMapper::state_label( EffectiveFieldState::Invalid ) );
		self::assertSame( 'Default delivery setup', ConfigurationFieldCatalog::slice_label( '' ) );
		self::assertStringContainsString( 'legacy category rule', strtolower( ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE ) );
		self::assertSame( CollectionConfigurationMode::Replace->value, 'replace' );
		self::assertStringNotContainsString( 'UNRESOLVED_GLOBAL_VALUE', ReasonCodeLabelMapper::explain( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ) );
	}
}
