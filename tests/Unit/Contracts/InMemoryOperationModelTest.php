<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use LogicException;
use PHPUnit\Framework\TestCase;

final class InMemoryOperationModelTest extends TestCase {

	private function identity(int $site = 1, string $principal = 'staff:7', string $target = 'product:17', string $authority = 'wordpress'): OperationIdentity {
		return new OperationIdentity($site, $authority, $principal, 'fixture.save', 1, $target, 'same-token');
	}

	private function intent(OperationIdentity $identity, int $row = 10, int $revision = 4, int $priority = 33): CanonicalIntent {
		return CanonicalIntent::from_command($identity, ['row_id' => $row], ['revision' => $revision], ['priority' => $priority]);
	}

	public function test_equal_intent_replays_without_second_effect_or_audit_and_new_attempt_id_is_not_intent(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$writes = $audits = 0;
		$effect = static function () use (&$writes, &$audits): OperationOutcome { ++$writes; ++$audits; return OperationOutcome::accepted(); };
		$context = RequestContext::create();
		self::assertSame('accepted', $model->attempt($id, $intent, $context, static fn() => true, $effect)->state);
		$retry = $context->child();
		self::assertNotSame($context->request_id, $retry->request_id);
		self::assertSame('accepted', $model->attempt($id, $this->intent($id), $retry, static fn() => true, $effect)->state);
		self::assertSame(1, $writes);
		self::assertSame(1, $audits);
	}

	public function test_same_token_with_changed_payload_row_or_original_revision_conflicts(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$model->attempt($id, $this->intent($id), RequestContext::create(), static fn() => true, static fn() => OperationOutcome::accepted());
		foreach ([$this->intent($id, priority: 99), $this->intent($id, row: 11), $this->intent($id, revision: 5)] as $changed) {
			$outcome = $model->attempt($id, $changed, RequestContext::create(), static fn() => true, static function (): never { self::fail('Conflicting effect executed.'); });
			self::assertSame('intent_conflict', $outcome->error->code);
		}
	}

	public function test_authorization_precedes_reservation_and_replay_disclosure(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$writes = 0;
		$effect = static function () use (&$writes): OperationOutcome { ++$writes; return OperationOutcome::accepted(); };
		$denied = $model->attempt($id, $intent, RequestContext::create(), static fn() => false, $effect);
		self::assertSame('not_authorized', $denied->error->code);
		self::assertSame(0, $writes);
		$model->attempt($id, $intent, RequestContext::create(), static fn() => true, $effect);
		$denied_replay = $model->attempt($id, $this->intent($id, priority: 99), RequestContext::create(), static fn() => false, $effect);
		self::assertSame('not_authorized', $denied_replay->error->code);
		self::assertSame(1, $writes);
		$denied_reconcile = $model->reconcile($id, $intent, RequestContext::create(), static fn() => false, static function (): never { self::fail('Unauthorized reconciliation disclosed.'); });
		self::assertSame('not_authorized', $denied_reconcile->error->code);
	}

	public function test_site_authority_principal_and_exact_target_are_independent_namespaces(): void {
		$model = new InMemoryOperationModel();
		$writes = 0;
		foreach ([$this->identity(), $this->identity(site: 2), $this->identity(principal: 'staff:8'), $this->identity(target: 'variation:17'), $this->identity(authority: 'integration')] as $id) {
			self::assertSame('accepted', $model->attempt($id, $this->intent($id), RequestContext::create(), static fn() => true, static function () use (&$writes): OperationOutcome { ++$writes; return OperationOutcome::accepted(); })->state);
		}
		self::assertSame(5, $writes);
	}

	public function test_pending_reservation_blocks_reentrant_second_effect(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$writes = 0;
		$completed = $model->attempt($id, $intent, RequestContext::create(), static fn() => true, static function () use ($model, $id, $intent, &$writes): OperationOutcome {
			$pending = $model->attempt($id, $intent, RequestContext::create(), static fn() => true, static function (): never { self::fail('Pending operation repeated its effect.'); });
			self::assertSame('pending', $pending->state);
			++$writes;
			return OperationOutcome::accepted();
		});
		self::assertSame('accepted', $completed->state);
		self::assertSame(1, $writes);
	}

