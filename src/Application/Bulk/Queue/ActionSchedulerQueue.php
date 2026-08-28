<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * WooCommerce Action Scheduler adapter. Passes only job IDs, never catalogs.
 *
 * Prefers async enqueue so Bulk Tools does not wait solely on an unknown host
 * cron cadence. Kick is best-effort and must never drain the whole AS queue.
 */
final class ActionSchedulerQueue implements BackgroundQueueInterface {

	public const HOOK = 'cetech_de_bulk_job_tick';

	public const GROUP = 'cetech-delivery-engine-bulk';

	public function __construct(
		private readonly ActionSchedulerGateway $gateway = new WpActionSchedulerGateway()
	) {
	}

	public function is_available(): bool {
		return $this->gateway->can_schedule() && $this->gateway->can_unschedule();
	}

	public function enqueue_job_tick( int $job_id, int $delay_seconds = 0 ): bool {
		if ( ! $this->is_available() || $job_id <= 0 ) {
			return false;
		}

		$args   = [ 'job_id' => $job_id ];
		$unique = $this->gateway->unique_supported();
		$ok     = false;

		if ( $delay_seconds > 0 ) {
			$ok = $this->gateway->schedule_single(
				time() + $delay_seconds,
				self::HOOK,
				$args,
				self::GROUP,
				$unique
			);
		} elseif ( $this->gateway->can_enqueue_async() ) {
			$ok = $this->gateway->enqueue_async( self::HOOK, $args, self::GROUP, $unique );
		} else {
			$ok = $this->gateway->schedule_single( time(), self::HOOK, $args, self::GROUP, $unique );
		}

		if ( $ok ) {
			$this->gateway->attempt_kick();
		}

		return $ok;
	}

	public function cancel_job_ticks( int $job_id ): void {
		if ( ! $this->gateway->can_unschedule() || $job_id <= 0 ) {
			return;
		}

		$this->gateway->unschedule_all( self::HOOK, [ 'job_id' => $job_id ], self::GROUP );
	}

	public function unavailable_reason(): string {
		return $this->is_available() ? '' : 'action_scheduler_unavailable';
	}

	/**
	 * @return array<string, scalar|null>
	 */
	public function diagnostic_snapshot( ?int $job_id = null ): array {
		$args = null !== $job_id ? [ 'job_id' => $job_id ] : null;

		return [
			'available'               => $this->is_available(),
			'async_enqueue_supported' => $this->gateway->can_enqueue_async(),
			'unique_actions_supported'=> $this->gateway->unique_supported(),
			'wp_cron_disabled'        => $this->gateway->wp_cron_disabled(),
			'pending_actions'         => $this->is_available()
				? $this->gateway->pending_count( self::HOOK, $args, self::GROUP )
				: 0,
		];
	}
}
