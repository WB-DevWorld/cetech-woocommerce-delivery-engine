<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

/**
 * Action Scheduler APIs are safe only after the datastore is initialized.
 *
 * function_exists( 'as_enqueue_async_action' ) is not sufficient: the
 * functions can exist while Action Scheduler's store is still booting.
 *
 * Unique continuation is implemented here rather than via
 * as_enqueue_async_action( ..., unique=true ). Action Scheduler 3.9.2
 * unique inserts match hook + group against pending AND in-progress and
 * ignore arguments. Enqueueing the same hook from inside a running action
 * therefore returns the current action ID and leaves no successor after
 * completion. That is the Ghana Location Pack liveness defect (issue #33).
 */
final class ActionSchedulerReadiness {

	public static function is_initialized(): bool {
		if ( class_exists( \ActionScheduler::class, false ) && is_callable( [ \ActionScheduler::class, 'is_initialized' ] ) ) {
			try {
				if ( true === \ActionScheduler::is_initialized() ) {
					return true;
				}
			} catch ( \Throwable ) {
				return false;
			}
		}

		if ( class_exists( \Action_Scheduler::class, false ) && is_callable( [ \Action_Scheduler::class, 'is_initialized' ] ) ) {
			try {
				if ( true === \Action_Scheduler::is_initialized() ) {
					return true;
				}
			} catch ( \Throwable ) {
				return false;
			}
		}

		return function_exists( 'did_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	public static function can_enqueue_async(): bool {
		return self::is_initialized() && function_exists( 'as_enqueue_async_action' );
	}

	public static function can_schedule_single(): bool {
		return self::is_initialized() && function_exists( 'as_schedule_single_action' );
	}

	public static function can_unschedule(): bool {
		return self::is_initialized() && function_exists( 'as_unschedule_all_actions' );
	}

	public static function current_time(): int {
		$store = $GLOBALS['cetech_de_as_store'] ?? null;
		if ( is_object( $store ) && method_exists( $store, 'now' ) ) {
			return (int) $store->now();
		}

		return time();
	}

	public static function unschedule_all( string $hook, string $group ): void {
		if ( ! self::can_unschedule() ) {
			return;
		}

		as_unschedule_all_actions( $hook, null, $group );
	}

	public static function pending_count( string $hook, string $group ): int {
		return count( self::scheduled_ids( $hook, $group, self::status_pending() ) );
	}

	public static function in_progress_count( string $hook, string $group ): int {
		return count( self::scheduled_ids( $hook, $group, self::status_running() ) );
	}

	public static function has_active_continuation( string $hook, string $group ): bool {
		return self::pending_count( $hook, $group ) > 0 || self::in_progress_count( $hook, $group ) > 0;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public static function enqueue_unique_async( string $hook, array $args, string $group ): bool {
		if ( array_key_exists( 'cetech_de_test_as_enqueue_attempts', $GLOBALS ) ) {
			++$GLOBALS['cetech_de_test_as_enqueue_attempts'];
		}

		if ( ! self::can_enqueue_async() ) {
			if ( array_key_exists( 'cetech_de_test_as_enqueue_blocked', $GLOBALS ) ) {
				++$GLOBALS['cetech_de_test_as_enqueue_blocked'];
			}

			return false;
		}

		if ( self::pending_count( $hook, $group ) > 0 ) {
			self::cap_pending( $hook, $group, 1 );
			if ( array_key_exists( 'cetech_de_test_as_enqueue_invoked', $GLOBALS ) ) {
				++$GLOBALS['cetech_de_test_as_enqueue_invoked'];
			}

			return true;
		}

		// unique=false so an in-progress action for this hook/group cannot
		// suppress creation of a pending successor. Dedup is pending-exists
		// plus a hard cap of one pending action for the same hook/group.
		$id = as_enqueue_async_action( $hook, $args, $group, false );
		self::cap_pending( $hook, $group, 1 );

		if ( array_key_exists( 'cetech_de_test_as_enqueue_invoked', $GLOBALS ) ) {
			++$GLOBALS['cetech_de_test_as_enqueue_invoked'];
		}

		if ( is_numeric( $id ) ) {
			return (int) $id > 0;
		}

		return false !== $id && null !== $id;
	}

	/**
	 * Schedule at most one pending delayed action for hook/group.
	 * Uses unique=false so an in-progress action cannot suppress the future check.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function schedule_unique_delayed( string $hook, array $args, string $group, int $timestamp ): bool {
		if ( array_key_exists( 'cetech_de_test_as_schedule_attempts', $GLOBALS ) ) {
			++$GLOBALS['cetech_de_test_as_schedule_attempts'];
		}

		if ( ! self::can_schedule_single() ) {
			return false;
		}

		$timestamp = max( $timestamp, self::current_time() );
		if ( self::pending_count( $hook, $group ) > 0 ) {
			self::cap_pending( $hook, $group, 1 );
			if ( array_key_exists( 'cetech_de_test_as_schedule_invoked', $GLOBALS ) ) {
				++$GLOBALS['cetech_de_test_as_schedule_invoked'];
			}

			return true;
		}

		$id = as_schedule_single_action( $timestamp, $hook, $args, $group, false );
		self::cap_pending( $hook, $group, 1 );

		if ( array_key_exists( 'cetech_de_test_as_schedule_invoked', $GLOBALS ) ) {
			++$GLOBALS['cetech_de_test_as_schedule_invoked'];
		}

		if ( is_numeric( $id ) ) {
			return (int) $id > 0;
		}

		return false !== $id && null !== $id;
	}

	/**
	 * @return list<int>
	 */
	public static function scheduled_ids( string $hook, string $group, string $status ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}

		$found = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => $group,
				'status'   => $status,
				'per_page' => 20,
				'orderby'  => 'date',
				'order'    => 'ASC',
			],
			'ids'
		);
		if ( ! is_array( $found ) ) {
			return [];
		}
		$ids = [];
		foreach ( $found as $id ) {
			$ids[] = (int) $id;
		}

		return $ids;
	}

	private static function cap_pending( string $hook, string $group, int $max ): void {
		$max = max( 1, $max );
		$ids = self::scheduled_ids( $hook, $group, self::status_pending() );
		if ( count( $ids ) <= $max ) {
			return;
		}
		foreach ( array_slice( $ids, $max ) as $extra_id ) {
			self::cancel_action_id( (int) $extra_id );
		}
	}

	private static function cancel_action_id( int $action_id ): void {
		if ( $action_id <= 0 ) {
			return;
		}
		if ( ! class_exists( \ActionScheduler::class, false ) || ! is_callable( [ \ActionScheduler::class, 'store' ] ) ) {
			return;
		}
		try {
			$store = \ActionScheduler::store();
		} catch ( \Throwable ) {
			return;
		}
		if ( ! is_object( $store ) || ! method_exists( $store, 'cancel_action' ) ) {
			return;
		}
		try {
			$store->cancel_action( $action_id );
		} catch ( \Throwable ) {
			return;
		}
	}

	private static function status_pending(): string {
		if ( class_exists( \ActionScheduler_Store::class, false ) && defined( \ActionScheduler_Store::class . '::STATUS_PENDING' ) ) {
			return (string) \ActionScheduler_Store::STATUS_PENDING;
		}

		return 'pending';
	}

	private static function status_running(): string {
		if ( class_exists( \ActionScheduler_Store::class, false ) && defined( \ActionScheduler_Store::class . '::STATUS_RUNNING' ) ) {
			return (string) \ActionScheduler_Store::STATUS_RUNNING;
		}

		return 'in-progress';
	}
}
