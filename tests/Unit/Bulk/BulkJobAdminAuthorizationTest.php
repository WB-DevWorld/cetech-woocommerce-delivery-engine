<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAccess;
use CetechDeliveryEngine\Presentation\Admin\BulkJobProgressEndpoint;
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/bulk-ajax-stubs.php';

final class BulkJobAdminAuthorizationTest extends TestCase {
	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
	private InMemoryBulkJobRepository $jobs;
	private InMemoryBoundedQueue $queue;
	private BulkJobEngine $engine;
	private BulkToolsPage $page;

	protected function setUp(): void {
		parent::setUp();
		$this->remember_fixture_globals();
		AdminPageAccess::bind( null );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_product_delivery_rules' => true ];
		$GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects'] = [];
		$GLOBALS['cetech_de_test_ajax_response'] = null;
		$_POST = [];
		$_GET  = [];
		$this->jobs = new InMemoryBulkJobRepository();
		$this->queue = new InMemoryBoundedQueue();
		$worker = new BulkJobWorker(
			$this->jobs,
			new InMemoryCatalogTargetQuery(),
			new CatalogScopeMutator( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ),
			$this->queue
		);
		$this->engine = new BulkJobEngine( $this->jobs, $this->queue, $worker );
		$exporter = new ConfigurationExporter(
			$this->createMock( DeliveryOfferRepositoryInterface::class ),
			$this->createMock( DestinationZoneRepositoryInterface::class ),
			$this->createMock( DestinationRuleRepositoryInterface::class ),
			$this->createMock( RateCardRepositoryInterface::class ),
			$this->createMock( LogisticsProfileRepositoryInterface::class ),
			$this->createMock( PickupLocationRepositoryInterface::class ),
			$this->createMock( SupplierRepositoryInterface::class ),
			$this->createMock( OriginRepositoryInterface::class )
		);
		$this->page = new BulkToolsPage( $this->engine, $this->jobs, new AdminActionHandler( new AdminNoticeService() ), $exporter, new CatalogCsvMapper() );
	}

