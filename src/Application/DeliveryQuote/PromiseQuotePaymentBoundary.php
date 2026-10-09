<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteStoredRow,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository,DeliveryQuoteSchema};

/** Promise-enabled payment keeps original immutable history and adds no factory, provider or clock inside SQL. */
final class PromiseQuotePaymentBoundary implements \JsonSerializable {
	private readonly \Closure $clock;
	private ?QuoteCurrentEvidenceValidity $source_validity = null;
	public function __construct( private readonly QuoteStoredRow $quote, private readonly QuoteBinding $binding, private readonly PromiseQuoteSealLinkage $linkage, private readonly QuoteCurrentEvidenceGuard $current, ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn(): QuoteTime => QuoteTime::now() : \Closure::fromCallable( $clock );
		$f = $linkage->private_facts(); $b = $binding->row();
		if ( 2 !== $quote->header()->format_version() || null === $quote->terms() || null === $quote->context() || 'sealed' !== $binding->state() || $f['site_id'] !== $quote->site_id() || $f['quote_id'] !== $quote->header()->id()->value() || $f['body_digest'] !== $quote->header()->body_digest() || $f['order_id'] !== $b['order_id'] || $f['placement_id'] !== $b['placement_uuid'] || $f['snapshot_digest'] !== $b['snapshot_digest'] || $f['context_digest'] !== $b['context_digest'] || $f['promise_packet_digest'] !== $quote->terms()->promise_packet()->digest() ) { throw new \InvalidArgumentException( 'Acknowledged original promise seal is required.' ); }
	}
	public function eligible_at( QuoteTime $at ): bool { return $this->quote->header()->valid_at( $at ) && $this->quote->terms()->feasibility_at( $at ); }
	/** Pure final check after this exact read has acknowledged and retired. */
	public function eligible_after_read( QuoteTime $at ): bool { return null !== $this->source_validity && $this->source_validity->valid_at( $at ) && $this->eligible_at( $at ); }
	/** Trusted native clock observation, called only while no SQL owner is live. */
	public function capture_time(): QuoteTime { $at = ( $this->clock )(); if ( ! $at instanceof QuoteTime ) { throw new \RuntimeException( 'Promise continuation clock unavailable.' ); } return $at; }
	public function tables( OperationSession $session ): array { return array_values( array_unique( [ ...DeliveryQuoteSchema::tables( $session->table_prefix() ), ...$this->current->tables( $session ) ] ) ); }
	public function verify_at( OperationSession $session, QuoteBinding $binding, QuoteTime $captured_at ): bool {
		$this->source_validity = null;
		try {
			if ( ! $session->in_transaction() || $session->is_retired() || $session->site_id() !== $this->quote->site_id() || $binding->row() !== $this->binding->row() || ! $this->eligible_at( $captured_at ) ) { return false; }
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $this->quote->header(), $binding );
			if ( ! $this->current instanceof QuoteTimedCurrentEvidenceGuard || null === ( $validity = $this->current->validity_at( $session, $this->quote->header()->owner(), $this->quote->context(), $captured_at ) ) || ! $validity->valid_at( $captured_at ) ) { return false; }
			$repository = new DeliveryQuoteRepository( $session ); $quote = $repository->find_quote( $this->quote->header()->id(), true ); $saved_binding = null === $quote ? null : $repository->find_binding( $quote, true );
			if ( null === $quote || null === $saved_binding || $quote->row() !== $this->quote->row() || $saved_binding->row() !== $binding->row() ) { return false; }
			$linkage = $verifier->promise_seal( $quote, $records, $saved_binding );
			if ( null === $linkage || ! hash_equals( $linkage->to_private_json(), $this->linkage->to_private_json() ) || ! $this->eligible_at( $captured_at ) ) { return false; }
			$this->source_validity = $validity; return true;
		} catch ( \Throwable ) { return false; }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promise payment evidence requires private native access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promise payment evidence cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise payment evidence cannot be hydrated generically.' ); }
}
