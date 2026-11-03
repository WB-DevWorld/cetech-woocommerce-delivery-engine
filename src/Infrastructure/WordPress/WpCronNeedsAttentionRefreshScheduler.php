<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionRefreshSchedulerInterface;

/**
 * WP-Cron single event for the Needs Attention refresh.
 *
 * Runs from system cron when DISABLE_WP_CRON is set, and from visitor-spawned
 * WP-Cron otherwise. At most one event is queued at a time.
 */
final class WpCronNeedsAttentionRefreshScheduler implements NeedsAttentionRefreshSchedulerInterface {

	public function is_scheduled(): bool {
		return function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::HOOK );
	}

	public function schedule( int $timestamp ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || $this->is_scheduled() ) {
			return;
		}

		wp_schedule_single_event( $timestamp, self::HOOK );
	}

	public function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}
}
