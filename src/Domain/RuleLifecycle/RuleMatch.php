<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

final readonly class RuleMatch {
	public function __construct( public bool $matched, public int $specificity ) {
		if ( $specificity < 0 ) {
			throw new \InvalidArgumentException( 'Invalid rule specificity.' );
		}
	}
}
