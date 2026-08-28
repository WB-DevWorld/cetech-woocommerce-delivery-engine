<?php
/**
 * Disposable Action Scheduler function doubles for isolated Plugin-boot tests.
 * Loaded only from a process-isolated test so other suites do not see AS as available.
 */

declare(strict_types=1);

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $unique = false ) {
		$GLOBALS['cetech_de_test_as_calls']['schedule'][] = [
			'timestamp' => (int) $timestamp,
			'hook'      => (string) $hook,
			'args'      => is_array( $args ) ? $args : [],
			'group'     => (string) $group,
			'unique'    => (bool) $unique,
		];

		return 1;
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = false ) {
		$GLOBALS['cetech_de_test_as_calls']['async'][] = [
			'hook'   => (string) $hook,
			'args'   => is_array( $args ) ? $args : [],
			'group'  => (string) $group,
			'unique' => (bool) $unique,
		];

		return 1;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function as_unschedule_all_actions( $hook, $args = [], $group = '' ): void {
		$GLOBALS['cetech_de_test_as_calls']['unschedule'][] = [
			'hook'  => (string) $hook,
			'args'  => is_array( $args ) ? $args : [],
			'group' => (string) $group,
		];
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	/**
	 * @param array<string, mixed> $query
	 * @return list<int>
	 */
	function as_get_scheduled_actions( $query = [], $return_format = 'OBJECT' ) {
		unset( $query, $return_format );
		$count = count( $GLOBALS['cetech_de_test_as_calls']['async'] ?? [] );

		return $count > 0 ? range( 1, $count ) : [];
	}
}

if ( ! class_exists( 'ActionScheduler', false ) ) {
	class ActionScheduler {
		public static function store(): object {
			return new stdClass();
		}
	}
}

if ( ! class_exists( 'ActionScheduler_AsyncRequest_QueueRunner', false ) ) {
	class ActionScheduler_AsyncRequest_QueueRunner {
		public function __construct( $store ) {
			unset( $store );
		}

		public function maybe_dispatch(): void {
			$GLOBALS['cetech_de_test_as_calls']['kick'][] = 'maybe_dispatch';
		}
	}
}
