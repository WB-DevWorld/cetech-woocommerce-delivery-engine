<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTerms, QuoteTime};

/**
 * Private detached observations from a completed read, never a placement or payment grant.
 * A binding transaction must repeat every current proof; the retained guard holds no read lock.
 */
final readonly class FixedPromotionQuotePlacementEvidence implements \JsonSerializable {

	public function __construct( private FixedPromotionQuoteAcceptCommand $command, private QuoteStoredRow $row, private QuoteTime $evaluated, private QuoteCurrentEvidenceValidity $current_validity, private QuoteCurrentEvidenceGuard $current_guard ) {
		$header = $command->header();
		$accepted = $row->accepted_at();
		if ( 3 !== $header->format_version() || 3 !== $command->context()->format_version() || 3 !== $command->terms()->format_version()
			|| FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'accepted' !== $row->state() || 2 !== $row->revision() || null === $accepted
			|| ! $row->header()->owner()->equals( $command->owner() ) || ! $header->matches_reference( $command->reference() )
			|| $row->header()->to_private_json() !== $header->to_private_json() || $row->context()?->to_private_json() !== $command->context()->to_private_json() || $row->terms()?->to_private_json() !== $command->terms()->to_private_json()
			|| $evaluated->compare( $accepted ) < 0 || ! $header->valid_at( $evaluated ) || $evaluated->compare( $command->hold_until() ) >= 0 || ! $current_validity->valid_at( $evaluated ) ) {
			throw new \InvalidArgumentException( 'Promotion placement observations are inconsistent.' );
		}
	}

	public function owner(): QuoteOwner { return $this->command->owner(); }
	public function acceptance(): FixedPromotionQuoteAcceptCommand { return $this->command; }
	public function reference(): QuoteReference { return $this->command->reference(); }
	public function header(): QuoteHeader { return $this->row->header(); }
	public function context(): QuoteContext { return $this->row->context(); }
	public function terms(): QuoteTerms { return $this->row->terms(); }
	public function quote_record(): QuoteStoredRow { return $this->row; }
	public function accepted_at(): QuoteTime { return $this->row->accepted_at(); }
	public function evaluated_at(): QuoteTime { return $this->evaluated; }
	public function validity(): QuoteCurrentEvidenceValidity { return $this->current_validity; }
	public function guard(): QuoteCurrentEvidenceGuard { return $this->current_guard; }

	public function jsonSerialize(): never { throw new \LogicException( 'Promotion placement observations require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion placement observations cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion placement observations require a new current read.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_placement_evidence' => 'redacted' ]; }
}
