<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteCurrentEvidenceGuard, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetAllocationSet, FixedPromotionBudgetReservation};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Private ephemeral preparation facts, never proof of committed storage or admission. */
final readonly class FixedPromotionQuotePreparation implements \JsonSerializable {
	private function __construct( private FixedPromotionBudgetAllocationSet $set, private array $claims, private FixedPromotionQuoteCurrentEvidenceGuard $current_guard, private QuoteCurrentEvidenceValidity $current_validity ) {}

	/** @internal Only observations from an explicitly owned preparation belong here. */
	public static function from_prepared( FixedPromotionBudgetAllocationSet $set, array $reservations, FixedPromotionQuoteCurrentEvidenceGuard $guard, QuoteCurrentEvidenceValidity $validity, QuoteTime $at, QuoteTime $hold_until ): self {
		$requests = $set->requests();
		if ( ! array_is_list( $reservations ) || count( $reservations ) !== count( $requests ) || ! $validity->valid_at( $at ) || $at->compare( $hold_until ) >= 0 || $hold_until->compare( $set->header()->expires_at() ) > 0 ) { self::refuse(); }
		$claims = [];
		foreach ( $requests as $i => $request ) {
			$claim = $reservations[$i];
			if ( ! $claim instanceof FixedPromotionBudgetReservation || ! $claim->matches( $request ) || ! $claim->is_held_at( $at ) || ! $claim->expires_at()->equals( $hold_until ) ) { self::refuse(); }
			$claims[] = $claim;
		}
		return new self( $set, $claims, $guard, $validity );
	}
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->set; }
	/** @return list<FixedPromotionBudgetReservation> */
	public function reservations(): array { return $this->claims; }
	public function guard(): FixedPromotionQuoteCurrentEvidenceGuard { return $this->current_guard; }
	public function validity(): QuoteCurrentEvidenceValidity { return $this->current_validity; }
	private static function refuse(): never { throw new \InvalidArgumentException( 'Promotion preparation facts are unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion preparation facts require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion preparation facts cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion preparation facts cannot be reconstructed implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_preparation' => 'redacted' ]; }
}
