<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkQueueHealth;
use CetechDeliveryEngine\Application\Bulk\BulkStaleJobQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerQueue;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use PHPUnit\Framework\TestCase;

final class BulkQueueHealthTest extends TestCase {

	public function test_stale_jobs_mark_scheduler_as_apparently_not_running(): void {
		$jobs = new InMemoryBulkJobRepository();
		$now  = time();
		$jobs->save_job(
			BulkJob::create(
				BulkOperationType::CatalogUpdate,
				1,
				[ 'scope' => 'selected_ids', 'selected_ids' => [ 1 ] ],
				[ 'field_actions' => [] ]
			)->with(
				[
					'id'         => 1,
					'status'     => BulkJobStatus::Previewing,
					'created_at' => gmdate( 'Y-m-d H:i:s', $now - 700 ),
					'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 700 ),
				]
			)
		);

		$health = new BulkQueueHealth(
			new InMemoryBoundedQueue(),
			$jobs,
			new BulkStaleJobQuery( $jobs )
		);
		$snap = $health->snapshot();

		self::assertSame( 1, $snap['stale_job_count'] );
		self::assertSame( 1, $snap['active_jobs'] );
		self::assertSame( 'not_running', $snap['scheduler_health'] );
		self::assertFalse( $snap['runner_apparently_ok'] );
	}

	public function test_missing_action_scheduler_is_unavailable_not_silently_healthy(): void {
		$gateway           = new RecordingActionSchedulerGateway();
		$gateway->schedule = false;
		$health            = new BulkQueueHealth(
			new ActionSchedulerQueue( $gateway ),
			new InMemoryBulkJobRepository(),
			new BulkStaleJobQuery( new InMemoryBulkJobRepository() )
		);
		$snap = $health->snapshot();

		self::assertFalse( $snap['available'] );
		self::assertSame( 'unavailable', $snap['scheduler_health'] );
		self::assertSame( 'action_scheduler_unavailable', $snap['unavailable_reason'] );
	}

	public function test_system_status_and_needs_attention_surfaces_exist(): void {
		$status = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/SystemStatusPage.php' );
		$needs  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/NeedsAttentionPage.php' );

		self::assertStringContainsString( 'Bulk background processing', $status );
		self::assertStringContainsString( 'Scheduler health', $status );
		self::assertStringContainsString( 'Immediate async enqueue supported', $status );
		self::assertStringContainsString( 'Bulk jobs waiting for background processing', $needs );
		self::assertStringContainsString( 'Process next batch', $needs );
	}
}