	public function test_lost_acknowledgement_replays_unconfirmed_until_original_operation_reconciles(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$writes = $audits = 0;
		$first = $model->attempt($id, $intent, RequestContext::create(), static fn() => true, static function () use (&$writes, &$audits): never {
			++$writes; ++$audits;
			throw new \RuntimeException('Private SQL and credentials must not escape.');
		});
		self::assertSame('unconfirmed', $first->state);
		self::assertNull($first->mutation_accepted);
		$retry = RequestContext::create();
		$unconfirmed = $model->attempt($id, $intent, $retry, static fn() => true, static function (): never { self::fail('Unknown operation repeated its effect.'); });
		self::assertSame($retry->request_id, $unconfirmed->error->context->request_id);
		self::assertStringNotContainsString('Private SQL', json_encode($unconfirmed->error->to_array(), JSON_THROW_ON_ERROR));
		self::assertSame('accepted', $model->reconcile($id, $intent, $retry, static fn() => true, static fn() => OperationOutcome::accepted())->state);
		self::assertSame(1, $writes);
		self::assertSame(1, $audits);
	}

	public function test_publication_retry_cannot_erase_accepted_mutation_or_repeat_effect_and_audit(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$writes = $audits = 0;
		$error = new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request');
		$model->attempt($id, $intent, RequestContext::create(), static fn() => true, static function () use (&$writes, &$audits, $error): OperationOutcome { ++$writes; ++$audits; return OperationOutcome::awaiting_publication($error); });
		foreach ([OperationOutcome::pending(), OperationOutcome::not_applicable(), OperationOutcome::unconfirmed($error), OperationOutcome::rejected(new ContractError('stale_revision', RequestContext::create(), 'reload_and_submit'))] as $false_fact) {
			try {
				$model->reconcile($id, $intent, RequestContext::create(), static fn() => true, static fn() => $false_fact);
				self::fail('Accepted mutation fact was erased.');
			} catch (LogicException $exception) {
				self::assertSame('Reconciliation cannot erase an accepted mutation.', $exception->getMessage());
			}
		}
		$replay = $model->attempt($id, $intent, RequestContext::create(), static fn() => true, static function (): never { self::fail('Publication retry repeated effect.'); });
		self::assertTrue($replay->mutation_accepted);
		self::assertTrue($replay->publication_pending);
		self::assertSame('accepted', $model->reconcile($id, $intent, RequestContext::create(), static fn() => true, static fn() => OperationOutcome::accepted())->state);
		self::assertSame(1, $writes);
		self::assertSame(1, $audits);
	}

	public function test_known_precommit_rejection_retries_original_preconditions_and_keeps_conflict_guard(): void {
		$model = new InMemoryOperationModel();
		$id = $this->identity();
		$intent = $this->intent($id);
		$current_revision = 4;
		$attempts = $writes = 0;
		$effect = static function () use (&$current_revision, &$attempts, &$writes): OperationOutcome {
			++$attempts;
			if (4 !== $current_revision) { return OperationOutcome::rejected(new ContractError('stale_revision', RequestContext::create(), 'reload_and_submit')); }
			if (1 === $attempts) { return OperationOutcome::rejected(new ContractError('temporarily_unavailable', RequestContext::create(), 'retry_original_request')); }
			++$writes;
			return OperationOutcome::accepted();
		};
		self::assertSame('rejected', $model->attempt($id, $intent, RequestContext::create(), static fn() => true, $effect)->state);
		$current_revision = 5;
		self::assertSame('stale_revision', $model->attempt($id, $intent, RequestContext::create(), static fn() => true, $effect)->error->code);
		self::assertSame('intent_conflict', $model->attempt($id, $this->intent($id, revision: 5), RequestContext::create(), static fn() => true, $effect)->error->code);
		self::assertSame(0, $writes);
		$current_revision = 4;
		self::assertSame('accepted', $model->attempt($id, $intent, RequestContext::create(), static fn() => true, $effect)->state);
		self::assertSame(1, $writes);
	}
}
