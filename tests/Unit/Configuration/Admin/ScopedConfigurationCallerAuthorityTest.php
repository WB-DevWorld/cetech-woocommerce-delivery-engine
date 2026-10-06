<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Presentation\Admin\ProductExceptionsPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Product;

/** Synthetic caller regression tests; native WordPress principal qualification is separate. */
final class ScopedConfigurationCallerAuthorityTest extends TestCase {
	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;

	private InMemoryScopedConfigurationRepository $repository;
	private RecordingConfigurationAuditLogger $audit;
	private ScopedConfigurationPage $page;
	private ProductExceptionsPage $exceptions;
	private ProductExceptionsQuery $query;

	protected function setUp(): void {
		$this->remember_fixture_globals();
		$_GET = [];
		$_POST = [];
		AdminPageAccess::bind( null );
		FulfilmentProfileRegistry::reset_for_tests();
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects'] = [];
		$GLOBALS['cetech_de_test_caps'] = [ ScopedConfigurationAuthorization::CAPABILITY_PRODUCT => true ];
		$GLOBALS['cetech_de_test_edit_posts'] = [ 101 => true, 102 => false, 201 => true, 202 => true ];
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}
		$GLOBALS['cetech_de_test_wc_products'] = [
			101 => new WC_Product( [ 'id' => 101, 'type' => 'simple', 'name' => 'Owned Lamp' ] ),
			102 => new WC_Product( [ 'id' => 102, 'type' => 'simple', 'name' => 'Foreign Lamp' ] ),
			201 => new WC_Product( [ 'id' => 201, 'type' => 'variable', 'name' => 'Owned Chair' ] ),
			202 => new WC_Product( [ 'id' => 202, 'type' => 'variation', 'parent_id' => 201, 'name' => 'Oak Chair' ] ),
		];
		$this->repository = new InMemoryScopedConfigurationRepository();
		$resolver = new EffectiveConfigurationResolver( $this->repository, new EffectiveConfigurationValidator(), new PassthroughFulfilmentConstraintService() );
		$target = new ProductTargetResolver( new Requirements() );
		$authorization = new ScopedConfigurationAuthorization();
		$this->audit = new RecordingConfigurationAuditLogger();
		$offers  = new InMemoryDeliveryOfferRepository();
		$offers->seed( 1, [ 'route' => DeliveryRoute::LocalDelivery->value, 'public_label' => 'Local Van', 'internal_name' => 'Local Van' ] );
		$offers->seed( 2, [ 'route' => DeliveryRoute::Air->value, 'public_label' => 'Air Freight', 'internal_name' => 'Air Freight' ] );
		$admin = new ScopedConfigurationAdminService( $this->repository, $resolver, new ScopedConfigurationSubmissionParser(), new ProductVariationScopeGuard( $target ), new EntityLabelResolver( $offers ), new LegacyCategoryConfigurationInspector(), $this->audit );
		$catalog = new InMemoryCatalogIndex( [ 101 => [ 'label' => 'Owned Lamp', 'type' => 'simple' ], 102 => [ 'label' => 'Foreign Lamp', 'type' => 'simple' ], 201 => [ 'label' => 'Owned Chair', 'type' => 'variable', 'variations' => [ 202 => 'Oak Chair' ] ] ] );
		$settings = new SiteWideDefaultsSettings();
		$classifier = new CatalogInheritanceClassifier( $this->repository, $catalog, $resolver, $settings );
		$defaults = new SiteWideDefaultsService( $this->repository, $settings, $classifier, $resolver, $catalog );
		$actions = new AdminActionHandler( new AdminNoticeService() );
		$this->page = new ScopedConfigurationPage( $admin, $target, $actions, $authorization, $defaults, $offers );
		$this->query = new ProductExceptionsQuery( $this->repository, $catalog, $classifier, $settings, new OperationalReadinessAssessor( $resolver ) );
		$this->exceptions = new ProductExceptionsPage( $this->query, $defaults, $actions, $target, $authorization, $admin );
	}

	protected function tearDown(): void {
		$_GET = [];
		$_POST = [];
		AdminPageAccess::bind( null );
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	public function test_empty_picker_cannot_accept_a_foreign_parent_without_a_target(): void {
		$_GET = [ 'scope_type' => 'variation', 'parent_product_id' => '102' ];
		$this->denied_render( $this->page );
		self::assertNull( $this->repository->getGlobalConfiguration() );
		self::assertSame( 0, $this->repository->getWriteCalls() );
		$_GET = [ 'scope_type' => 'variation' ];
		self::assertStringContainsString( 'name="scope_type" value="variation"', $this->html( $this->page ) );
	}

	public function test_valid_nonce_cannot_save_or_reset_foreign_product_but_owned_save_succeeds(): void {
		$foreign = $this->seed( ConfigurationScopeType::Product, 102, '', 8 );
		$before = $this->repository->getWriteCalls();
		foreach ( [ ScopedConfigurationPage::ACTION_SAVE, ScopedConfigurationPage::ACTION_RESET ] as $action ) {
			$_POST = $this->post( $action, 'product', 102 );
			$this->redirect( fn () => $this->page->handle_actions() );
		}
		self::assertSame( $before, $this->repository->getWriteCalls() );
		self::assertSame( $foreign, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 102, '' ) );
		self::assertCount( 0, $this->audit->calls );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( 9, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' )?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertCount( 1, $this->audit->calls );
	}

	public function test_unknown_scope_render_never_creates_global_root(): void {
		foreach ( [ 'unknown', [ 'global' ] ] as $scope ) {
			$_GET = [ 'scope_type' => $scope ];
			$this->denied_render( $this->page );
		}
		self::assertNull( $this->repository->getGlobalConfiguration() );
		self::assertSame( 0, $this->repository->getWriteCalls() );
	}

	public function test_explicit_global_render_requires_global_capability(): void {
		$_GET = [ 'scope_type' => 'global' ];
		$this->denied_render( $this->page );
		self::assertNull( $this->repository->getGlobalConfiguration() );
	}

	public function test_foreign_render_denied_and_owned_render_preserves_nondefault_slice(): void {
		$this->seed( ConfigurationScopeType::Product, 102, 'in_store', 8 );
		$this->seed( ConfigurationScopeType::Product, 101, 'in_store', 7 );
		$before = $this->repository->getWriteCalls();
		$_GET = [ 'scope_type' => 'product', 'scope_id' => '102', 'slice_key' => 'in_store' ];
		$this->denied_render( $this->page );
		$_GET = [ 'scope_type' => 'product', 'scope_id' => '101', 'slice_key' => 'in_store' ];
		$html = $this->html( $this->page );
		self::assertStringContainsString( 'name="slice_key" value="in_store"', $html );
		self::assertSame( $before, $this->repository->getWriteCalls() );
	}

	public function test_wrong_variation_parent_denies_save_reset_and_render(): void {
		$variation = $this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$before = $this->repository->getWriteCalls();
		foreach ( [ ScopedConfigurationPage::ACTION_SAVE, ScopedConfigurationPage::ACTION_RESET ] as $action ) {
			$_POST = $this->post( $action, 'variation', 202, 'in_store', 101 );
			$this->redirect( fn () => $this->page->handle_actions() );
		}
		$_GET = [ 'scope_type' => 'variation', 'scope_id' => '202', 'slice_key' => 'in_store', 'parent_product_id' => '101' ];
		$this->denied_render( $this->page );
		self::assertSame( $before, $this->repository->getWriteCalls() );
		self::assertSame( $variation, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' ) );
	}

	public function test_correct_parent_reset_removes_only_selected_slice_and_audits_once(): void {
		$default = $this->seed( ConfigurationScopeType::Variation, 202, '', 5, 201 );
		$slice   = $this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_RESET, 'variation', 202, 'in_store', 201 );
		$_POST['expected_revision'] = (string) $slice->scope->config_version;
		$_POST['expected_scope_row_id'] = (string) $slice->scope->id;
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' ) );
		self::assertSame( $default, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, '' ) );
		self::assertCount( 1, $this->audit->calls );
		self::assertSame( 'scoped_configuration_reset', $this->audit->calls[0]['action'] );
		self::assertSame( 'in_store', $this->audit->calls[0]['previous']['slice_key'] );
		self::assertSame( 201, $this->audit->calls[0]['previous']['parent_product_id'] );
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertCount( 1, $this->audit->calls );
	}

	public function test_variation_save_requires_actual_parent_permission_and_preserves_other_slice(): void {
		$default = $this->seed( ConfigurationScopeType::Variation, 202, '', 5, 201 );
		$GLOBALS['cetech_de_test_edit_posts'][201] = false;
		$before = $this->repository->getWriteCalls();
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'variation', 202, 'in_store', 201 );
		$_POST['create_slice'] = '1';
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( $before, $this->repository->getWriteCalls() );
		$GLOBALS['cetech_de_test_edit_posts'][201] = true;
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( 9, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' )?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame( $default, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, '' ) );
	}

	public function test_unknown_scope_and_malformed_slice_post_do_not_mutate(): void {
		$this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$before = $this->repository->getWriteCalls();
		foreach ( [ [ 'scope_type' => 'unknown' ], [ 'scope_type' => [ 'product' ] ], [ 'slice_key' => [ 'in_store' ] ], [ 'slice_key' => 'unknown' ] ] as $override ) {
			$_POST = array_replace( $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 ), $override );
			$this->redirect( fn () => $this->page->handle_actions() );
		}
		self::assertSame( $before, $this->repository->getWriteCalls() );
		self::assertNull( $this->repository->getGlobalConfiguration() );
		self::assertCount( 0, $this->audit->calls );
	}

	public function test_malformed_identity_missing_target_and_wrong_wc_type_cannot_mutate(): void {
		$this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$before = $this->repository->getWriteCalls();
		foreach ( [ -101, '-101', '101junk', [ 101 ], 0, 999, 202 ] as $id ) {
			$_POST = $this->post( ScopedConfigurationPage::ACTION_RESET, 'product', $id );
			$this->redirect( fn () => $this->page->handle_actions() );
		}
		self::assertSame( $before, $this->repository->getWriteCalls() );
		self::assertCount( 0, $this->audit->calls );
	}

	public function test_exception_query_form_and_action_roundtrip_the_represented_slice(): void {
		$slice = $this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$default = $this->seed( ConfigurationScopeType::Variation, 202, '', 5, 201 );
		$rows = $this->query->list();
		self::assertSame( 'in_store', $rows[0]['slice_key'] );
		$html = $this->html( $this->exceptions );
		self::assertStringContainsString( 'slice_key=in_store', $html );
		self::assertStringContainsString( 'name="slice_key" value="in_store"', $html );
		self::assertStringContainsString( 'name="parent_product_id" value="201"', $html );
		self::assertStringContainsString( 'name="expected_revision" value="' . (string) $slice->scope->config_version . '"', $html );
		self::assertStringContainsString( 'name="expected_scope_row_id" value="' . (string) $slice->scope->id . '"', $html );
		self::assertStringContainsString( 'name="request_token"', $html );
		$_POST = [ 'cetech_de_action' => 'cetech_de_reset_exception', 'cetech_de_nonce' => 'test-nonce-cetech_de_reset_exception', 'item_type' => 'variation', 'item_id' => '202', 'slice_key' => 'in_store', 'parent_product_id' => '201', 'expected_revision' => (string) $slice->scope->config_version, 'expected_scope_row_id' => (string) $slice->scope->id, 'request_token' => 'exception-reset' ];
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' ) );
		self::assertSame( $default, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, '' ) );
		self::assertCount( 1, $this->audit->calls );
	}

	public function test_exception_unknown_type_and_foreign_item_are_denied_and_hidden(): void {
		$foreign = $this->seed( ConfigurationScopeType::Product, 102, 'in_store', 8 );
		$before = $this->repository->getWriteCalls();
		foreach ( [ 'invalid', 'product' ] as $type ) {
			$_POST = [ 'cetech_de_action' => 'cetech_de_reset_exception', 'cetech_de_nonce' => 'test-nonce-cetech_de_reset_exception', 'item_type' => $type, 'item_id' => '102', 'slice_key' => 'in_store' ];
			$this->redirect( fn () => $this->exceptions->handle_actions() );
		}
		self::assertStringNotContainsString( 'Foreign Lamp', $this->html( $this->exceptions ) );
		self::assertSame( $before, $this->repository->getWriteCalls() );
		self::assertSame( $foreign, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 102, 'in_store' ) );
	}

	public function test_rendered_forms_keep_the_opened_revision_and_distinct_tokens(): void {
		$scope = $this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$_GET  = [ 'scope_type' => 'product', 'scope_id' => '101' ];
		$html  = $this->html( $this->page );
		self::assertStringContainsString( 'name="expected_revision" value="' . (string) $scope->scope->config_version . '"', $html );
		self::assertStringContainsString( 'name="expected_scope_row_id" value="' . (string) $scope->scope->id . '"', $html );
		preg_match_all( '/name="request_token" value="([^"]+)"/', $html, $tokens );
		self::assertGreaterThanOrEqual( 2, count( $tokens[1] ) );
		self::assertNotSame( $tokens[1][0], $tokens[1][1] );

		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$_POST['expected_revision']     = '0';
		$_POST['expected_scope_row_id'] = (string) $scope->scope->id;
		$_POST['request_token']         = $tokens[1][0];
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( 5, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' )?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		$draft = get_transient( 'cetech_de_scoped_draft_7_product_101__none' );
		self::assertIsArray( $draft );
		self::assertSame( 0, $draft['expected_revision'] );
		self::assertSame( (int) $scope->scope->id, $draft['expected_scope_row_id'] );

		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 201 );
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( $draft, get_transient( 'cetech_de_scoped_draft_7_product_101__none' ) );
	}

	public function test_customize_no_change_uses_the_unchanged_version_notice(): void {
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$_POST['customize'] = '1';
		$this->redirect( fn () => $this->page->handle_actions() );
		$saved = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' );
		self::assertNotNull( $saved );
		$_POST['expected_revision']     = (string) $saved->scope->config_version;
		$_POST['expected_scope_row_id'] = (string) $saved->scope->id;
		$_POST['request_token']         = 'customize-same';
		$this->redirect( fn () => $this->page->handle_actions() );
		$notice = get_transient( 'cetech_de_admin_notice_7' );
		self::assertIsArray( $notice );
		self::assertSame( 'No semantic changes detected. Configuration version unchanged.', $notice['message'] );
	}

	public function test_customize_rerender_keeps_pickup_disable_and_an_empty_option_replacement(): void {
		$scope = $this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$_POST['customize']     = '1';
		$_POST['request_token'] = 'customize-stale';
		$_POST['fields']        = [
			ConfigurationFieldKey::PICKUP_LOCATION_ID      => [ 'mode' => 'disable', 'value' => '9' ],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS      => [ 'mode' => 'replace', 'members' => [] ],
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ],
		];
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( 5, $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' )?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );

		$_GET  = [ 'scope_type' => 'product', 'scope_id' => '101', 'customize' => '1' ];
		$html  = $this->html( $this->page );
		self::assertStringContainsString( 'value="disable" checked="checked"', $html );
		self::assertStringContainsString( 'value="replace" checked="checked"', $html );
		self::assertStringNotContainsString( 'value="1" checked=', $html );
		self::assertStringNotContainsString( 'value="2" checked=', $html );
		self::assertMatchesRegularExpression( '/cetech-de-compatible-option" hidden[^>]*>.*Air Freight/s', $html );
		self::assertMatchesRegularExpression( '/cetech-de-compatible-option" data-profiles="[^"]*"[^>]*>.*Local Van/s', $html );
		self::assertStringContainsString( 'name="expected_revision" value="' . (string) $scope->scope->config_version . '"', $html );
		self::assertStringContainsString( 'These unsaved values now apply to the current saved revision.', $html );
		self::assertStringNotContainsString( 'value="customize-stale"', $html );
	}

	public function test_an_omitted_member_list_stays_empty_on_customize_and_the_general_editor(): void {
		$this->repository->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ) ],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ) ]
		) );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$_POST['customize']     = '1';
		$_POST['request_token'] = 'empty-options';
		$_POST['fields']        = [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace' ],
		];
		$this->redirect( fn () => $this->page->handle_actions() );
		$_GET = [ 'scope_type' => 'product', 'scope_id' => '101', 'customize' => '1' ];
		$html = $this->html( $this->page );
		self::assertStringContainsString( 'value="replace" checked="checked"', $html );
		self::assertDoesNotMatchRegularExpression( '/value="1"[^>]*checked="checked"/', $html );
		self::assertStringContainsString( 'These unsaved values now apply to the current saved revision.', $html );
		preg_match( '/name="request_token" value="([^"]+)"/', $html, $token );
		preg_match( '/name="expected_revision" value="([^"]+)"/', $html, $revision );
		preg_match( '/name="expected_scope_row_id" value="([^"]+)"/', $html, $row );
		$_POST['expected_revision']     = $revision[1];
		$_POST['expected_scope_row_id'] = $row[1];
		$_POST['request_token']         = $token[1];
		unset( $_POST['fields'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] );
		$this->redirect( fn () => $this->page->handle_actions() );
		$saved = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' );
		self::assertSame( [], $saved?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
		$audits = count( $this->audit->calls );
		$_POST['fields'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] = [];
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( $audits, count( $this->audit->calls ) );
		self::assertSame( [], $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' )?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );

		$GLOBALS['cetech_de_test_caps'][ ScopedConfigurationAuthorization::CAPABILITY_GLOBAL ] = true;
		$this->repository->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Global, 0, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ) ]
		) );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'global', 0 );
		$_POST['request_token'] = 'global-empty-options';
		$_POST['fields']        = [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace' ],
		];
		$this->redirect( fn () => $this->page->handle_actions() );
		$_GET  = [ 'scope_type' => 'global' ];
		$editor = $this->html( $this->page );
		self::assertMatchesRegularExpression( '/value="replace"\s+checked="checked"/', $editor );
		self::assertDoesNotMatchRegularExpression( '/name="fields\[delivery_offer_ids\]\[members\]\[\]" value="1"[^>]*checked="checked"/', $editor );
		self::assertStringContainsString( 'name="fields[delivery_offer_ids][members][]" value="1"', $editor );
	}

	public function test_replaying_a_reset_does_not_claim_a_recreated_scope_was_removed(): void {
		$product = $this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$_POST   = [
			'cetech_de_action'       => 'cetech_de_reset_exception',
			'cetech_de_nonce'        => 'test-nonce-cetech_de_reset_exception',
			'item_type'              => 'product',
			'item_id'                => '101',
			'slice_key'              => '',
			'expected_revision'      => (string) $product->scope->config_version,
			'expected_scope_row_id'  => (string) $product->scope->id,
			'request_token'          => 'product-reset-replay',
		];
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' ) );
		$recreated = $this->seed( ConfigurationScopeType::Product, 101, '', 8 );
		$audits    = count( $this->audit->calls );
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		$kept = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' );
		self::assertSame( (int) $recreated->scope->id, (int) $kept?->scope->id );
		self::assertSame( (int) $recreated->scope->config_version, (int) $kept?->scope->config_version );
		self::assertSame( 8, $kept?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame( $audits, count( $this->audit->calls ) );
		self::assertSame( 'This reset was already completed. The recorded settings were not changed again.', get_transient( 'cetech_de_admin_notice_7' )['message'] );
		self::assertStringNotContainsString( 'Site-wide Defaults', (string) get_transient( 'cetech_de_admin_notice_7' )['message'] );

		$variation = $this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$_POST     = [
			'cetech_de_action'      => 'cetech_de_reset_exception',
			'cetech_de_nonce'       => 'test-nonce-cetech_de_reset_exception',
			'item_type'             => 'variation',
			'item_id'               => '202',
			'slice_key'             => 'in_store',
			'parent_product_id'     => '201',
			'expected_revision'     => (string) $variation->scope->config_version,
			'expected_scope_row_id' => (string) $variation->scope->id,
			'request_token'         => 'variation-reset-replay',
		];
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		$recreated_variation = $this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 4, 201 );
		$audits              = count( $this->audit->calls );
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		$kept_variation = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' );
		self::assertSame( (int) $recreated_variation->scope->id, (int) $kept_variation?->scope->id );
		self::assertSame( 4, $kept_variation?->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame( $audits, count( $this->audit->calls ) );
		self::assertSame( 'This reset was already completed. The recorded settings were not changed again.', get_transient( 'cetech_de_admin_notice_7' )['message'] );
		self::assertStringNotContainsString( 'product settings', (string) get_transient( 'cetech_de_admin_notice_7' )['message'] );

		$this->repository->deleteScope( ConfigurationScopeType::Variation, 202, 'in_store' );
		$removed = count( $this->audit->calls );
		$this->redirect( fn () => $this->exceptions->handle_actions() );
		self::assertSame( $removed, count( $this->audit->calls ) );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, 202, 'in_store' ) );
		self::assertSame( 'Nothing was reset. The item may already be using inherited settings.', get_transient( 'cetech_de_admin_notice_7' )['message'] );
	}

	public function test_known_stale_reload_adopts_the_current_revision_without_refreshing_an_uncertain_retry(): void {
		$scope = $this->seed( ConfigurationScopeType::Product, 101, '', 5 );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'product', 101 );
		$_POST['expected_revision']     = (string) $scope->scope->config_version;
		$_POST['expected_scope_row_id'] = (string) $scope->scope->id;
		$_POST['request_token']         = 'accepted-edit';
		$this->redirect( fn () => $this->page->handle_actions() );
		$current = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' );
		self::assertNotNull( $current );

		$_POST['expected_revision']     = (string) $scope->scope->config_version;
		$_POST['expected_scope_row_id'] = (string) $scope->scope->id;
		$_POST['request_token']         = 'stale-form';
		$_POST['fields'][ ConfigurationFieldKey::ESTIMATED_DELIVERY ] = [ 'mode' => 'override', 'value' => '4 days' ];
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( 9, $current->scalars[ ConfigurationFieldKey::PRIORITY ]->value );

		$_GET = [ 'scope_type' => 'product', 'scope_id' => '101' ];
		$html = $this->html( $this->page );
		self::assertStringContainsString( 'name="expected_revision" value="' . (string) $current->scope->config_version . '"', $html );
		self::assertStringContainsString( 'name="expected_scope_row_id" value="' . (string) $current->scope->id . '"', $html );
		self::assertStringContainsString( 'value="4 days"', $html );
		self::assertStringNotContainsString( 'value="stale-form"', $html );
		self::assertStringContainsString( 'These unsaved values now apply to the current saved revision.', $html );

		$GLOBALS['cetech_de_test_caps'][ ScopedConfigurationAuthorization::CAPABILITY_GLOBAL ] = true;
		$this->repository->refuse_publications = 1;
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'global', 0 );
		$_POST['request_token'] = 'global-create';
		$this->redirect( fn () => $this->page->handle_actions() );
		$_GET  = [ 'scope_type' => 'global' ];
		$retry = $this->html( $this->page );
		self::assertStringContainsString( 'name="expected_scope_row_id" value="0"', $retry );
		self::assertStringContainsString( 'name="request_token" value="global-create"', $retry );
		self::assertStringNotContainsString( 'These unsaved values now apply to the current saved revision.', $retry );
	}

	public function test_failed_global_publication_retries_the_original_row_identity(): void {
		$GLOBALS['cetech_de_test_caps'][ ScopedConfigurationAuthorization::CAPABILITY_GLOBAL ] = true;
		$this->repository->refuse_publications = 1;
		$_POST = $this->post( ScopedConfigurationPage::ACTION_SAVE, 'global', 0 );
		$_POST['request_token'] = 'global-create';
		$this->redirect( fn () => $this->page->handle_actions() );
		$stored = $this->repository->getGlobalConfiguration();
		self::assertNotNull( $stored );
		self::assertGreaterThan( 0, (int) $stored->scope->id );
		$_GET  = [ 'scope_type' => 'global' ];
		$html  = $this->html( $this->page );
		self::assertStringContainsString( 'name="expected_scope_row_id" value="0"', $html );
		self::assertStringContainsString( 'name="request_token" value="global-create"', $html );
		$writes = $this->repository->getWriteCalls();
		$_POST['expected_revision']     = '0';
		$_POST['expected_scope_row_id'] = '0';
		$this->redirect( fn () => $this->page->handle_actions() );
		self::assertSame( $writes, $this->repository->getWriteCalls() );
		self::assertSame( (int) $stored->scope->id, (int) $this->repository->getGlobalConfiguration()?->scope->id );
	}

	private function seed( ConfigurationScopeType $type, int $id, string $slice, int $priority, ?int $parent = null ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration( new ScopedConfiguration( new ConfigurationScope( null, $type, $id, $slice, $parent, RecordStatus::Active, 1, ConfigurationSource::Native, null ), [ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ) ] ) );
	}

	private function post( string $action, string $type, mixed $id, string $slice = '', ?int $parent = null ): array {
		return [ 'cetech_de_action' => $action, 'cetech_de_nonce' => 'test-nonce-' . $action, 'scope_type' => $type, 'scope_id' => $id, 'slice_key' => $slice, 'parent_product_id' => $parent, 'expected_revision' => '0', 'expected_scope_row_id' => '0', 'request_token' => 'caller-' . $action . '-' . ( is_scalar( $id ) ? (string) $id : 'malformed' ), 'fields' => [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '9' ] ] ];
	}

	private function redirect( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected redirect.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	private function denied_render( object $page ): void {
		try {
			$page->render();
			self::fail( 'Expected denied render.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'wp_die', $exception->getMessage() );
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
