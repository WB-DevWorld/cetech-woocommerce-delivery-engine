<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Queue;

/**
 * Narrow Action Scheduler / WP-Cron surface used by Bulk Tools.
 *
 * Implementations must never run an unbounded queue drain.
 */
interface ActionSchedulerGateway {

	public function can_schedule(): bool;

	public function can_unschedule(): bool;

	public function can_enqueue_async(): bool;

	public function unique_supported(): bool;

	public function enqueue_async( string $hook, array $args, string $group, bool $unique ): bool;

	public function schedule_single( int $timestamp, string $hook, array $args, string $group, bool $unique ): bool;

	public function unschedule_all( string $hook, array $args, string $group ): void;

	/**
	 * Best-effort kick. Must not process an unbounded Action Scheduler queue.
	 */
	public function attempt_kick(): void;

	public function pending_count( string $hook, ?array $args, string $group ): int;

	public function wp_cron_disabled(): bool;
}
