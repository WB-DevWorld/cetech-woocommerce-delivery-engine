<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseResult;

/** Closed refusal facts; no supplied calendar, address or private source text escapes. */
final class PromiseCalculationException extends \RuntimeException {
	public function __construct( private readonly string $safe_reason ) {
		if ( ! in_array( $safe_reason, PromiseResult::REASON_CODES, true ) ) { throw new \InvalidArgumentException( 'Invalid calculation refusal.' ); }
		parent::__construct( 'Service promise calculation refused.' );
	}

	public function reason(): string { return $this->safe_reason; }
}
