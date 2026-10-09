<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Persistence;
use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationReadiness};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageReadiness;
final class PromiseOperationReadiness implements OperationReadiness {
	public function assert_ready( OperationSession $session ): void { ( new DatabaseOperationReadiness() )->assert_ready( $session ); ( new PromiseStorageReadiness( $session ) )->assert_ready(); }
}
