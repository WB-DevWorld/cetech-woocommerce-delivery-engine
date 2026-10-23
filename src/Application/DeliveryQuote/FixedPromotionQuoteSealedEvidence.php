<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTerms, QuoteTime};

/** Detached current sealed observations, never a retained lock or placement/payment grant. */
final readonly class FixedPromotionQuoteSealedEvidence implements \JsonSerializable {

	public function __construct( private FixedPromotionQuoteSealCommand $command, private QuoteStoredRow $quote, private QuoteBinding $binding, private int $current_control_revision, private QuoteTime $evaluated, private QuoteCurrentEvidenceValidity $validity ) {
		$header = $command->header();
		if ( $current_control_revision < 1 || 3 !== $header->format_version() || 3 !== $command->context()->format_version() || 3 !== $command->terms()->format_version()
			|| FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'accepted' !== $quote->state() || 2 !== $quote->revision() || null === $quote->accepted_at()
			|| $quote->row() !== $command->verification()->binding_command()->quote_record()->row()
			|| 'sealed' !== $binding->state() || 3 !== $binding->revision() || QuoteBinding::from_row( $binding->row(), $quote )->row() !== $binding->row() ) { self::invalid(); }
		$actual = $binding->row(); $expected = $command->binding()->row();
		foreach ( array_diff( QuoteBinding::FIELDS, [ 'sealed_at' ] ) as $field ) { if ( $actual[$field] !== $expected[$field] ) { self::invalid(); } }
		$sealed = QuoteTime::parse( $actual['sealed_at'] );
		if ( $sealed->compare( QuoteTime::parse( $command->verified_binding()->row()['verified_at'] ) ) < 0
			|| ! $header->valid_at( $sealed ) || $sealed->compare( $command->hold_until() ) >= 0
			|| $evaluated->compare( $sealed ) < 0 || ! $header->valid_at( $evaluated ) || $evaluated->compare( $command->hold_until() ) >= 0 || ! $validity->valid_at( $evaluated ) ) { self::invalid(); }
	}

	public function owner(): QuoteOwner { return $this->command->owner(); }
	public function reference(): QuoteReference { return $this->command->reference(); }
	public function header(): QuoteHeader { return $this->quote->header(); }
	public function context(): QuoteContext { return $this->quote->context(); }
	public function terms(): QuoteTerms { return $this->quote->terms(); }
	public function quote_record(): QuoteStoredRow { return $this->quote; }
	public function binding(): QuoteBinding { return $this->binding; }
	public function sealed_at(): QuoteTime { return QuoteTime::parse( $this->binding->row()['sealed_at'] ); }
	public function evaluated_at(): QuoteTime { return $this->evaluated; }
	public function validity(): QuoteCurrentEvidenceValidity { return $this->validity; }
	public function control_revision(): int { return $this->current_control_revision; }

	private static function invalid(): never { throw new \InvalidArgumentException( 'Promotion sealed observations are inconsistent.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion sealed observations require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion sealed observations cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion sealed observations require a new current read.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_sealed_evidence' => 'redacted' ]; }
}
