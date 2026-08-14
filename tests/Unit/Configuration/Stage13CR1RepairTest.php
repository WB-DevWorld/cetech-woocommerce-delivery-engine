<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Admin\AdminMoneyFormatter;
use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\OfferEstimatedDeliveryDisplay;
use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\OperationalState;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\ProductDeliveryPanel;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use PHPUnit\Framework\TestCase;

final class Stage13CR1RepairTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_roles']   = [];
		$GLOBALS['cetech_de_test_caps']    = [];
		FulfilmentProfileRegistry::reset_for_tests();
	}

	public function test_wizard_step_1_continue_goes_to_step_2(): void {
		self::assertSame( [ 'step' => 2 ], SetupWizardPage::continue_redirect_args( 1 ) );
		self::assertSame( 'cetech_de_wizard_save_step', SetupWizardPage::ACTION_SAVE_STEP );
	}

	public function test_wizard_continue_and_back_transitions(): void {
		self::assertSame( [ 'step' => 2 ], SetupWizardPage::continue_redirect_args( 1 ) );
		self::assertSame( [ 'step' => 3, 'profile_index' => 0 ], SetupWizardPage::continue_redirect_args( 2 ) );
		self::assertSame( [ 'step' => 3, 'profile_index' => 1 ], SetupWizardPage::continue_redirect_args( 3, 0, 2 ) );
		self::assertSame( [ 'step' => 4, 'profile_index' => 0 ], SetupWizardPage::continue_redirect_args( 3, 1, 2 ) );
		self::assertSame( [ 'step' => 4, 'profile_index' => 0 ], SetupWizardPage::continue_redirect_args( 3, 0, 1 ) );
		self::assertSame( [ 'step' => 5 ], SetupWizardPage::continue_redirect_args( 4 ) );
		self::assertSame( [ 'step' => 6 ], SetupWizardPage::continue_redirect_args( 5 ) );

		self::assertSame( [ 'step' => 1 ], SetupWizardPage::back_redirect_args( 2 ) );
		self::assertSame( [ 'step' => 2 ], SetupWizardPage::back_redirect_args( 3, 0, 2 ) );
		self::assertSame( [ 'step' => 3, 'profile_index' => 0 ], SetupWizardPage::back_redirect_args( 3, 1, 2 ) );
		self::assertSame( [ 'step' => 3, 'profile_index' => 1 ], SetupWizardPage::back_redirect_args( 4, 0, 2 ) );
		self::assertSame( [ 'step' => 4 ], SetupWizardPage::back_redirect_args( 5 ) );
		self::assertSame( [ 'step' => 5 ], SetupWizardPage::back_redirect_args( 6 ) );
	}

	public function test_preview_post_page_gate(): void {
		self::assertTrue(
			EffectiveConfigurationPreviewPage::should_handle_posted_action(
				EffectiveConfigurationPreviewPage::SLUG,
				'cetech_de_preview_effective_configuration'
			)
		);
		self::assertFalse(
			EffectiveConfigurationPreviewPage::should_handle_posted_action(
				SetupWizardPage::SLUG,
				SetupWizardPage::ACTION_SAVE_STEP
			)
		);
		self::assertFalse(
			EffectiveConfigurationPreviewPage::should_handle_posted_action(
				EffectiveConfigurationPreviewPage::SLUG,
				''
			)
		);
	}

	public function test_capability_ensure_current_upgrades_without_activation(): void {
		$administrator = $this->fake_role();
		$shop_manager  = $this->fake_role();
		$GLOBALS['cetech_de_test_roles'] = [
			'administrator' => $administrator,
			'shop_manager'  => $shop_manager,
		];
		$GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] = 1;

		( new Capabilities() )->ensure_current();

		self::assertSame( Capabilities::VERSION, (int) $GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] );
		self::assertTrue( ! empty( $administrator->capabilities[ Capabilities::DIAGNOSTICS ] ) );
		self::assertTrue( ! empty( $administrator->capabilities['manage_product_delivery_rules'] ) );
		self::assertTrue( empty( $shop_manager->capabilities[ Capabilities::DIAGNOSTICS ] ) );
		self::assertTrue( ! empty( $shop_manager->capabilities['manage_product_delivery_rules'] ) );
	}

	public function test_operational_state_fresh_vs_prior_legacy_vs_ecr_active(): void {
		$fresh = $this->state_service( false );
		$fresh_state = $fresh->current();
		self::assertSame( OperationalState::CUSTOMER_RUNTIME_NONE, $fresh_state->customer_runtime );
		self::assertSame( 'Setup is not finished', $fresh_state->overview_title );
		self::assertNotSame( 'Delivery system active', $fresh_state->overview_title );
		self::assertFalse( $fresh_state->scan_catalog_as_customer_problems );

		$GLOBALS['cetech_de_test_options']['cetech_de_enable_product_delivery_selector'] = 1;
		$prior = $this->state_service( false );
		$prior_state = $prior->current();
		self::assertSame( OperationalState::CUSTOMER_RUNTIME_LEGACY, $prior_state->customer_runtime );
		self::assertSame( 'Existing delivery configuration is still serving customers', $prior_state->overview_title );
		self::assertSame( 'Existing configuration active', $prior_state->settings_status_label );
		self::assertFalse( $prior_state->scan_catalog_as_customer_problems );
		self::assertStringContainsString( 'still serving customers', $prior_state->settings_status_detail );

		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$GLOBALS['cetech_de_test_options'][ 'cetech_de_' . $flag ] = 1;
		}
		$active = $this->state_service( true );
		$active_state = $active->current();
		self::assertSame( OperationalState::CUSTOMER_RUNTIME_ECR, $active_state->customer_runtime );
		self::assertSame( 'Delivery system active', $active_state->overview_title );
		self::assertTrue( $active_state->scan_catalog_as_customer_problems );
	}

	public function test_zero_configured_defaults_uses_eligible_label(): void {
		$state = $this->state_service( true )->current();
		self::assertSame( 'Products eligible for Site-wide Defaults', $state->products_defaults_label );
		self::assertStringNotContainsString( 'using Site-wide Defaults', $state->products_defaults_label );
		self::assertFalse( $state->defaults_applied );
	}

	public function test_needs_attention_skips_catalog_flood_when_legacy_serving(): void {
		$fresh = $this->state_service( false )->current();
		self::assertFalse( $fresh->scan_catalog_as_customer_problems );

		$GLOBALS['cetech_de_test_options']['cetech_de_enable_product_delivery_selector'] = 1;
		$legacy = $this->state_service( false )->current();
		self::assertFalse( $legacy->scan_catalog_as_customer_problems );
		self::assertTrue( $legacy->customers_still_use_previous_rules() );

		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$GLOBALS['cetech_de_test_options'][ 'cetech_de_' . $flag ] = 1;
		}
		$ecr = $this->state_service( true )->current();
		self::assertTrue( $ecr->scan_catalog_as_customer_problems );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Configuration/Catalog/NeedsAttentionQuery.php' );
		self::assertStringContainsString( 'scan_catalog_as_customer_problems', $source );
		self::assertStringContainsString( 'has_custom_fields', $source );
		self::assertStringContainsString( 'ConfigurationScopeType::Product', $source );
		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/NeedsAttentionPage.php' );
		self::assertStringContainsString( 'Existing delivery configuration is still serving customers', $page );
		self::assertStringContainsString( 'customers_still_use_previous_rules', $page );
	}

	public function test_offer_estimated_delivery_display_hides_internal_service_level(): void {
		self::assertSame( '—', OfferEstimatedDeliveryDisplay::label( [ 'service_level' => 'standard' ] ) );
		self::assertSame( '3–5 days', OfferEstimatedDeliveryDisplay::label( [ 'service_level' => '3–5 days' ] ) );
	}

	public function test_admin_money_formatter_trims_excess_decimals(): void {
		self::assertStringContainsString( '25.00', AdminMoneyFormatter::display( '25.0000' ) );
		self::assertSame( '25.00', AdminMoneyFormatter::input_amount( '25.0000' ) );
	}

	public function test_store_aware_examples_are_not_ghana_hardcoded(): void {
		$examples = [
			StoreAwareExamples::charge_list_example(),
			StoreAwareExamples::charge_editor_name_example(),
			StoreAwareExamples::area_help(),
			StoreAwareExamples::area_list_example(),
			StoreAwareExamples::area_name_example(),
			StoreAwareExamples::supplier_origin_example(),
		];
		$blob = implode( "\n", $examples );
		self::assertStringNotContainsString( 'Accra', $blob );
		self::assertStringNotContainsString( 'GHS', $blob );
		self::assertStringNotContainsString( 'Madina', $blob );
	}

	public function test_product_exceptions_customized_labels_are_business_only(): void {
		self::assertTrue( ConfigurationFieldCatalog::is_business_field( ConfigurationFieldKey::FULFILMENT_CHOICE ) );
		self::assertTrue( ConfigurationFieldCatalog::is_business_field( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) );
		self::assertTrue( ConfigurationFieldCatalog::is_private_field( ConfigurationFieldKey::SUPPLIER_ID ) );
		self::assertTrue( ConfigurationFieldCatalog::is_private_field( ConfigurationFieldKey::ORIGIN_ID ) );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Configuration/Catalog/ProductExceptionsQuery.php' );
		self::assertStringContainsString( 'business_field_keys', $source );
		self::assertStringContainsString( 'private_field_keys', $source );
		self::assertStringContainsString( 'technical_delivery_details_label', $source );
		self::assertStringContainsString( 'Technical delivery details', ConfigurationFieldCatalog::technical_delivery_details_label() );
	}

	public function test_product_delivery_panel_wording(): void {
		self::assertSame(
			'Product-specific delivery settings',
			ProductDeliveryPanel::currently_using_label( false, true, 'In Warehouse' )
		);
		self::assertSame(
			'In Warehouse Site-wide Default',
			ProductDeliveryPanel::currently_using_label( false, false, 'In Warehouse' )
		);
		self::assertSame(
			'Product Settings',
			ProductDeliveryPanel::currently_using_label( true, false, 'In Warehouse' )
		);

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/ProductDeliveryPanel.php' );
		self::assertStringContainsString( 'Currently using:', $source );
		self::assertStringContainsString( 'Product-specific delivery settings', $source );
		self::assertStringContainsString( 'Based on: %s Site-wide Default', $source );
		self::assertStringContainsString( 'This product has no special delivery settings.', $source );
		self::assertStringContainsString( 'business_field_keys', $source );
	}

	public function test_variation_product_settings_source_label(): void {
		self::assertSame(
			'Variation-specific',
			EffectiveConfigurationPreviewPage::source_label_for( 'Variation-specific', true )
		);
		self::assertSame(
			'Product Settings',
			EffectiveConfigurationPreviewPage::source_label_for( 'Product-specific', true )
		);
		self::assertSame(
			'Product Settings',
			EffectiveConfigurationPreviewPage::source_label_for( 'Site-wide Default', true )
		);
		self::assertSame(
			'Product-specific',
			EffectiveConfigurationPreviewPage::source_label_for( 'Product-specific', false )
		);
		self::assertSame(
			'Mixed',
			EffectiveConfigurationPreviewPage::provenance_summary_label(
				[
					[ 'provenance_label' => 'Site-wide Default' ],
					[ 'provenance_label' => 'Product-specific' ],
				]
			)
		);
		self::assertSame(
			'Product-specific',
			EffectiveConfigurationPreviewPage::provenance_summary_label(
				[
					[ 'provenance_label' => 'Product-specific' ],
					[ 'provenance_label' => 'product override' ],
				]
			)
		);
	}

	private function state_service( bool $setup_complete ): OperationalStateService {
		$settings = new SiteWideDefaultsSettings();
		if ( $setup_complete ) {
			$settings->save(
				[
					'setup_completed' => true,
					'active_profiles' => [ 'in_warehouse' ],
					'primary_profile' => 'in_warehouse',
				]
			);
		}

		$scopes = $this->createMock( ScopedConfigurationRepositoryInterface::class );
		$scopes->method( 'findByScopeAndSlice' )->willReturn( null );
		$summaries = new SiteWideDefaultSummary( $scopes );
		$flags     = new FeatureFlags();

		return new OperationalStateService(
			$flags,
			$settings,
			new SetupWizardProgress( $settings ),
			new ClassicCheckoutRuntimeActivation( $flags, $settings ),
			$summaries
		);
	}

	private function fake_role(): object {
		return new class() {
			/** @var array<string, bool> */
			public array $capabilities = [];

			public function add_cap( string $cap ): void {
				$this->capabilities[ $cap ] = true;
			}

			public function remove_cap( string $cap ): void {
				unset( $this->capabilities[ $cap ] );
			}
		};
	}
}
