<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/**
 * Explicit trusted adopter contract. No profile is registered in production.
 * Locking and mutation use only the supplied owner; publication is separate.
 */
interface OperationProfile {
	public function operation(): string;
	public function version(): int;
	public function authorize( OperationIdentity $identity ): bool;
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand;
	/** @return list<string> Exact owned same-site InnoDB participants. */
	public function transactional_tables( OperationSession $session ): array;
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget;
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation;
	/** Idempotent monotonic publication of recorded facts only; never mutation. */
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool;
	public function result_schema(): OperationSchema;
	public function publication_schema(): ?OperationSchema;
	public function actor_schema(): OperationSchema;
	public function target_schema(): OperationSchema;
	/** @return non-empty-list<string> Finite reviewed reason vocabulary. */
	public function reason_codes(): array;
	/** @return non-empty-list<string> Finite reviewed material-field vocabulary. */
	public function changed_fields(): array;
	/** Verify profile-specific result/event identities and revision relationships. */
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool;
}
