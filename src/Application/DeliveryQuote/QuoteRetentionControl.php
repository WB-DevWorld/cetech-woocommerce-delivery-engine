<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
interface QuoteRetentionControl {
 public function transactional_tables(OperationSession $session):array;
 /** Must current-lock the server-resolved control; no cached state or caller claim. */
 public function assert_enabled(OperationSession $session):void;
}
