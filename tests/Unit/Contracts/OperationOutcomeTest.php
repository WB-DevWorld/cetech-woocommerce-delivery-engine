<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationOutcomeTest extends TestCase {

	public function test_pending_accepted_rejected_and_not_applicable_have_distinct_completion_facts(): void {
		$pending = OperationOutcome::pending();
		$accepted = OperationOutcome::accepted();
		$rejected = OperationOutcome::rejected(new ContractError('stale_revision', RequestContext::create(), 'reload_and_submit'));
		$irrelevant = OperationOutcome::not_applicable();
		self::assertSame('pending', $pending->state);
		self::assertFalse($pending->is_complete());
		self::assertNull($pending->mutation_accepted);
		self::assertTrue($accepted->is_complete());
		self::assertTrue($accepted->mutation_accepted);
		self::assertTrue($rejected->is_complete());
		self::assertFalse($rejected->mutation_accepted);
		self::assertSame('not_applicable', $irrelevant->state);
		self::assertTrue($irrelevant->is_complete());
		self::assertFalse($irrelevant->mutation_accepted);
	}

	public function test_unknown_commit_and_pending_publication_do_not_claim_the_same_mutation_fact(): void {
		$error = new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request');
		$unknown = OperationOutcome::unconfirmed($error);
		$publication = OperationOutcome::awaiting_publication($error);
		self::assertSame('unconfirmed', $unknown->state);
		self::assertSame('unconfirmed', $publication->state);
		self::assertNull($unknown->mutation_accepted);
		self::assertTrue($publication->mutation_accepted);
		self::assertFalse($unknown->publication_pending);
		self::assertTrue($publication->publication_pending);
		self::assertFalse($unknown->is_complete());
		self::assertFalse($publication->is_complete());
	}

	public function test_rejection_cannot_turn_unknown_commit_into_known_failure(): void {
		$this->expectException(InvalidArgumentException::class);
		OperationOutcome::rejected(new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request'));
	}

	public function test_unconfirmed_cannot_hide_a_known_rejection(): void {
		$this->expectException(InvalidArgumentException::class);
		OperationOutcome::unconfirmed(new ContractError('stale_revision', RequestContext::create(), 'reload_and_submit'));
	}
}
