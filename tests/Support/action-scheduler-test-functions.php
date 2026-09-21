<?php

declare(strict_types=1);

/**
 * Process-wide Action Scheduler test doubles.
 *
 * When $GLOBALS['cetech_de_as_store'] holds an ActionSchedulerUniqueStore,
 * enqueue/unschedule/query follow Action Scheduler 3.9.2 unique semantics
 * (hook + group against pending and in-progress; arguments ignored).
 * Otherwise the functions record calls and return a dummy ID so existing
 * suites keep working without a store.
 */

if ( ! class_exists( 'ActionScheduler_Store', false ) ) {
	class ActionScheduler_Store {
		public const STATUS_PENDING  = 'pending';
		public const STATUS_RUNNING  = 'in-progress';
		public const STATUS_COMPLETE = 'complete';
		public const STATUS_FAILED   = 'failed';
		public const STATUS_CANCELED = 'canceled';
	}
}

if ( ! class_exists( 'ActionScheduler', false ) ) {
	class ActionScheduler {
		/**
		 * @param string|null $function_name
		 */
		public static function is_initialized( $function_name = null ): bool {
			unset( $function_name );

			return function_exists( 'did_action' ) && did_action( 'action_scheduler_init' ) > 0;
		}

		/**
		 * @return object
		 */
		public static function store() {
			$store = $GLOBALS['cetech_de_as_store'] ?? null;
			if ( is_object( $store ) ) {
				return $store;
			}

			return new class() {
				public function cancel_action( $action_id ): void {
					unset( $action_id );
				}

				public function fetch_action( $action_id ) {
					unset( $action_id );

					return null;
				}
			};
		}
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = false ) {
		$store = $GLOBALS['cetech_de_as_store'] ?? null;
		if ( is_object( $store ) && method_exists( $store, 'enqueue_async' ) ) {
			return (int) $store->enqueue_async( (string) $hook, is_array( $args ) ? $args : [], (string) $group, (bool) $unique );
		}
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
	 * @param mixed $args
	 */
	function as_unschedule_all_actions( $hook, $args = [], $group = '' ): void {
		$store = $GLOBALS['cetech_de_as_store'] ?? null;
		if ( is_object( $store ) && method_exists( $store, 'unschedule_all' ) ) {
			$store->unschedule_all( (string) $hook, $args, (string) $group );

			return;
		}
		$GLOBALS['cetech_de_test_as_calls']['unschedule'][] = [
			'hook'  => (string) $hook,
			'args'  => $args,
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
		$store = $GLOBALS['cetech_de_as_store'] ?? null;
		if ( is_object( $store ) && method_exists( $store, 'query' ) ) {
			return $store->query( is_array( $query ) ? $query : [], (string) $return_format );
		}
		unset( $query, $return_format );

		return [];
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return int
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $unique = false ) {
		$store = $GLOBALS['cetech_de_as_store'] ?? null;
		if ( is_object( $store ) && method_exists( $store, 'schedule_single' ) ) {
			return (int) $store->schedule_single( (int) $timestamp, (string) $hook, is_array( $args ) ? $args : [], (string) $group, (bool) $unique );
		}
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
