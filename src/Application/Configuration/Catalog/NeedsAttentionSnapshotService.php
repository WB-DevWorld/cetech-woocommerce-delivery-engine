<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Site-wide Needs Attention catalog count, computed off the admin request path.
 *
 * The catalog result does not depend on who is looking; only visibility does
 * (NeedsAttentionCountQuery). Admin screens read the last complete snapshot and
 * never scan the catalog themselves. Any write that can change catalog
 * readiness moves the generation; a snapshot is fresh only for the generation
 * it was computed from and for at most TTL_SECONDS.
 */
final class NeedsAttentionSnapshotService {

	/** Safety net for a change no invalidation hook saw. */
	public const TTL_SECONDS = 900;

	/** Debounce: writes within this window share one refresh. */
	public const REFRESH_DELAY_SECONDS = 30;

	/** One cron run stops after this long and continues in the next run. */
	public const RUN_BUDGET_SECONDS = 20;

	/** A runner lock older than this is treated as abandoned. */
	public const LOCK_TTL_SECONDS = 120;

	public const RUN_LOCKED    = 'locked';
	public const RUN_SAVED     = 'saved';
	public const RUN_CONTINUED = 'continued';
	public const RUN_RESTARTED = 'restarted';

	private bool $dirty = false;

	public function __construct(
		private readonly NeedsAttentionSnapshotStore $store,
		private readonly NeedsAttentionQuery $catalog,
		private readonly NeedsAttentionRefreshSchedulerInterface $scheduler,
		private readonly string $plugin_version,
		private readonly ?\Closure $clock = null
	) {
	}

	/**
	 * Catalog reading for admin screens. Schedules a refresh when there is no
	 * snapshot yet or the one there is no longer fresh. Never scans.
	 */
	public function reading(): NeedsAttentionCatalogReading {
		$snapshot = $this->current_snapshot();
		if ( null === $snapshot ) {
			$this->request_refresh();

			return new NeedsAttentionCatalogReading( null, null, false );
		}

		$fresh = $this->is_fresh( $snapshot );
		if ( ! $fresh ) {
			$this->request_refresh();
		}

		return new NeedsAttentionCatalogReading( $snapshot->count, $snapshot->computed_at, $fresh );
	}

	/**
	 * The last complete snapshot written by this plugin version, fresh or not.
	 */
	public function current_snapshot(): ?NeedsAttentionCatalogSnapshot {
		$snapshot = $this->store->snapshot();
		if ( null === $snapshot || ! $snapshot->complete || $snapshot->plugin_version !== $this->plugin_version ) {
			return null;
		}

		return $snapshot;
	}

	public function is_fresh( NeedsAttentionCatalogSnapshot $snapshot ): bool {
		$age = $this->now() - $snapshot->computed_at;

		return $snapshot->generation === $this->store->generation()
			&& $age >= -60
			&& $age < self::TTL_SECONDS;
	}

	public function generation(): int {
		return $this->store->generation();
	}

	/**
	 * Save a complete scan result, unless an invalidation happened since the
	 * scan started; then the result is dropped and a refresh is queued.
	 */
	public function record_complete_scan( int $count, int $product_total, int $generation_at_start ): bool {
		if ( $this->store->stored_generation() !== $generation_at_start ) {
			$this->request_refresh();

			return false;
		}

		$this->store->save_snapshot(
			new NeedsAttentionCatalogSnapshot(
				max( 0, $count ),
				max( 0, $product_total ),
				$this->now(),
				$generation_at_start,
				true,
				$this->plugin_version
			)
		);

		return true;
	}

	/**
	 * Background refresh (the WP-Cron event). Counts batches from the saved
	 * cursor until the catalog ends or the time budget runs out; then either
	 * saves the snapshot or stores the cursor and queues the next run. If the
	 * generation moved meanwhile, the partial result is discarded and the scan
	 * starts again from the first product.
	 *
	 * @param int $max_batches Upper bound on batches in this run (tests).
	 */
	public function run_refresh( int $max_batches = PHP_INT_MAX ): string {
		if ( ! $this->store->acquire_lock( $this->now(), self::LOCK_TTL_SECONDS ) ) {
			// The holder queues its own continuation; make sure one is queued anyway.
			$this->request_refresh();

			return self::RUN_LOCKED;
		}

		try {
			$generation = $this->store->stored_generation();
			$progress   = $this->store->progress();
			if ( null === $progress || $progress['generation'] !== $generation ) {
				$progress = [
					'generation' => $generation,
					'offset'     => 0,
					'count'      => 0,
					'started_at' => $this->now(),
				];
			}

			$deadline = $this->now() + self::RUN_BUDGET_SECONDS;
			$batches  = 0;

			while ( true ) {
				$batch                = $this->catalog->count_batch( $progress['offset'] );
				$progress['offset'] += $batch->scanned;
				$progress['count']  += $batch->flagged;
				++$batches;

				if ( $batch->reached_end ) {
					$this->store->clear_progress();

					return $this->record_complete_scan( $progress['count'], $progress['offset'], $generation )
						? self::RUN_SAVED
						: self::RUN_RESTARTED;
				}

				if ( $batches >= $max_batches || $this->now() >= $deadline ) {
					break;
				}
			}

			if ( $this->store->stored_generation() !== $generation ) {
				$this->store->clear_progress();
				$this->request_refresh( 0 );

				return self::RUN_RESTARTED;
			}

			$this->store->save_progress( $progress );
			$this->request_refresh( 0 );

			return self::RUN_CONTINUED;
		} finally {
			$this->store->release_lock();
		}
	}

	public function request_refresh( int $delay_seconds = self::REFRESH_DELAY_SECONDS ): void {
		if ( $this->scheduler->is_scheduled() ) {
			return;
		}

		$this->scheduler->schedule( $this->now() + max( 0, $delay_seconds ) );
	}

	/**
	 * Note that catalog readiness may have changed. Coalesced: the generation
	 * moves once per request, in flush() (registered on shutdown).
	 */
	public function mark_dirty(): void {
		$this->dirty = true;
	}

	public function is_dirty(): bool {
		return $this->dirty;
	}

	public function flush(): void {
		if ( ! $this->dirty ) {
			return;
		}

		$this->dirty = false;
		$this->store->new_generation();
		$this->request_refresh();
	}

	public function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}
}
