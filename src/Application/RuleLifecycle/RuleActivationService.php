<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Explicit host boundary only. No scheduler, cron hook or automatic publisher. */
final class RuleActivationService {

	public function __construct( private RuleLifecycleReadService $reader, private RuleLifecycleService $lifecycle ) {}

	public function scan_due_page( OperationIdentity $identity, string $family, array $authorization_scope, RuleTime $at, RequestContext $request, ?int $ceiling = null, ?RuleTime $cursor_at = null, ?int $cursor_id = null ): array {
		return $this->reader->scan_due( $identity, $family, $authorization_scope, $at, $request, $ceiling, $cursor_at, $cursor_id );
	}

	public function activate_original( OperationIdentity $identity, string $family, string $version_uuid, array $authorization_scope, RequestContext $request ): OperationAttemptResult|ContractError {
		$original = $this->reader->activation_envelope( $identity, $family, $version_uuid, $authorization_scope, $request );
		if ( $original instanceof ContractError ) { return $original; }
		return $this->lifecycle->attempt( $original['identity'], $original['payload'], $request );
	}

	/** Reconciliation never rebuilds today's revisions or performs another mutation. */
	public function reconcile_original( OperationIdentity $identity, string $family, string $version_uuid, array $authorization_scope, RequestContext $request ): OperationAttemptResult|ContractError {
		$original = $this->reader->activation_envelope( $identity, $family, $version_uuid, $authorization_scope, $request );
		if ( $original instanceof ContractError ) { return $original; }
		return $this->lifecycle->reconcile( $original['identity'], $original['payload'], $request );
	}
}
