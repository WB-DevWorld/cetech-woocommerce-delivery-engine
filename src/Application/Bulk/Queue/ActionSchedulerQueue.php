<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * WooCommerce Action Scheduler adapter. Passes only job IDs, never catalogs.
 */
final class ActionSchedulerQueue implements BackgroundQueueInterface {

	public const HOOK = 'cetech_de_bulk_job_tick';

	public const GROUP = 'cetech-delivery-engine-bulk';

	public function is_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	public function enqueue_job_tick( int $job_id, int $delay_seconds = 0 ): bool {
		if ( ! $this->is_available() || $job_id <= 0 ) {
			return false;
		}

		as_schedule_single_action(
			time() + max( 0, $delay_seconds ),
			self::HOOK,
			[ 'job_id' => $job_id ],
			self::GROUP
		);

		return true;
	}

	public function cancel_job_ticks( int $job_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || $job_id <= 0 ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, [ 'job_id' => $job_id ], self::GROUP );
	}

	public function unavailable_reason(): string {
		return $this->is_available() ? '' : 'action_scheduler_unavailable';
	}
}
