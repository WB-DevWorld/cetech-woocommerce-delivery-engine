<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

interface OperationConnectionFactory {
	/** Open outside an owned unit; never replace a session during its transaction. */
	public function open(): OperationSession;
}
