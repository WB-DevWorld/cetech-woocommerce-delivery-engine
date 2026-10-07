<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\EmergencyControl;

/** Server observations only; their bounded estimate is not a live cart census. */
final readonly class EmergencyControlDiagnostics implements \JsonSerializable {
	public const OBSERVATION_LIMIT = 200;
	public const ACTIVATION_POLICY = 'current_site_all_or_none';

	public function __construct(
		public int $site_id,
		public EmergencyControlReadResult $control,
		public string $module_readiness = 'unknown',
		public string $activation_policy = self::ACTIVATION_POLICY,
		public ?bool $publication_pending = null,
		public int $observed_lines = 0,
		public int $observed_packages = 0,
		public bool $observed_complete = false
	) {
		if ( $site_id < 1 || ( null !== $control->state && $control->state->site_id !== $site_id )
			|| ! in_array( $module_readiness, [ 'ready', 'unready', 'unknown' ], true )
			|| self::ACTIVATION_POLICY !== $activation_policy
			|| $observed_lines < 0 || $observed_lines > self::OBSERVATION_LIMIT
			|| $observed_packages < 0 || $observed_packages > self::OBSERVATION_LIMIT ) { self::invalid(); }
	}

	/** The exact schema refuses nested, renamed and pre-encoded private input. */
	public static function from_array( array $facts ): self {
		$keys = [ 'site_id', 'control', 'module_readiness', 'activation_policy', 'publication_pending', 'observed_lines', 'observed_packages', 'observed_complete' ];
		if ( count( $facts ) !== count( $keys ) || array_diff( $keys, array_keys( $facts ) )
			|| ! is_int( $facts['site_id'] ) || ! $facts['control'] instanceof EmergencyControlReadResult
			|| ! is_string( $facts['module_readiness'] ) || ! is_string( $facts['activation_policy'] )
			|| ( null !== $facts['publication_pending'] && ! is_bool( $facts['publication_pending'] ) ) || ! is_int( $facts['observed_lines'] )
			|| ! is_int( $facts['observed_packages'] ) || ! is_bool( $facts['observed_complete'] ) ) { self::invalid(); }
		return new self( $facts['site_id'], $facts['control'], $facts['module_readiness'], $facts['activation_policy'], $facts['publication_pending'], $facts['observed_lines'], $facts['observed_packages'], $facts['observed_complete'] );
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Emergency-control diagnostics require an authorized projection.' ); }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid emergency-control diagnostic facts.' ); }
}
