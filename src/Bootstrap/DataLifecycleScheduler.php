<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;

/** Advisory wake-ups. The owned SQL coordinator is the sole effect fence. */
final class DataLifecycleScheduler {

	public const HOOK = 'cetech_de_data_lifecycle_cleanup_batch';
	public const GROUP = 'cetech-delivery-engine-data-lifecycle';
	public const MAINTENANCE_INTERVAL_SECONDS = 300;
	public const SUSPENSION_INSPECTION_LIMIT = 200;

	private \Closure $clock;
	private bool $registered = false;
	private bool $suspended = false;
	private int $registered_site = 0;
	/** @var array<int, self> Current-request callbacks owned by this exact adapter. */
	private static array $instances = [];

	public function __construct( private DataLifecycleCleanupService $service, ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn (): int => time() : \Closure::fromCallable( $clock );
	}

	public function register(): void {
		$site = self::current_site();
		if ( $this->registered || $site < 1 || ! function_exists( 'add_action' ) ) {
			return;
		}
		$this->registered = true;
		$this->suspended = false;
		$this->registered_site = $site;
		self::$instances[spl_object_id( $this )] = $this;
		add_action( self::HOOK, [ $this, 'tick' ], 10, 3 );
		add_action( 'action_scheduler_init', [ $this, 'boot' ], 20, 0 );
		if ( ActionSchedulerReadiness::is_initialized() ) {
			$this->boot();
		}
	}

	public function boot(): ?DataLifecycleResult {
		return $this->ensure_dispatch();
	}

