<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseVersionReadService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromiseCalendarVersionLoader;

/** Unmounted private adapter; caller references never confer read authority. */
final readonly class StoredPromiseCalendarVersionLoader implements PromiseCalendarVersionLoader {
	public function __construct( private PromiseVersionReadService $reads, private OperationIdentity $actor ) {}
	public function load_versions( array $references ): array { return $this->reads->load_calendar_versions( $this->actor, $references ); }
}
