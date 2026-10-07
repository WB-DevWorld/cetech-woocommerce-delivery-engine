<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\EmergencyControl;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Internal observation; never serialize actor or original row bytes implicitly. */
final readonly class EmergencyControlReadResult implements \JsonSerializable {
	private function __construct( public string $status, public bool $available, public string $code, public ?EmergencyControlState $state, public ?ContractError $error ) {}
	public static function ready( EmergencyControlState $state ): self { return new self( 'ready', true, 'ready', $state, null ); }
	public static function unavailable( string $code, RequestContext $request ): self {
		if ( ! in_array( $code, [ 'temporarily_unavailable', 'stale_revision', 'checkout_suspended', 'not_authorized', 'outcome_unknown' ], true ) ) { throw new \InvalidArgumentException( 'Invalid emergency-control read outcome.' ); }
		$error = match ( $code ) {
			'not_authorized' => new ContractError( $code, $request, 'contact_support' ),
			'outcome_unknown' => new ContractError( $code, $request, 'reconcile_original_request' ),
			'stale_revision' => new ContractError( $code, $request, 'reload_and_submit' ),
			default => new ContractError( 'temporarily_unavailable', $request, 'retry_original_request' ),
		};
		return new self( 'unavailable', false, $code, null, $error );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized emergency-control projection is required.' ); }
}
