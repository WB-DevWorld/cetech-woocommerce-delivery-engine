<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTime};

/** Detached current issued1 observations; no source, lock, admission or writer capability. */
final readonly class FixedPromotionQuoteIssuedEvidence implements \JsonSerializable {

	private FixedPromotionQuoteIssueCommand $command;
	public function __construct( FixedPromotionQuoteIssueCommand $issue, private QuoteStoredRow $row, private QuoteTime $evaluated, private QuoteCurrentEvidenceValidity $current_validity ) {
		$issue = $issue->as_recovery(); $header = $issue->header();
		if ( 3 !== $header->format_version() || 3 !== $issue->context()->format_version() || 3 !== $issue->terms()->format_version()
			|| FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'issued' !== $row->state() || 1 !== $row->revision() || null !== $row->accepted_at() || null !== $row->row()['transition_at']
			|| ! $row->header()->owner()->equals( $issue->owner() ) || ! $header->matches_reference( $issue->reference() )
			|| $row->header()->to_private_json() !== $header->to_private_json() || $row->context()?->to_private_json() !== $issue->context()->to_private_json() || $row->terms()?->to_private_json() !== $issue->terms()->to_private_json()
			|| ! $header->valid_at( $evaluated ) || $evaluated->compare( $issue->hold_until() ) >= 0 || ! $current_validity->valid_at( $evaluated ) ) { throw new \InvalidArgumentException( 'Promotion issued observations are inconsistent.' ); }
		$this->command = $issue;
	}
	public function issue(): FixedPromotionQuoteIssueCommand { return $this->command; }
	public function quote_record(): QuoteStoredRow { return $this->row; }
	public function owner(): QuoteOwner { return $this->command->owner(); }
	public function header(): QuoteHeader { return $this->row->header(); }
	public function reference(): QuoteReference { return $this->command->reference(); }
	public function evaluated_at(): QuoteTime { return $this->evaluated; }
	public function validity(): QuoteCurrentEvidenceValidity { return $this->current_validity; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion issued observations require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion issued observations cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion issued observations require a fresh current read.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_issued_evidence' => 'redacted' ]; }
}
