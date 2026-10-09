<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseVersionReadService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromisePolicyVersionLoader;

/** Unmounted private adapter. The read service reauthorizes each exact load before SQL and disclosure. */
final readonly class StoredPromisePolicyVersionLoader implements PromisePolicyVersionLoader {
	public function __construct( private PromiseVersionReadService $reads, private OperationIdentity $actor ) {}
	public function load_versions( array $references ): array { return $this->reads->load_policy_versions( $this->actor, $references ); }
}
