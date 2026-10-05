<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetFilters;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
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
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/bulk-ajax-stubs.php';

/**
 * COR-004 catalog target identity. Attribute checks lock matcher semantics.
 * Physical SQL identity is asserted by the MariaDB group cor004-real-db.
 */
final class CatalogTargetIdentityTest extends TestCase {

	use \CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;

	protected function setUp(): void {
		parent::setUp();
		$this->remember_fixture_globals();
	}

	protected function tearDown(): void {
		$this->restore_fixture_globals();
		parent::tearDown();
	}

	public function test_unknown_only_filter_is_rejected_before_any_target_is_selected(): void {
		$query = $this->populated_query();
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported catalog filter.' );
		$definition = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [ 'not_a_catalog_key' => 'broaden' ],
			]
		);
		$query->count( $definition );
	}

	public function test_unknown_key_beside_a_known_criterion_is_rejected_rather_than_ignored(): void {
		$this->expectException( \InvalidArgumentException::class );
		CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [
					CatalogTargetFilters::CATEGORY_ID => 50,
					'term_taxonomy_id'                => 900,
				],
			]
		);
	}

	public function test_known_criterion_and_empty_filters_keep_their_existing_results(): void {
		$query      = $this->populated_query();
		$legitimate = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [ CatalogTargetFilters::CATEGORY_ID => 50 ],
			]
		);
		$empty = CatalogTargetDefinition::from_array(
			[
				'scope' => BulkTargetScope::MatchingFilters->value,
			]
		);

		self::assertSame( [ 101 ], $this->ids( $query, $legitimate ) );
		self::assertSame( 1, $query->count( $legitimate ) );
		self::assertFalse( $empty->has_matching_criteria() );
		self::assertSame( [], $this->ids( $query, $empty ) );
		self::assertSame( 0, $query->count( $empty ) );
	}

	public function test_pickup_filter_requires_the_exact_endpoint(): void {
		$query = $this->populated_query();
		$page  = $this->ids(
			$query,
			CatalogTargetDefinition::from_array(
				[
					'scope'   => BulkTargetScope::MatchingFilters->value,
					'filters' => [ CatalogTargetFilters::PICKUP_LOCATION_ID => 7 ],
				]
			)
		);

		self::assertSame( [ 201 ], $page );
	}

	public function test_offer_membership_is_exact_and_remove_is_not_inclusion(): void {
		$query = $this->populated_query();
		$ids   = $this->ids(
			$query,
			CatalogTargetDefinition::from_array(
				[
					'scope'   => BulkTargetScope::MatchingFilters->value,
					'filters' => [ CatalogTargetFilters::DELIVERY_OPTION_ID => 1 ],
				]
			)
		);

		self::assertSame( [ 301, 304, 305 ], $ids );
	}

	public function test_direct_matcher_does_not_treat_an_unknown_key_as_a_match(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 1, 'ONLY' ), [ CatalogTargetFilters::CATEGORY_ID => 50 ] );
		$definition = new CatalogTargetDefinition(
			BulkTargetScope::MatchingFilters,
			[],
			[ 'not_a_catalog_key' => 'broaden' ]
		);

		$this->expectException( \InvalidArgumentException::class );
		$query->count( $definition );
	}

	public function test_stored_unknown_filter_fails_before_item_materialization(): void {
		$jobs   = new InMemoryBulkJobRepository();
		$queue  = new InMemoryBoundedQueue();
		$worker = new BulkJobWorker(
			$jobs,
			$this->populated_query(),
			new CatalogScopeMutator( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ),
			$queue
		);
		$job = $jobs->save_job(
			BulkJob::create(
				BulkOperationType::CatalogUpdate,
				7,
				[
					'scope'   => BulkTargetScope::MatchingFilters->value,
					'filters' => [ 'not_a_catalog_key' => 'broaden' ],
				],
				[ 'field_actions' => [] ]
			)->with_status( BulkJobStatus::Previewing )
		);
		$worker->tick( (int) $job->id );
		$saved = $jobs->find_job( (int) $job->id );

		self::assertSame( BulkJobStatus::Failed, $saved->status );
		self::assertSame( 'unsupported_catalog_filter', $saved->error_code );
		self::assertSame( 0, $jobs->count_items( (int) $job->id ) );
	}

	public function test_denied_catalog_preview_preserves_job_state(): void {
		AdminPageAccess::bind( null );
		$GLOBALS['cetech_de_test_caps']       = [ 'manage_product_delivery_rules' => false ];
		$GLOBALS['cetech_de_test_user_id']    = 7;
		$GLOBALS['cetech_de_test_is_admin']   = true;
		$GLOBALS['cetech_de_test_logged_in']  = true;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects']  = [];
		$_POST                                = [
			'cetech_de_action' => BulkToolsPage::ACTION_PREVIEW,
			'cetech_de_nonce'  => 'test-nonce-' . BulkToolsPage::ACTION_PREVIEW,
			'target_scope'     => BulkTargetScope::MatchingFilters->value,
			'category_id'      => '50',
			'fulfilment_action'=> 'override',
			'fulfilment_value' => 'in_store',
		];
		$jobs = new InMemoryBulkJobRepository();
		$page = $this->page_for( $jobs );

		try {
			$page->handle_actions();
			self::fail( 'Expected the real handler to redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}

		self::assertSame( 0, $jobs->count_jobs() );
		self::assertSame( 'error', get_transient( 'cetech_de_admin_notice_7' )['type'] );
	}

	private function populated_query(): InMemoryCatalogTargetQuery {
		$query = new InMemoryCatalogTargetQuery();
		$query->add(
			new CatalogTarget( 'product', 101, 'CAT-50' ),
			[ CatalogTargetFilters::CATEGORY_ID => 50, 'category_ids' => [ 50 ] ]
		);
		$query->add(
			new CatalogTarget( 'product', 102, 'TAG-COLLISION' ),
			[ CatalogTargetFilters::TAG_ID => 900, 'tag_ids' => [ 900 ] ]
		);
		$query->add(
			new CatalogTarget( 'product', 201, 'PICKUP-A' ),
			[ CatalogTargetFilters::PICKUP_LOCATION_ID => 7 ]
		);
		$query->add(
			new CatalogTarget( 'product', 203, 'INSTORE-ONLY' ),
			[ 'in_store_pickup' => true ]
		);
		$query->add(
			new CatalogTarget( 'product', 204, 'OTHER-ENDPOINT' ),
			[ CatalogTargetFilters::PICKUP_LOCATION_ID => 8, 'in_store_pickup' => true ]
		);
		$query->add(
			new CatalogTarget( 'product', 301, 'REPLACE-1' ),
			[ 'delivery_option_ids' => [ 1 ], 'delivery_option_mode' => 'replace' ]
		);
		$query->add(
			new CatalogTarget( 'product', 302, 'MEMBER-10' ),
			[ 'delivery_option_ids' => [ 10 ], 'delivery_option_mode' => 'replace' ]
		);
		$query->add(
			new CatalogTarget( 'product', 303, 'REMOVE-1' ),
			[ 'delivery_option_ids' => [ 1 ], 'delivery_option_mode' => 'remove' ]
		);
		$query->add(
			new CatalogTarget( 'product', 304, 'ADD-1' ),
			[ 'delivery_option_ids' => [ 1 ], 'delivery_option_mode' => 'add' ]
		);
		$query->add(
			new CatalogTarget( 'product', 305, 'INHERIT-1' ),
			[ 'delivery_option_mode' => 'inherit', 'inherited_delivery_option_ids' => [ 1 ] ]
		);

		return $query;
	}

	/**
	 * @return list<int>
	 */
	private function ids( InMemoryCatalogTargetQuery $query, CatalogTargetDefinition $definition ): array {
		$ids = [];
		foreach ( $query->page_after( $definition, 0, 50 ) as $target ) {
			$ids[] = $target->id;
		}

		return $ids;
	}

	private function page_for( InMemoryBulkJobRepository $jobs ): BulkToolsPage {
		$queue  = new InMemoryBoundedQueue();
		$worker = new BulkJobWorker(
			$jobs,
			new InMemoryCatalogTargetQuery(),
			new CatalogScopeMutator( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ),
			$queue
		);
		$engine   = new BulkJobEngine( $jobs, $queue, $worker );
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

		return new BulkToolsPage( $engine, $jobs, new AdminActionHandler( new AdminNoticeService() ), $exporter, new CatalogCsvMapper() );
	}
}
