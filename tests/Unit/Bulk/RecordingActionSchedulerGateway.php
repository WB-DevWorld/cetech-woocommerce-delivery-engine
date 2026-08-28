<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerGateway;

final class RecordingActionSchedulerGateway implements ActionSchedulerGateway {

	public bool $schedule = true;

	public bool $unschedule = true;

	public bool $async = true;

	public bool $unique = true;

	public bool $cron_disabled = false;

	/** @var list<array<string, mixed>> */
	public array $calls = [];

	public int $pending = 0;

	/** @var list<array{hook: string, args: ?array, group: string}> */
	public array $pending_queries = [];

	public function can_schedule(): bool {
		return $this->schedule;
	}

	public function can_unschedule(): bool {
		return $this->unschedule;
	}

	public function can_enqueue_async(): bool {
		return $this->async;
	}

	public function unique_supported(): bool {
		return $this->unique;
	}

	public function enqueue_async( string $hook, array $args, string $group, bool $unique ): bool {
		$this->calls[] = [
			'op'     => 'async',
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
		];

		return true;
	}

	public function schedule_single( int $timestamp, string $hook, array $args, string $group, bool $unique ): bool {
		$this->calls[] = [
			'op'        => 'schedule',
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'unique'    => $unique,
		];

		return true;
	}

	public function unschedule_all( string $hook, array $args, string $group ): void {
		$this->calls[] = [
			'op'    => 'unschedule',
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		];
	}

	public function attempt_kick(): void {
		$this->calls[] = [ 'op' => 'kick' ];
	}

	public function pending_count( string $hook, ?array $args, string $group ): int {
		$this->pending_queries[] = [
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		];

		return $this->pending;
	}

	public function wp_cron_disabled(): bool {
		return $this->cron_disabled;
	}

	public function ops(): array {
		return array_column( $this->calls, 'op' );
	}
}
