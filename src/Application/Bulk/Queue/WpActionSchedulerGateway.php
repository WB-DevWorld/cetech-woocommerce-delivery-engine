<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * Live WooCommerce Action Scheduler adapter. Kick is best-effort and bounded.
 */
final class WpActionSchedulerGateway implements ActionSchedulerGateway {

	public function can_schedule(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	public function can_unschedule(): bool {
		return function_exists( 'as_unschedule_all_actions' );
	}

	public function can_enqueue_async(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	public function unique_supported(): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		try {
			return ( new \ReflectionFunction( 'as_schedule_single_action' ) )->getNumberOfParameters() >= 5;
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function enqueue_async( string $hook, array $args, string $group, bool $unique ): bool {
		if ( ! $this->can_enqueue_async() ) {
			return false;
		}

		if ( $unique && $this->unique_supported() ) {
			as_enqueue_async_action( $hook, $args, $group, true );
		} else {
			as_enqueue_async_action( $hook, $args, $group );
		}

		return true;
	}

	public function schedule_single( int $timestamp, string $hook, array $args, string $group, bool $unique ): bool {
		if ( ! $this->can_schedule() ) {
			return false;
		}

		if ( $unique && $this->unique_supported() ) {
			as_schedule_single_action( $timestamp, $hook, $args, $group, true );
		} else {
			as_schedule_single_action( $timestamp, $hook, $args, $group );
		}

		return true;
	}

	public function unschedule_all( string $hook, array $args, string $group ): void {
		if ( ! $this->can_unschedule() ) {
			return;
		}

		as_unschedule_all_actions( $hook, $args, $group );
	}

	public function attempt_kick(): void {
		try {
			if ( class_exists( '\ActionScheduler_AsyncRequest_QueueRunner' ) && class_exists( '\ActionScheduler' ) ) {
				$store = \ActionScheduler::store();
				$runner = new \ActionScheduler_AsyncRequest_QueueRunner( $store );
				if ( method_exists( $runner, 'maybe_dispatch' ) ) {
					$runner->maybe_dispatch();
				} elseif ( method_exists( $runner, 'dispatch' ) ) {
					$runner->dispatch();
				}
			}
		} catch ( \Throwable ) {
			// Loopback or async dispatch may be blocked. The durable job remains queued.
		}

		if ( $this->wp_cron_disabled() ) {
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			try {
				spawn_cron();
			} catch ( \Throwable ) {
				// Ignore; Action Scheduler or the admin continue path can still run.
			}
		}
	}

	public function pending_count( string $hook, ?array $args, string $group ): int {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$query = [
				'hook'     => $hook,
				'group'    => $group,
				'status'   => class_exists( '\ActionScheduler_Store' ) ? \ActionScheduler_Store::STATUS_PENDING : 'pending',
				'per_page' => 20,
			];
			if ( null !== $args ) {
				$query['args'] = $args;
			}
			$found = as_get_scheduled_actions( $query, 'ids' );

			return is_array( $found ) ? count( $found ) : 0;
		}

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( $hook, $args, $group ) ? 1 : 0;
		}

		return 0;
	}

	public function wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
