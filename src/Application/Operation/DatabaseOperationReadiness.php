<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;

final class DatabaseOperationReadiness implements OperationReadiness {
	public function assert_ready( OperationSession $session ): void {
		( new OperationStoreReadiness( $session ) )->assert_ready();
	}
}
