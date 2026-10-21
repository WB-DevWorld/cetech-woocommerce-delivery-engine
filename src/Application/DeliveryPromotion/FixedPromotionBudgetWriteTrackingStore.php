<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Per-call mutation uncertainty marker, never a replacement for the caller's rollback. */
final class FixedPromotionBudgetWriteTrackingStore implements FixedPromotionBudgetAccountStore, \JsonSerializable {
	private bool $attempted = false;
	private ?\Closure $assert_owned;
	/** The optional caller fence observes the same owner before and after every delegate. */
	public function __construct( private FixedPromotionBudgetAccountStore $inner, ?callable $assert_owned = null ) { $this->assert_owned = null === $assert_owned ? null : \Closure::fromCallable( $assert_owned ); }
	public function write_attempted(): bool { return $this->attempted; }
	public function tables( OperationSession $session ): array { return $this->invoke( fn() => $this->inner->tables( $session ) ); }
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState { return $this->invoke( fn() => $this->inner->lock_account( $session, $account_key ) ); }
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation { return $this->invoke( fn() => $this->inner->lock_reservation( $session, $claim_key ) ); }
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation { return $this->invoke( fn() => $this->inner->lock_reservation_for_account( $session, $claim_key, $account_key ) ); }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { return $this->invoke( fn() => $this->inner->replace_account( $session, $expected, $next ), true ); }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { return $this->invoke( fn() => $this->inner->insert_reservation( $session, $reservation ), true ); }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { return $this->invoke( fn() => $this->inner->replace_reservation( $session, $expected, $next ), true ); }
	private function invoke( callable $delegate, bool $write = false ): mixed { if ( null !== $this->assert_owned ) { ( $this->assert_owned )(); } if ( $write ) { $this->attempted = true; } $result = $delegate(); if ( null !== $this->assert_owned ) { ( $this->assert_owned )(); } return $result; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion write tracking is private.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion write tracking cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion write tracking requires its owned store.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_write_tracking' => 'redacted' ]; }
}