	/** No synchronous cleanup fallback and no start before AS initialization. */
	public function ensure_dispatch(): ?DataLifecycleResult {
		$site = self::current_site();
		if ( $this->suspended || $site < 1 || ( $this->registered_site > 0 && $this->registered_site !== $site ) || ! self::can_schedule() ) {
			return null;
		}
		try {
			$result = $this->service->read( $site );
			// Storage failure is not absence and cannot authorize a new ceiling.
			if ( 'refused' === $result->status && 'no_checkpoint' === $result->reason ) {
				$result = $this->service->start( $site, 'expired', expect_absent: true );
			}
			return $this->publish( $result );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** An old or foreign action cannot select a site, replace a run or repeat a batch. */
	public function tick( mixed $site_id, mixed $run_id, mixed $checkpoint_token ): ?DataLifecycleResult {
		$site = self::current_site();
		if ( $this->suspended || ( $this->registered_site > 0 && $this->registered_site !== $site ) || ! self::valid_args( [ 'site_id' => $site_id, 'run_id' => $run_id, 'checkpoint_token' => $checkpoint_token ], $site ) ) {
			return null;
		}
		try {
			$current = $this->service->read( $site );
			$progress = $current->progress;
			if ( 'accepted' !== $current->status || null === $progress || 'expired' !== $progress->mode
				|| ! hash_equals( $progress->run_id, $run_id ) || ! hash_equals( $progress->checkpoint_token, $checkpoint_token ) ) {
				return $current;
			}
			if ( 'completed' === $progress->status ) {
				$current = $this->service->start( $site, 'expired', expected_previous: $progress );
				$progress = $current->progress;
				if ( 'accepted' !== $current->status || null === $progress ) {
					return $current;
				}
			}
			if ( 'running' === $progress->status ) {
				// Exactly one bounded batch. A refused/uncertain result is never rerun here.
				$current = $this->service->batch( $site, DataLifecycleContinuation::checkpoint( $progress ) );
			}
			return $this->publish( $current );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** Publication cannot change accepted SQL progress or claim a global queue count. */
	public function publish( DataLifecycleResult $result ): DataLifecycleResult {
		$progress = $result->progress;
		if ( 'accepted' !== $result->status || null === $progress || 'expired' !== $progress->mode
			|| $progress->site_id !== self::current_site() || ! in_array( $progress->status, [ 'running', 'completed' ], true ) ) {
			return $result;
		}
		try {
			if ( $this->suspended || ( $this->registered_site > 0 && $this->registered_site !== $progress->site_id ) || ! self::can_schedule() ) {
				return $result->with_publication_pending();
			}
			$args = self::args( $progress );
			$pending = as_get_scheduled_actions(
				[ 'hook' => self::HOOK, 'group' => self::GROUP, 'args' => $args, 'status' => 'pending', 'per_page' => 1 ],
				'ids'
			);
			if ( ! is_array( $pending ) ) {
				return $result->with_publication_pending();
			}
			if ( [] !== $pending ) {
				return self::positive_id( $pending[0] ) > 0 ? $result : $result->with_publication_pending();
			}
			$now = ( $this->clock )();
			$delay = 'completed' === $progress->status ? self::MAINTENANCE_INTERVAL_SECONDS : 1;
			if ( ! is_int( $now ) || $now < 1 || $now > PHP_INT_MAX - $delay ) {
				return $result->with_publication_pending();
			}
			// AS unique=true also matches running actions and can lose this successor.
			$id = as_schedule_single_action( $now + $delay, self::HOOK, $args, self::GROUP, false );
			return self::positive_id( $id ) > 0 ? $result : $result->with_publication_pending();
		} catch ( \Throwable ) {
			return $result->with_publication_pending();
		}
	}

	/**
	 * Stop this site's owned callbacks and retain AS rows.
	 *
	 * AS cancel_action updates only by ID, so a pending precheck cannot protect a
	 * concurrent claim. We deliberately do not call it or mutate shared AS SQL.
	 */
	public static function suspend_current_site(): array {
		$report = [ 'dispatch_stopped' => false, 'handlers_removed' => 0, 'cancellation_supported' => false,
			'pending_inspected' => 0, 'pending_retained' => 0, 'limit_reached' => false ];
		$site = self::current_site();
		if ( $site < 1 ) {
			return $report;
		}
		try {
			$stopped = true;
			foreach ( self::$instances as $id => $instance ) {
				if ( $instance->registered_site !== $site ) {
					continue;
				}
				$instance->suspended = true;
				if ( ! function_exists( 'remove_action' ) ) {
					$stopped = false;
					continue;
				}
				foreach ( [ [ self::HOOK, 'tick', 10 ], [ 'action_scheduler_init', 'boot', 20 ] ] as [ $hook, $method, $priority ] ) {
					if ( remove_action( $hook, [ $instance, $method ], $priority ) ) {
						++$report['handlers_removed'];
					}
				}
				$instance->registered = false;
				unset( self::$instances[$id] );
			}
			$report['dispatch_stopped'] = $stopped;
			if ( ! self::can_schedule() || ! class_exists( \ActionScheduler::class, false ) || ! is_callable( [ \ActionScheduler::class, 'store' ] ) ) {
				return $report;
			}
			$store = \ActionScheduler::store();
			if ( ! is_object( $store ) || ! is_callable( [ $store, 'fetch_action' ] ) || ! is_callable( [ $store, 'get_status' ] ) ) {
				return $report;
			}
			$ids = as_get_scheduled_actions(
				[ 'hook' => self::HOOK, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => self::SUSPENSION_INSPECTION_LIMIT + 1, 'orderby' => 'action_id', 'order' => 'ASC' ],
				'ids'
			);
			if ( ! is_array( $ids ) || count( $ids ) > self::SUSPENSION_INSPECTION_LIMIT + 1 ) {
				return $report;
			}
			$report['limit_reached'] = count( $ids ) > self::SUSPENSION_INSPECTION_LIMIT;
			foreach ( array_slice( $ids, 0, self::SUSPENSION_INSPECTION_LIMIT ) as $id ) {
				++$report['pending_inspected'];
				$id = self::positive_id( $id );
				if ( $id < 1 || 'pending' !== $store->get_status( $id ) ) {
					continue;
				}
				$action = $store->fetch_action( $id );
				if ( ! is_object( $action ) || ! is_callable( [ $action, 'get_hook' ] ) || ! is_callable( [ $action, 'get_group' ] ) || ! is_callable( [ $action, 'get_args' ] )
					|| self::HOOK !== $action->get_hook() || self::GROUP !== $action->get_group() || ! self::valid_args( $action->get_args(), $site ) ) {
					continue;
				}
				++$report['pending_retained'];
			}
		} catch ( \Throwable ) {
			// The observed counts remain bounded; no global cancellation claim.
		}
		return $report;
	}

	private static function can_schedule(): bool {
		return ActionSchedulerReadiness::can_schedule_single() && function_exists( 'as_get_scheduled_actions' );
	}

	private static function current_site(): int {
		if ( ! function_exists( 'get_current_blog_id' ) ) {
			return 0;
		}
		$site = get_current_blog_id();
		return is_int( $site ) && $site > 0 ? $site : 0;
	}

	private static function args( DataLifecycleProgress $progress ): array {
		return [ 'site_id' => $progress->site_id, 'run_id' => $progress->run_id, 'checkpoint_token' => $progress->checkpoint_token ];
	}

	private static function valid_args( mixed $args, int $site ): bool {
		return $site > 0 && is_array( $args ) && [ 'site_id', 'run_id', 'checkpoint_token' ] === array_keys( $args )
			&& is_int( $args['site_id'] ) && $site === $args['site_id']
			&& is_string( $args['run_id'] ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/D', $args['run_id'] )
			&& is_string( $args['checkpoint_token'] ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/D', $args['checkpoint_token'] );
	}

	private static function positive_id( mixed $id ): int {
		if ( is_int( $id ) ) {
			return $id > 0 ? $id : 0;
		}
		if ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $id ) || strlen( $id ) > strlen( (string) PHP_INT_MAX )
			|| strlen( $id ) === strlen( (string) PHP_INT_MAX ) && strcmp( $id, (string) PHP_INT_MAX ) > 0 ) {
			return 0;
		}
		return (int) $id;
	}
}
