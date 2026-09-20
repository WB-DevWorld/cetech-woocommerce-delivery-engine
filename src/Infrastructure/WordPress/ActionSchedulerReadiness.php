<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

/**
 * Action Scheduler APIs are safe only after the datastore is initialized.
 *
 * function_exists( 'as_enqueue_async_action' ) is not sufficient: the
 * functions can exist while Action Scheduler's store is still booting.
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

	public static function can_unschedule(): bool {
		return self::is_initialized() && function_exists( 'as_unschedule_all_actions' );
	}

	public static function unschedule_all( string $hook, string $group ): void {
		if ( ! self::can_unschedule() ) {
			return;
		}

		as_unschedule_all_actions( $hook, null, $group );
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

		if ( self::can_unschedule() ) {
			as_unschedule_all_actions( $hook, null, $group );
		}

		as_enqueue_async_action( $hook, $args, $group, true );

		if ( array_key_exists( 'cetech_de_test_as_enqueue_invoked', $GLOBALS ) ) {
			++$GLOBALS['cetech_de_test_as_enqueue_invoked'];
		}

		return true;
	}
}
