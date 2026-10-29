<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsHomePage;
use CetechDeliveryEngine\Presentation\Admin\ProductExceptionsPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\RecordingConfigurationAuditLogger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use WC_Product;

/**
 * M2 admin defects: Save & Apply submits, failed input is kept, filtered empty state,
 * list context survives Back, toolbar controls carry accessible names.
 */
final class M2AdminDefectsTest extends TestCase {
	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;

	private const SAVE_PROFILE_ACTION = 'cetech_de_save_profile_defaults';
	private const RESET_ACTION        = 'cetech_de_reset_exception';

	private InMemoryScopedConfigurationRepository $repository;
	private ScopedConfigurationPage $page;
	private ProductExceptionsPage $exceptions;
	private DeliverySettingsHomePage $home;

	protected function setUp(): void {
		$this->remember_fixture_globals();
		$_GET  = [];
		$_POST = [];
		AdminPageAccess::bind( null );
		FulfilmentProfileRegistry::reset_for_tests();
		$GLOBALS['cetech_de_test_is_admin']   = true;
		$GLOBALS['cetech_de_test_logged_in']  = true;
		$GLOBALS['cetech_de_test_user_id']    = 7;
		$GLOBALS['cetech_de_test_options']    = [];
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects']  = [];
		$GLOBALS['cetech_de_test_caps']       = [
			ScopedConfigurationAuthorization::CAPABILITY_PRODUCT => true,
			Capabilities::SITE_WIDE                             => true,
		];
		$GLOBALS['cetech_de_test_edit_posts'] = [ 101 => true, 201 => true, 202 => true ];
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}
		$GLOBALS['cetech_de_test_wc_products'] = [
			101 => new WC_Product( [ 'id' => 101, 'type' => 'simple', 'name' => 'Owned Lamp' ] ),
			201 => new WC_Product( [ 'id' => 201, 'type' => 'variable', 'name' => 'Owned Chair' ] ),
			202 => new WC_Product( [ 'id' => 202, 'type' => 'variation', 'parent_id' => 201, 'name' => 'Oak Chair' ] ),
		];

		$this->repository = new InMemoryScopedConfigurationRepository();
		$resolver         = new EffectiveConfigurationResolver( $this->repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() );
		$target           = new ProductTargetResolver( new Requirements() );
		$authorization    = new ScopedConfigurationAuthorization();
		$offers           = new InMemoryDeliveryOfferRepository();
		$offers->seed( 1, [ 'route' => DeliveryRoute::LocalDelivery->value, 'public_label' => 'Local Van', 'internal_name' => 'Local Van' ] );
		$labels     = new EntityLabelResolver( $offers );
		$admin      = new ScopedConfigurationAdminService( $this->repository, $resolver, new ScopedConfigurationSubmissionParser(), new ProductVariationScopeGuard( $target ), $labels, new LegacyCategoryConfigurationInspector(), new RecordingConfigurationAuditLogger() );
		$catalog    = new InMemoryCatalogIndex( [ 101 => [ 'label' => 'Owned Lamp', 'type' => 'simple' ], 201 => [ 'label' => 'Owned Chair', 'type' => 'variable', 'variations' => [ 202 => 'Oak Chair' ] ] ] );
		$settings   = new SiteWideDefaultsSettings();
		$classifier = new CatalogInheritanceClassifier( $this->repository, $catalog, $resolver, $settings );
		$defaults   = new SiteWideDefaultsService( $this->repository, $settings, $classifier, $resolver, $catalog );
		$actions    = new AdminActionHandler( new AdminNoticeService() );
		$query      = new ProductExceptionsQuery( $this->repository, $catalog, $classifier, $settings, new OperationalReadinessAssessor( $resolver ) );

		$this->page       = new ScopedConfigurationPage( $admin, $target, $actions, $authorization, $defaults, $offers );
		$this->exceptions = new ProductExceptionsPage( $query, $defaults, $actions, $target, $authorization, $admin );
		$this->home       = new DeliverySettingsHomePage(
			$defaults,
			$settings,
			new SiteWideDefaultSummary( $this->repository, $offers, null, $labels ),
			( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor(),
			$this->repository,
			$labels,
			$actions,
			$offers,
			new InMemoryPickupLocationRepository()
		);
	}

