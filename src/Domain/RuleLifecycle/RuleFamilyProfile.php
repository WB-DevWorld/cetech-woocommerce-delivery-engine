<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Trusted explicit family contract. No existing business family is registered. */
interface RuleFamilyProfile {
	public function family(): string;
	public function version(): int;
	public function policy_hash(): string;
	public function scope_schema(): RuleSchema;
	public function payload_schema(): RuleSchema;
	public function subject_schema(): RuleSchema;
	public function authorize( OperationIdentity $identity, array $scope ): bool;
	public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool;
	public function match( array $scope, array $subject ): RuleMatch;
	/** Null means that overlap could not be established; publication must refuse. */
	public function overlaps( array $left_scope, array $right_scope ): ?bool;
	/** @return non-empty-list<array{field:string,direction:string}> Explicit order, never SQL order. */
	public function precedence(): array;
	/** 'refuse', or 'tie_break' with an explicit stable logical_uuid rank. */
	public function equal_rank_policy(): string;
	public function unavailable_reason(): string;
}
