<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

require_once __DIR__ . '/BulkJobBulk9RepairTest.php';

use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class BulkWorkerTakeoverTest extends TestCase {

	private InMemoryBulkJobRepository $jobs;

	private ArrayRateCardStore $rates;

	private BulkJobWorker $owner;

	private BulkJobWorker $successor;

	private InMemoryBoundedQueue $queue;

	protected function setUp(): void {
		$this->jobs      = new InMemoryBulkJobRepository();
		$this->rates     = new ArrayRateCardStore();
		$this->queue     = new InMemoryBoundedQueue();
		$this->owner     = $this->worker();
		$this->successor = $this->worker();
		$this->rates->save(
			[
				'id'            => 1,
				'internal_code' => 'accra',
				'base_amount'   => '100.0000',
				'base_currency' => 'GHS',
				'status'        => 'active',
				'priority'      => 100,
			]
		);
	}

	public function test_a_worker_that_loses_its_claim_before_dispatch_does_not_write_the_source(): void {
		$job = $this->seed_job( false );
		$this->owner->set_qualification_boundary(
			function ( string $phase ) use ( $job ): void {
				if ( 'before_source' !== $phase ) {
					return;
				}
				$this->expire_claims( (int) $job->id );
				$this->successor->tick( (int) $job->id );
			}
		);

		$this->owner->tick( (int) $job->id );
		$saved = $this->jobs->find_job( (int) $job->id );

		self::assertSame( '110.0000', $this->rates->findById( 1 )['base_amount'] ?? null );
		self::assertSame( 1, $saved?->processed_count );
		self::assertSame( 1, $saved?->changed_count );
		self::assertSame( BulkJobStatus::Completed, $saved?->status );
		self::assertNull( $saved?->claim_token );
		self::assertSame( BulkJobItemStatus::Changed, $this->jobs->list_items( (int) $job->id, 10 )[0]->status );
	}

	public function test_a_dry_run_result_is_not_credited_twice_after_takeover(): void {
		$job = $this->seed_job( true );
		$this->owner->set_qualification_boundary(
			function ( string $phase ) use ( $job ): void {
				if ( 'after_result' !== $phase ) {
					return;
				}
				$this->expire_claims( (int) $job->id );
				$this->successor->tick( (int) $job->id );
			}
		);

		$this->owner->tick( (int) $job->id );
		$saved = $this->jobs->find_job( (int) $job->id );

		self::assertSame( '100.0000', $this->rates->findById( 1 )['base_amount'] ?? null );
		self::assertSame( 1, $saved?->processed_count );
		self::assertSame( 1, $saved?->changed_count );
		self::assertSame( BulkJobStatus::Ready, $saved?->status );
	}

	public function test_a_late_rollback_does_not_adopt_the_successor_or_restore_twice(): void {
		$job = $this->seed_job( false );
		$this->owner->tick( (int) $job->id );
		self::assertSame( '110.0000', $this->rates->findById( 1 )['base_amount'] ?? null );
		$changed = $this->jobs->list_items( (int) $job->id, 10 )[0];
		$rollback = $this->jobs->save_job(
			BulkJob::create(
				BulkOperationType::Rollback,
				1,
				$job->target_definition,
				$job->action_manifest,
				false,
				$job->batch_size,
				$job->id
			)->with(
				[
					'status'               => BulkJobStatus::RollbackQueued,
					'enumeration_complete' => true,
					'total_count'          => 1,
					'enumerated_count'     => 1,
				]
			)
		);
		$this->jobs->insert_items(
			[
				BulkJobItem::pending( (int) $rollback->id, 'rate_card', 1, 'RC-1' )->with(
					[
						'before_snapshot'          => $changed->before_snapshot,
						'after_fingerprint'        => $changed->after_fingerprint,
						'precondition_fingerprint' => $changed->after_fingerprint,
					]
				),
			]
		);
		$this->owner->set_qualification_boundary(
			function ( string $phase ) use ( $rollback ): void {
				if ( 'before_source' !== $phase ) {
					return;
				}
				$this->expire_claims( (int) $rollback->id );
				$this->successor->tick( (int) $rollback->id );
			}
		);

		$this->owner->tick( (int) $rollback->id );
		$saved = $this->jobs->find_job( (int) $rollback->id );

		self::assertSame( '100.0000', $this->rates->findById( 1 )['base_amount'] ?? null );
		self::assertSame( 1, $saved?->summary['rollback_restored'] ?? 0 );
		self::assertSame( BulkJobStatus::RolledBack, $saved?->status );
		self::assertNull( $saved?->claim_token );
	}

	public function test_a_stale_owner_does_not_enqueue_after_the_successor_finishes(): void {
		$job = $this->seed_job( false );
		$this->owner->set_qualification_boundary(
			function ( string $phase ) use ( $job ): void {
				if ( 'before_finalize' !== $phase ) {
					return;
				}
				$this->expire_claims( (int) $job->id );
				$this->successor->tick( (int) $job->id );
			}
		);

		$this->owner->tick( (int) $job->id );

		self::assertSame( 0, $this->queue->queued_count() );
		self::assertSame( BulkJobStatus::Completed, $this->jobs->find_job( (int) $job->id )?->status );
		self::assertNull( $this->jobs->find_job( (int) $job->id )?->claim_token );
	}

	public function test_a_stale_owner_does_not_schedule_a_rollback_tick(): void {
		$job = $this->seed_rollback_job();
		$this->owner->set_qualification_boundary(
			function ( string $phase ) use ( $job ): void {
				if ( 'before_requeue' !== $phase ) {
					return;
				}
				$this->expire_claims( (int) $job->id );
				$current = $this->jobs->find_job( (int) $job->id );
				self::assertInstanceOf( BulkJob::class, $current );
				$this->jobs->save_job( $current->with( [ 'cancel_requested' => true ] ) );
				$this->successor->tick( (int) $job->id );
			}
		);

		$this->owner->tick( (int) $job->id );

		self::assertSame( 0, $this->queue->queued_count() );
		self::assertSame( BulkJobStatus::Cancelled, $this->jobs->find_job( (int) $job->id )?->status );
	}

	public function test_a_replayed_outcome_is_not_credited_while_the_owner_still_holds_the_job(): void {
		$this->jobs->treat_terminal_item_save_as_replay = true;
		$job = $this->seed_job( false );

		$this->owner->tick( (int) $job->id );
		$saved = $this->jobs->find_job( (int) $job->id );

		self::assertSame( 0, $saved?->processed_count );
		self::assertSame( 0, $saved?->changed_count );
		self::assertSame( BulkJobItemStatus::Changed, $this->jobs->list_items( (int) $job->id, 10 )[0]->status );
	}

	private function expire_claims( int $job_id ): void {
		$current = $this->jobs->find_job( $job_id );
		self::assertInstanceOf( BulkJob::class, $current );
		$this->jobs->save_job( $current->with_claim( $current->claim_token, '2000-01-01 00:00:00' ) );
		foreach ( $this->jobs->list_items( $job_id, 10 ) as $item ) {
			if ( BulkJobItemStatus::Claimed === $item->status ) {
				$this->jobs->save_item( $item->with( [ 'claimed_at' => '2000-01-01 00:00:00' ] ) );
			}
		}
	}

	private function seed_job( bool $dry_run ): BulkJob {
		$job = $this->jobs->save_job(
			BulkJob::create(
				BulkOperationType::RateCardUpdate,
				1,
				[
					'scope'            => 'selected_ids',
					'selected_ids'     => [ 1 ],
					'variation_policy' => 'preserve_overrides',
				],
				[
					'amount_op'    => 'increase_fixed',
					'amount_value' => '10',
				],
				$dry_run
			)->with(
				[
					'status'               => $dry_run ? BulkJobStatus::Previewing : BulkJobStatus::Running,
					'enumeration_complete' => true,
					'total_count'          => 1,
					'enumerated_count'     => 1,
				]
			)
		);
		$this->jobs->insert_items(
			[
				BulkJobItem::pending( (int) $job->id, 'rate_card', 1, 'RC-1' ),
			]
		);

		return $job;
	}

	private function seed_rollback_job(): BulkJob {
		$job = $this->jobs->save_job(
			BulkJob::create(
				BulkOperationType::RateCardUpdate,
				1,
				[
					'scope'            => 'selected_ids',
					'selected_ids'     => [ 1 ],
					'variation_policy' => 'preserve_overrides',
				],
				[
					'amount_op'    => 'increase_fixed',
					'amount_value' => '10',
				],
				false
			)->with(
				[
					'status'               => BulkJobStatus::RollingBack,
					'enumeration_complete' => true,
					'total_count'          => 1,
					'enumerated_count'     => 1,
				]
			)
		);
		$this->jobs->insert_items(
			[
				BulkJobItem::pending( (int) $job->id, 'rate_card', 1, 'RC-1' )->with(
					[
						'status'      => BulkJobItemStatus::Claimed,
						'claim_token' => 'other-worker',
						'claimed_at'  => gmdate( 'Y-m-d H:i:s' ),
					]
				),
			]
		);

		return $job;
	}

	private function worker(): BulkJobWorker {
		$offers = new ArrayDeliveryOfferStore();

		return new BulkJobWorker(
			$this->jobs,
			new InMemoryCatalogTargetQuery(),
			new CatalogScopeMutator(
				new InMemoryScopedConfigurationRepository(),
				new EffectiveConfigurationValidator(),
				new HardFulfilmentConstraintService( $offers ),
				new SiteWideDefaultsSettings()
			),
			$this->queue,
			30,
			new RateCardBulkMutator( $this->rates, $offers )
		);
	}
}
