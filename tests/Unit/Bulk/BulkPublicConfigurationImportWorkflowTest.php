<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
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

/** Real production PHP handlers and worker; WordPress transport and persistence fixtures. */
final class BulkPublicConfigurationImportWorkflowTest extends TestCase {
	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;

	private InMemoryBulkJobRepository $jobs;
	private InMemoryBoundedQueue $queue;
	private BulkJobEngine $engine;
	private BulkToolsPage $page;
	private array $saved_public_rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->remember_fixture_globals();
		AdminPageAccess::bind( null );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_product_delivery_rules' => true, 'import_delivery_data' => true ];
		$GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects'] = [];
		$GLOBALS['cetech_de_test_ajax_response'] = null;
		$_POST = [];
		$_GET = [];

		$offers = $this->createMock( DeliveryOfferRepositoryInterface::class );
		$offers->method( 'findByCode' )->willReturn( null );
		$offers->method( 'save' )->willReturnCallback( function ( array $row ): int {
			$this->saved_public_rows[] = $row;
			return count( $this->saved_public_rows );
		} );
		$zones = $this->createStub( DestinationZoneRepositoryInterface::class );
		$rules = $this->createStub( DestinationRuleRepositoryInterface::class );
		$rates = $this->createStub( RateCardRepositoryInterface::class );
		$logistics = $this->createStub( LogisticsProfileRepositoryInterface::class );
		$pickups = $this->createStub( PickupLocationRepositoryInterface::class );
		$suppliers = $this->createMock( SupplierRepositoryInterface::class );
		$origins = $this->createMock( OriginRepositoryInterface::class );
		foreach ( [ $suppliers, $origins ] as $private ) {
			$private->expects( self::never() )->method( 'findByCode' );
			$private->expects( self::never() )->method( 'save' );
		}
		$importer = new ConfigurationImporter( $offers, $zones, $rules, $rates, $logistics, $pickups, $suppliers, $origins );
		$exporter = new ConfigurationExporter( $offers, $zones, $rules, $rates, $logistics, $pickups, $suppliers, $origins );
		$this->jobs = new InMemoryBulkJobRepository();
		$this->queue = new InMemoryBoundedQueue();
		$worker = new BulkJobWorker(
			$this->jobs,
			new InMemoryCatalogTargetQuery(),
			new CatalogScopeMutator( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ),
			$this->queue,
			30,
			null,
			$importer
		);
		$this->engine = new BulkJobEngine( $this->jobs, $this->queue, $worker );
		$this->page = new BulkToolsPage( $this->engine, $this->jobs, new AdminActionHandler( new AdminNoticeService() ), $exporter, new CatalogCsvMapper() );
	}

	protected function tearDown(): void {
		AdminPageAccess::bind( null );
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	public static function source_private_flags(): array {
		return [ 'explicit-private-export' => [ true ], 'misdeclared-public-export' => [ false ] ];
	}

	#[DataProvider( 'source_private_flags' )]
	public function test_actual_upload_keeps_public_workflow_open_without_retained_private_rows( bool $source_private_flag ): void {
		$job = $this->upload( $this->mixed_package( $source_private_flag ) );
		self::assertSame( BulkJobStatus::Previewing, $job->status );
		self::assertFalse( $job->action_manifest['include_private_sources'] );
		self::assertFalse( $job->action_manifest['package']['manifest']['include_private_sources'] );
		self::assertSame( [ 'delivery_options' ], $job->action_manifest['package']['manifest']['exported_sections'] );
		self::assertSame( [ 'delivery_options' => 1 ], $job->action_manifest['package']['manifest']['counts'] );
		self::assertTrue( ( new BulkJobAccess( $this->engine ) )->can_access( $job ) );
		$this->assert_no_private_data( $job );
		$render = $this->render_job( $job );
		self::assertStringContainsString( $job->job_code, $render );
		self::assertStringContainsString( BulkToolsPage::ACTION_CANCEL, $render );
		self::assertStringNotContainsString( 'You do not have permission to access this bulk job.', $render );
		$this->progress( $job, false );
		self::assertTrue( $GLOBALS['cetech_de_test_ajax_response']['success'] );
		self::assertSame( 'previewing', $GLOBALS['cetech_de_test_ajax_response']['data']['status'] );
		self::assertSame( [], $this->saved_public_rows, 'The initiating upload and read-only progress must not import.' );

		$this->progress( $job, true );
		self::assertTrue( $GLOBALS['cetech_de_test_ajax_response']['success'] );
		$preview = $this->jobs->find_job( (int) $job->id );
		self::assertSame( BulkJobStatus::Ready, $preview->status );
		self::assertSame( 1, $preview->total_count );
		self::assertSame( 1, $preview->changed_count );
		self::assertSame( 0, $preview->failed_count );
		self::assertSame( [], $this->saved_public_rows, 'Dry run must stay read-only.' );
		$this->assert_no_private_data( $preview );
		$render = $this->render_job( $preview );
		self::assertStringContainsString( 'PUBLIC-DELIVERY-LABEL', $render );
		self::assertStringContainsString( BulkToolsPage::ACTION_APPLY, $render );

		$this->post_action( BulkToolsPage::ACTION_APPLY, [ 'job_id' => (string) $job->id ] );
		$queued = $this->jobs->find_job( (int) $job->id );
		self::assertSame( BulkJobStatus::Queued, $queued->status );
		self::assertFalse( $queued->dry_run );
		self::assertSame( [], $this->saved_public_rows, 'Apply queues the worker.' );
		$this->post_action( BulkToolsPage::ACTION_CONTINUE, [ 'job_id' => (string) $job->id ] );
		$completed = $this->jobs->find_job( (int) $job->id );
		self::assertSame( BulkJobStatus::Completed, $completed->status );
		self::assertSame( [ [ 'internal_code' => 'PUBLIC-DELIVERY-CODE', 'name' => 'PUBLIC-DELIVERY-LABEL' ] ], $this->saved_public_rows );
		$this->assert_no_private_data( $completed );
		$this->progress( $completed, false );
		self::assertTrue( $GLOBALS['cetech_de_test_ajax_response']['success'] );
		self::assertSame( 'completed', $GLOBALS['cetech_de_test_ajax_response']['data']['status'] );
		self::assertFalse( current_user_can( 'manage_private_sources' ), 'Normal public workflow never grants private access.' );
	}

	public function test_actual_public_upload_can_be_cancelled_without_private_permission(): void {
		$job = $this->upload( $this->mixed_package( true ) );
		$this->post_action( BulkToolsPage::ACTION_CANCEL, [ 'job_id' => (string) $job->id ] );
		$fresh = $this->jobs->find_job( (int) $job->id );
		self::assertSame( BulkJobStatus::Cancelled, $fresh->status );
		self::assertSame( [], $this->saved_public_rows );
		$this->assert_no_private_data( $fresh );
	}

	public function test_revoking_import_permission_still_denies_public_job_read_and_action(): void {
		$job = $this->upload( $this->mixed_package( true ) );
		unset( $GLOBALS['cetech_de_test_caps']['import_delivery_data'] );
		$this->progress( $job, true );
		self::assertSame( [ 'success' => false, 'data' => [ 'message' => 'forbidden' ], 'status' => 403 ], $GLOBALS['cetech_de_test_ajax_response'] );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ) );
		$render = $this->render_job( $job );
		self::assertStringNotContainsString( $job->job_code, $render );
		$this->post_action( BulkToolsPage::ACTION_CANCEL, [ 'job_id' => (string) $job->id ] );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ) );
		self::assertSame( [], $this->saved_public_rows );
	}

	public function test_invalid_original_package_is_rejected_before_projection_and_job_creation(): void {
		$payload = $this->mixed_package( true )->to_array();
		$payload['manifest']['format_version'] = 99;
		$this->post_action( BulkToolsPage::ACTION_CONFIG_IMPORT, [ 'package_json' => wp_json_encode( $payload ) ] );
		self::assertSame( 0, $this->jobs->count_jobs() );
		self::assertSame( 0, $this->queue->queued_count() );
		self::assertSame( 'error', get_transient( 'cetech_de_admin_notice_7' )['type'] );
	}

	public function test_malformed_and_oversized_original_uploads_never_create_jobs(): void {
		$sections = $this->mixed_package( true )->sections;
		$sections['suppliers'][0]['internal_note'] = str_repeat( 'x', 5242880 );
		$oversized = ConfigurationPackage::create( 'source-version', '6', $sections, true );
		$original_json = $oversized->to_json();
		self::assertGreaterThan( 5242880, strlen( $original_json ), 'The complete valid original upload exceeds the byte limit.' );
		self::assertLessThan( 5242880, strlen( $oversized->without_private_sources()->to_json() ), 'Projection would shrink this package below the limit.' );
		self::assertSame( $oversized->to_array(), ConfigurationPackage::from_json( $original_json )->to_array(), 'The over-limit package is valid JSON and format.' );
		foreach ( [ '{invalid-json', $original_json ] as $json ) {
			$this->post_action( BulkToolsPage::ACTION_CONFIG_IMPORT, [ 'package_json' => $json ] );
			self::assertSame( 0, $this->jobs->count_jobs(), 'Original byte validation must precede omission of private sections.' );
			self::assertSame( 0, $this->queue->queued_count() );
			self::assertSame( 'error', get_transient( 'cetech_de_admin_notice_7' )['type'] );
		}
	}

	public function test_actual_private_upload_retains_private_authority_and_revocation_denies(): void {
		$GLOBALS['cetech_de_test_caps']['manage_private_sources'] = true;
		$source = $this->mixed_package( true );
		$job = $this->upload( $source );
		self::assertTrue( $job->action_manifest['include_private_sources'] );
		self::assertSame( $source->to_array(), $job->action_manifest['package'] );
		self::assertTrue( ( new BulkJobAccess( $this->engine ) )->can_access( $job ) );
		unset( $GLOBALS['cetech_de_test_caps']['manage_private_sources'] );
		self::assertFalse( ( new BulkJobAccess( $this->engine ) )->can_access( $job ) );
		$this->progress( $job, true );
		self::assertSame( [ 'success' => false, 'data' => [ 'message' => 'forbidden' ], 'status' => 403 ], $GLOBALS['cetech_de_test_ajax_response'] );
		$this->post_action( BulkToolsPage::ACTION_CANCEL, [ 'job_id' => (string) $job->id ] );
		self::assertSame( $job, $this->jobs->find_job( (int) $job->id ) );
		self::assertSame( 0, $this->jobs->count_items( (int) $job->id ) );
		self::assertSame( [], $this->saved_public_rows );
		self::assertStringNotContainsString( $job->job_code, $this->render_job( $job ) );
	}

	public function test_actual_upload_preserves_source_metadata_and_public_reference_payload_without_mutating_source(): void {
		$public = [
			'delivery_options' => [ [ 'internal_code' => 'PUBLIC-OPTION', 'name' => 'Public option / source text' ] ],
			'rate_cards' => [ [
				'internal_code' => 'PUBLIC-RATE',
				'delivery_offer_code' => 'PUBLIC-OPTION',
				'destination_zone_code' => 'SOURCE-AREA',
				'supplier_code' => 'SOURCE-SUPPLIER-REFERENCE',
				'origin_code' => 'SOURCE-ORIGIN-REFERENCE',
				'amount' => '12.3400',
			] ],
			'profile_defaults' => [ [
				'profile_key' => 'in_store',
				'scalars' => [
					'supplier_id' => [ 'mode' => 'override', 'value' => 'SOURCE-SUPPLIER-REFERENCE', 'value_type' => 'int' ],
					'origin_id' => [ 'mode' => 'override', 'value' => 'SOURCE-ORIGIN-REFERENCE', 'value_type' => 'int' ],
					'handling_days' => [ 'mode' => 'override', 'value' => 2, 'value_type' => 'int' ],
				],
				'collections' => [],
			] ],
			'site_wide_defaults' => [ 'active_profiles' => [ 'in_store' ], 'primary_profile' => 'in_store' ],
		];
		$sections = $public + [
			'suppliers' => [ [ 'internal_code' => 'PRIVATE-SUPPLIER-CODE', 'name' => 'PRIVATE-SUPPLIER-LABEL' ] ],
			'origins' => [ [ 'internal_code' => 'PRIVATE-ORIGIN-CODE', 'name' => 'PRIVATE-ORIGIN-LABEL' ] ],
		];
		$manifest = ConfigurationPackage::create( 'source-plugin-0.8.7', 'source-schema-4', $sections, true )->manifest;
		$manifest['exported_at'] = '2024-03-12T09:41:27+00:00';
		$source = new ConfigurationPackage( $manifest, $sections );
		$before = $source->to_array();
		$projection = $source->without_private_sources();
		self::assertNotSame( $source, $projection, 'Projection returns an independent package.' );
		self::assertSame( $before, $source->to_array(), 'Projection must not mutate source manifest or sections.' );
		self::assertSame( $public, $projection->sections );

		$job = $this->upload( $source );
		$stored = $job->action_manifest['package'];
		self::assertSame( $before, $source->to_array(), 'The actual initiating route also leaves the source unchanged.' );
		self::assertSame( $public, $stored['sections'], 'Public reference payload is preserved; this test selects no transitive authority policy.' );
		self::assertSame( wp_json_encode( $public ), wp_json_encode( $stored['sections'] ), 'Ordinary public sections preserve their serialized bytes.' );
		foreach ( [ 'format_name', 'format_version', 'plugin_version', 'source_schema_version', 'exported_at' ] as $key ) {
			self::assertSame( $before['manifest'][ $key ], $projection->manifest[ $key ] );
			self::assertSame( $before['manifest'][ $key ], $stored['manifest'][ $key ] );
		}
		self::assertSame( array_keys( $public ), $stored['manifest']['exported_sections'] );
		self::assertSame( [ 'delivery_options' => 1, 'rate_cards' => 1, 'profile_defaults' => 1, 'site_wide_defaults' => 2 ], $stored['manifest']['counts'] );
		self::assertFalse( $stored['manifest']['include_private_sources'] );
		self::assertFalse( $job->action_manifest['include_private_sources'] );
		self::assertSame( BulkJobStatus::Previewing, $job->status );
		self::assertSame( 0, $this->jobs->count_items( (int) $job->id ), 'No reference lookup or import occurs in the initiating request.' );
		self::assertSame( [], $this->saved_public_rows );
		$this->assert_no_private_data( $job );
	}

	private function mixed_package( bool $private_flag ): ConfigurationPackage {
		return ConfigurationPackage::create( 'source-version', '6', [
			'delivery_options' => [ [ 'internal_code' => 'PUBLIC-DELIVERY-CODE', 'name' => 'PUBLIC-DELIVERY-LABEL' ] ],
			'suppliers' => [ [ 'internal_code' => 'PRIVATE-SUPPLIER-CODE', 'name' => 'PRIVATE-SUPPLIER-LABEL' ] ],
			'origins' => [ [ 'internal_code' => 'PRIVATE-ORIGIN-CODE', 'name' => 'PRIVATE-ORIGIN-LABEL' ] ],
		], $private_flag );
	}

	private function upload( ConfigurationPackage $package ): BulkJob {
		$this->post_action( BulkToolsPage::ACTION_CONFIG_IMPORT, [ 'package_json' => $package->to_json() ] );
		self::assertSame( 1, $this->jobs->count_jobs() );
		return $this->jobs->list_jobs()[0];
	}

	private function post_action( string $action, array $data ): void {
		$_POST = [ 'cetech_de_action' => $action, 'cetech_de_nonce' => 'test-nonce-' . $action ] + $data;
		try {
			$this->page->handle_actions();
			self::fail( 'Expected real handler redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	private function progress( BulkJob $job, bool $advance ): void {
		$_POST = [ 'job_id' => (string) $job->id, 'advance' => $advance ? '1' : '0', 'nonce' => 'test-nonce-' . BulkJobProgressEndpoint::ACTION ];
		try {
			( new BulkJobProgressEndpoint( $this->engine ) )->handle();
			self::fail( 'Expected endpoint response.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_ajax_response', $exception->getMessage() );
		}
	}

	private function render_job( BulkJob $job ): string {
		$_GET = [ 'tab' => 'jobs', 'job' => (string) $job->id ];
		ob_start();
		try {
			$this->page->render();
			$render = (string) ob_get_contents();
			self::assertStringNotContainsString( 'PRIVATE-SUPPLIER', $render );
			self::assertStringNotContainsString( 'PRIVATE-ORIGIN', $render );
			return $render;
		} finally {
			ob_end_clean();
		}
	}

	private function assert_no_private_data( BulkJob $job ): void {
		self::assertArrayNotHasKey( 'suppliers', $job->action_manifest['package']['sections'] );
		self::assertArrayNotHasKey( 'origins', $job->action_manifest['package']['sections'] );
		$receipt = wp_json_encode( [ $job->action_manifest, $job->summary, $this->jobs->list_items( (int) $job->id ) ] );
		self::assertStringNotContainsString( 'PRIVATE-SUPPLIER', $receipt );
		self::assertStringNotContainsString( 'PRIVATE-ORIGIN', $receipt );
	}
}
