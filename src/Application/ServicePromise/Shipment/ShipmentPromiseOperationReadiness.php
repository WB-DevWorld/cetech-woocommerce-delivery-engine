<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Shipment;
use CetechDeliveryEngine\Application\Operation\{OperationReadiness,DatabaseOperationReadiness};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseReadiness;
final class ShipmentPromiseOperationReadiness implements OperationReadiness {
 public function assert_ready(OperationSession $session):void{(new DatabaseOperationReadiness())->assert_ready($session);(new ShipmentPromiseReadiness($session))->assert_ready();}
}
