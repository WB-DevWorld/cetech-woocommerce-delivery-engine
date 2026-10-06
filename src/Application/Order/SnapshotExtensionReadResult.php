<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/** Extension evidence is distinct from the unchanged V1/V2 base read result. */
final readonly class SnapshotExtensionReadResult implements \JsonSerializable {
	public function __construct( public string $status, public ?SnapshotExtensionFacts $facts = null ) {
		if ( ! in_array( $status, [ 'not_recorded', 'recorded', 'ignored_optional', 'malformed', 'unsupported_required' ], true ) || ( 'recorded' === $status ) !== ( null !== $facts ) ) {
			throw new \InvalidArgumentException( 'Invalid snapshot extension result.' );
		}
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Snapshot extension result requires an explicit projection.' ); }
}
