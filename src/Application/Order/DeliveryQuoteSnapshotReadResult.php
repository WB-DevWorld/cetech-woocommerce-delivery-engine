<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** A finite historical read outcome, never a placement/charge acceptance receipt. */
final readonly class DeliveryQuoteSnapshotReadResult implements \JsonSerializable {
	public function __construct( public string $status, public ?DeliveryQuoteSnapshotEnvelope $envelope = null ) {
		if ( ! in_array( $status, [ 'not_recorded', 'recorded', 'missing', 'malformed', 'unsupported' ], true ) || ( 'recorded' === $status ) !== ( null !== $envelope ) ) { throw new \InvalidArgumentException( 'Invalid historical delivery quote read result.' ); }
	}
	public function supported(): bool { return in_array( $this->status, [ 'not_recorded', 'recorded' ], true ); }
	public function jsonSerialize(): never { throw new \LogicException( 'A safe historical delivery quote projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Historical delivery quote reads cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Historical delivery quote reads require strict decoding.' ); }
}
