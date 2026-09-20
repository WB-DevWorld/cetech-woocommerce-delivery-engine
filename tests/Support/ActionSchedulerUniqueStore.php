<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

/**
 * Faithful Action Scheduler uniqueness store for isolated tests.
 *
 * Action Scheduler 3.9.2 unique inserts match hook + group against
 * pending and in-progress statuses and ignore arguments. This store
 * reproduces that contract plus pending-only unscheduling.
 */
final class ActionSchedulerUniqueStore {

	public const STATUS_PENDING = 'pending';

	public const STATUS_RUNNING = 'in-progress';

	public const STATUS_COMPLETE = 'complete';

	public const STATUS_FAILED = 'failed';

	public const STATUS_CANCELED = 'canceled';

	/** @var array<int, array{hook: string, args: array<string, mixed>, group: string, status: string, unique: bool}> */
	private array $actions = [];

	private int $next_id = 0;

	public static function install(): self {
		$store = new self();
		$GLOBALS['cetech_de_as_store'] = $store;
		$GLOBALS['wp_actions']['action_scheduler_init'] = max( 1, (int) ( $GLOBALS['wp_actions']['action_scheduler_init'] ?? 0 ) );
		$GLOBALS['cetech_de_test_as_calls']             = [
			'async'      => [],
			'unschedule' => [],
			'schedule'   => [],
		];

		return $store;
	}

	public static function uninstall(): void {
		unset( $GLOBALS['cetech_de_as_store'] );
	}

	public function reset(): void {
		$this->actions = [];
		$this->next_id = 0;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function enqueue_async( string $hook, array $args, string $group, bool $unique ): int {
		$GLOBALS['cetech_de_test_as_calls']['async'][] = [
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
		];
		if ( $unique ) {
			$existing = $this->find_unique( $hook, $group );
			if ( $existing > 0 ) {
				return $existing;
			}
		}
		$id = ++$this->next_id;
		$this->actions[ $id ] = [
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'status' => self::STATUS_PENDING,
			'unique' => $unique,
		];

		return $id;
	}

	/**
	 * @param mixed $args
	 */
	public function unschedule_all( string $hook, $args, string $group ): void {
		$GLOBALS['cetech_de_test_as_calls']['unschedule'][] = [
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		];
		foreach ( $this->actions as $id => $row ) {
			if ( $row['hook'] !== $hook || $row['group'] !== $group ) {
				continue;
			}
			if ( self::STATUS_PENDING !== $row['status'] ) {
				continue;
			}
			$this->actions[ $id ]['status'] = self::STATUS_CANCELED;
		}
	}

	public function cancel_action( int $action_id ): void {
		if ( ! isset( $this->actions[ $action_id ] ) ) {
			return;
		}
		if ( self::STATUS_PENDING === $this->actions[ $action_id ]['status'] ) {
			$this->actions[ $action_id ]['status'] = self::STATUS_CANCELED;
		}
	}

	public function fetch_action( int $action_id ): object {
		$hook = (string) ( $this->actions[ $action_id ]['hook'] ?? '' );

		return new class( $hook ) {
			public function __construct( private string $hook ) {
			}

			public function get_hook(): string {
				return $this->hook;
			}
		};
	}

	public function claim_next_pending( string $hook, string $group ): int {
		foreach ( $this->actions as $id => $row ) {
			if ( $row['hook'] === $hook && $row['group'] === $group && self::STATUS_PENDING === $row['status'] ) {
				$this->actions[ $id ]['status'] = self::STATUS_RUNNING;

				return $id;
			}
		}

		return 0;
	}

	public function mark_running( int $action_id ): void {
		if ( isset( $this->actions[ $action_id ] ) ) {
			$this->actions[ $action_id ]['status'] = self::STATUS_RUNNING;
		}
	}

	public function complete( int $action_id ): void {
		if ( isset( $this->actions[ $action_id ] ) ) {
			$this->actions[ $action_id ]['status'] = self::STATUS_COMPLETE;
		}
	}

	public function fail( int $action_id ): void {
		if ( isset( $this->actions[ $action_id ] ) ) {
			$this->actions[ $action_id ]['status'] = self::STATUS_FAILED;
		}
	}

	public function count_by_status( string $hook, string $group, string $status ): int {
		$count = 0;
		foreach ( $this->actions as $row ) {
			if ( $row['hook'] === $hook && $row['group'] === $group && $row['status'] === $status ) {
				++$count;
			}
		}

		return $count;
	}

	public function last_id(): int {
		return $this->next_id;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return list<int>
	 */
	public function query( array $query, string $return_format = 'ids' ): array {
		unset( $return_format );
		$hook      = (string) ( $query['hook'] ?? '' );
		$group     = (string) ( $query['group'] ?? '' );
		$status    = $query['status'] ?? '';
		$statuses  = is_array( $status ) ? $status : ( '' === $status ? [] : [ (string) $status ] );
		$per_page  = (int) ( $query['per_page'] ?? 50 );
		$ids       = [];
		foreach ( $this->actions as $id => $row ) {
			if ( '' !== $hook && $row['hook'] !== $hook ) {
				continue;
			}
			if ( '' !== $group && $row['group'] !== $group ) {
				continue;
			}
			if ( [] !== $statuses && ! in_array( $row['status'], $statuses, true ) ) {
				continue;
			}
			$ids[] = $id;
		}
		if ( 'DESC' === strtoupper( (string) ( $query['order'] ?? 'ASC' ) ) ) {
			$ids = array_reverse( $ids );
		}
		if ( $per_page > 0 ) {
			$ids = array_slice( $ids, 0, $per_page );
		}

		return $ids;
	}

	private function find_unique( string $hook, string $group ): int {
		foreach ( $this->actions as $id => $row ) {
			if ( $row['hook'] !== $hook || $row['group'] !== $group ) {
				continue;
			}
			if ( in_array( $row['status'], [ self::STATUS_PENDING, self::STATUS_RUNNING ], true ) ) {
				return $id;
			}
		}

		return 0;
	}
}
