<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** A new lifecycle's UTC half-open interval; legacy Rate Card dates are separate. */
final readonly class RuleInterval {
	public function __construct( public RuleTime $from, public ?RuleTime $until = null ) {
		if ( null !== $until && $until->compare( $from ) <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid rule effective interval.' );
		}
	}

	public function contains( RuleTime $at ): bool {
		return $at->compare( $this->from ) >= 0 && ( null === $this->until || $at->compare( $this->until ) < 0 );
	}

	public function overlaps( self $other ): bool {
		return ( null === $this->until || $other->from->compare( $this->until ) < 0 ) && ( null === $other->until || $this->from->compare( $other->until ) < 0 );
	}
}