	protected function tearDown(): void {
		$_GET  = [];
		$_POST = [];
		AdminPageAccess::bind( null );
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	public function test_exceptions_toolbar_controls_have_accessible_names(): void {
		$html = $this->html( $this->exceptions );

		foreach ( [ 'cetech-de-exception-search', 'cetech-de-exception-fulfilment', 'cetech-de-exception-type', 'cetech-de-exception-status' ] as $id ) {
			self::assertStringContainsString( '<label class="screen-reader-text" for="' . $id . '">', $html, $id . ' needs a label' );
			self::assertStringContainsString( 'id="' . $id . '"', $html );
		}
		self::assertStringNotContainsString( '<select name="fulfilment">', $html );
	}

	public function test_exceptions_empty_state_tells_filtered_from_unfiltered(): void {
		$unfiltered = $this->html( $this->exceptions );
		self::assertStringContainsString( 'No product exceptions.', $unfiltered );
		self::assertStringContainsString( 'All products currently follow their normal delivery defaults.', $unfiltered );
		self::assertStringNotContainsString( 'Clear filters', $unfiltered );

		$_GET     = [ 's' => 'nothing-matches' ];
		$filtered = $this->html( $this->exceptions );
		self::assertStringContainsString( 'No product exceptions match these filters.', $filtered );
		self::assertStringNotContainsString( 'All products currently follow their normal delivery defaults.', $filtered );
		self::assertStringContainsString( 'Clear filters', $filtered );
		self::assertStringContainsString( 'page=' . ProductExceptionsPage::SLUG . '"', $filtered );
	}

	public function test_exceptions_list_context_survives_edit_links_and_reset(): void {
		$seeded = $this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$_GET   = [ 's' => 'Owned', 'exception_type' => 'product' ];
		$html   = $this->html( $this->exceptions );

		self::assertStringContainsString( 'return_to=' . ProductExceptionsPage::SLUG, $html );
		self::assertStringContainsString( 'return_s=Owned', $html );
		self::assertStringContainsString( 'return_exception_type=product', $html );
		self::assertStringContainsString( 'name="return_to" value="' . ProductExceptionsPage::SLUG . '"', $html );
		self::assertStringContainsString( 'name="return_s" value="Owned"', $html );

		$_POST = [
			'cetech_de_action'      => self::RESET_ACTION,
			'cetech_de_nonce'       => 'test-nonce-' . self::RESET_ACTION,
			'item_type'             => 'product',
			'item_id'               => '101',
			'slice_key'             => '',
			'expected_revision'     => (string) $seeded->scope->config_version,
			'expected_scope_row_id' => (string) $seeded->scope->id,
			'request_token'         => 'm2-reset-keeps-filters',
			'return_to'             => ProductExceptionsPage::SLUG,
			'return_s'              => 'Owned',
			'return_exception_type' => 'product',
		];
		$this->redirect( fn () => $this->exceptions->handle_actions() );

		$location = $this->last_redirect();
		self::assertStringContainsString( 'page=' . ProductExceptionsPage::SLUG, $location );
		self::assertStringContainsString( 's=Owned', $location );
		self::assertStringContainsString( 'exception_type=product', $location );
	}

	public function test_scoped_editor_links_back_to_the_filtered_list_and_keeps_context_on_save(): void {
		$this->seed( ConfigurationScopeType::Product, 101, '', 5 );

		$_GET = [ 'scope_type' => 'product', 'scope_id' => '101', 'customize' => '1' ];
		self::assertStringNotContainsString( 'Back to Product Exceptions', $this->html( $this->page ) );

		$_GET = [ 'scope_type' => 'product', 'scope_id' => '101', 'customize' => '1', 'return_to' => ProductExceptionsPage::SLUG, 'return_s' => 'Owned' ];
		$html = $this->html( $this->page );
		self::assertStringContainsString( 'Back to Product Exceptions', $html );
		self::assertStringContainsString( 'page=' . ProductExceptionsPage::SLUG . '&amp;s=Owned', $html );
		self::assertStringContainsString( 'name="return_to" value="' . ProductExceptionsPage::SLUG . '"', $html );
		self::assertStringContainsString( 'name="return_s" value="Owned"', $html );

		$_GET  = [];
		$_POST = [
			'cetech_de_action'      => ScopedConfigurationPage::ACTION_SAVE,
			'cetech_de_nonce'       => 'test-nonce-' . ScopedConfigurationPage::ACTION_SAVE,
			'scope_type'            => 'product',
			'scope_id'              => 101,
			'slice_key'             => '',
			'parent_product_id'     => null,
			'expected_revision'     => '0',
			'expected_scope_row_id' => '0',
			'request_token'         => 'm2-save-keeps-context',
			'customize'             => '1',
			'return_to'             => ProductExceptionsPage::SLUG,
			'return_s'              => 'Owned',
			'fields'                => [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '9' ] ],
		];
		$this->redirect( fn () => $this->page->handle_actions() );

		$location = $this->last_redirect();
		self::assertStringContainsString( 'page=' . ScopedConfigurationPage::SLUG, $location );
		self::assertStringContainsString( 'return_to=' . ProductExceptionsPage::SLUG, $location );
		self::assertStringContainsString( 'return_s=Owned', $location );

		$_POST['return_to'] = 'somewhere-else';
		$_POST['request_token'] = 'm2-save-foreign-context';
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertStringNotContainsString( 'return_', $this->last_redirect() );
	}

