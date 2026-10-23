<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Private read disposition. Ready observations do not authorize a later placement or payment. */
final readonly class FixedPromotionQuotePlacementReadResult implements \JsonSerializable {

	public function __construct( public string $status, public ?string $reason = null, public ?FixedPromotionQuotePlacementEvidence $evidence = null ) {
		if ( ! in_array( $status, [ 'ready', 'unavailable' ], true ) || ( null !== $reason && 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $reason ) )
			|| ( 'ready' === $status && ( null === $evidence || null !== $reason ) ) || ( 'unavailable' === $status && null !== $evidence ) ) {
			throw new \InvalidArgumentException( 'Promotion placement read result is inconsistent.' );
		}
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Promotion placement reads require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion placement reads cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion placement reads require a new owned read.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_placement_read' => 'redacted' ]; }
}
