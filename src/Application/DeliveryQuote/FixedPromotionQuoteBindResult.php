<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;

/** Private acknowledged prepared history. It grants no verification, sealing, budget spending or payment authority. */
final readonly class FixedPromotionQuoteBindResult implements \JsonSerializable {
	public function __construct( public OperationAttemptResult $attempt, public ?FixedPromotionQuoteBindCommand $command = null, public ?QuoteStoredRow $quote = null, public ?QuoteBinding $binding = null ) {
		if ( ( null === $quote ) !== ( null === $binding ) || ( 'accepted' === $attempt->outcome->state && null === $binding ) ) { throw new \InvalidArgumentException( 'Promotion binding result facts are inconsistent.' ); }
		if ( null !== $binding ) {
			if ( null === $command || 'accepted' !== $attempt->completion?->state || 'accepted' !== $quote->state() || 2 !== $quote->revision() || null === $quote->accepted_at() || $quote->row() !== $command->quote_record()->row() || 'prepared' !== $binding->state() || 1 !== $binding->revision() || QuoteBinding::from_row( $binding->row(), $quote )->row() !== $binding->row() ) { throw new \InvalidArgumentException( 'Promotion binding result facts are inconsistent.' ); }
			$actual = $binding->row(); $expected = $command->binding()->row();
			foreach ( array_diff( QuoteBinding::FIELDS, [ 'id', 'created_at' ] ) as $field ) { if ( $actual[$field] !== $expected[$field] ) { throw new \InvalidArgumentException( 'Promotion binding result facts are inconsistent.' ); } }
			$at = QuoteTime::parse( $actual['created_at'] );
			if ( $at->compare( $quote->accepted_at() ) < 0 || ! $command->header()->valid_at( $at ) || $at->compare( $command->hold_until() ) >= 0 ) { throw new \InvalidArgumentException( 'Promotion binding result facts are inconsistent.' ); }
			foreach ( [ 'quote_id' => $command->header()->id()->value(), 'quote_revision' => 2, 'state' => 'prepared', 'body_digest' => $command->header()->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $command->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'binding_id' => $binding->id(), 'placement_id' => $actual['placement_uuid'], 'order_id' => $actual['order_id'], 'binding_revision' => 1, 'manifest_digest' => $actual['managed_group_manifest_digest'] ] as $field => $value ) { if ( ( $attempt->completion->result[$field] ?? null ) !== $value ) { throw new \InvalidArgumentException( 'Promotion binding result facts are inconsistent.' ); } }
		}
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion binding results require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion binding results cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion binding results require acknowledged physical facts.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_bind_result' => 'redacted' ]; }
}
