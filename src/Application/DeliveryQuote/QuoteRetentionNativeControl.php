<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
final class QuoteRetentionNativeControl implements QuoteRetentionControl {
 public function __construct(private readonly bool $trusted_equivalent_route=false) {}
 public function transactional_tables(OperationSession $session):array { return [(new EmergencyControlStore())->options_table($session)]; }
 public function assert_enabled(OperationSession $session):void {
  $store=new EmergencyControlStore(); if(!$this->trusted_equivalent_route) { $store->assert_standard_wordpress_route($session); }
  $store->assert_ready($session,$session->site_id()); if(!$store->current($session)->enabled()) { throw new OperationRefusal('temporarily_unavailable','retry_original_request'); }
 }
}
