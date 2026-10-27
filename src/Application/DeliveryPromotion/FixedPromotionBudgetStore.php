<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Authoritative same-owner SQL port. It never opens or completes a transaction. */
interface FixedPromotionBudgetStore {
	public function tables( OperationSession $session ): array;
	/** Account first: its lock also serializes absence of a claim in that account. */
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState;
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation;
	/** Compare every expected persisted field; false or a thrown error requires caller rollback. */
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool;
	/** Unique claim key; an existing or uncertain insert is never silently accepted. */
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool;
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool;
}
