<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use PHPUnit\Framework\TestCase;

final class BulkClaimFenceTest extends TestCase {

	public function test_second_unexpired_claim_loses_and_cannot_overwrite_the_owner(): void {
		$jobs = new InMemoryBulkJobRepository();
		$job  = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 7, [ 'scope' => 'selected_ids' ], [] ) );
		$owner = $jobs->claim_job( (int) $job->id, 'owner-a', 300 );
		$loser = $jobs->claim_job( (int) $job->id, 'owner-b', 300 );

		self::assertNotNull( $owner );
		self::assertNull( $loser );
		self::assertSame( 'owner-a', $jobs->find_job( (int) $job->id )->claim_token );

		$stale = $owner->with_progress( 1, 1, 9, 9, 0, 0, 0, true, '9', [] );
		$stale = $stale->with_claim( 'owner-b', $owner->claimed_at );
		try {
			$jobs->save_job( $stale );
			self::fail( 'A stale claim must not save.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Stale bulk claim.', $exception->getMessage() );
		}
		self::assertSame( 0, $jobs->find_job( (int) $job->id )->processed_count );
		self::assertSame( 'owner-a', $jobs->find_job( (int) $job->id )->claim_token );
	}

	public function test_expired_item_renewal_rejects_the_previous_outcome_write(): void {
		$jobs = new InMemoryBulkJobRepository();
		$job  = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 7, [ 'scope' => 'selected_ids' ], [] ) );
		$jobs->insert_items(
			[
				BulkJobItem::pending( (int) $job->id, 'product', 11, 'SKU' )->with(
					[
						'status'      => BulkJobItemStatus::Claimed,
						'claim_token' => 'old',
						'claimed_at'  => gmdate( 'Y-m-d H:i:s', time() - 1000 ),
					]
				),
			]
		);
		$first = $jobs->claim_items( (int) $job->id, 1, 'new-owner', 30 );
		self::assertCount( 1, $first );
		$stale = $first[0]->with(
			[
				'claim_token' => 'old',
				'status'      => BulkJobItemStatus::Changed,
				'result'      => [ 'applied' => true ],
			]
		);

		try {
			$jobs->save_item( $stale );
			self::fail( 'A stale item outcome must not save.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Stale bulk claim.', $exception->getMessage() );
		}
		$durable = $jobs->list_items( (int) $job->id, 10 )[0];
		self::assertSame( 'new-owner', $durable->claim_token );
		self::assertSame( BulkJobItemStatus::Claimed, $durable->status );
	}

	public function test_pending_claim_has_one_winner_and_terminal_save_clears_the_token(): void {
		$jobs = new InMemoryBulkJobRepository();
		$job  = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 7, [ 'scope' => 'selected_ids' ], [] ) );
		$jobs->insert_items( [ BulkJobItem::pending( (int) $job->id, 'product', 12, 'SKU-12' ) ] );
		$winner = $jobs->claim_items( (int) $job->id, 1, 'winner', 30 );
		$loser  = $jobs->claim_items( (int) $job->id, 1, 'loser', 30 );

		self::assertCount( 1, $winner );
		self::assertSame( [], $loser );
		$applied = false;
		$done    = $jobs->save_item( $winner[0]->with( [ 'status' => BulkJobItemStatus::Changed, 'result' => [ 'ok' => true ] ] ), $applied );
		self::assertTrue( $applied );
		self::assertNull( $done->claim_token );
		self::assertSame( BulkJobItemStatus::Changed, $jobs->list_items( (int) $job->id, 10 )[0]->status );
		$replayed = true;
		$repeat   = $jobs->save_item( $winner[0]->with( [ 'status' => BulkJobItemStatus::Changed, 'result' => [ 'ok' => true ] ] ), $replayed );
		self::assertFalse( $replayed );
		self::assertSame( [ 'ok' => true ], $repeat->result );
		try {
			$jobs->save_item( $winner[0]->with( [ 'status' => BulkJobItemStatus::Changed, 'result' => [ 'ok' => false ] ] ) );
			self::fail( 'A different outcome with the old token must not be acknowledged.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Stale bulk claim.', $exception->getMessage() );
		}
		self::assertSame( [ 'ok' => true ], $jobs->list_items( (int) $job->id, 10 )[0]->result );
	}

	public function test_lost_release_is_distinct_from_a_failed_release_statement(): void {
		$jobs = new InMemoryBulkJobRepository();
		$job  = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 7, [ 'scope' => 'selected_ids' ], [] ) );
		$owned = $jobs->claim_job( (int) $job->id, 'owner', 300 );

		self::assertTrue( $jobs->release_job_claim( (int) $job->id, 'owner' ) );
		self::assertNull( $jobs->find_job( (int) $job->id )->claim_token );
		self::assertFalse( $jobs->release_job_claim( (int) $job->id, 'owner' ) );
		unset( $owned );
	}

	public function test_identical_job_save_is_not_a_write_failure(): void {
		$jobs = new InMemoryBulkJobRepository();
		$job  = $jobs->save_job( BulkJob::create( BulkOperationType::CatalogUpdate, 7, [ 'scope' => 'selected_ids' ], [] ) );
		$again = $jobs->save_job( $job );

		self::assertSame( $job->id, $again->id );
		self::assertSame( 0, $again->processed_count );
	}
}
