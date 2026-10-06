<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** A profile's typed staged result; only the owned acknowledged COMMIT accepts. */
final readonly class OperationMutation {
	private function __construct( public bool $changed, public OperationCompletion $completion, public ?OperationMaterialEvent $event ) {}

	public static function changed( OperationCompletion $completion, OperationMaterialEvent $event ): self {
		if ( 'accepted' !== $completion->state ) {
			throw new \InvalidArgumentException( 'Material mutation requires accepted completion facts.' );
		}
		return new self( true, $completion, $event );
	}

	public static function unchanged( OperationCompletion $completion ): self {
		if ( ! in_array( $completion->state, [ 'not_applicable', 'rejected' ], true ) || null !== $completion->publication ) {
			throw new \InvalidArgumentException( 'No-change mutation cannot claim material acceptance.' );
		}
		return new self( false, $completion, null );
	}
}
