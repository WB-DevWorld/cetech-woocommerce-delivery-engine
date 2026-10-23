<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;

/** Private immutable sealed linkage history; no spending or payment grant. */
final readonly class FixedPromotionQuoteSealResult implements \JsonSerializable {
	public function __construct( public OperationAttemptResult $attempt, public ?FixedPromotionQuoteSealCommand $command = null, public ?QuoteStoredRow $quote = null, public ?QuoteBinding $binding = null ) {
		if ( ( null === $quote ) !== ( null === $binding ) || ( 'accepted' === $attempt->outcome->state && null === $binding ) ) { self::invalid(); }
		if ( null !== $binding ) {
			if ( null === $command || 'accepted' !== $attempt->completion?->state || 'accepted' !== $quote->state() || 2 !== $quote->revision() || null === $quote->accepted_at()
				|| $quote->row() !== $command->verification()->binding_command()->quote_record()->row() || 'sealed' !== $binding->state() || 3 !== $binding->revision() || QuoteBinding::from_row( $binding->row(), $quote )->row() !== $binding->row() ) { self::invalid(); }
			$actual = $binding->row(); $expected = $command->binding()->row();
			foreach ( array_diff( QuoteBinding::FIELDS, [ 'sealed_at' ] ) as $field ) { if ( $actual[$field] !== $expected[$field] ) { self::invalid(); } }
			$at = QuoteTime::parse( $actual['sealed_at'] );
			if ( $at->compare( QuoteTime::parse( $command->verified_binding()->row()['verified_at'] ) ) < 0 || ! $command->header()->valid_at( $at ) || $at->compare( $command->hold_until() ) >= 0 ) { self::invalid(); }
			foreach ( [ 'quote_id' => $command->header()->id()->value(), 'quote_revision' => 2, 'state' => 'sealed', 'body_digest' => $command->header()->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $command->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'control_revision' => $command->control_revision(), 'binding_id' => $binding->id(), 'placement_id' => $actual['placement_uuid'], 'order_id' => $actual['order_id'], 'binding_revision' => 3, 'manifest_digest' => $actual['managed_group_manifest_digest'], 'snapshot_digest' => $command->snapshot_digest(), 'context_digest' => $command->context_digest(), 'native_money_digest' => $actual['native_money_digest'] ] as $field => $value ) { if ( ( $attempt->completion->result[$field] ?? null ) !== $value ) { self::invalid(); } }
		}
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Promotion seal result facts are inconsistent.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion seal results require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion seal results cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion seal results require acknowledged physical facts.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_seal_result' => 'redacted' ]; }
}