	protected function tearDown(): void {
		AdminPageAccess::bind( null );
		$GLOBALS['cetech_de_test_caps'] = [];
		$_POST = [];
		$_GET = [];
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	public static function restricted_actions(): array {
		$cases = [];
		foreach ( [ BulkOperationType::RateCardUpdate, BulkOperationType::ConfigImport ] as $operation ) {
			foreach ( [ BulkToolsPage::ACTION_APPLY, BulkToolsPage::ACTION_CANCEL, BulkToolsPage::ACTION_ROLLBACK, BulkToolsPage::ACTION_CONTINUE ] as $action ) {
				$cases[ $operation->value . ':' . $action ] = [ $operation, $action ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'restricted_actions' )]
	public function test_valid_nonce_and_product_capability_cannot_operate_stored_restricted_jobs( BulkOperationType $operation, string $action ): void {
		$status = match ( $action ) {
			BulkToolsPage::ACTION_APPLY => BulkJobStatus::Ready,
			BulkToolsPage::ACTION_ROLLBACK => BulkJobStatus::Completed,
			default => BulkJobStatus::Previewing,
		};
		$job = $this->seed( $operation, $status );
		$this->post_action( $action, (int) $job->id );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ), 'The denial must precede every job save or worker claim.' );
		self::assertSame( 1, $this->jobs->count_jobs(), 'Rollback must not create a child.' );
		self::assertSame( 0, $this->queue->queued_count() );
		self::assertSame( 'error', get_transient( 'cetech_de_admin_notice_7' )['type'] );
	}

	public function test_exact_rate_permission_allows_real_apply_and_its_later_revocation_denies(): void {
		$job = $this->seed( BulkOperationType::RateCardUpdate );
		$GLOBALS['cetech_de_test_caps']['manage_delivery_rate_cards'] = true;
		$this->post_action( BulkToolsPage::ACTION_APPLY, (int) $job->id );
		$queued = $this->jobs->find_job( (int) $job->id );
		self::assertSame( BulkJobStatus::Queued, $queued->status );
		self::assertSame( 1, $this->queue->queued_count() );
		unset( $GLOBALS['cetech_de_test_caps']['manage_delivery_rate_cards'] );
		$this->post_action( BulkToolsPage::ACTION_CANCEL, (int) $job->id );
		self::assertSame( $queued, $this->jobs->find_job( (int) $job->id ) );
		self::assertSame( 1, $this->queue->queued_count() );
		self::assertSame( 'error', get_transient( 'cetech_de_admin_notice_7' )['type'] );
	}

	public function test_exact_import_permission_allows_real_apply(): void {
		$job = $this->seed( BulkOperationType::ConfigImport );
		$GLOBALS['cetech_de_test_caps']['import_delivery_data'] = true;
		$this->post_action( BulkToolsPage::ACTION_APPLY, (int) $job->id );
		self::assertSame( BulkJobStatus::Queued, $this->jobs->find_job( (int) $job->id )->status );
		self::assertSame( 1, $this->queue->queued_count() );
	}

	public function test_retained_private_rows_deny_disclosure_but_preserve_public_only_apply(): void {
		$job = $this->seed( BulkOperationType::ConfigImport, BulkJobStatus::Ready, [
			'include_private_sources' => false,
			'package' => [ 'manifest' => [ 'include_private_sources' => false ], 'sections' => [ 'suppliers' => [ [ 'internal_code' => 'PRIVATE-SUPPLIER' ] ] ] ],
		] );
		$GLOBALS['cetech_de_test_caps']['import_delivery_data'] = true;
		$access = new BulkJobAccess( $this->engine );
		self::assertFalse( $access->can_access( $job ), 'Retained private results must not be disclosed.' );
		$this->post_action( BulkToolsPage::ACTION_APPLY, (int) $job->id );
		self::assertSame( BulkJobStatus::Queued, $this->jobs->find_job( (int) $job->id )->status );
		self::assertFalse( $this->jobs->find_job( (int) $job->id )->action_manifest['include_private_sources'] );
		self::assertSame( 1, $this->queue->queued_count() );
		$GLOBALS['cetech_de_test_caps']['manage_private_sources'] = true;
		self::assertTrue( $access->can_access( $job ) );
	}

	public function test_revoked_stored_private_inclusion_denies_apply(): void {
		$job = $this->seed( BulkOperationType::ConfigImport, BulkJobStatus::Ready, [ 'include_private_sources' => true ] );
		$GLOBALS['cetech_de_test_caps']['import_delivery_data'] = true;
		$this->post_action( BulkToolsPage::ACTION_APPLY, (int) $job->id );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ) );
		self::assertSame( 0, $this->queue->queued_count() );
		$GLOBALS['cetech_de_test_caps']['manage_private_sources'] = true;
		$this->post_action( BulkToolsPage::ACTION_APPLY, (int) $job->id );
		self::assertSame( BulkJobStatus::Queued, $this->jobs->find_job( (int) $job->id )->status );
	}

	public function test_valid_ajax_nonce_cannot_read_or_advance_restricted_stored_job(): void {
		$job = $this->seed( BulkOperationType::RateCardUpdate, BulkJobStatus::Previewing );
		$_POST = [ 'job_id' => (string) $job->id, 'advance' => '1', 'nonce' => 'test-nonce-' . BulkJobProgressEndpoint::ACTION ];
		$this->ajax_request();
		self::assertSame( [ 'success' => false, 'data' => [ 'message' => 'forbidden' ], 'status' => 403 ], $GLOBALS['cetech_de_test_ajax_response'] );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ), 'The guard must precede worker claims, saves and advancement.' );
		self::assertSame( 0, $this->queue->queued_count() );
		self::assertSame( 0, $this->jobs->count_items( (int) $job->id ) );
		$GLOBALS['cetech_de_test_caps']['manage_delivery_rate_cards'] = true;
		$_POST['advance'] = '0';
		$this->ajax_request();
		self::assertTrue( $GLOBALS['cetech_de_test_ajax_response']['success'] );
		self::assertSame( 200, $GLOBALS['cetech_de_test_ajax_response']['status'] );
		self::assertSame( $job->job_code, $GLOBALS['cetech_de_test_ajax_response']['data']['code'] );
	}

	public function test_ajax_nonce_failure_precedes_job_lookup_result(): void {
		$job = $this->seed( BulkOperationType::CatalogUpdate );
		$_POST = [ 'job_id' => (string) $job->id, 'advance' => '1', 'nonce' => 'wrong-nonce' ];
		$this->ajax_request();
		self::assertSame( 403, $GLOBALS['cetech_de_test_ajax_response']['status'] );
		self::assertSame( 'bad_nonce', $GLOBALS['cetech_de_test_ajax_response']['data']['message'] );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ) );
	}

	public function test_job_history_and_detail_hide_restricted_code_and_summary_until_authorized(): void {
		$allowed = $this->seed( BulkOperationType::CatalogUpdate );
		$restricted = $this->seed( BulkOperationType::RateCardUpdate )->with( [ 'summary' => [ 'marker' => 'RESTRICTED-RATE-RESULT' ] ] );
		$this->jobs->save_job( $restricted );
		$_GET = [ 'tab' => 'jobs', 'job' => (string) $restricted->id ];
		$denied = $this->render_page();
		self::assertStringContainsString( $allowed->job_code, $denied );
		self::assertStringNotContainsString( $restricted->job_code, $denied );
		self::assertStringNotContainsString( 'RESTRICTED-RATE-RESULT', $denied );
		$GLOBALS['cetech_de_test_caps']['manage_delivery_rate_cards'] = true;
		$permitted = $this->render_page();
		self::assertStringContainsString( $restricted->job_code, $permitted );
		self::assertStringContainsString( 'RESTRICTED-RATE-RESULT', $permitted );
	}

	public function test_rollback_inherits_original_operation_and_missing_or_cyclic_ancestors_deny(): void {
		$source = $this->seed( BulkOperationType::RateCardUpdate, BulkJobStatus::Completed );
		$child = $this->seed( BulkOperationType::Rollback )->with( [ 'parent_job_id' => $source->id ] );
		$this->jobs->save_job( $child );
		$access = new BulkJobAccess( $this->engine );
		self::assertFalse( $access->can_access( $child ) );
		$GLOBALS['cetech_de_test_caps']['manage_delivery_rate_cards'] = true;
		self::assertTrue( $access->can_access( $child ) );
		self::assertFalse( $access->can_access( $child->with( [ 'parent_job_id' => 999 ] ) ) );
		$cyclic = $child->with( [ 'parent_job_id' => $child->id ] );
		$this->jobs->save_job( $cyclic );
		self::assertFalse( $access->can_access( $cyclic ) );
	}

	public function test_unsupported_operation_types_do_not_fall_back_to_product_permissions(): void {
		$GLOBALS['cetech_de_test_caps']['*'] = true;
		$access = new BulkJobAccess( $this->engine );
		self::assertFalse( $access->can_access( $this->seed( BulkOperationType::EntityUpdate ) ) );
		self::assertFalse( $access->can_access( $this->seed( BulkOperationType::Cleanup ) ) );
	}

	private function seed( BulkOperationType $operation, BulkJobStatus $status = BulkJobStatus::Ready, array $manifest = [] ): BulkJob {
		return $this->jobs->save_job( BulkJob::create( $operation, 7, [ 'scope' => 'selected_ids', 'selected_ids' => [ 11 ] ], $manifest )->with_status( $status )->with( [ 'changed_count' => 1 ] ) );
	}

	private function post_action( string $action, int $job_id ): void {
		$_POST = [ 'cetech_de_action' => $action, 'cetech_de_nonce' => 'test-nonce-' . $action, 'job_id' => (string) $job_id ];
		try {
			$this->page->handle_actions();
			self::fail( 'Expected the real handler to redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	private function ajax_request(): void {
		try {
			( new BulkJobProgressEndpoint( $this->engine ) )->handle();
			self::fail( 'Expected a JSON response to terminate the endpoint.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_ajax_response', $exception->getMessage() );
		}
	}

	private function render_page(): string {
		ob_start();
		try {
			$this->page->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
