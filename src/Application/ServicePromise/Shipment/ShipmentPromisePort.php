<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Shipment;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Shipment\{Shipment,ShipmentAggregateWriteResult};
/** Native composition resolves the original saved packet's site, independently of current adoption. */
interface ShipmentPromisePort {
 public function create_aggregate(\WC_Order $order,Shipment $draft,array $items,ShipmentEventSource $source,?int $actor):?ShipmentAggregateWriteResult;
 public function predict_paid_order(\WC_Order $order):void;
}
