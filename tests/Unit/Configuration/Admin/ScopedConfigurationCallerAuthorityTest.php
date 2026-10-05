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
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
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
		$admin = new ScopedConfigurationAdminService( $this->repository, $resolver, new ScopedConfigurationSubmissionParser(), new ProductVariationScopeGuard( $target ), new EntityLabelResolver(), new LegacyCategoryConfigurationInspector(), $this->audit );
		$catalog = new InMemoryCatalogIndex( [ 101 => [ 'label' => 'Owned Lamp', 'type' => 'simple' ], 102 => [ 'label' => 'Foreign Lamp', 'type' => 'simple' ], 201 => [ 'label' => 'Owned Chair', 'type' => 'variable', 'variations' => [ 202 => 'Oak Chair' ] ] ] );
		$settings = new SiteWideDefaultsSettings();
		$classifier = new CatalogInheritanceClassifier( $this->repository, $catalog, $resolver, $settings );
		$defaults = new SiteWideDefaultsService( $this->repository, $settings, $classifier, $resolver, $catalog );
		$actions = new AdminActionHandler( new AdminNoticeService() );
		$this->page = new ScopedConfigurationPage( $admin, $target, $actions, $authorization, $defaults );
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
		$this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$_POST = $this->post( ScopedConfigurationPage::ACTION_RESET, 'variation', 202, 'in_store', 201 );
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
		$this->seed( ConfigurationScopeType::Variation, 202, 'in_store', 6, 201 );
		$default = $this->seed( ConfigurationScopeType::Variation, 202, '', 5, 201 );
		$rows = $this->query->list();
		self::assertSame( 'in_store', $rows[0]['slice_key'] );
		$html = $this->html( $this->exceptions );
		self::assertStringContainsString( 'slice_key=in_store', $html );
		self::assertStringContainsString( 'name="slice_key" value="in_store"', $html );
		self::assertStringContainsString( 'name="parent_product_id" value="201"', $html );
		$_POST = [ 'cetech_de_action' => 'cetech_de_reset_exception', 'cetech_de_nonce' => 'test-nonce-cetech_de_reset_exception', 'item_type' => 'variation', 'item_id' => '202', 'slice_key' => 'in_store', 'parent_product_id' => '201' ];
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

	private function seed( ConfigurationScopeType $type, int $id, string $slice, int $priority, ?int $parent = null ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration( new ScopedConfiguration( new ConfigurationScope( null, $type, $id, $slice, $parent, RecordStatus::Active, 1, ConfigurationSource::Native, null ), [ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ) ] ) );
	}

	private function post( string $action, string $type, mixed $id, string $slice = '', ?int $parent = null ): array {
		return [ 'cetech_de_action' => $action, 'cetech_de_nonce' => 'test-nonce-' . $action, 'scope_type' => $type, 'scope_id' => $id, 'slice_key' => $slice, 'parent_product_id' => $parent, 'fields' => [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '9' ] ] ];
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
