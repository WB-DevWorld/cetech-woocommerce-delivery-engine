<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Independently captured host context supplied by composition, never inferred from a promise input. */
final readonly class PromiseCalculationRuntime {
	private array $facts;
	public function __construct( array $captured_runtime ) {
		PromiseShape::fields( $captured_runtime, [ 'timezone_data_version', 'runtime_id', 'digest' ] );
		$this->facts = [ 'timezone_data_version' => PromiseShape::text( $captured_runtime['timezone_data_version'], 128 ), 'runtime_id' => PromiseShape::id( $captured_runtime['runtime_id'] ), 'digest' => PromiseShape::digest( $captured_runtime['digest'] ) ];
	}
	/** Context equality is not an independent filesystem tzdata attestation. */
	public function matches( array $runtime ): bool {
		return $this->facts['timezone_data_version'] === timezone_version_get() && $this->facts['timezone_data_version'] === $runtime['timezone_data_version'] && $this->facts['runtime_id'] === $runtime['runtime_id'] && hash_equals( $this->facts['digest'], $runtime['digest'] );
	}
}
