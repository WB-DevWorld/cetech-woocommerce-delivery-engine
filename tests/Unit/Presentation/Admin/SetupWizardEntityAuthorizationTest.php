<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\ContextualEntityService;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercises real wizard dispatch and entity writes; no physical WordPress certification. */
final class SetupWizardEntityAuthorizationTest extends TestCase {
	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;

	private SetupWizardPage $page;
	private InMemoryDeliveryOfferRepository $offers;
	private InMemoryDestinationZoneRepository $zones;
	private InMemoryDestinationRuleRepository $rules;
	private InMemoryPickupLocationRepository $pickups;
	private InMemoryScopedConfigurationRepository $scopes;
	private SetupWizardProgress $progress;
	/** @var array<int, array<string, mixed>> */
	private array $rate_rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->remember_fixture_globals();
		$_POST = [];
		$_GET = [];
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_caps'] = [];
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects'] = [];
		AdminPageAccess::bind( null );
		FulfilmentProfileRegistry::reset_for_tests();
		WooCommerceCountryCatalog::override_for_tests( [ 'GH' => 'Ghana' ] );
		// Same fallback as existing canonical-address tests; no production bootstrap mutation.
		if ( ! function_exists( 'wc_get_base_location' ) ) {
			eval( 'function wc_get_base_location(): array { return array( "country" => "GH", "state" => "AA" ); }' );
		}
		$this->offers = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 10, [ 'internal_code' => 'existing', 'public_label' => 'Existing', 'route' => DeliveryRoute::LocalDelivery->value, 'status' => 'active' ] );
		$this->zones = new InMemoryDestinationZoneRepository();
		$this->zones->save( [ 'id' => 20, 'internal_code' => 'existing', 'internal_name' => 'Existing area', 'status' => 'active' ] );
		$this->rules = new InMemoryDestinationRuleRepository();
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->scopes = new InMemoryScopedConfigurationRepository();
		$this->rate_rows = [];
		$rates = $this->createMock( RateCardRepositoryInterface::class );
		$rates->method( 'findByCode' )->willReturn( null );
		$rates->method( 'save' )->willReturnCallback( function ( array $row ): int {
			$id = count( $this->rate_rows ) + 1;
			$row['id'] = $id;
			$this->rate_rows[ $id ] = $row;
			return $id;
		} );
		$entities = new ContextualEntityService(
			$this->offers, $this->zones, $this->rules, $rates, $this->pickups,
			new DeliveryOfferValidator(), new DestinationZoneValidator(), new DestinationRuleValidator(),
			new RateCardValidator(
				$this->offers, $this->zones,
				$this->createMock( LogisticsProfileRepositoryInterface::class ),
				$this->createMock( SupplierRepositoryInterface::class ),
				$this->createMock( OriginRepositoryInterface::class )
			),
			new PickupLocationValidator()
		);
		$settings = new SiteWideDefaultsSettings();
		$this->progress = new SetupWizardProgress( $settings );
		$this->progress->save( [ 'status' => SetupWizardProgress::STATUS_IN_PROGRESS, 'step' => 3, 'draft' => [ 'active_profiles' => [ 'in_warehouse', 'in_store' ], 'primary_profile' => 'in_warehouse' ] ] );
		$catalog = new InMemoryCatalogIndex();
		$resolver = new EffectiveConfigurationResolver( $this->scopes, new EffectiveConfigurationValidator() );
		$classifier = new CatalogInheritanceClassifier( $this->scopes, $catalog, $resolver, $settings );
		$defaults = new SiteWideDefaultsService( $this->scopes, $settings, $classifier, $resolver, $catalog );
		$summaries = new SiteWideDefaultSummary( $this->scopes );
		$flags = new FeatureFlags();
		$runtime = new ClassicCheckoutRuntimeActivation( $flags, $settings );
		$operational = new OperationalStateService( $flags, $settings, $this->progress, $runtime, $summaries );
		$this->page = new SetupWizardPage(
			$this->progress, $defaults, $settings, $summaries, $entities, $this->scopes,
			$this->offers, $this->zones, $rates, $this->pickups,
			new NeedsAttentionQuery( $catalog, new OperationalReadinessAssessor( $resolver ), $operational, $classifier, $this->scopes ),
			new AdminActionHandler( new AdminNoticeService() ), $runtime,
			new WooCommerceShippingReadiness( new Requirements() ), $operational
		);
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET = [];
		AdminPageAccess::bind( null );
		FulfilmentProfileRegistry::reset_for_tests();
		WooCommerceCountryCatalog::override_for_tests( null );
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	/** @return array<string, array{string, string}> */
	public static function entity_actions(): array {
		return [
			'option' => [ SetupWizardPage::ACTION_CREATE_OPTION, 'manage_delivery_offers' ],
			'charge' => [ SetupWizardPage::ACTION_CREATE_CHARGE, 'manage_delivery_rate_cards' ],
			'area' => [ SetupWizardPage::ACTION_CREATE_AREA, 'manage_delivery_zones' ],
			'pickup' => [ SetupWizardPage::ACTION_CREATE_PICKUP, Capabilities::PICKUP ],
		];
	}

	/** @dataProvider entity_actions */
	public function test_settings_permission_and_valid_nonce_cannot_create_entity( string $action, string $capability ): void {
		unset( $capability );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true ];
		$_POST = $this->post_for( $action );
		$before = $this->snapshot();
		self::assertSame( 'wp_die', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider entity_actions */
	public function test_exact_entity_permission_without_settings_cannot_create_entity( string $action, string $capability ): void {
		$GLOBALS['cetech_de_test_caps'] = [ $capability => true ];
		$_POST = $this->post_for( $action );
		$before = $this->snapshot();
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider entity_actions */
	public function test_exact_entity_permission_creates_entity_through_wizard( string $action, string $capability ): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, $capability => true ];
		$_POST = $this->post_for( $action );
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		switch ( $action ) {
			case SetupWizardPage::ACTION_CREATE_OPTION:
				self::assertSame( 2, $this->offers->count_all() );
				$scope = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, 'in_warehouse' );
				self::assertNotNull( $scope );
				self::assertSame( [ 11 ], $scope->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
				break;
			case SetupWizardPage::ACTION_CREATE_CHARGE:
				self::assertCount( 1, $this->rate_rows );
				self::assertSame( 20, $this->rate_rows[1]['destination_zone_id'] );
				self::assertSame( 1, $this->zones->count_all() );
				break;
			case SetupWizardPage::ACTION_CREATE_AREA:
				self::assertSame( 2, $this->zones->count_all() );
				self::assertSame( 1, $this->rules->count_all() );
				break;
			case SetupWizardPage::ACTION_CREATE_PICKUP:
				self::assertSame( 1, $this->pickups->count_all() );
				self::assertSame( 1, $this->progress->read()['draft']['pickup_location_ids']['in_store'] );
				break;
		}
	}

	/** @dataProvider entity_actions */
	public function test_missing_wrong_or_other_action_nonce_cannot_create_entity( string $action, string $capability ): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, $capability => true ];
		foreach ( [ null, 'wrong', 'test-nonce-' . SetupWizardPage::ACTION_SAVE_STEP ] as $nonce ) {
			$_POST = $this->post_for( $action );
			if ( null === $nonce ) {
				unset( $_POST['cetech_de_nonce'] );
			} else {
				$_POST['cetech_de_nonce'] = $nonce;
			}
			$before = $this->snapshot();
			self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
			self::assertSame( $before, $this->snapshot() );
		}
	}

	/** @dataProvider entity_actions */
	public function test_non_admin_request_cannot_create_entity( string $action, string $capability ): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, $capability => true ];
		$GLOBALS['cetech_de_test_is_admin'] = false;
		$_POST = $this->post_for( $action );
		$before = $this->snapshot();
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	public function test_zone_permission_does_not_authorize_pickup_creation(): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_delivery_zones' => true ];
		$_POST = $this->post_for( SetupWizardPage::ACTION_CREATE_PICKUP );
		$before = $this->snapshot();
		self::assertSame( 'wp_die', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	/** @dataProvider entity_actions */
	public function test_restricted_vendor_with_entity_permissions_cannot_create_entity( string $action, string $capability ): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, $capability => true ];
		AdminPageAccess::bind( new WcfmVendorIsolation( static fn ( ?int $user_id ): bool => true, static fn (): bool => true ) );
		$_POST = $this->post_for( $action );
		$before = $this->snapshot();
		self::assertSame( 'wp_die', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	public function test_charge_cannot_implicitly_create_default_area_without_zone_permission(): void {
		$this->zones->hardDelete( 20 );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_delivery_rate_cards' => true ];
		$_POST = $this->post_for( SetupWizardPage::ACTION_CREATE_CHARGE );
		$_POST['charge_area_id'] = 0;
		$before = $this->snapshot();
		self::assertSame( 'wp_die', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	public function test_charge_can_implicitly_create_default_area_with_zone_permission(): void {
		$this->zones->hardDelete( 20 );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_delivery_rate_cards' => true, 'manage_delivery_zones' => true ];
		$_POST = $this->post_for( SetupWizardPage::ACTION_CREATE_CHARGE );
		$_POST['charge_area_id'] = 0;
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		self::assertSame( 1, $this->zones->count_all() );
		self::assertSame( 1, $this->rules->count_all() );
		self::assertCount( 1, $this->rate_rows );
		self::assertSame( 1, $this->rate_rows[1]['destination_zone_id'] );
	}

	public function test_charge_can_use_existing_default_area_without_zone_permission(): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_delivery_rate_cards' => true ];
		$_POST = $this->post_for( SetupWizardPage::ACTION_CREATE_CHARGE );
		$_POST['charge_area_id'] = 0;
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		self::assertCount( 1, $this->rate_rows );
		self::assertSame( 20, $this->rate_rows[1]['destination_zone_id'] );
		self::assertSame( 1, $this->zones->count_all() );
		self::assertSame( 0, $this->rules->count_all() );
	}

	public function test_revoked_pickup_permission_denies_later_request(): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, Capabilities::PICKUP => true ];
		$_POST = $this->post_for( SetupWizardPage::ACTION_CREATE_PICKUP );
		self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
		self::assertSame( 1, $this->pickups->count_all() );
		unset( $GLOBALS['cetech_de_test_caps'][ Capabilities::PICKUP ] );
		$_POST['pickup_name'] = 'Second Pickup';
		$before = $this->snapshot();
		self::assertSame( 'wp_die', $this->dispatch() );
		self::assertSame( $before, $this->snapshot() );
	}

	public function test_settings_only_save_later_keeps_both_existing_nonce_paths(): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true ];
		foreach ( [ false, true ] as $alternate_nonce ) {
			$_POST = [
				'cetech_de_action' => SetupWizardPage::ACTION_SAVE_LATER,
				'cetech_de_nonce' => 'test-nonce-' . SetupWizardPage::ACTION_SAVE_LATER,
				'setup_step' => $alternate_nonce ? 5 : 4,
			];
			if ( $alternate_nonce ) {
				unset( $_POST['cetech_de_nonce'] );
				$_POST['cetech_de_save_later'] = 1;
				$_POST['cetech_de_save_later_nonce'] = 'test-nonce-' . SetupWizardPage::ACTION_SAVE_LATER;
			}
			$before = $this->snapshot();
			self::assertSame( 'cetech_de_test_redirect', $this->dispatch() );
			self::assertSame( $alternate_nonce ? 5 : 4, $this->progress->read()['step'] );
			$after = $this->snapshot();
			unset( $before['progress'], $after['progress'] );
			self::assertSame( $before, $after );
		}
	}

	/** @return array<string, mixed> */
	private function post_for( string $action ): array {
		return [
			'cetech_de_action' => $action, 'cetech_de_nonce' => 'test-nonce-' . $action,
			'setup_step' => 3, 'profile_index' => 0, 'profile_key' => 'in_store',
			'option_name' => 'New option', 'option_route' => DeliveryRoute::LocalDelivery->value, 'option_active' => 1,
			'charge_name' => 'New charge', 'charge_style' => 'flat', 'charge_amount' => '10', 'charge_option_id' => 10, 'charge_area_id' => 20,
			'area_name' => 'New area', 'area_city' => '',
			'pickup_name' => 'New Pickup', 'pickup_address' => '10 Main Street', 'pickup_city' => 'Accra',
		];
	}

	/** @return array<string, mixed> */
	private function snapshot(): array {
		return [
			'offers' => $this->offers->list(), 'zones' => $this->zones->list(), 'rules' => $this->rules->list(),
			'pickups' => $this->pickups->list(), 'rates' => $this->rate_rows,
			'scope_writes' => $this->scopes->getWriteCalls(),
			'progress' => get_option( SetupWizardProgress::OPTION_NAME, [] ),
		];
	}

	private function dispatch(): string {
		try {
			$this->page->handle_actions();
		} catch ( RuntimeException $exception ) {
			if ( in_array( $exception->getMessage(), [ 'wp_die', 'cetech_de_test_redirect' ], true ) ) {
				return $exception->getMessage();
			}
			throw $exception;
		}
		self::fail( 'Expected the wizard action to deny or redirect.' );
	}
}
