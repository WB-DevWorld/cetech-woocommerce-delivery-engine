<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\DecisionAuditFacts;
use CetechDeliveryEngine\Domain\Contracts\DecisionContext;
use CetechDeliveryEngine\Domain\Contracts\DecisionProjection;
use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DecisionAuditFactsTest extends TestCase {

	private function identity(DecisionTarget $target, string $operation = 'configuration.save'): OperationIdentity {
		return new OperationIdentity($target->site_id, 'wordpress', 'staff:9', $operation, 1, $target->key(), 'RAW-TOKEN-MUST-STAY-PRIVATE');
	}

	private function facts(DecisionTarget $target, ?OperationOutcome $completion = null): DecisionAuditFacts {
		return new DecisionAuditFacts($target, $this->identity($target), $completion ?? OperationOutcome::accepted(), 9, 'settings_changed', 7, 8, ['priority']);
	}

	public function test_authorized_audit_projection_contains_only_accepted_revision_and_change_facts(): void {
		$target = new DecisionTarget(1, 42, 43, 'in_store');
		$facts = $this->facts($target);
		$context = new DecisionContext('configuration.save', $target, RequestContext::create(), new DateTimeImmutable(), 'accepted', ['settings_changed'], material_change: $facts);
		$projected = DecisionProjection::for_material_audit($target, RequestContext::create(), static fn(DecisionTarget $requested, string $purpose) => $requested->equals($target) && 'material_audit' === $purpose, static fn() => $context);
		self::assertTrue($projected['accepted_change']);
		self::assertSame(7, $projected['before_revision']);
		self::assertSame(8, $projected['after_revision']);
		self::assertSame(['priority'], $projected['changed_fields']);
		self::assertSame($facts->identity->namespace_digest(), $projected['private_completion_identity']);
		self::assertStringNotContainsString('RAW-TOKEN', json_encode($projected, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('staff:9', json_encode($projected, JSON_THROW_ON_ERROR));
		$log = DecisionProjection::for_diagnostic_log($context);
		self::assertSame('accepted', $log['outcome']);
		self::assertArrayNotHasKey('accepted_change', $log);
		self::assertArrayNotHasKey('actor_id', $log);
		self::assertArrayNotHasKey('private_completion_identity', $log);
	}

	public function test_publication_pending_preserves_accepted_mutation_and_unconfirmed_external_status(): void {
		$target = new DecisionTarget(1, 42);
		$completion = OperationOutcome::awaiting_publication(new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request'));
		$context = new DecisionContext('configuration.save', $target, RequestContext::create(), new DateTimeImmutable(), 'unconfirmed', ['publication_pending'], material_change: $this->facts($target, $completion));
		$shopper = DecisionProjection::for_shopper($context);
		$audit = DecisionProjection::for_material_audit($target, RequestContext::create(), static fn() => true, static fn() => $context);
		self::assertSame('unconfirmed', $shopper['status']);
		self::assertSame('reconcile_original_request', $shopper['recovery_action']);
		self::assertTrue($audit['accepted_change']);
		self::assertSame('unconfirmed', $audit['completion_outcome']);
		self::assertTrue($audit['publication_pending']);
	}

	public function test_unknown_pending_rejected_or_no_effect_cannot_be_accepted_audit_facts(): void {
		$target = new DecisionTarget(1, 42);
		$error = new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request');
		foreach ([OperationOutcome::pending(), OperationOutcome::not_applicable(), OperationOutcome::unconfirmed($error), OperationOutcome::rejected(new ContractError('stale_revision', RequestContext::create(), 'reload_and_submit'))] as $completion) {
			try { $this->facts($target, $completion); self::fail('Nonaccepted mutation claimed as audit.'); }
			catch (InvalidArgumentException $exception) { self::assertSame('Invalid accepted mutation facts.', $exception->getMessage()); }
		}
	}

	public function test_wrong_target_revision_reason_and_renamed_change_fields_are_rejected(): void {
		$target = new DecisionTarget(1, 42);
		$other = new DecisionTarget(1, 43);
		foreach ([[$this->identity($other), 7, 8, 'settings_changed', ['priority']], [$this->identity($target), 7, 7, 'settings_changed', ['priority']], [$this->identity($target), 7, 8, 'settings_reset', ['priority']], [$this->identity($target), 7, 8, 'settings_changed', ['metadata']], [$this->identity($target), 7, 8, 'settings_changed', [['priority' => 'SQL secret']]], [$this->identity($target), 7, 8, 'settings_changed', ['priority', 'priority']]] as [$identity, $before, $after, $reason, $fields]) {
			try { new DecisionAuditFacts($target, $identity, OperationOutcome::accepted(), 9, $reason, $before, $after, $fields); self::fail('Invalid material facts accepted.'); }
			catch (InvalidArgumentException $exception) { self::assertContains($exception->getMessage(), ['Invalid accepted mutation facts.', 'Invalid accepted mutation fields.']); }
		}
	}

	public function test_changed_fields_detach_php_references(): void {
		$target = new DecisionTarget(1, 42);
		$field = 'priority';
		$facts = new DecisionAuditFacts($target, $this->identity($target), OperationOutcome::accepted(), 9, 'settings_changed', 7, 8, [&$field]);
		$field = 'RAW-TOKEN';
		self::assertSame(['priority'], $facts->changed_fields());
	}

	public function test_admin_read_grant_is_not_material_audit_authority_and_loader_is_not_called(): void {
		$target = new DecisionTarget(1, 42);
		$loads = 0;
		$result = DecisionProjection::for_material_audit($target, RequestContext::create(), static fn(DecisionTarget $target, string $purpose) => 'admin_explanation' === $purpose, static function () use (&$loads): never { ++$loads; self::fail('Audit read allowed by admin-only grant.'); });
		self::assertInstanceOf(ContractError::class, $result);
		self::assertSame('not_authorized', $result->code);
		self::assertSame(0, $loads);
	}

	public function test_mutation_context_cannot_publish_contradictory_completion_or_another_scope_facts(): void {
		$target = new DecisionTarget(1, 42);
		$unknown = OperationOutcome::awaiting_publication(new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request'));
		foreach ([['accepted', $this->facts($target, $unknown)], ['rejected', $this->facts($target)], ['accepted', $this->facts(new DecisionTarget(1, 43))], ['accepted', null]] as [$outcome, $facts]) {
			try { new DecisionContext('configuration.save', $target, RequestContext::create(), new DateTimeImmutable(), $outcome, ['settings_changed'], material_change: $facts); self::fail('Contradictory accepted facts emitted.'); }
			catch (InvalidArgumentException $exception) { self::assertContains($exception->getMessage(), ['Mutation facts do not belong to this decision.', 'Decision outcome contradicts mutation completion.', 'Decision outcome requires consistent accepted mutation facts.']); }
		}
	}
}
