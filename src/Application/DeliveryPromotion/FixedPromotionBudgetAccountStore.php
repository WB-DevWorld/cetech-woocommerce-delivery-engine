<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetReservation;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Current claim reads with explicit account identity, without transaction-scoped lock caches. */
interface FixedPromotionBudgetAccountStore extends FixedPromotionBudgetStore {
	/** Lock the account before the claim and verify its immutable account binding. */
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation;
}
