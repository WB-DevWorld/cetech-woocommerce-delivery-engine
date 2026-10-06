<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use LogicException;
use Throwable;

/** TEST MODEL ONLY: no persistence, database transaction, process safety or production adoption. */
final class InMemoryOperationModel {

	/** @var array<string, array{intent: CanonicalIntent, outcome: OperationOutcome}> */
	private array $records = [];

	/** Authorization is current on every attempt, including replay disclosure. */
	public function attempt(OperationIdentity $identity, CanonicalIntent $intent, RequestContext $context, callable $authorize, callable $effect): OperationOutcome {
		if (true !== $authorize($identity)) {
			return OperationOutcome::rejected(new ContractError('not_authorized', $context, 'contact_support'));
		}
		$key = $identity->namespace_digest();
		$record = $this->records[$key] ?? null;
		if (null !== $record && !$record['intent']->equals($intent)) {
			return OperationOutcome::rejected(new ContractError('intent_conflict', $context, 'reload_and_submit'));
		}
		if (null !== $record && 'rejected' !== $record['outcome']->state) {
			return $this->for_attempt($record['outcome'], $context);
		}
		// Known precommit rejection may be tried again against the SAME original
		// preconditions. The effect must revalidate them; this is not a stale bypass.
		$this->records[$key] = ['intent' => $intent, 'outcome' => OperationOutcome::pending()];
		try {
			$outcome = $effect();
			if (!$outcome instanceof OperationOutcome) {
				throw new LogicException('Invalid test-model completion.');
			}
		} catch (Throwable) {
			$outcome = OperationOutcome::unconfirmed(new ContractError('outcome_unknown', $context, 'reconcile_original_request'));
		}
		$this->records[$key]['outcome'] = $outcome;
		return $this->for_attempt($outcome, $context);
	}

	/** Reconcile the original reservation; this method never invokes another effect. */
	public function reconcile(OperationIdentity $identity, CanonicalIntent $intent, RequestContext $context, callable $authorize, callable $resolve): OperationOutcome {
		if (true !== $authorize($identity)) {
			return OperationOutcome::rejected(new ContractError('not_authorized', $context, 'contact_support'));
		}
		$key = $identity->namespace_digest();
		$record = $this->records[$key] ?? null;
		if (null === $record || !$record['intent']->equals($intent)) {
			return OperationOutcome::rejected(new ContractError('intent_conflict', $context, 'reload_and_submit'));
		}
		if ($record['outcome']->is_complete()) {
			return $this->for_attempt($record['outcome'], $context);
		}
		$outcome = $resolve($record['outcome']);
		if (!$outcome instanceof OperationOutcome || (true === $record['outcome']->mutation_accepted && true !== $outcome->mutation_accepted)) {
			throw new LogicException('Reconciliation cannot erase an accepted mutation.');
		}
		$this->records[$key]['outcome'] = $outcome;
		return $this->for_attempt($outcome, $context);
	}

	private function for_attempt(OperationOutcome $outcome, RequestContext $context): OperationOutcome {
		if (null === $outcome->error) {
			return $outcome;
		}
		$error = new ContractError($outcome->error->code, $context, $outcome->error->recovery_action, $outcome->error->parameters, $outcome->error->field_violations);
		return match ($outcome->state) {
			'rejected' => OperationOutcome::rejected($error),
			'unconfirmed' => $outcome->publication_pending ? OperationOutcome::awaiting_publication($error) : OperationOutcome::unconfirmed($error),
			default => throw new LogicException('Invalid test-model outcome.'),
		};
	}
}
