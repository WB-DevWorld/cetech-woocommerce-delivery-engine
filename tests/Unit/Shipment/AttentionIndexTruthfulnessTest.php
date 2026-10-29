<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\AttentionIndexLock;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\OrderShipmentOperationsSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentRefundInspector;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;

/**
 * COR-033: concurrent index updates preserve all entries or report explicitly; bounded pages
 * do not omit later order groups; capped or incomplete counts stay truthful.
 */
final class AttentionIndexTruthfulnessTest extends TestCase {

	private WpdbShipmentRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']         = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_failing_options'] = [];
		$GLOBALS['cetech_de_test_wc_orders']       = [];
		AttentionIndexLock::$max_attempts = 3;
		AttentionIndexLock::$wait_micros  = 0;
		$this->repository = ShipmentCreationFixtures::repository();
	}

	protected function tearDown(): void {
		AttentionIndexLock::$max_attempts = 40;
		AttentionIndexLock::$wait_micros  = 25000;
		$GLOBALS['cetech_de_test_failing_options'] = [];
	}

	public function test_index_mutations_take_and_release_the_lock_and_keep_every_entry(): void {
		$store = new ShipmentOperationsIssueStore();

		self::assertTrue( $store->add( 1, 10, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW ) );
		self::assertTrue( $store->add( 2, 20, ShipmentOperationsIssueStore::CODE_ORDER_CANCELLED_AFTER_PROGRESS ) );
		self::assertArrayNotHasKey( ShipmentOperationsIssueStore::INDEX_OPTION . AttentionIndexLock::LOCK_SUFFIX, $GLOBALS['cetech_de_test_options'], 'the lock is released after the write' );
		self::assertSame( [ 1, 2 ], array_keys( $store->all() ) );
		self::assertTrue( $store->add( 1, 10, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW ), 'an existing entry is a successful no-op' );
		self::assertTrue( $store->remove_code( 1, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW ) );
		self::assertSame( [ 2 ], array_keys( $store->all() ) );
	}

	public function test_a_held_lock_makes_the_mutation_report_failure_without_touching_the_index(): void {
		$store = new ShipmentOperationsIssueStore();
		$store->add( 7, 70, ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED );
		$GLOBALS['cetech_de_test_options'][ ShipmentOperationsIssueStore::INDEX_OPTION . AttentionIndexLock::LOCK_SUFFIX ] = (string) time();

		self::assertFalse( $store->add( 8, 80, ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED ), 'a live lock held by another writer refuses the write' );
		self::assertSame( [ 7 ], array_keys( $store->all() ), 'the index was not overwritten' );

		// A crashed writer's stale lock is taken over.
		$GLOBALS['cetech_de_test_options'][ ShipmentOperationsIssueStore::INDEX_OPTION . AttentionIndexLock::LOCK_SUFFIX ] = (string) ( time() - AttentionIndexLock::TTL_SECONDS - 5 );
		self::assertTrue( $store->add( 8, 80, ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED ) );
		self::assertSame( [ 7, 8 ], array_keys( $store->all() ) );
	}

	public function test_a_failed_option_write_is_reported_and_the_order_meta_remains_the_truth(): void {
		$failures = new ShipmentCreationFailureStore();
		$cod      = new CodAwaitingShipmentStore();
		$order    = new WC_Order( [ 'id' => 5100, 'order_number' => '5100', 'paid' => true, 'status' => 'processing', 'payment_method' => 'cod' ] );
		$order->save();

		$GLOBALS['cetech_de_test_failing_options'] = [ ShipmentCreationFailureStore::INDEX_OPTION, CodAwaitingShipmentStore::INDEX_OPTION ];

		self::assertFalse( $failures->mark_failed( $order, ShipmentCreationErrorCode::RepositoryWriteFailed ) );
		self::assertTrue( $failures->is_failed( $order ), 'the per-order meta still records the failure' );
		self::assertSame( [], $failures->failed_order_ids() );

		self::assertFalse( $cod->mark_awaiting( $order ) );
		self::assertTrue( $cod->is_awaiting( $order ) );

		$GLOBALS['cetech_de_test_failing_options'] = [];
		self::assertTrue( $failures->mark_failed( $order, ShipmentCreationErrorCode::RepositoryWriteFailed ), 'a retry repairs the index' );
		self::assertSame( [ 5100 ], $failures->failed_order_ids() );
		self::assertSame( 1, $failures->count() );
	}

	public function test_counts_are_not_capped_at_the_page_size_and_the_page_discloses_what_it_omits(): void {
		$store = new ShipmentOperationsIssueStore();

		for ( $i = 1; $i <= 60; $i++ ) {
			$shipment = $this->repository->create( Shipment::create( 9000 + $i, 'g-' . $i, ShipmentStatus::Processing, shipment_number: 'S-' . $i ) );
			$store->add( $shipment->id, 9000 + $i, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW );
		}

		// Two delayed shipments: one already indexed (counted once), one only delayed.
		$indexed_delayed = $this->repository->findById( 1 );
		self::assertNotNull( $indexed_delayed );
		$this->repository->update( $indexed_delayed->withStatus( ShipmentStatus::Delayed ) );
		$this->repository->create( Shipment::create( 9999, 'g-delayed', ShipmentStatus::Delayed, shipment_number: 'S-D' ) );

		$query = new ShipmentOperationsIssueQuery( new FeatureFlags(), $this->repository, $store );

		self::assertCount( 50, $query->list( 50 ), 'the page stays bounded' );
		self::assertSame( 61, $query->count(), '60 indexed issues plus one delayed-only shipment, the indexed delayed one counted once' );
		self::assertSame( [ 'shown' => 50, 'total' => 61, 'truncated' => true ], $query->summary( 50 ) );
	}

	public function test_cancelled_shipments_leave_the_total_and_lookups_are_batched(): void {
		$store = new ShipmentOperationsIssueStore();
		$a     = $this->repository->create( Shipment::create( 9101, 'g-a', ShipmentStatus::Processing, shipment_number: 'A' ) );
		$b     = $this->repository->create( Shipment::create( 9102, 'g-b', ShipmentStatus::Cancelled, shipment_number: 'B' ) );
		$store->add( $a->id, 9101, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW );
		$store->add( $b->id, 9102, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW );
		$store->add( 424242, 9103, ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->sql_log = [];
		$query = new ShipmentOperationsIssueQuery( new FeatureFlags(), $this->repository, $store );

		self::assertSame( 1, $query->total() );
		$by_id_lookups = array_filter( $wpdb->sql_log, static fn ( string $sql ): bool => str_contains( $sql, '`id` IN (' ) );
		self::assertNotEmpty( $by_id_lookups, 'indexed shipments are looked up in one batched query, not one query per entry' );
	}

	public function test_an_order_with_more_than_one_page_of_groups_is_traversed_completely(): void {
		for ( $i = 1; $i <= 101; $i++ ) {
			$this->repository->create( Shipment::create( 7777, 'group|delivery|' . $i, ShipmentStatus::AwaitingFulfilment, shipment_number: '7777-D' . $i ) );
		}

		self::assertCount( 101, $this->repository->findByOrderId( 7777 ), 'later groups beyond the first page are not omitted' );

		$issues     = new ShipmentOperationsIssueStore();
		$subscriber = new OrderShipmentOperationsSubscriber(
			new FeatureFlags(),
			$this->repository,
			new ShipmentStatusService( $this->repository, $issues ),
			new ShipmentRefundInspector(),
			$issues
		);
		$order = new WC_Order( [ 'id' => 7777, 'order_number' => '7777', 'paid' => true, 'status' => 'cancelled' ] );
		$order->save();

		$subscriber->handle_order_cancelled( 7777, $order );

		$cancelled = 0;
		foreach ( $this->repository->findByOrderId( 7777 ) as $shipment ) {
			if ( ShipmentStatus::Cancelled === $shipment->status ) {
				++$cancelled;
			}
		}
		self::assertSame( 101, $cancelled, 'the cancellation hook reaches every group, not only the first hundred' );
	}

	public function test_cod_and_failure_badges_count_the_whole_index(): void {
		$cod      = new CodAwaitingShipmentStore();
		$failures = new ShipmentCreationFailureStore();

		for ( $i = 1; $i <= 520; $i++ ) {
			$order = new WC_Order( [ 'id' => 10000 + $i, 'order_number' => (string) ( 10000 + $i ), 'paid' => true, 'status' => 'processing', 'payment_method' => 'cod' ] );
			$order->save();
			$cod->mark_awaiting( $order );
			$failures->mark_failed( $order, ShipmentCreationErrorCode::RepositoryWriteFailed );
		}

		self::assertSame( 520, $cod->count(), 'the COD badge is no longer capped at 500' );
		self::assertSame( 520, $failures->count() );
	}
}
