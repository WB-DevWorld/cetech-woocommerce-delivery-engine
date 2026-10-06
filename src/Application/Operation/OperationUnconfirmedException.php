<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Operation;

/** Internal epistemic failure; never persisted over a possible accepted receipt. */
final class OperationUnconfirmedException extends \RuntimeException {
	public function __construct() {
		parent::__construct( 'The original operation outcome could not be confirmed.' );
	}
}
