<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Known business rejection, distinct from connection/commit uncertainty. */
final class OperationRefusal extends \RuntimeException {
	public readonly string $error_code;
	public readonly string $recovery_action;
	public readonly array $parameters;
	public readonly array $field_violations;

	public function __construct( string $error_code, string $recovery_action, array $parameters = [], array $field_violations = [] ) {
		$error = new ContractError( $error_code, RequestContext::create(), $recovery_action, $parameters, $field_violations );
		if ( 'rejected' !== $error->completion_outcome ) {
			throw new \InvalidArgumentException( 'Operation refusal requires known rejection.' );
		}
		$this->error_code = $error->code;
		$this->recovery_action = $error->recovery_action;
		$this->parameters = $error->parameters;
		$this->field_violations = $error->field_violations;
		parent::__construct( 'Operation was refused.' );
	}

	public function error( RequestContext $context ): ContractError {
		return new ContractError( $this->error_code, $context, $this->recovery_action, $this->parameters, $this->field_violations );
	}
}