	public function test_save_and_apply_is_a_submit_of_the_profile_form(): void {
		$_GET = [ 'profile' => FulfilmentAvailability::InStore->value ];
		$html = $this->html( $this->home );

		self::assertStringContainsString( '<button type="submit" class="button" name="' . DeliverySettingsHomePage::SAVE_AND_APPLY_FIELD . '" value="1">Save &amp; Apply Site-wide</button>', $html );
		self::assertDoesNotMatchRegularExpression( '/<a class="button" href="[^"]*' . preg_quote( SetupWizardPage::SLUG, '/' ) . '[^"]*">Save/', $html );

		$_POST = $this->profile_post( [ 'delivery' ], 'delivery' );
		$_POST[ DeliverySettingsHomePage::SAVE_AND_APPLY_FIELD ] = '1';
		$this->redirect( fn () => $this->home->handle_actions() );

		self::assertStringContainsString( 'page=' . SetupWizardPage::SLUG, $this->last_redirect() );
		$saved = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, FulfilmentAvailability::InStore->value );
		self::assertSame( '7–9 business days', $saved?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
	}

	public function test_profile_editor_keeps_the_submitted_input_after_a_validation_error(): void {
		// Store Pickup enabled without a pickup location is rejected by InStoreMethodSelection.
		$_POST = $this->profile_post( [ 'delivery', 'store_pickup' ], 'store_pickup' );
		$this->redirect( fn () => $this->home->handle_actions() );

		$location = $this->last_redirect();
		self::assertStringContainsString( 'page=' . DeliverySettingsHomePage::SLUG, $location );
		self::assertStringContainsString( 'profile=' . FulfilmentAvailability::InStore->value, $location );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, FulfilmentAvailability::InStore->value )?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ] ?? null );

		$_POST = [];
		$_GET  = [ 'profile' => FulfilmentAvailability::InStore->value ];
		$html  = $this->html( $this->home );

		self::assertStringContainsString( 'Your unsaved changes are shown below.', $html );
		self::assertStringContainsString( 'value="7–9 business days"', $html );
		self::assertMatchesRegularExpression( '/name="available_methods\[\]" value="store_pickup"[^>]*checked/', $html );
		self::assertMatchesRegularExpression( '/name="default_customer_choice" value="store_pickup"[^>]*checked/', $html );
		self::assertMatchesRegularExpression( '/name="fields\[delivery_offer_ids\]\[members\]\[\]" value="1"[^>]*checked/', $html );

		// The draft is consumed once; the next render shows the saved (empty) values again.
		$again = $this->html( $this->home );
		self::assertStringNotContainsString( 'Your unsaved changes are shown below.', $again );
		self::assertStringNotContainsString( 'value="7–9 business days"', $again );
	}

	public function test_zone_editor_controls_have_accessible_names(): void {
		$zones = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		self::assertSame( 2, substr_count( $zones, "__( 'Remove %s', 'cetech-woocommerce-delivery-engine' )" ) );
		self::assertStringContainsString( "esc_attr__( 'Remove postcode', 'cetech-woocommerce-delivery-engine' )", $zones );
		self::assertStringContainsString( "esc_attr__( 'Postcode match mode', 'cetech-woocommerce-delivery-engine' )", $zones );
		self::assertStringContainsString( "esc_attr__( 'Condition type', 'cetech-woocommerce-delivery-engine' )", $zones );
		self::assertDoesNotMatchRegularExpression( '/<button type="button" class="button-link" data-remove-member="[^"]*">×/', $zones );
	}

	/**
	 * @param list<string> $methods
	 * @return array<string, mixed>
	 */
	private function profile_post( array $methods, string $default_choice ): array {
		return [
			'cetech_de_action'        => self::SAVE_PROFILE_ACTION,
			'cetech_de_nonce'         => 'test-nonce-' . self::SAVE_PROFILE_ACTION,
			'profile_key'             => FulfilmentAvailability::InStore->value,
			'available_methods'       => $methods,
			'default_customer_choice' => $default_choice,
			'fields'                  => [
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '7–9 business days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '1' ] ],
				ConfigurationFieldKey::PICKUP_LOCATION_ID => [ 'mode' => 'override', 'value' => '' ],
			],
		];
	}

	private function seed( ConfigurationScopeType $type, int $id, string $slice, int $priority, ?int $parent = null ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, $type, $id, $slice, $parent, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ) ]
			)
		);
	}

	private function last_redirect(): string {
		$redirects = $GLOBALS['cetech_de_test_redirects'] ?? [];
		self::assertNotEmpty( $redirects, 'Expected a redirect to be recorded.' );

		return (string) end( $redirects );
	}

	private function redirect( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected redirect.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	private function html( object $page ): string {
		ob_start();
		try {
			$page->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
