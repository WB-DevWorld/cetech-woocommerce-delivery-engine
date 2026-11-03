<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Queues the background Needs Attention catalog refresh.
 */
interface NeedsAttentionRefreshSchedulerInterface {

	public const HOOK = 'cetech_de_refresh_attention_snapshot';

	public function is_scheduled(): bool;

	public function schedule( int $timestamp ): void;

	public function clear(): void;
}
