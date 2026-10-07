<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
/** Strict detached per-group native facts, not a generic provider response. */
final readonly class QuoteNativeGroupReceipt implements \JsonSerializable {
 private array $facts;
 public function __construct(array $group) {
  $terms=QuoteTerms::from_array(['format_version'=>1,'groups'=>[$group]]);
  if(!$terms->checkout_acceptable()){throw new \InvalidArgumentException('Invalid native quote receipt.');}
  $this->facts=$terms->private_facts()['groups'][0];
 }
 public function final_money():QuoteMoney{return QuoteMoney::from_array($this->facts['final']);}
 public function tax_money():QuoteMoney{return QuoteMoney::from_array($this->facts['tax']);}
 public function total_money():QuoteMoney{return QuoteMoney::from_array($this->facts['total']);}
 public function customer_label():string{return $this->facts['customer_label'];}
 public function native_tax_receipt():array{return $this->facts['native_tax_receipt'];}
 public function native_money_receipt():array{return $this->facts['native_money_receipt'];}
 public function promotion():array{return $this->facts['promotion'];}
 public function jsonSerialize():never{throw new \LogicException('An authorized native quote projection is required.');}
 public function __serialize():never{throw new \LogicException('Native quote receipts cannot be serialized generically.');}
 public function __unserialize(array $data):never{throw new \LogicException('Native quote receipts cannot be reconstructed generically.');}
}
